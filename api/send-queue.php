<?php

require_once __DIR__ . '/config.php';

if (PHP_SAPI !== 'cli') {
    app_log('system', 'Web request to the cron worker was rejected');
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Not found.';
    exit;
}

$API_KEY = app_env('NGIRIMWA_API_KEY');
$GROUP_ID = app_env('NGIRIMWA_GROUP_ID');
$API_BASE_URL = trim(app_env('NGIRIMWA_BASE_URL'));
$API_BASE_URL_CONFIGURED = $API_BASE_URL !== '';
$API_BASE_URL = rtrim($API_BASE_URL, '/');
$DEVICE_STATUS_URL = $API_BASE_URL_CONFIGURED ? $API_BASE_URL . '/devices/status' : '';
$SEND_URL = $API_BASE_URL_CONFIGURED ? $API_BASE_URL . '/messages/send' : '';
$WEBHOOK_TOKEN = app_env('UPTIME_WEBHOOK_TOKEN');

$QUEUE_FILE = __DIR__ . '/queue.json';
$STATUS_FILE = __DIR__ . '/last-status.json';
$AUTH_BLOCK_FILE = __DIR__ . '/ngirimwa-auth.lock';

function log_send($channel, $message, $context = [])
{
    app_log($channel, $message, $context);
    if ($channel !== 'cron-run') {
        app_log('cron-run', $message, $context);
    }
}

