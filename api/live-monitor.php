<?php #ide cemerlang eki guistian

require_once __DIR__ . '/config.php';

if (PHP_SAPI !== 'cli') {
    app_log('system', 'Web request to the live monitor worker was rejected');
    http_response_code(404);
    exit;
}

function live_monitor_read_nameservers($path)
{
    if (!is_readable($path)) {
        return [];
    }

    $lines = @file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (!is_array($lines)) {
        return [];
    }

    $servers = [];
    foreach ($lines as $line) {
        if (!preg_match('/^\s*nameserver\s+([^\s#]+)/i', $line, $matches)) {
            continue;
        }
        $address = trim($matches[1], '[]');
        if (filter_var($address, FILTER_VALIDATE_IP)) {
            $servers[] = $address;
        }
    }

    return array_values(array_unique($servers));
}

function live_monitor_dns_servers()
{
    // Prefer upstream resolver files when the system uses a local DNS stub.
    foreach ([
        '/run/systemd/resolve/resolv.conf',
        '/run/NetworkManager/no-stub-resolv.conf',
        '/run/NetworkManager/resolv.conf'
    ] as $resolverPath) {
        $servers = live_monitor_read_nameservers($resolverPath);
        $upstreamServers = array_values(array_filter($servers, static function ($address) {
            return $address !== '::1' && !(strpos($address, '127.') === 0);
        }));
        if ($upstreamServers !== []) {
            return $upstreamServers;
        }
    }

    // Fall back to the resolver list used by the system, including a local stub.
    return live_monitor_read_nameservers('/etc/resolv.conf');
}

function live_monitor_dns_packet($transactionId)
{
    $packet = pack('nnnnnn', $transactionId, 0x0100, 1, 0, 0, 0);
    foreach (explode('.', 'cloudflare.com') as $label) {
        $packet .= chr(strlen($label)) . $label;
    }
    return $packet . "\0" . pack('nn', 1, 1);
}

function live_monitor_dns_response_valid($response, $transactionId)
{
    if (!is_string($response) || strlen($response) < 12) {
        return false;
    }
    $header = unpack('nid/nflags/nquestions/nanswers', substr($response, 0, 8));
    return is_array($header)
        && $header['id'] === $transactionId
        && ($header['flags'] & 0x8000) !== 0
        && ($header['flags'] & 0x000F) === 0
        && $header['answers'] > 0;
}

function live_monitor_dns_label($address, $index)
{
    if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
        return $address;
    }
    return 'Resolver lokal ' . $index;
}

