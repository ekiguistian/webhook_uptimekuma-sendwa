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

$snapshot = [];
$snapshotTimestamp = 0;
foreach ([__DIR__ . '/live-monitor.json', app_log_directory() . '/live-monitor.json'] as $snapshotPath) {
    $candidate = app_read_json_array($snapshotPath);
    if (!is_array($candidate) || !is_string($candidate['checked_at'] ?? null)) {
        continue;
    }

    $candidateTimestamp = strtotime($candidate['checked_at']) ?: 0;
    if ($candidateTimestamp >= $snapshotTimestamp) {
        $snapshot = $candidate;
        $snapshotTimestamp = $candidateTimestamp;
    }
}
$metrics = app_delivery_metrics();
$uptimeKumaUrlConfigured = app_env('UPTIME_STATUS_WEBSITE') !== '';

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

$dnsSnapshot = is_array($snapshot['dns'] ?? null) ? $snapshot['dns'] : [];
$dnsCheck = $sanitizeCheck($dnsSnapshot);
$dnsCheck['resolvers'] = [];
foreach (($dnsSnapshot['resolvers'] ?? []) as $resolver) {
    if (!is_array($resolver)) {
        continue;
    }
    $dnsCheck['resolvers'][] = [
        'label' => is_string($resolver['label'] ?? null) ? substr($resolver['label'], 0, 80) : 'Resolver',
        'ok' => is_bool($resolver['ok'] ?? null) ? $resolver['ok'] : null,
        'ms' => is_numeric($resolver['ms'] ?? null) ? round((float) $resolver['ms'], 2) : null
    ];
}

$response = [
    'checked_at' => is_string($snapshot['checked_at'] ?? null) ? $snapshot['checked_at'] : null,
    'domain_checked_at' => is_string($snapshot['domain_checked_at'] ?? null) ? $snapshot['domain_checked_at'] : null,
    'checks' => [
        'dns' => $dnsCheck,
        'ngirimwa' => $sanitizeCheck($snapshot['ngirimwa'] ?? null),
        'uptime_kuma' => $sanitizeCheck($snapshot['uptime_kuma'] ?? null, $uptimeKumaUrlConfigured)
    ],
    'messages' => [
        'success' => max(0, (int) ($metrics['success'] ?? 0)),
        'failure' => max(0, (int) ($metrics['failure'] ?? 0)),
        'skipped_same_status' => max(0, (int) ($metrics['skipped_same_status'] ?? 0))
    ],
    'configuration' => [
        'ngirimwa_api_url' => app_env('NGIRIMWA_BASE_URL') !== '',
        'api_key' => app_env('NGIRIMWA_API_KEY') !== '',
        'webhook_token' => app_env('UPTIME_WEBHOOK_TOKEN') !== '',
        'group_id' => app_env('NGIRIMWA_GROUP_ID') !== '',
        'uptime_kuma_url' => $uptimeKumaUrlConfigured
    ]
];

$uptimeKumaCheck = $sanitizeCheck($snapshot['uptime_kuma'] ?? null, $uptimeKumaUrlConfigured);
if (!$uptimeKumaUrlConfigured) {
    $uptimeKumaCheck = ['configured' => false, 'ok' => null, 'ms' => null];
} elseif (!$uptimeKumaCheck['configured']) {
    // An old snapshot may predate the configured URL; wait for the next worker sample.
    $uptimeKumaCheck = ['configured' => true, 'ok' => null, 'ms' => null];
}
$response['checks']['uptime_kuma'] = $uptimeKumaCheck;

echo json_encode($response, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
