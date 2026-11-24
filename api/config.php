<?php #ide cemerlang eki guistian

function load_env_file($path)
{
    if (!is_readable($path)) {
        return;
    }

    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) {
        return;
    }

    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') {
            continue;
        }

        $separator = strpos($line, '=');
        if ($separator === false) {
            continue;
        }

        $name = trim(substr($line, 0, $separator));
        $value = trim(substr($line, $separator + 1));
        if (!preg_match('/^[A-Z_][A-Z0-9_]*$/', $name)) {
            continue;
        }

        if (strlen($value) >= 2) {
            $first = substr($value, 0, 1);
            $last = substr($value, -1);
            if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
                $value = substr($value, 1, -1);
            }
        }

        // Existing process environment takes precedence over .env.
        if (getenv($name) !== false) {
            continue;
        }

        putenv($name . '=' . $value);
        $_ENV[$name] = $value;
        $_SERVER[$name] = $value;
    }
}

function app_env($name, $default = '')
{
    $value = getenv($name);
    return $value === false ? $default : trim($value);
}

function app_log_directory()
{
    return dirname(__DIR__) . '/log';
}

function app_count_delivery_log_entries($path)
{
    if (!is_readable($path)) {
        return 0;
    }

    $handle = @fopen($path, 'rb');
    if ($handle === false) {
        return 0;
    }

    $count = 0;
    while (($line = fgets($handle)) !== false) {
        $contextStart = strpos($line, ' {');
        if ($contextStart === false) {
            continue;
        }

        $context = json_decode(trim(substr($line, $contextStart + 1)), true);
        if (is_array($context) && array_key_exists('service', $context)) {
            $count++;
        }
    }
    fclose($handle);

    return $count;
}

function app_count_same_status_skips($path)
{
    if (!is_readable($path)) {
        return 0;
    }

    $handle = @fopen($path, 'rb');
    if ($handle === false) {
        return 0;
    }

    $count = 0;
    while (($line = fgets($handle)) !== false) {
        if (strpos($line, 'SKIP SAME:') !== false
            || strpos($line, 'Duplicate monitor status suppressed during the 10-minute cooldown') !== false) {
            $count++;
        }
    }
    fclose($handle);

    return $count;
}

function app_delivery_metrics($incrementChannel = null, $context = [])
{
    $logDirectory = app_log_directory();
    if (!is_dir($logDirectory)) {
        @mkdir($logDirectory, 0750, true);
    }

    $metricsPath = $logDirectory . '/delivery-metrics.json';
    $lock = @fopen($logDirectory . '/delivery-metrics.lock', 'c');
    if ($lock === false || !flock($lock, LOCK_EX)) {
        if (is_resource($lock)) {
            fclose($lock);
        }
        $metrics = app_read_json_array($metricsPath);
        return is_array($metrics)
            ? ($metrics + ['success' => 0, 'failure' => 0, 'skipped_same_status' => 0])
            : ['success' => 0, 'failure' => 0, 'skipped_same_status' => 0];
    }

    $metrics = app_read_json_array($metricsPath);
    $initialized = is_array($metrics);
    $metricsChanged = false;
    if (!$initialized) {
        $metrics = [
            'success' => app_count_delivery_log_entries($logDirectory . '/send-success.log'),
            'failure' => app_count_delivery_log_entries($logDirectory . '/send-failure.log'),
            'skipped_same_status' => app_count_same_status_skips($logDirectory . '/system.log')
        ];
        $metricsChanged = true;
    } else {
        foreach (['success', 'failure', 'skipped_same_status'] as $metricName) {
            if (!isset($metrics[$metricName]) || !is_numeric($metrics[$metricName])) {
                $metrics[$metricName] = $metricName === 'skipped_same_status'
                    ? app_count_same_status_skips($logDirectory . '/system.log')
                    : 0;
                $metricsChanged = true;
            }
        }
    }

    $incremented = false;
    if ($incrementChannel === 'skip-same-status') {
        $metrics['skipped_same_status'] = (int) ($metrics['skipped_same_status'] ?? 0) + 1;
        $incremented = true;
    } elseif (isset($context['service']) && $context['service'] !== '') {
        if ($incrementChannel === 'send-success') {
            $metrics['success'] = (int) ($metrics['success'] ?? 0) + 1;
            $incremented = true;
        } elseif ($incrementChannel === 'send-failure') {
            $metrics['failure'] = (int) ($metrics['failure'] ?? 0) + 1;
            $incremented = true;
        }
    }

    if ($incremented) {
        $metrics['updated_at'] = date('c');
    }
    if ($metricsChanged || $incremented) {
        if (!app_write_json_array($metricsPath, $metrics)) {
            error_log('Could not write delivery metrics');
        }
    }

    flock($lock, LOCK_UN);
    fclose($lock);
    return $metrics;
}

