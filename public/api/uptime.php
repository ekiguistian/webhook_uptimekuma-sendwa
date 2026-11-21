<?php

$endpoint = dirname(__DIR__, 2) . '/api/uptime.php';
if (!is_file($endpoint)) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(["ok"=>false, "error"=>"Webhook handler is unavailable"]);
    exit;
}

require $endpoint;