function live_monitor_dns_probe()
{
    $servers = live_monitor_dns_servers();
    if ($servers === []) {
        return [
            'configured' => false,
            'ok' => null,
            'ms' => null,
            'resolvers' => [],
            'error' => 'dns_resolvers_not_found'
        ];
    }

    $pending = [];
    $results = [];
    foreach ($servers as $index => $address) {
        $label = live_monitor_dns_label($address, $index + 1);
        $startedAt = microtime(true);
        try {
            $transactionId = random_int(0, 65535);
        } catch (Throwable $exception) {
            $results[$address] = ['label' => $label, 'ok' => false, 'ms' => null, 'error' => 'transaction_id_unavailable'];
            continue;
        }

        $socketHost = strpos($address, ':') !== false ? '[' . $address . ']' : $address;
        $socket = @stream_socket_client('udp://' . $socketHost . ':53', $errno, $error, 0.3, STREAM_CLIENT_CONNECT);
        if ($socket === false) {
            $results[$address] = [
                'label' => $label,
                'ok' => false,
                'ms' => round((microtime(true) - $startedAt) * 1000, 2),
                'error' => 'dns_server_unreachable'
            ];
            continue;
        }

        stream_set_blocking($socket, false);
        $packet = live_monitor_dns_packet($transactionId);
        if (@fwrite($socket, $packet) !== strlen($packet)) {
            fclose($socket);
            $results[$address] = [
                'label' => $label,
                'ok' => false,
                'ms' => round((microtime(true) - $startedAt) * 1000, 2),
                'error' => 'dns_query_write_failed'
            ];
            continue;
        }

        $pending[] = [
            'address' => $address,
            'label' => $label,
            'socket' => $socket,
            'transaction_id' => $transactionId,
            'started_at' => $startedAt
        ];
    }

    $deadline = microtime(true) + 1.2;
    while ($pending !== []) {
        $remaining = $deadline - microtime(true);
        if ($remaining <= 0) {
            break;
        }

        $readSockets = array_map(static function ($item) {
            return $item['socket'];
        }, $pending);
        $writeSockets = [];
        $exceptSockets = [];
        $timeoutMicroseconds = min(200000, (int) ($remaining * 1000000));
        $selected = @stream_select(
            $readSockets,
            $writeSockets,
            $exceptSockets,
            intdiv($timeoutMicroseconds, 1000000),
            $timeoutMicroseconds % 1000000
        );
        if ($selected === false) {
            break;
        }
        if ($selected === 0) {
            continue;
        }

        foreach ($pending as $pendingIndex => $item) {
            if (!in_array($item['socket'], $readSockets, true)) {
                continue;
            }
            $response = @fread($item['socket'], 512);
            $validResponse = live_monitor_dns_response_valid($response, $item['transaction_id']);
            $results[$item['address']] = [
                'label' => $item['label'],
                'ok' => $validResponse,
                'ms' => round((microtime(true) - $item['started_at']) * 1000, 2),
                'error' => $validResponse ? null : 'dns_response_invalid'
            ];
            fclose($item['socket']);
            unset($pending[$pendingIndex]);
        }
        $pending = array_values($pending);
    }

    foreach ($pending as $item) {
        $results[$item['address']] = [
            'label' => $item['label'],
            'ok' => false,
            'ms' => round((microtime(true) - $item['started_at']) * 1000, 2),
            'error' => 'dns_query_timeout'
        ];
        fclose($item['socket']);
    }

    $resolverResults = array_values($results);
    $responsive = array_values(array_filter($resolverResults, static function ($item) {
        return !empty($item['ok']) && is_numeric($item['ms'] ?? null);
    }));
    $averageMs = null;
    if ($responsive !== []) {
        $averageMs = round(array_sum(array_column($responsive, 'ms')) / count($responsive), 2);
    }

    return [
        'configured' => true,
        'ok' => $responsive !== [],
        'ms' => $averageMs,
        'resolvers' => $resolverResults,
        'error' => $responsive !== [] ? null : 'no_dns_resolver_responded'
    ];
}

function live_monitor_icmp_ping($url)
{
    if ($url === '') {
        return ['configured' => false, 'ok' => null, 'ms' => null, 'error' => null];
    }
    if (filter_var($url, FILTER_VALIDATE_URL) === false) {
        return ['configured' => true, 'ok' => false, 'ms' => null, 'error' => 'invalid_url'];
    }

    $host = parse_url($url, PHP_URL_HOST);
    if (!is_string($host) || $host === '') {
        return ['configured' => true, 'ok' => false, 'ms' => null, 'error' => 'invalid_host'];
    }
    $host = trim($host, '[]');
    if (filter_var($host, FILTER_VALIDATE_IP) === false
        && filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) === false) {
        return ['configured' => true, 'ok' => false, 'ms' => null, 'error' => 'invalid_host'];
    }
    if (!function_exists('proc_open')) {
        return ['configured' => true, 'ok' => false, 'ms' => null, 'error' => 'icmp_process_unavailable'];
    }

    $isWindows = PHP_OS_FAMILY === 'Windows';
    $pingBinary = 'ping';
    if (!$isWindows) {
        foreach (['/usr/bin/ping', '/bin/ping', '/usr/sbin/ping', '/sbin/ping'] as $candidate) {
            if (is_executable($candidate)) {
                $pingBinary = $candidate;
                break;
            }
        }
    }
    $command = $isWindows
        ? [$pingBinary, '-n', '1', '-w', '1000', $host]
        : [$pingBinary, '-n', '-c', '1', '-W', '1', $host];
    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w']
    ];
    $startedAt = microtime(true);
    $processEnvironment = [
        'LC_ALL' => 'C',
        'PATH' => (string) (getenv('PATH') ?: '/usr/bin:/bin:/usr/sbin:/sbin')
    ];
    $systemRoot = getenv('SystemRoot');
    if (is_string($systemRoot) && $systemRoot !== '') {
        $processEnvironment['SystemRoot'] = $systemRoot;
    }
    $process = @proc_open($command, $descriptors, $pipes, null, $processEnvironment);
    if (!is_resource($process)) {
        return ['configured' => true, 'ok' => false, 'ms' => null, 'error' => 'icmp_process_unavailable'];
    }

    fclose($pipes[0]);
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);
    $stdout = '';
    $stderr = '';
    $exitCode = null;
    $timedOut = false;
    do {
        $stdout .= (string) stream_get_contents($pipes[1]);
        $stderr .= (string) stream_get_contents($pipes[2]);
        $processStatus = proc_get_status($process);
        if (!$processStatus['running']) {
            $exitCode = (int) $processStatus['exitcode'];
            break;
        }
        if (microtime(true) - $startedAt >= 4) {
            $timedOut = true;
            @proc_terminate($process);
            break;
        }
        usleep(50000);
    } while (true);
    $stdout .= (string) stream_get_contents($pipes[1]);
    $stderr .= (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $closeCode = proc_close($process);
    if ($exitCode === null || $exitCode < 0) {
        $exitCode = $closeCode;
    }
    $output = (string) $stdout . "\n" . (string) $stderr;
    $milliseconds = null;
    if (preg_match('/time\s*<\s*1\s*ms/i', $output)) {
        $milliseconds = 0.5;
    } elseif (preg_match('/time\s*=\s*([0-9]+(?:[.,][0-9]+)?)\s*ms/i', $output, $matches)) {
        $milliseconds = (float) str_replace(',', '.', $matches[1]);
    }

    $reachable = $exitCode === 0 && $milliseconds !== null;
    return [
        'configured' => true,
        'ok' => $reachable,
        'ms' => $reachable ? round($milliseconds, 2) : null,
        'error' => $reachable ? null : ($timedOut ? 'icmp_ping_timeout' : ($exitCode === 127 ? 'icmp_ping_not_installed' : 'icmp_ping_failed')),
        'duration_ms' => round((microtime(true) - $startedAt) * 1000, 2)
    ];
}