function configure_app_error_logging()
{
    ini_set('display_errors', '0');
    ini_set('display_startup_errors', '0');
    ini_set('log_errors', '1');
    error_reporting(E_ALL);

    $logDirectory = app_log_directory();
    if (!is_dir($logDirectory)) {
        @mkdir($logDirectory, 0750, true);
    }
    ini_set('error_log', $logDirectory . '/php-error.log');
}

function app_log($channel, $message, $context = [])
{
    $allowedChannels = ['system', 'send-success', 'send-failure', 'cron-run'];
    if (!in_array($channel, $allowedChannels, true)) {
        $channel = 'system';
    }

    if (in_array($channel, ['send-success', 'send-failure'], true)) {
        app_delivery_metrics($channel, $context);
    }

    $logDirectory = app_log_directory();
    if (!is_dir($logDirectory)) {
        @mkdir($logDirectory, 0750, true);
    }

    $contextJson = $context
        ? json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE)
        : '';
    $message = preg_replace('/[\r\n\x00]+/', ' ', (string) $message);
    $line = '[' . date('c') . '] ' . $message;
    if ($contextJson !== false && $contextJson !== '') {
        $line .= ' ' . $contextJson;
    }
    $line .= PHP_EOL;
    $channels = in_array($channel, ['system', 'cron-run'], true)
        ? [$channel]
        : [$channel, 'system'];
    foreach ($channels as $logChannel) {
        if (file_put_contents($logDirectory . '/' . $logChannel . '.log', $line, FILE_APPEND | LOCK_EX) === false) {
            error_log('Application log write failed for channel ' . $logChannel);
        }
    }
}

function app_log_rate_limited($channel, $message, $key, $intervalSeconds = 60, $context = [])
{
    $logDirectory = app_log_directory();
    if (!is_dir($logDirectory)) {
        @mkdir($logDirectory, 0750, true);
    }

    $lock = @fopen($logDirectory . '/event-throttle.lock', 'c');
    if ($lock === false || !flock($lock, LOCK_EX)) {
        if (is_resource($lock)) {
            fclose($lock);
        }
        return false;
    }

    $stateFile = $logDirectory . '/event-throttle.json';
    $state = app_read_json_array($stateFile);
    if (!is_array($state)) {
        $state = [];
    }

    $now = time();
    $stateKey = hash('sha256', (string) $key);
    foreach ($state as $storedKey => $lastLoggedAt) {
        if (!is_numeric($lastLoggedAt) || $now - (int) $lastLoggedAt > 3600) {
            unset($state[$storedKey]);
        }
    }

    $lastLoggedAt = (int) ($state[$stateKey] ?? 0);
    if ($now - $lastLoggedAt < max(1, (int) $intervalSeconds)) {
        flock($lock, LOCK_UN);
        fclose($lock);
        return false;
    }

    $state[$stateKey] = $now;
    if (count($state) > 512) {
        asort($state, SORT_NUMERIC);
        $state = array_slice($state, -512, null, true);
    }
    $stateJson = json_encode($state, JSON_PRETTY_PRINT);
    if ($stateJson !== false) {
        file_put_contents($stateFile, $stateJson, LOCK_EX);
    }

    app_log($channel, $message, $context);
    flock($lock, LOCK_UN);
    fclose($lock);
    return true;
}

function app_queue_lock()
{
    $lock = @fopen(dirname(__DIR__) . '/api/queue.lock', 'c');
    if ($lock === false || !flock($lock, LOCK_EX)) {
        if (is_resource($lock)) {
            fclose($lock);
        }
        return false;
    }
    return $lock;
}

function app_queue_unlock($lock)
{
    if (is_resource($lock)) {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

function app_read_json_array($path)
{
    if (!file_exists($path)) {
        return [];
    }
    $contents = file_get_contents($path);
    if ($contents === false) {
        return null;
    }
    $decoded = json_decode($contents, true);
    return is_array($decoded) ? $decoded : null;
}

function app_write_json_array($path, $data)
{
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    return $json !== false && file_put_contents($path, $json, LOCK_EX) !== false;
}

configure_app_error_logging();
load_env_file(dirname(__DIR__) . '/.env');
