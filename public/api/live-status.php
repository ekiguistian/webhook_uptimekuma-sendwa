<?php #ide cemerlang eki guistian

$projectRoot = dirname(__DIR__, 2);
require_once $projectRoot . '/api/config.php';

$endpoint = $projectRoot . '/api/live-status.php';
if (!is_file($endpoint)) {
    app_log('system', 'Public live status wrapper cannot find its internal handler');
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'message' => 'Service temporarily unavailable']);
    exit;
}

require $endpoint;
