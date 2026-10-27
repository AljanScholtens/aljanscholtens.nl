<?php
declare(strict_types=1);

// Installed at ~/www/fog-alert.php. Keep all private files outside the web root.
$privateDir = dirname(__DIR__) . '/private';
$configPath = $privateDir . '/fog-alert-config.php';
if (!is_file($configPath)) {
    http_response_code(503);
    exit("Configuration missing\n");
}
$config = require $configPath;
if (!is_array($config) || !isset($config['cron_key'])
    || (!isset($config['ntfy_topic'])
        && !isset($config['telegram_bot_token'], $config['telegram_chat_id']))) {
    http_response_code(503);
    exit("Invalid configuration\n");
}

$suppliedKey = $_GET['key'] ?? '';
if (!is_string($suppliedKey) || !hash_equals($config['cron_key'], $suppliedKey)) {
    http_response_code(403);
    exit("Forbidden\n");
}

$zone = new DateTimeZone('Europe/Amsterdam');
$now = new DateTimeImmutable('now', $zone);
$preview = ($_GET['preview'] ?? '') === '1';
if (!$preview && (int) $now->format('G') !== 20 && ($_GET['check'] ?? '') !== 'now') {
    exit("Outside evening check window\n");
}

require_once __DIR__ . '/fog-weather.php';
require_once __DIR__ . '/fog-feedback.php';

try {
    $lockPath = $privateDir . '/fog-alert.lock';
    $lock = fopen($lockPath, 'c');
    if ($lock === false || !flock($lock, LOCK_EX)) {
        throw new RuntimeException('Could not lock the alert job');
    }

    $tomorrow = $now->modify('+1 day')->format('Y-m-d');
    $sentPath = $privateDir . '/fog-alert-last-sent.txt';
    if (!$preview && is_file($sentPath)
        && trim((string) file_get_contents($sentPath)) === $tomorrow) {
        exit("Alert already sent for tomorrow\n");
    }

    $forecast = fogBuildForecast($now, $zone);
    if (!$preview && @file_put_contents(
        $privateDir . '/fog-alert-last-check.txt',
        $forecast['check_status'],
        LOCK_EX
    ) === false) {
        error_log('Fog alert: could not save last check status');
    }
    if ($preview) {
        header('Content-Type: text/plain; charset=utf-8');
        echo $forecast['title'] . "\n" . $forecast['message'] . "\n";
        exit;
    }
    $telegramText = $forecast['title'] . "\n\n" . $forecast['message'];
    $feedbackReady = $forecast['metrics'] !== null
        && !empty($config['telegram_bot_token'])
        && !empty($config['telegram_chat_id']);
    try {
        fogSaveForecastArchive($privateDir, $forecast, $now, null, $telegramText);
    } catch (Throwable $archiveError) {
        error_log('Fog alert archive: ' . $archiveError->getMessage());
        $feedbackReady = false;
        $telegramText = $forecast['title'] . "\n\n" . $forecast['message'];
    }
    $messageId = null;
    if (!empty($config['telegram_bot_token']) && !empty($config['telegram_chat_id'])) {
        $messageId = fogSendTelegram(
            $config,
            (string) $config['telegram_chat_id'],
            $telegramText,
            $feedbackReady ? fogFeedbackKeyboard($forecast['date']) : null
        );
    } else {
        [$notifyStatus] = fogRequest(
            'https://ntfy.sh/' . rawurlencode($config['ntfy_topic']),
            $forecast['message'],
            [
                'Title: ' . $forecast['title'],
                'Priority: ' . $forecast['priority'],
                'Content-Type: text/plain; charset=utf-8',
            ]
        );
        if ($notifyStatus < 200 || $notifyStatus >= 300) {
            throw new RuntimeException('Notification returned HTTP ' . $notifyStatus);
        }
    }
    try {
        fogSaveForecastArchive($privateDir, $forecast, $now, $messageId, $telegramText);
    } catch (Throwable $archiveError) {
        error_log('Fog alert archive after delivery: ' . $archiveError->getMessage());
    }
    if (file_put_contents($sentPath, $tomorrow . "\n", LOCK_EX) === false) {
        throw new RuntimeException('Notification sent, but delivery state could not be saved');
    }
    echo "Alert sent for $tomorrow\n";
} catch (Throwable $error) {
    error_log('Fog alert: ' . $error->getMessage());
    http_response_code(502);
    echo "Fog alert failed; see server error log\n";
}