function live_monitor_domain_pings($targets)
{
    $results = [];
    foreach ($targets as $name => $url) {
        $results[$name] = live_monitor_icmp_ping($url);
    }
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
    $apiBaseUrl = trim(app_env('NGIRIMWA_BASE_URL'));
    if ($apiBaseUrl === '') {
        $apiBaseUrl = 'https://dash.ngirimwa.com/api/v1';
    }
    $apiBaseUrl = rtrim($apiBaseUrl, '/');
    $uptimeKumaUrl = trim(app_env('UPTIME_STATUS_WEBSITE'));
    $dnsResult = live_monitor_dns_probe();
    $targets = [
        'ngirimwa' => $apiBaseUrl . '/devices/status',
        'uptime_kuma' => $uptimeKumaUrl
    ];
    $targetsHash = hash('sha256', json_encode($targets, JSON_UNESCAPED_SLASHES));
    $snapshotPath = __DIR__ . '/live-monitor.json';
    $previous = app_read_json_array($snapshotPath);
    $domainCheckedAt = is_array($previous) ? (int) ($previous['domain_checked_at'] ?? 0) : 0;
    $sameTargets = is_array($previous) && hash_equals(
        (string) ($previous['domain_targets_hash'] ?? ''),
        $targetsHash
    );
    $domainChecksDue = !$sameTargets || $domainCheckedAt <= 0 || (time() - $domainCheckedAt) >= 60;

    if ($domainChecksDue) {
        $domainResults = live_monitor_domain_pings($targets);
        $domainCheckedAt = time();
    } else {
        $domainResults = [
            'ngirimwa' => $previous['ngirimwa'] ?? ['configured' => false, 'ok' => null, 'ms' => null, 'error' => null],
            'uptime_kuma' => $previous['uptime_kuma'] ?? ['configured' => false, 'ok' => null, 'ms' => null, 'error' => null]
        ];
    }

    return [
        'checked_at' => date(DATE_ATOM),
        'domain_checked_at' => date(DATE_ATOM, $domainCheckedAt),
        'domain_checked_epoch' => $domainCheckedAt,
        'domain_targets_hash' => $targetsHash,
        'dns' => $dnsResult,
        'ngirimwa' => $domainResults['ngirimwa'],
        'uptime_kuma' => $domainResults['uptime_kuma']
    ];
}

$lock = @fopen(__DIR__ . '/live-monitor.lock', 'c');
if ($lock === false) {
    app_log('system', 'Could not open the live monitor lock file');
    exit(1);
}
if (!flock($lock, LOCK_EX | LOCK_NB)) {
    fclose($lock);
    app_log_rate_limited('cron-run', 'Live monitor worker skipped because another run is active', 'live-monitor-overlap', 60);
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
