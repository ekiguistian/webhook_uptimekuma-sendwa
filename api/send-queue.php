<?php

require_once __DIR__ . '/config.php';

$API_KEY = app_env('NGIRIMWA_API_KEY');
$GROUP_ID = app_env('NGIRIMWA_GROUP_ID');
$API_BASE_URL = rtrim(app_env('NGIRIMWA_BASE_URL', 'https://dash.ngirimwa.com/api/v1'), '/');
$SEND_URL = $API_BASE_URL . '/messages/send';

$QUEUE_FILE  = __DIR__ . '/queue.json';
$STATUS_FILE = __DIR__ . '/last-status.json';
$LOG_FILE    = __DIR__ . '/send.log';
$AUTH_BLOCK_FILE = __DIR__ . '/ngirimwa-auth.lock';

function log_send($message) {
    global $LOG_FILE;
    file_put_contents($LOG_FILE, date('c') . ' | ' . $message . PHP_EOL, FILE_APPEND | LOCK_EX);
}

if ($API_KEY === '' || $GROUP_ID === '') {
    log_send('CONFIG ERROR: missing NGIRIMWA_API_KEY or NGIRIMWA_GROUP_ID');
    exit(1);
}

// Avoid overlapping cron executions.
$lock = fopen(__DIR__ . '/cron.lock', 'c');
if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
    if (is_resource($lock)) {
        fclose($lock);
    }
    exit;
}

$apiKeyFingerprint = hash('sha256', $API_KEY);
$authBlock = file_exists($AUTH_BLOCK_FILE)
    ? json_decode(file_get_contents($AUTH_BLOCK_FILE), true)
    : null;
if (is_array($authBlock) && ($authBlock['api_key_hash'] ?? '') === $apiKeyFingerprint) {
    log_send('AUTH BLOCKED: NgirimWA returned 401/403 for this API key; update the key before retrying');
    exit(1);
}
if (file_exists($AUTH_BLOCK_FILE)) {
    unlink($AUTH_BLOCK_FILE);
}

$queue = file_exists($QUEUE_FILE)
    ? json_decode(file_get_contents($QUEUE_FILE), true)
    : [];

if (!is_array($queue) || empty($queue)) {
    exit;
}

// FIFO, skipping items that are waiting for a retry or need manual correction.
uasort($queue, function ($a, $b) {
    return ($a['queued_at'] ?? 0) <=> ($b['queued_at'] ?? 0);
});

$service = null;
$item = null;
foreach ($queue as $candidateService => $candidateItem) {
    if (!empty($candidateItem['blocked_error'])) {
        continue;
    }
    if (($candidateItem['next_attempt_at'] ?? 0) > time()) {
        continue;
    }
    $service = $candidateService;
    $item = $candidateItem;
    break;
}
if ($service === null || !is_array($item)) {
    exit;
}

// Accept queued messages from the previous gateway format during migration.
$message = $item['message'] ?? ($item['payload']['message'] ?? null);
if (!is_string($message) || $message === '') {
    log_send('QUEUE ERROR: missing message for ' . $service);
    exit(1);
}

$idempotencyKey = $item['idempotency_key'] ?? null;
if (!is_string($idempotencyKey) || $idempotencyKey === '') {
    $idempotencyKey = bin2hex(random_bytes(16));
    $queue[$service]['idempotency_key'] = $idempotencyKey;
}

// Do not keep old gateway credentials or destination data in the queue.
$queue[$service]['message'] = $message;
unset($queue[$service]['payload']);