function resolve_api_curl_mapping($url)
{
    $parts = parse_url($url);
    $host = is_array($parts) ? strtolower((string) ($parts['host'] ?? '')) : '';
    $scheme = is_array($parts) ? strtolower((string) ($parts['scheme'] ?? 'https')) : 'https';
    $port = is_array($parts) ? (int) ($parts['port'] ?? ($scheme === 'https' ? 443 : 80)) : 443;

    $resolution = [
        'host' => $host,
        'mapping' => [],
        'dns_lookup_ms' => 0.0,
        'error' => null
    ];

    if ($host === '') {
        $resolution['error'] = 'Invalid NgirimWA API base URL';
        return $resolution;
    }

    if (filter_var(trim($host, '[]'), FILTER_VALIDATE_IP)) {
        return $resolution;
    }

    $totalLookupMs = 0.0;
    for ($attempt = 1; $attempt <= 2; $attempt++) {
        $startedAt = microtime(true);
        $records = dns_get_record($host, DNS_A | DNS_AAAA);
        $totalLookupMs += (microtime(true) - $startedAt) * 1000;
        $resolution['dns_lookup_ms'] = round($totalLookupMs, 3);

        $addresses = [];
        if (is_array($records)) {
            foreach ($records as $record) {
                if (($record['type'] ?? '') === 'A' && filter_var($record['ip'] ?? '', FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                    $addresses[] = $record['ip'];
                } elseif (($record['type'] ?? '') === 'AAAA' && filter_var($record['ipv6'] ?? '', FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
                    $addresses[] = '[' . $record['ipv6'] . ']';
                }
            }
        }
        $addresses = array_values(array_unique($addresses));

        if ($addresses !== []) {
            $resolution['mapping'] = [$host . ':' . $port . ':' . implode(',', $addresses)];
            return $resolution;
        }

        $resolution['error'] = 'No A or AAAA records returned for the NgirimWA API host';
        if ($attempt < 2) {
            log_send('cron-run', 'NgirimWA DNS lookup returned no address; retrying once', [
                'api_host' => $host,
                'attempt' => $attempt,
                'dns_lookup_ms' => $resolution['dns_lookup_ms']
            ]);
            sleep(1);
        }
    }

    return $resolution;
}

function curl_connection_timing_context($handle, $dnsLookupMs)
{
    $curlNameLookupMs = (float) curl_getinfo($handle, CURLINFO_NAMELOOKUP_TIME) * 1000;
    $tcpConnectMs = (float) curl_getinfo($handle, CURLINFO_CONNECT_TIME) * 1000;
    $tlsConnectMs = (float) curl_getinfo($handle, CURLINFO_APPCONNECT_TIME) * 1000;
    $apiReadyMs = $tlsConnectMs > 0 ? $tlsConnectMs : $tcpConnectMs;

    return [
        'dns_lookup_ms' => round((float) $dnsLookupMs, 3),
        'curl_name_lookup_ms' => round($curlNameLookupMs, 3),
        'tcp_connect_ms' => round(max(0, $tcpConnectMs - $curlNameLookupMs), 3),
        'tls_handshake_ms' => round(max(0, $tlsConnectMs - $tcpConnectMs), 3),
        'api_connect_ms' => round(max(0, $apiReadyMs - $curlNameLookupMs), 3)
    ];
}

function defer_queue($reason, $context = [])
{
    global $QUEUE_FILE;
    $queueLock = app_queue_lock();
    if ($queueLock === false) {
        log_send('system', 'Could not lock queue while recording deferred delivery');
        return;
    }

    $queue = app_read_json_array($QUEUE_FILE);
    if (!is_array($queue)) {
        app_queue_unlock($queueLock);
        log_send('system', 'Could not read queue while recording deferred delivery');
        return;
    }

    $now = time();
    $changed = false;
    $expiredItems = [];
    foreach ($queue as $service => &$item) {
        if (!is_array($item)) {
            continue;
        }
        if (!is_numeric($item['deferred_since'] ?? null)) {
            $item['deferred_since'] = $now;
            $changed = true;
        }
        $item['deferred_reason'] = $reason;
        $changed = true;
        $deferredSeconds = max(0, $now - (int) $item['deferred_since']);
        if ($deferredSeconds > 600) {
            $expiredItems[] = [
                'service' => (string) $service,
                'status' => $item['status'] ?? null,
                'deferred_seconds' => $deferredSeconds,
                'reason' => $item['deferred_reason']
            ];
            unset($queue[$service]);
        }
    }
    unset($item);

    $saved = !$changed || app_write_json_array($QUEUE_FILE, $queue);
    app_queue_unlock($queueLock);
    if (!$saved) {
        log_send('system', 'Could not save queue after recording deferred delivery');
        return;
    }

    foreach ($expiredItems as $expiredItem) {
        log_send('send-failure', 'Queued message expired after more than 10 minutes without delivery', $expiredItem);
    }
    if ($queue !== []) {
        app_log_rate_limited('cron-run', 'Queue processing deferred; messages remain queued', 'queue-deferred-' . $reason, 60, [
            'reason' => $reason,
            'queued_count' => count($queue)
        ] + $context);
    }
}

function clear_queue_deferred($clearReasons)
{
    global $QUEUE_FILE;
    $queueLock = app_queue_lock();
    if ($queueLock === false) {
        return false;
    }

    $queue = app_read_json_array($QUEUE_FILE);
    if (!is_array($queue)) {
        app_queue_unlock($queueLock);
        return false;
    }

    $changed = false;
    foreach ($queue as &$item) {
        if (!is_array($item)) {
            continue;
        }
        if (isset($item['deferred_since']) && in_array($item['deferred_reason'] ?? '', $clearReasons, true)) {
            unset($item['deferred_since'], $item['deferred_reason']);
            $changed = true;
        }
    }
    unset($item);
    $saved = !$changed || app_write_json_array($QUEUE_FILE, $queue);
    app_queue_unlock($queueLock);
    return $saved;
}

function remove_queue_item($service)
{
    global $QUEUE_FILE;
    $queueLock = app_queue_lock();
    if ($queueLock === false) {
        log_send('system', 'Could not lock the queue while removing a failed item', ['service' => (string) $service]);
        return false;
    }

    $queue = app_read_json_array($QUEUE_FILE);
    if (!is_array($queue)) {
        $queue = [];
    }
    unset($queue[$service]);
    $removed = app_write_json_array($QUEUE_FILE, $queue);
    app_queue_unlock($queueLock);
    if (!$removed) {
        log_send('system', 'Could not remove a processed item from the send queue', ['service' => (string) $service]);
        return false;
    }
    return true;
}

function persist_queue_item($service, $message, $idempotencyKey)
{
    global $QUEUE_FILE;
    $queueLock = app_queue_lock();
    if ($queueLock === false) {
        return false;
    }

    $queue = app_read_json_array($QUEUE_FILE);
    if (!is_array($queue) || !isset($queue[$service])) {
        app_queue_unlock($queueLock);
        return false;
    }

    $queue[$service]['message'] = $message;
    $queue[$service]['idempotency_key'] = $idempotencyKey;
    unset($queue[$service]['payload']);
    $saved = app_write_json_array($QUEUE_FILE, $queue);
    app_queue_unlock($queueLock);
    return $saved;
}

function complete_queue_item($service, $status)
{
    global $QUEUE_FILE, $STATUS_FILE;
    $queueLock = app_queue_lock();
    if ($queueLock === false) {
        log_send('system', 'Could not lock queue after NgirimWA accepted a message', ['service' => (string) $service]);
        return false;
    }

    $queue = app_read_json_array($QUEUE_FILE);
    $lastStatus = app_read_json_array($STATUS_FILE);
    if (!is_array($queue)) {
        $queue = [];
    }
    if (!is_array($lastStatus)) {
        $lastStatus = [];
    }

    $lastStatus[$service] = [
        'status' => $status,
        'accepted_at' => time()
    ];
    $statusSaved = app_write_json_array($STATUS_FILE, $lastStatus);
    unset($queue[$service]);
    $queueSaved = app_write_json_array($QUEUE_FILE, $queue);
    app_queue_unlock($queueLock);

    if (!$statusSaved) {
        log_send('system', 'Could not update last-status.json after NgirimWA accepted a message', [
            'service' => (string) $service
        ]);
    }
    if (!$queueSaved) {
        log_send('system', 'Could not remove accepted item from the send queue', ['service' => (string) $service]);
    }
    return $statusSaved && $queueSaved;
}

// Avoid overlapping cron executions.
$lock = fopen(__DIR__ . '/cron.lock', 'c');
if ($lock === false) {
    log_send('system', 'Could not open the cron lock file');
    exit(1);
}
if (!flock($lock, LOCK_EX | LOCK_NB)) {
    fclose($lock);
    app_log_rate_limited('cron-run', 'Worker skipped: another execution is still active', 'send-queue-overlap', 60);
    exit;
}
app_log_rate_limited('cron-run', 'Worker started', 'send-queue-worker-started', 60);

$queueLock = app_queue_lock();
if ($queueLock === false) {
    log_send('system', 'Could not lock the queue before processing');
    exit(1);
}
$queue = app_read_json_array($QUEUE_FILE);

if (!is_array($queue)) {
    log_send('system', 'Send queue is invalid JSON; clearing it to prevent stale sends');
    app_write_json_array($QUEUE_FILE, []);
    app_queue_unlock($queueLock);
    exit(1);
}

$queueChanged = false;
$now = time();
foreach ($queue as $queuedService => &$queuedItem) {
    if (!is_array($queuedItem)) {
        log_send('send-failure', 'Invalid queued message removed', [
            'service' => (string) $queuedService,
            'reason' => 'invalid_queue_item'
        ]);
        unset($queue[$queuedService]);
        $queueChanged = true;
        continue;
    }

    if (!is_numeric($queuedItem['queued_at'] ?? null)) {
        // Give legacy queue entries a full ten-minute grace period from migration.
        $queuedItem['queued_at'] = $now;
        $queueChanged = true;
    }
}
unset($queuedItem);
if ($queueChanged && !app_write_json_array($QUEUE_FILE, $queue)) {
    app_queue_unlock($queueLock);
    log_send('system', 'Could not save send queue after applying the 10-minute expiry');
    exit(1);
}
app_queue_unlock($queueLock);
if (empty($queue)) {
    app_log_rate_limited('cron-run', 'Worker completed: no queued messages', 'send-queue-empty', 60);
    exit;
}

if (!$API_BASE_URL_CONFIGURED || $API_KEY === '' || $GROUP_ID === '' || $WEBHOOK_TOKEN === '') {
    $missing = [];
    if (!$API_BASE_URL_CONFIGURED) $missing[] = 'NGIRIMWA_BASE_URL';
    if ($API_KEY === '') $missing[] = 'NGIRIMWA_API_KEY';
    if ($GROUP_ID === '') $missing[] = 'NGIRIMWA_GROUP_ID';
    if ($WEBHOOK_TOKEN === '') $missing[] = 'UPTIME_WEBHOOK_TOKEN';
    defer_queue('configuration_incomplete', [
        'missing' => $missing,
        'queued_count' => count($queue)
    ]);
    exit(1);
}

$apiKeyFingerprint = hash('sha256', $API_KEY . "\0" . $API_BASE_URL);
$authBlock = file_exists($AUTH_BLOCK_FILE)
    ? json_decode(file_get_contents($AUTH_BLOCK_FILE), true)
    : null;
if (is_array($authBlock) && ($authBlock['api_key_hash'] ?? '') === $apiKeyFingerprint) {
    defer_queue('authentication_blocked', ['queued_count' => count($queue)]);
    exit(1);
}
if (file_exists($AUTH_BLOCK_FILE)) {
    unlink($AUTH_BLOCK_FILE);
}

$apiResolution = resolve_api_curl_mapping($DEVICE_STATUS_URL);
if ($apiResolution['error'] !== null) {
    defer_queue('api_dns_resolution_failed', [
        'queued_count' => count($queue),
        'api_host' => $apiResolution['host'],
        'dns_lookup_ms' => $apiResolution['dns_lookup_ms'],
        'dns_error' => $apiResolution['error']
    ]);
    exit(1);
}

// Resolve the API host separately so the connection timeout only covers the
// TCP/TLS connection. The original hostname remains in the URL for HTTPS/SNI.
$checkResponse = false;
$checkHttpCode = 0;
$checkError = '';
$checkResult = null;
$deviceStatus = null;
$checkTimingContext = ['dns_lookup_ms' => $apiResolution['dns_lookup_ms']];

// Retry a transient transport failure once before deferring to the next cron run.
for ($attempt = 1; $attempt <= 2; $attempt++) {
    $checkResponse = false;
    $checkHttpCode = 0;
    $checkError = '';
    $checkResult = null;

    $check = curl_init($DEVICE_STATUS_URL);
    if ($check === false) {
        $checkError = 'Could not initialize the API/device check request';
    } else {
        $checkOptions = [
            CURLOPT_HTTPGET => true,
            CURLOPT_HTTPHEADER => [
                'x-api-key: ' . $API_KEY,
                'Accept: application/json'
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 20
        ];
        if ($apiResolution['mapping'] !== []) {
            $checkOptions[CURLOPT_RESOLVE] = $apiResolution['mapping'];
        }
        curl_setopt_array($check, $checkOptions);

        $checkResponse = curl_exec($check);
        $checkHttpCode = curl_getinfo($check, CURLINFO_HTTP_CODE);
        $checkError = curl_error($check);
        $checkTimingContext = curl_connection_timing_context($check, $apiResolution['dns_lookup_ms']);
        curl_close($check);
    }

    $checkResult = is_string($checkResponse) ? json_decode($checkResponse, true) : null;
    $deviceStatus = is_array($checkResult) ? ($checkResult['data']['status'] ?? null) : null;
    $apiAvailable = $checkError === ''
        && $checkHttpCode >= 200
        && $checkHttpCode < 300
        && is_array($checkResult)
        && ($checkResult['success'] ?? false) === true;

    if ($apiAvailable || $checkError === '' || $attempt === 2) {
        break;
    }

    log_send('cron-run', 'API/device check transport failed; retrying once', [
        'attempt' => $attempt,
        'curl_error' => $checkError
    ] + $checkTimingContext);
    sleep(1);
}

$deviceStatus = is_array($checkResult) ? ($checkResult['data']['status'] ?? null) : null;
$deviceReady = $apiAvailable && $deviceStatus === 'connected';

if (!$deviceReady) {
    $errorCode = is_array($checkResult) ? ($checkResult['details']['code'] ?? null) : null;
    if ($checkHttpCode === 401 || $checkHttpCode === 403) {
        $authBlockJson = json_encode([
            'api_key_hash' => $apiKeyFingerprint,
            'blocked_at' => date('c')
        ], JSON_PRETTY_PRINT);
        if ($authBlockJson !== false) {
            file_put_contents($AUTH_BLOCK_FILE, $authBlockJson, LOCK_EX);
        }
    }

    defer_queue('api_or_device_unavailable', [
        'queued_count' => count($queue),
        'http_code' => $checkHttpCode,
        'device_status' => $deviceStatus,
        'error_code' => $errorCode,
        'curl_error' => $checkError
    ] + $checkTimingContext);
    exit(1);
}

if (!clear_queue_deferred([
    'configuration_incomplete',
    'authentication_blocked',
    'api_dns_resolution_failed',
    'api_or_device_unavailable'
])) {
    log_send('system', 'Could not clear deferred state after API/device became ready');
    exit(1);
}

log_send('cron-run', 'NgirimWA API/device check passed', [
    'http_code' => $checkHttpCode,
    'device_status' => $deviceStatus
] + $checkTimingContext);

// FIFO: at most one message is sent per cron execution.
uasort($queue, function ($a, $b) {
    return ($a['queued_at'] ?? 0) <=> ($b['queued_at'] ?? 0);
});
$service = array_key_first($queue);
$item = $queue[$service];

// Accept queued messages from the previous gateway format during migration.
$message = is_array($item)
    ? ($item['message'] ?? ($item['payload']['message'] ?? null))
    : null;
if (!is_string($message) || $message === '') {
    log_send('send-failure', 'Queue item does not contain a valid message', ['service' => (string) $service]);
    remove_queue_item($service);
    exit(1);
}

$idempotencyKey = is_array($item) ? ($item['idempotency_key'] ?? null) : null;
if (!is_string($idempotencyKey) || $idempotencyKey === '') {
    $idempotencyKey = bin2hex(random_bytes(16));
}

// Remove legacy gateway credentials/destination from this queue item before any send.
if (!persist_queue_item($service, $message, $idempotencyKey)) {
    log_send('send-failure', 'Could not persist queue item before sending', ['service' => (string) $service]);
    remove_queue_item($service);
    exit(1);
}

$payload = [
    'to' => $GROUP_ID,
    'message' => $message,
    'ref_id' => 'uptime-' . $idempotencyKey,
    'expires_in' => 30
];
$requestBody = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
if ($requestBody === false) {
    log_send('send-failure', 'Could not encode queued message', ['service' => (string) $service]);
    remove_queue_item($service);
    exit(1);
}

$send = curl_init($SEND_URL);
if ($send === false) {
    defer_queue('send_request_unavailable', ['service' => (string) $service]);
    exit(1);
}
$sendOptions = [
    CURLOPT_POST => true,
    CURLOPT_HTTPHEADER => [
        'x-api-key: ' . $API_KEY,
        'Content-Type: application/json',
        'Accept: application/json',
        'Idempotency-Key: ' . $idempotencyKey
    ],
    CURLOPT_POSTFIELDS => $requestBody,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_CONNECTTIMEOUT => 10,
    CURLOPT_TIMEOUT => 20
];
if ($apiResolution['mapping'] !== []) {
    $sendOptions[CURLOPT_RESOLVE] = $apiResolution['mapping'];
}
curl_setopt_array($send, $sendOptions);

$response = curl_exec($send);
$httpCode = curl_getinfo($send, CURLINFO_HTTP_CODE);
$error = curl_error($send);
$sendTimingContext = curl_connection_timing_context($send, $apiResolution['dns_lookup_ms']);
curl_close($send);

$result = is_string($response) ? json_decode($response, true) : null;
$accepted = $error === ''
    && $httpCode >= 200
    && $httpCode < 300
    && is_array($result)
    && ($result['success'] ?? false) === true;

if (!$accepted) {
    $errorCode = is_array($result) ? ($result['details']['code'] ?? null) : null;
    if ($httpCode === 401 || $httpCode === 403) {
        $authBlockJson = json_encode([
            'api_key_hash' => $apiKeyFingerprint,
            'blocked_at' => date('c')
        ], JSON_PRETTY_PRINT);
        if ($authBlockJson !== false) {
            file_put_contents($AUTH_BLOCK_FILE, $authBlockJson, LOCK_EX);
        }
        defer_queue('authentication_failed', [
            'queued_count' => count($queue),
            'http_code' => $httpCode,
            'error_code' => $errorCode
        ]);
    } else {
        defer_queue('send_not_accepted', [
            'service' => (string) $service,
            'http_code' => $httpCode,
            'error_code' => $errorCode,
            'curl_error' => $error
        ] + $sendTimingContext);
    }
    exit(1);
}

$trackingId = $result['data']['tracking_id'] ?? 'unknown';
log_send('send-success', 'NgirimWA accepted the message', [
    'service' => (string) $service,
    'tracking_id' => $trackingId
] + $sendTimingContext);

complete_queue_item($service, $item['status'] ?? null);
log_send('cron-run', 'Worker completed: queued message processed', [
    'service' => (string) $service,
    'status' => $item['status'] ?? null
]);
