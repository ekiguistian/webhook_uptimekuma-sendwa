<?php

require_once __DIR__ . '/config.php';

$NGIRIMWA_API_KEY  = app_env('NGIRIMWA_API_KEY');
$NGIRIMWA_GROUP_ID = app_env('NGIRIMWA_GROUP_ID');
$MESSAGE_SIGNATURE = app_env('UPTIME_MESSAGE_SIGNATURE', 'Nexan Network Monitoring');
$STATUS_WEBSITE    = app_env('UPTIME_STATUS_WEBSITE', 'https://status-server.nexan.id');

header('Content-Type: application/json; charset=utf-8');

$QUEUE_FILE  = __DIR__ . '/queue.json';
$STATUS_FILE = __DIR__ . '/last-status.json';
$LOG_FILE    = __DIR__ . '/app.log';

$raw = file_get_contents("php://input");
$data = json_decode($raw, true);

function log_msg($msg) {
    global $LOG_FILE;
    file_put_contents($LOG_FILE, date('c')." | ".$msg.PHP_EOL, FILE_APPEND | LOCK_EX);
}

if ($NGIRIMWA_API_KEY === '' || $NGIRIMWA_GROUP_ID === '') {
    log_msg("CONFIG ERROR: missing NGIRIMWA_API_KEY or NGIRIMWA_GROUP_ID");
    http_response_code(500);
    echo json_encode(["ok"=>false, "error"=>"Configuration missing"]);
    exit;
}

if (!is_array($data)) {
    http_response_code(400);
    echo json_encode(["ok"=>false, "error"=>"Invalid JSON payload"]);
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
        log_msg("INVALID");
        echo json_encode(["ok"=>true]);
        exit;
    }

    $status = ($hbStatus == 1) ? 'UP' : 'DOWN';

    $rawMsg = $data['heartbeat']['msg'] ?? '-';
    $target = substr(preg_replace('/\s+/', ' ', $rawMsg), 0, 200);

    $time = $data['heartbeat']['localDateTime'] ?? date("Y-m-d H:i:s");
}

// load
$queue = file_exists($QUEUE_FILE)
    ? json_decode(file_get_contents($QUEUE_FILE), true)
    : [];

$lastStatus = file_exists($STATUS_FILE)
    ? json_decode(file_get_contents($STATUS_FILE), true)
    : [];

if (!is_array($queue)) $queue = [];
if (!is_array($lastStatus)) $lastStatus = [];

// skip same
if (($lastStatus[$monitorName] ?? null) === $status) {
    log_msg("SKIP SAME: $monitorName");
    exit;
}

// skip queued
if (isset($queue[$monitorName])) {
    log_msg("SKIP QUEUED: $monitorName");
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

$queueJson = json_encode($queue, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
if ($queueJson === false || file_put_contents($QUEUE_FILE, $queueJson, LOCK_EX) === false) {
    log_msg("QUEUE ERROR: could not save message for $monitorName");
    http_response_code(500);
    echo json_encode(["ok"=>false, "error"=>"Could not queue message"]);
    exit;
}

log_msg("QUEUED: $monitorName ($status)");

echo json_encode([
    "ok" => true,
    "queued" => true
]);
