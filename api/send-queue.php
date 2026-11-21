<?php

$WEAGATE_API_URL   = "https://mywifi.weagate.com/api/send-message";
$DEVICE_STATUS_URL = "https://mywifi.weagate.com/api/device-status";

$QUEUE_FILE  = __DIR__ . '/queue.json';
$STATUS_FILE = __DIR__ . '/last-status.json';
$LOG_FILE    = __DIR__ . '/send.log';

// lock
$lock = fopen(__DIR__ . '/cron.lock', 'c');
if (!flock($lock, LOCK_EX | LOCK_NB)) exit;

// load queue
$queue = file_exists($QUEUE_FILE)
    ? json_decode(file_get_contents($QUEUE_FILE), true)
    : [];

if (!is_array($queue) || empty($queue)) exit;

// fifo
uasort($queue, fn($a,$b) => $a['queued_at'] <=> $b['queued_at']);

$service = array_key_first($queue);
$item    = $queue[$service];

$token = $item['payload']['token'] ?? null;
if (!$token) exit;

// =====================
// cek device status
// =====================
$ch = curl_init($DEVICE_STATUS_URL);
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_HTTPHEADER => [
        "Content-Type: application/json",
        "Accept: application/json"
    ],
    CURLOPT_POSTFIELDS => json_encode(["token"=>$token]),
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 10
]);

$resStatus = curl_exec($ch);
$httpStatus = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$errStatus  = curl_error($ch);
curl_close($ch);

file_put_contents($LOG_FILE,
    date('c')." | $service | STATUS HTTP:$httpStatus | ERR:$errStatus | RESP:$resStatus\n",
    FILE_APPEND
);

if ($httpStatus != 200 || $errStatus) exit;

$jsonStatus = json_decode($resStatus, true);
$deviceStatus = $jsonStatus['data']['device_status'] ?? null;

if ($deviceStatus !== 'connected') {
    file_put_contents($LOG_FILE,
        date('c')." | $service | DEVICE OFFLINE ($deviceStatus)\n",
        FILE_APPEND
    );
    exit;
}

// =====================
// kirim pesan
// =====================
$ch = curl_init($WEAGATE_API_URL);
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_HTTPHEADER => [
        "Content-Type: application/json",
        "Accept: application/json"
    ],
    CURLOPT_POSTFIELDS => json_encode($item['payload']),
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 15
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$error    = curl_error($ch);

curl_close($ch);

file_put_contents($LOG_FILE,
    date('c')." | $service | SEND HTTP:$httpCode | ERR:$error | RESP:$response\n",
    FILE_APPEND
);

// gagal → retry
if ($httpCode != 200 || $error) exit;

// update status
$lastStatus = file_exists($STATUS_FILE)
    ? json_decode(file_get_contents($STATUS_FILE), true)
    : [];

if (!is_array($lastStatus)) $lastStatus = [];

$lastStatus[$service] = $item['status'];

file_put_contents($STATUS_FILE, json_encode($lastStatus, JSON_PRETTY_PRINT));

// hapus queue
unset($queue[$service]);
file_put_contents($QUEUE_FILE, json_encode($queue, JSON_PRETTY_PRINT));