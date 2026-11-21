<?php

$DEVICE_TOKEN = trim((string) getenv('WEAGATE_DEVICE_TOKEN'));
$CHAT_ID      = trim((string) getenv('WEAGATE_CHAT_ID'));

$QUEUE_FILE  = __DIR__ . '/queue.json';
$STATUS_FILE = __DIR__ . '/last-status.json';
$LOG_FILE    = __DIR__ . '/app.log';
$DEBUG_FILE  = __DIR__ . '/debug.log';

// raw
$raw = file_get_contents("php://input");
file_put_contents($DEBUG_FILE, date('c')." | ".$raw.PHP_EOL, FILE_APPEND);

$data = json_decode($raw, true);

// log
function log_msg($msg) {
    global $LOG_FILE;
    file_put_contents($LOG_FILE, date('c')." | ".$msg.PHP_EOL, FILE_APPEND);
}

if ($DEVICE_TOKEN === '' || $CHAT_ID === '') {
    log_msg("CONFIG ERROR: missing WEAGATE_DEVICE_TOKEN or WEAGATE_CHAT_ID");
    http_response_code(500);
    echo json_encode(["ok"=>false, "error"=>"Configuration missing"]);
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
$text .= "— Nexan Network Monitoring\n";
$text .= "Website : https://status-server.nexan.id";

// queue
$queue[$monitorName] = [
    "status"    => $status,
    "queued_at" => time(),
    "payload"   => [
        "token"   => $DEVICE_TOKEN,
        "to"      => $CHAT_ID,
        "type"    => "text",
        "message" => substr($text, 0, 3500),
        "instan"  => 1
    ]
];

file_put_contents($QUEUE_FILE, json_encode($queue, JSON_PRETTY_PRINT));

log_msg("QUEUED: $monitorName ($status)");

echo json_encode([
    "ok" => true,
    "queued" => true
]);
