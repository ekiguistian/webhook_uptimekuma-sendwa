<?php

require_once __DIR__ . '/config.php';

$MESSAGE_SIGNATURE = app_env('UPTIME_MESSAGE_SIGNATURE', 'Server Monitoring');
$STATUS_WEBSITE    = app_env('UPTIME_STATUS_WEBSITE', 'https://status.example.com');

header('Content-Type: application/json; charset=utf-8');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    http_response_code(405);
    echo json_encode(["ok"=>false, "message"=>"Request could not be processed"]);
    exit;
}

$webhookToken = app_env('UPTIME_WEBHOOK_TOKEN');
if ($webhookToken === '') {
    app_log('system', 'Webhook unavailable because authentication token is not configured');
    http_response_code(503);
    echo json_encode(["ok"=>false, "message"=>"Service temporarily unavailable"]);
    exit;
}

$providedToken = $_SERVER['HTTP_X_WEBHOOK_TOKEN'] ?? '';
if (!is_string($providedToken) || !hash_equals($webhookToken, $providedToken)) {
    app_log_rate_limited(
        'system',
        'Webhook rejected: authentication token is missing or invalid',
        $_SERVER['REMOTE_ADDR'] ?? 'unknown'
    );
    http_response_code(401);
    echo json_encode(["ok"=>false, "message"=>"Request could not be processed"]);
    exit;
}

$contentType = strtolower(trim(explode(';', $_SERVER['CONTENT_TYPE'] ?? '')[0]));
if ($contentType !== 'application/json') {
    app_log('system', 'Webhook rejected: unsupported content type');
    http_response_code(415);
    echo json_encode(["ok"=>false, "message"=>"Request could not be processed"]);
    exit;
}

$maxPayloadBytes = 32768;
$contentLength = $_SERVER['CONTENT_LENGTH'] ?? '';
if ($contentLength !== '' && (!ctype_digit((string) $contentLength) || (int) $contentLength > $maxPayloadBytes)) {
    app_log('system', 'Webhook rejected: request body exceeds size limit');
    http_response_code(413);
    echo json_encode(["ok"=>false, "message"=>"Request could not be processed"]);
    exit;
}

$QUEUE_FILE  = __DIR__ . '/queue.json';
$STATUS_FILE = __DIR__ . '/last-status.json';

$raw = file_get_contents('php://input', false, null, 0, $maxPayloadBytes + 1);
if ($raw === false || strlen($raw) > $maxPayloadBytes) {
    app_log('system', 'Webhook rejected: request body could not be read or exceeds size limit');
    http_response_code(413);
    echo json_encode(["ok"=>false, "message"=>"Request could not be processed"]);
    exit;
}

$data = json_decode($raw, true);

function log_msg($msg) {
    app_log('system', $msg);
}

if (!is_array($data) || json_last_error() !== JSON_ERROR_NONE) {
    app_log('system', 'Webhook rejected: invalid JSON payload');
    http_response_code(400);
    echo json_encode(["ok"=>false, "message"=>"Request could not be processed"]);
    exit;
}

// Uptime Kuma test notifications can use a top-level message.
$testMessage = $data['msg'] ?? null;
$isTestNotification = false;
if (is_string($testMessage) && stripos($testMessage, 'test') !== false) {
    try {
        $testNumber = (string) random_int(1, 9);
        for ($digitIndex = 1; $digitIndex < 10; $digitIndex++) {
            $testNumber .= (string) random_int(0, 9);
        }
    } catch (Throwable $exception) {
        app_log('system', 'Uptime Kuma test ID generation failed');
        http_response_code(500);
        echo json_encode(["ok"=>false, "message"=>"Request could not be processed"]);
        exit;
    }
    $monitorName = 'TEST-' . $testNumber;
    $status      = $testNumber;
    $target      = '-';
    $time        = date("Y-m-d H:i:s");
    $isTestNotification = true;

    log_msg("TEST MODE: $monitorName ($status)");

} else {

    $monitorData = isset($data['monitor']) && is_array($data['monitor']) ? $data['monitor'] : [];
    $heartbeatData = isset($data['heartbeat']) && is_array($data['heartbeat']) ? $data['heartbeat'] : [];
    $monitorName = $monitorData['name'] ?? null;
    $hbStatus    = $heartbeatData['status'] ?? null;

    if (!is_string($monitorName) || !in_array($hbStatus, [0, 1], true)) {
        app_log('system', 'Webhook rejected: monitor name or heartbeat status is invalid');
        http_response_code(400);
        echo json_encode(["ok"=>false, "message"=>"Request could not be processed"]);
        exit;
    }

    $monitorName = trim(preg_replace('/[\x00-\x1F\x7F]+/', ' ', $monitorName));
    $monitorName = substr($monitorName, 0, 120);
    if ($monitorName === '') {
        app_log('system', 'Webhook rejected: monitor name is empty');
        http_response_code(400);
        echo json_encode(["ok"=>false, "message"=>"Request could not be processed"]);
        exit;
    }

    $status = $hbStatus === 1 ? 'UP' : 'DOWN';

    $rawMsg = $heartbeatData['msg'] ?? '-';
    if (!is_string($rawMsg)) {
        $rawMsg = '-';
    }
    $target = preg_replace('/\s+/', ' ', preg_replace('/[\x00-\x1F\x7F]+/', ' ', $rawMsg));
    $target = substr($target, 0, 200);

    $rawTime = $heartbeatData['localDateTime'] ?? null;
    $time = is_string($rawTime) && trim($rawTime) !== '' ? $rawTime : date('Y-m-d H:i:s');
    $time = trim(preg_replace('/[\x00-\x1F\x7F]+/', ' ', $time));
    $time = substr($time, 0, 64);
}

