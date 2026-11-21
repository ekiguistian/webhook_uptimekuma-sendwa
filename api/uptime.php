<?php

require_once __DIR__ . '/config.php';

$NGIRIMWA_API_KEY  = app_env('NGIRIMWA_API_KEY');
$NGIRIMWA_GROUP_ID = app_env('NGIRIMWA_GROUP_ID');
$MESSAGE_SIGNATURE = app_env('UPTIME_MESSAGE_SIGNATURE', 'Nexan Network Monitoring');
$STATUS_WEBSITE    = app_env('UPTIME_STATUS_WEBSITE', 'https://status-server.nexan.id');

header('Content-Type: application/json; charset=utf-8');

$QUEUE_FILE  = __DIR__ . '/queue.json';
$STATUS_FILE = __DIR__ . '/last-status.json';

$raw = file_get_contents("php://input");
$data = json_decode($raw, true);

function log_msg($msg) {
    app_log('system', $msg);
}

if ($NGIRIMWA_API_KEY === '' || $NGIRIMWA_GROUP_ID === '') {
    app_log('system', 'Webhook unavailable because required configuration is missing');
    http_response_code(503);
    echo json_encode(["ok"=>false, "message"=>"Service temporarily unavailable"]);
    exit;
}

if (!is_array($data)) {
    app_log('system', 'Webhook rejected: invalid JSON payload');
    http_response_code(400);
    echo json_encode(["ok"=>false, "message"=>"Request could not be processed"]);
    exit;
}

// test mode
if (isset($data['msg']) && stripos($data['msg'], 'test') !== false) {

    $monitorName = 'TEST-' . time();
    $status      = 'UP';
    $target      = '-';
    $time        = date("Y-m-d H:i:s");

    log_msg("TEST MODE: $monitorName");

} else {

    $monitorName = $data['monitor']['name'] ?? null;
    $hbStatus    = $data['heartbeat']['status'] ?? null;

    if (!$monitorName || $hbStatus === null) {
        app_log('system', 'Webhook rejected: monitor name or heartbeat status is missing');
        http_response_code(400);
        echo json_encode(["ok"=>false, "message"=>"Request could not be processed"]);
        exit;
    }

    $status = ($hbStatus == 1) ? 'UP' : 'DOWN';

    $rawMsg = $data['heartbeat']['msg'] ?? '-';
    $target = substr(preg_replace('/\s+/', ' ', $rawMsg), 0, 200);

    $time = $data['heartbeat']['localDateTime'] ?? date("Y-m-d H:i:s");
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

// skip same
if (($lastStatus[$monitorName] ?? null) === $status) {
    log_msg("SKIP SAME: $monitorName");
    app_queue_unlock($queueLock);
    exit;
}

// skip queued
if (isset($queue[$monitorName])) {
    log_msg("SKIP QUEUED: $monitorName");
    app_queue_unlock($queueLock);
    exit;
}

// message
$title = ($status === 'DOWN')
    ? "🚨 *NOC ALERT - DOWN*"
    : "✅ *NOC RECOVERY - UP*";

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
