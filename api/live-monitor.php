<?php

require_once __DIR__ . '/config.php';

if (PHP_SAPI !== 'cli') {
    app_log('system', 'Web request to the live monitor worker was rejected');
    http_response_code(404);
    exit;
}

function live_monitor_dns_probe()
{
    $host = '1.1.1.1';
    $queryName = 'cloudflare.com';
    try {
        $transactionId = random_int(0, 65535);
    } catch (Throwable $exception) {
        return ['ok' => false, 'ms' => null, 'error' => 'transaction_id_unavailable'];
    }

    $packet = pack('nnnnnn', $transactionId, 0x0100, 1, 0, 0, 0);
    foreach (explode('.', $queryName) as $label) {
        if ($label === '' || strlen($label) > 63) {
            return ['ok' => false, 'ms' => null, 'error' => 'invalid_dns_query_name'];
        }
        $packet .= chr(strlen($label)) . $label;
    }
    $packet .= "\0" . pack('nn', 1, 1);

    $startedAt = microtime(true);
    $socket = @stream_socket_client('udp://' . $host . ':53', $errno, $error, 1, STREAM_CLIENT_CONNECT);
    if ($socket === false) {
        return [
            'ok' => false,
            'ms' => round((microtime(true) - $startedAt) * 1000, 2),
            'error' => 'dns_server_unreachable'
        ];
    }

    stream_set_timeout($socket, 1);
    $written = @fwrite($socket, $packet);
    $response = $written === false ? false : @fread($socket, 512);
    $streamInfo = stream_get_meta_data($socket);
    fclose($socket);

    $elapsedMs = round((microtime(true) - $startedAt) * 1000, 2);
    if (!is_string($response) || strlen($response) < 12 || !empty($streamInfo['timed_out'])) {
        return ['ok' => false, 'ms' => $elapsedMs, 'error' => 'dns_query_timeout'];
    }

    $header = unpack('nid/nflags/nquestions/nanswers', substr($response, 0, 8));
    $validResponse = is_array($header)
        && $header['id'] === $transactionId
        && ($header['flags'] & 0x8000) !== 0
        && ($header['flags'] & 0x000F) === 0
        && $header['answers'] > 0;

    return [
        'ok' => $validResponse,
        'ms' => $elapsedMs,
        'error' => $validResponse ? null : 'dns_response_invalid'
    ];
}

function live_monitor_http_probes($targets)
{
    $results = [];
    $handles = [];
    $multiHandle = curl_multi_init();
    if ($multiHandle === false) {
        foreach ($targets as $name => $url) {
            $results[$name] = [
                'configured' => $url !== '',
                'ok' => false,
                'ms' => null,
                'error' => 'curl_multi_unavailable'
            ];
        }
        return $results;
    }

    foreach ($targets as $name => $url) {
        if ($url === '') {
            $results[$name] = ['configured' => false, 'ok' => null, 'ms' => null, 'error' => null];
            continue;
        }

        if (filter_var($url, FILTER_VALIDATE_URL) === false) {
            $results[$name] = ['configured' => true, 'ok' => false, 'ms' => null, 'error' => 'invalid_url'];
            continue;
        }

        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        if (!in_array($scheme, ['http', 'https'], true)) {
            $results[$name] = ['configured' => true, 'ok' => false, 'ms' => null, 'error' => 'unsupported_url_scheme'];
            continue;
        }

        $handle = curl_init($url);
        if ($handle === false) {
            $results[$name] = ['configured' => true, 'ok' => false, 'ms' => null, 'error' => 'curl_init_failed'];
            continue;
        }

        curl_setopt_array($handle, [
            CURLOPT_HTTPGET => true,
            CURLOPT_HTTPHEADER => ['Accept: */*'],
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_WRITEFUNCTION => static function ($curlHandle, $chunk) {
                return strlen($chunk);
            },
            CURLOPT_CONNECTTIMEOUT_MS => 1200,
            CURLOPT_TIMEOUT_MS => 2500,
            CURLOPT_NOSIGNAL => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_USERAGENT => 'UptimeKumaSendWA-LiveMonitor/1.0'
        ]);

        $multiStatus = curl_multi_add_handle($multiHandle, $handle);
        if ($multiStatus !== CURLM_OK) {
            curl_close($handle);
            $results[$name] = ['configured' => true, 'ok' => false, 'ms' => null, 'error' => 'curl_multi_add_failed'];
            continue;
        }
        $handles[$name] = $handle;
    }

    $running = 0;
    do {
        $multiStatus = curl_multi_exec($multiHandle, $running);
    } while ($multiStatus === CURLM_CALL_MULTI_PERFORM);

    while ($running > 0 && $multiStatus === CURLM_OK) {
        if (curl_multi_select($multiHandle, 0.1) === -1) {
            usleep(10000);
        }
        do {
            $multiStatus = curl_multi_exec($multiHandle, $running);
        } while ($multiStatus === CURLM_CALL_MULTI_PERFORM);
    }

    foreach ($handles as $name => $handle) {
        $info = curl_getinfo($handle);
        $error = curl_error($handle);
        $httpCode = (int) ($info['http_code'] ?? 0);
        $totalTime = (float) ($info['total_time'] ?? 0);
        $nameLookupTime = (float) ($info['namelookup_time'] ?? 0);
        $reachable = $error === '' && $httpCode > 0;

        $results[$name] = [
            'configured' => true,
            'ok' => $reachable,
            'ms' => $reachable ? round(max(0, $totalTime - $nameLookupTime) * 1000, 2) : null,
            'dns_ms' => round($nameLookupTime * 1000, 2),
            'error' => $reachable ? null : ($error !== '' ? 'http_request_failed' : 'no_http_response')
        ];

        curl_multi_remove_handle($multiHandle, $handle);
        curl_close($handle);
    }
    curl_multi_close($multiHandle);

    return $results;
}

