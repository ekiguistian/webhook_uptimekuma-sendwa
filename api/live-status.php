<?php

require_once __DIR__ . '/config.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');
header('X-Content-Type-Options: nosniff');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    header('Allow: GET');
    http_response_code(405);
    echo json_encode(['ok' => false, 'message' => 'Method not allowed']);
    exit;
}

$snapshot = app_read_json_array(__DIR__ . '/live-monitor.json');
if (!is_array($snapshot)) {
    $snapshot = [];
}
$metrics = app_delivery_metrics();
$uptimeKumaUrlConfigured = app_env('UPTIME_KUMA_URL') !== '' || app_env('UPTIME_STATUS_WEBSITE') !== '';

$sanitizeCheck = static function ($check, $defaultConfigured = true) {
    if (!is_array($check)) {
        return [
            'configured' => $defaultConfigured,
            'ok' => null,
            'ms' => null
        ];
    }

    $milliseconds = $check['ms'] ?? null;
    return [
        'configured' => (bool) ($check['configured'] ?? $defaultConfigured),
        'ok' => is_bool($check['ok'] ?? null) ? $check['ok'] : null,
        'ms' => is_numeric($milliseconds) ? round((float) $milliseconds, 2) : null
    ];
};

$response = [
    'checked_at' => is_string($snapshot['checked_at'] ?? null) ? $snapshot['checked_at'] : null,
    'checks' => [
        'dns' => $sanitizeCheck($snapshot['dns'] ?? null),
        'ngirimwa' => $sanitizeCheck($snapshot['ngirimwa'] ?? null),
        'uptime_kuma' => $sanitizeCheck($snapshot['uptime_kuma'] ?? null, $uptimeKumaUrlConfigured)
    ],
    'messages' => [
        'success' => max(0, (int) ($metrics['success'] ?? 0)),
        'failure' => max(0, (int) ($metrics['failure'] ?? 0))
    ],
    'configuration' => [
        'api_key' => app_env('NGIRIMWA_API_KEY') !== '',
        'webhook_token' => app_env('UPTIME_WEBHOOK_TOKEN') !== '',
        'group_id' => app_env('NGIRIMWA_GROUP_ID') !== '',
        'uptime_kuma_url' => $uptimeKumaUrlConfigured
    ]
];

echo json_encode($response, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
