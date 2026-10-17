<?php
declare(strict_types=1);

// Telegram sends commands here. The bot token and webhook secret stay outside www.
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    http_response_code(405);
    exit;
}

$privateDir = dirname(__DIR__) . '/private';
$configPath = $privateDir . '/fog-alert-config.php';
if (!is_file($configPath)) {
    http_response_code(503);
    exit;
}
$config = require $configPath;
if (!is_array($config)
    || !is_string($config['telegram_bot_token'] ?? null)
    || !is_scalar($config['telegram_chat_id'] ?? null)
    || !is_string($config['telegram_webhook_secret'] ?? null)
    || $config['telegram_webhook_secret'] === '') {
    http_response_code(503);
    exit;
}

$suppliedSecret = $_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] ?? '';
if (!is_string($suppliedSecret)
    || !hash_equals($config['telegram_webhook_secret'], $suppliedSecret)) {
    http_response_code(403);
    exit;
}

$raw = file_get_contents('php://input', false, null, 0, 65537);
if ($raw === false || strlen($raw) > 65536) {
    http_response_code(413);
    exit;
}
$update = json_decode($raw, true);
if (!is_array($update) || !is_int($update['update_id'] ?? null)) {
    http_response_code(200);
    exit;
}
$message = $update['message'] ?? null;
if (!is_array($message) || ($message['chat']['type'] ?? null) !== 'private'
    || !is_scalar($message['chat']['id'] ?? null)
    || (string) $message['chat']['id'] !== (string) $config['telegram_chat_id']) {
    http_response_code(200);
    exit;
}
$text = $message['text'] ?? null;
if (!is_string($text)
    || !preg_match('/^\/(weer|start|help)(?:@LPW_bot)?$/iD', trim($text), $match)) {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/fog-weather.php';

try {
    // Keep a small history because Telegram can retry the same update.
    $statePath = $privateDir . '/fog-bot-processed.json';
    $state = fopen($statePath, 'c+');
    if ($state === false || !flock($state, LOCK_EX)) {
        throw new RuntimeException('Could not lock bot state');
    }
    @chmod($statePath, 0600);
    $stored = json_decode((string) stream_get_contents($state), true);
    $recentIds = is_array($stored) ? array_values(array_filter($stored, 'is_string')) : [];
    $updateId = (string) $update['update_id'];
    if (in_array($updateId, $recentIds, true)) {
        http_response_code(200);
        exit;
    }

    if (strtolower($match[1]) === 'weer') {
        $zone = new DateTimeZone('Europe/Amsterdam');
        $now = new DateTimeImmutable('now', $zone);
        $forecast = fogBuildForecast($now, $zone);
        $reply = $forecast['title'] . "\n\n" . $forecast['message']
            . "\n\nOpgevraagd om " . $now->format('H:i') . '.';
    } else {
        $reply = 'Stuur /weer voor een actuele voorspelling voor morgenochtend bij Vries. Je ontvangt ook iedere avond automatisch een bericht.';
    }
    fogSendTelegram($config, (string) $config['telegram_chat_id'], $reply);

    $recentIds[] = $updateId;
    $encoded = json_encode(array_slice($recentIds, -100), JSON_THROW_ON_ERROR);
    rewind($state);
    if (!ftruncate($state, 0) || fwrite($state, $encoded) !== strlen($encoded)
        || !fflush($state)) {
        throw new RuntimeException('Could not save bot state');
    }
    http_response_code(200);
    echo "OK\n";
} catch (Throwable $error) {
    error_log('Fog bot: ' . $error->getMessage());
    http_response_code(503);
}