function live_monitor_write_snapshot($snapshot)
{
    // Keep the snapshot outside log/ because cron may run as root while PHP-FPM
    // runs as the site user. The public status handler can read this file safely.
    $path = __DIR__ . '/live-monitor.json';
    $temporaryPath = $path . '.tmp';
    $json = json_encode($snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    if ($json === false || file_put_contents($temporaryPath, $json, LOCK_EX) === false) {
        return false;
    }

    @chmod($temporaryPath, 0644);
    if (!@rename($temporaryPath, $path)) {
        @unlink($temporaryPath);
        return false;
    }
    return true;
}

function live_monitor_collect_snapshot()
{
    $apiBaseUrl = rtrim(app_env('NGIRIMWA_BASE_URL', 'https://dash.ngirimwa.com/api/v1'), '/');
    $uptimeKumaUrl = trim(app_env('UPTIME_KUMA_URL'));
    if ($uptimeKumaUrl === '') {
        $uptimeKumaUrl = trim(app_env('UPTIME_STATUS_WEBSITE'));
    }
    $dnsResult = live_monitor_dns_probe();

    $httpResults = live_monitor_http_probes([
        'ngirimwa' => $apiBaseUrl . '/devices/status',
        'uptime_kuma' => $uptimeKumaUrl
    ]);

    return [
        'checked_at' => date(DATE_ATOM),
        'dns' => $dnsResult,
        'ngirimwa' => $httpResults['ngirimwa'],
        'uptime_kuma' => $httpResults['uptime_kuma']
    ];
}

$lock = @fopen(__DIR__ . '/live-monitor.lock', 'c');
if ($lock === false) {
    app_log('system', 'Could not open the live monitor lock file');
    exit(1);
}
if (!flock($lock, LOCK_EX | LOCK_NB)) {
    fclose($lock);
    app_log('cron-run', 'Live monitor worker skipped because another run is active');
    exit(0);
}

$loopMode = in_array('--loop', $argv ?? [], true);
$iterations = $loopMode ? 12 : 1;
$startedAt = microtime(true);
$completedSamples = 0;
app_log('cron-run', 'Live monitor worker started', [
    'mode' => $loopMode ? 'loop' : 'once',
    'samples_planned' => $iterations
]);

for ($iteration = 0; $iteration < $iterations; $iteration++) {
    if ($iteration > 0) {
        $targetTime = $startedAt + ($iteration * 5);
        $remainingMicroseconds = (int) (($targetTime - microtime(true)) * 1000000);
        if ($remainingMicroseconds > 0) {
            usleep($remainingMicroseconds);
        }
    }

    try {
        if (live_monitor_write_snapshot(live_monitor_collect_snapshot())) {
            $completedSamples++;
        } else {
            app_log_rate_limited('system', 'Could not write live monitor snapshot', 'live-monitor-snapshot-write', 60);
        }
    } catch (Throwable $exception) {
        app_log_rate_limited('system', 'Live monitor sample failed', 'live-monitor-sample-failed', 60, [
            'error' => $exception->getMessage()
        ]);
    }
}

app_log('cron-run', 'Live monitor worker completed', [
    'samples_completed' => $completedSamples,
    'duration_ms' => round((microtime(true) - $startedAt) * 1000, 2)
]);
flock($lock, LOCK_UN);
fclose($lock);