$queueJson = json_encode($queue, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
if ($queueJson === false || file_put_contents($QUEUE_FILE, $queueJson, LOCK_EX) === false) {
    log_send('QUEUE ERROR: could not persist queue item for ' . $service);
    exit(1);
}

$payload = [
    'to' => $GROUP_ID,
    'message' => $message,
    'ref_id' => 'uptime-' . $idempotencyKey,
    'expires_in' => 3600
];

$requestBody = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
if ($requestBody === false) {
    log_send('REQUEST ERROR: could not encode message for ' . $service);
    exit(1);
}

$responseHeaders = [];
$ch = curl_init($SEND_URL);
if ($ch === false) {
    log_send('REQUEST ERROR: could not initialize cURL for ' . $service);
    exit(1);
}
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_HTTPHEADER => [
        'x-api-key: ' . $API_KEY,
        'Content-Type: application/json',
        'Accept: application/json',
        'Idempotency-Key: ' . $idempotencyKey
    ],
    CURLOPT_POSTFIELDS => $requestBody,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_CONNECTTIMEOUT => 5,
    CURLOPT_TIMEOUT => 20,
    CURLOPT_HEADERFUNCTION => function ($curl, $headerLine) use (&$responseHeaders) {
        $length = strlen($headerLine);
        $parts = explode(':', $headerLine, 2);
        if (count($parts) === 2) {
            $responseHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
        }
        return $length;
    }
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$error = curl_error($ch);
curl_close($ch);

$result = is_string($response) ? json_decode($response, true) : null;
$accepted = $error === ''
    && $httpCode >= 200
    && $httpCode < 300
    && is_array($result)
    && ($result['success'] ?? false) === true;

if (!$accepted) {
    $errorCode = is_array($result) ? ($result['details']['code'] ?? 'api_error') : 'invalid_response';
    if ($httpCode === 401 || $httpCode === 403) {
        $authBlockJson = json_encode([
            'api_key_hash' => $apiKeyFingerprint,
            'blocked_at' => date('c')
        ], JSON_PRETTY_PRINT);
        if ($authBlockJson !== false) {
            file_put_contents($AUTH_BLOCK_FILE, $authBlockJson, LOCK_EX);
        }
        log_send('AUTH BLOCKED: NgirimWA returned HTTP ' . $httpCode . '; update the API key or subscription before retrying');
        exit(1);
    }

    $retryable = $error !== ''
        || in_array($httpCode, [408, 409, 429], true)
        || $httpCode >= 500;
    if ($retryable) {
        $attempts = (int) ($item['attempts'] ?? 0) + 1;
        $retryAfter = $responseHeaders['retry-after'] ?? '';
        $delay = preg_match('/^\d+$/', $retryAfter) === 1
            ? min(3600, max(1, (int) $retryAfter))
            : min(3600, 30 * (2 ** min(7, $attempts - 1)));
        $queue[$service]['attempts'] = $attempts;
        $queue[$service]['next_attempt_at'] = time() + $delay;
        $queueJson = json_encode($queue, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        if ($queueJson !== false) {
            file_put_contents($QUEUE_FILE, $queueJson, LOCK_EX);
        }
        log_send('NGIRIMWA RETRY: service=' . $service . ' http=' . $httpCode . ' in=' . $delay . 's');
        exit(1);
    }

    $queue[$service]['blocked_error'] = $errorCode;
    $queueJson = json_encode($queue, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    if ($queueJson !== false) {
        file_put_contents($QUEUE_FILE, $queueJson, LOCK_EX);
    }
    log_send('NGIRIMWA ERROR: service=' . $service . ' http=' . $httpCode . ' code=' . $errorCode . ' curl=' . $error);
    exit(1);
}

$lastStatus = file_exists($STATUS_FILE)
    ? json_decode(file_get_contents($STATUS_FILE), true)
    : [];
if (!is_array($lastStatus)) {
    $lastStatus = [];
}
$lastStatus[$service] = $item['status'] ?? null;

$statusJson = json_encode($lastStatus, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
if ($statusJson === false || file_put_contents($STATUS_FILE, $statusJson, LOCK_EX) === false) {
    log_send('STATE ERROR: could not update last status for ' . $service);
    exit(1);
}

unset($queue[$service]);
$queueJson = json_encode($queue, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
if ($queueJson === false || file_put_contents($QUEUE_FILE, $queueJson, LOCK_EX) === false) {
    log_send('QUEUE ERROR: could not remove sent item for ' . $service);
    exit(1);
}

$trackingId = $result['data']['tracking_id'] ?? 'unknown';
log_send('NGIRIMWA ACCEPTED: service=' . $service . ' tracking_id=' . $trackingId);