// load
$queueLock = app_queue_lock();
if ($queueLock === false) {
    app_log('system', 'Webhook could not acquire the queue lock');
    http_response_code(503);
    echo json_encode(["ok"=>false, "message"=>"Service temporarily unavailable"]);
    exit;
}

$queue = app_read_json_array($QUEUE_FILE);
$lastStatus = app_read_json_array($STATUS_FILE);
if (!is_array($queue)) {
    app_log('system', 'Queue file is invalid; treating it as empty');
    $queue = [];
}
if (!is_array($lastStatus)) {
    app_log('system', 'Last status file is invalid; treating it as empty');
    $lastStatus = [];
}

// Suppress repeated states for ten minutes after the last accepted delivery.
$lastEntry = $lastStatus[$monitorName] ?? null;
$lastStatusValue = is_array($lastEntry) ? ($lastEntry['status'] ?? null) : $lastEntry;
$lastAcceptedAt = is_array($lastEntry) && is_numeric($lastEntry['accepted_at'] ?? null)
    ? (int) $lastEntry['accepted_at']
    : 0;
if ($lastStatusValue === $status) {
    if ($lastAcceptedAt <= 0) {
        // Migrate older status-only records and start their cooldown at deployment.
        $lastAcceptedAt = time();
        $lastStatus[$monitorName] = ['status' => $status, 'accepted_at' => $lastAcceptedAt];
        if (!app_write_json_array($STATUS_FILE, $lastStatus)) {
            app_log('system', 'Could not migrate legacy last-status entry', ['monitor' => $monitorName]);
        }
    }

    $elapsed = max(0, time() - $lastAcceptedAt);
    if (!$isTestNotification && $elapsed < 600) {
        app_log_rate_limited('system', 'Duplicate monitor status suppressed during the 10-minute cooldown', 'same-status-' . $monitorName, 60, [
            'monitor' => $monitorName,
            'status' => $status,
            'cooldown_seconds' => 600
        ]);
        app_queue_unlock($queueLock);
        exit;
    }
}

// skip queued
if (isset($queue[$monitorName])) {
    app_log_rate_limited('system', 'Monitor message skipped because an item is already queued', 'queued-status-' . $monitorName, 60, [
        'monitor' => $monitorName,
        'status' => $status
    ]);
    app_queue_unlock($queueLock);
    exit;
}

// message
$title = $isTestNotification
    ? "🧪 *UPTIME KUMA TEST*"
    : (($status === 'DOWN') ? "🚨 *NOC ALERT - DOWN*" : "✅ *NOC RECOVERY - UP*");

$text  = "{$title}\n\n";
$text .= "Service : {$monitorName}\n";
$text .= "Output  : {$target}\n";
$text .= "Status  : {$status}\n";
$text .= "Waktu   : {$time} WIB\n\n";
$text .= "— {$MESSAGE_SIGNATURE}\n";
$text .= "Website : {$STATUS_WEBSITE}";

// queue
$queue[$monitorName] = [
    "status"    => $status,
    "queued_at" => time(),
    "idempotency_key" => bin2hex(random_bytes(16)),
    "message"   => substr($text, 0, 3500)
];

$queueSaved = app_write_json_array($QUEUE_FILE, $queue);
if (!$queueSaved) {
    log_msg("QUEUE ERROR: could not save message for $monitorName");
    app_queue_unlock($queueLock);
    http_response_code(500);
    echo json_encode(["ok"=>false, "message"=>"Request could not be processed"]);
    exit;
}

log_msg("QUEUED: $monitorName ($status)");
app_queue_unlock($queueLock);

echo json_encode([
    "ok" => true,
    "queued" => true
]);
