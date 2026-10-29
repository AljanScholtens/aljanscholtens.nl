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
$callback = $update['callback_query'] ?? null;
if (is_array($callback)) {
    $callbackMessage = $callback['message'] ?? null;
    if (!is_array($callbackMessage)
        || ($callbackMessage['chat']['type'] ?? null) !== 'private'
        || !is_scalar($callbackMessage['chat']['id'] ?? null)
        || (string) $callbackMessage['chat']['id'] !== (string) $config['telegram_chat_id']
        || !is_scalar($callback['from']['id'] ?? null)
        || (string) $callback['from']['id'] !== (string) $config['telegram_chat_id']
        || !is_int($callbackMessage['message_id'] ?? null)
        || !is_string($callback['id'] ?? null)
        || !is_string($callback['data'] ?? null)) {
        http_response_code(200);
        exit;
    }
} else {
    $message = $update['message'] ?? null;
    if (!is_array($message) || ($message['chat']['type'] ?? null) !== 'private'
        || !is_scalar($message['chat']['id'] ?? null)
        || (string) $message['chat']['id'] !== (string) $config['telegram_chat_id']) {
        http_response_code(200);
        exit;
    }
    $text = $message['text'] ?? null;
    if (!is_string($text)
        || !preg_match('/^\/(weer|start|help)(?:@LPW_bot)?(?:[ \t]+(.+))?$/iD', trim($text), $match)) {
        http_response_code(200);
        exit;
    }
}

require_once __DIR__ . '/fog-weather.php';
require_once __DIR__ . '/fog-feedback.php';

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

    if (is_array($callback)) {
        $callbackData = $callback['data'];
        if (preg_match('/^fb:(\d{8}):(mist|photo|frost|color):([a-z_]+)$/D', $callbackData, $feedbackMatch)) {
            $stamp = $feedbackMatch[1];
            $date = substr($stamp, 0, 4) . '-' . substr($stamp, 4, 2) . '-' . substr($stamp, 6, 2);
            try {
                $answer = fogRecordFeedback(
                    $privateDir,
                    $date,
                    $callbackMessage['message_id'],
                    $feedbackMatch[2],
                    $feedbackMatch[3],
                    new DateTimeImmutable('now', new DateTimeZone('Europe/Amsterdam')),
                    $callback['id']
                );
            } catch (InvalidArgumentException $feedbackError) {
                $answer = 'Deze keuze herken ik niet.';
            }
        } else {
            $answer = 'Deze keuze herken ik niet.';
        }
        fogAnswerCallback($config, $callback['id'], $answer);
    } elseif (strtolower($match[1]) === 'weer') {
        $zone = new DateTimeZone('Europe/Amsterdam');
        $now = new DateTimeImmutable('now', $zone);
        $placeQuery = trim($match[2] ?? '');
        $place = null;
        $reply = null;
        if (strlen($placeQuery) > 80
            || ($placeQuery !== '' && preg_match('/[\x00-\x1F\x7F]/', $placeQuery))) {
            $reply = 'Gebruik /weer of /weer gevolgd door een Nederlandse plaatsnaam, bijvoorbeeld /weer Assen.';
        } elseif ($placeQuery !== '') {
            try {
                $search = fogFindDutchPlace($placeQuery);
                if ($search['status'] === 'ok') {
                    $place = $search['place'];
                } elseif ($search['status'] === 'ambiguous') {
                    $choices = [];
                    foreach (array_slice($search['places'], 0, 5) as $candidate) {
                        $choices[] = $candidate['name'] . ', ' . $candidate['qualifier'];
                    }
                    $reply = 'Meerdere plaatsen gevonden: ' . implode('; ', $choices)
                        . '. Voeg de provincie of gemeente toe, bijvoorbeeld /weer ' . $choices[0] . '.';
                } else {
                    $reply = 'Plaats niet gevonden: ' . $placeQuery . '. Gebruik een volledige Nederlandse plaatsnaam.';
                    if (!empty($search['suggestions'])) {
                        $reply .= ' Bedoelde je /weer ' . $search['suggestions'][0]['name'] . '?';
                    }
                }
            } catch (Throwable $placeError) {
                error_log('Fog bot place search: ' . $placeError->getMessage());
                $reply = 'Plaatsen zoeken lukt nu niet. Probeer /weer met een plaatsnaam later opnieuw.';
            }
        }
        if ($reply === null) {
            $forecast = fogBuildForecast($now, $zone, $place);
            $reply = $forecast['title'] . "\n\n" . $forecast['message'];
        }
    } else {
        $reply = 'Stuur /weer voor Vries of bijvoorbeeld /weer Assen voor een andere Nederlandse plaats. Je ontvangt ook iedere avond automatisch een bericht voor Vries. Onder de avondmelding kun je achteraf aangeven wat je zelf hebt gezien.';
    }
    if (!is_array($callback)) {
        fogSendTelegram($config, (string) $config['telegram_chat_id'], $reply);
    }

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
