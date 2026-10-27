<?php
declare(strict_types=1);

// Nightly forecast snapshots and optional observations stay outside the web root.
require_once __DIR__ . '/fog-weather.php';

function fogFeedbackKeyboard(string $date): array
{
    $stamp = str_replace('-', '', $date);
    $button = static function (string $label, string $kind, string $value) use ($stamp): array {
        return ['text' => $label, 'callback_data' => "fb:$stamp:$kind:$value"];
    };
    return ['inline_keyboard' => [
        [$button('Geen mist', 'mist', 'none'), $button('Mist', 'mist', 'light')],
        [$button('Dichte mist', 'mist', 'dense'), $button('Bosmist', 'mist', 'forest')],
        [$button('Dichte bosmist', 'mist', 'dense_forest'), $button('Niet gekeken', 'mist', 'unseen')],
        [$button('Foto waard: ja', 'photo', 'yes'), $button('Foto waard: nee', 'photo', 'no')],
    ]];
}

function fogForecastArchivePath(string $privateDir, string $date): string
{
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/D', $date)
        || !checkdate((int) substr($date, 5, 2), (int) substr($date, 8, 2), (int) substr($date, 0, 4))) {
        throw new InvalidArgumentException('Invalid forecast date');
    }
    return $privateDir . '/fog-forecasts/' . $date . '.json';
}

function fogSaveForecastArchive(
    string $privateDir,
    array $forecast,
    DateTimeImmutable $now,
    ?int $messageId,
    string $telegramText
): void {
    $path = fogForecastArchivePath($privateDir, (string) $forecast['date']);
    fogSaveJson($path, [
        'date' => $forecast['date'],
        'sent_at' => $now->format(DATE_ATOM),
        'place' => 'Vries',
        'forecast' => $forecast,
        'telegram_message_id' => $messageId,
        'telegram_text' => $telegramText,
    ]);
}

function fogRecordFeedback(
    string $privateDir,
    string $date,
    int $messageId,
    string $kind,
    string $value,
    DateTimeImmutable $now,
    string $callbackId
): string {
    if ($callbackId === '') {
        throw new InvalidArgumentException('Invalid feedback callback');
    }
    $archivePath = fogForecastArchivePath($privateDir, $date);
    $today = $now->format('Y-m-d');
    if ($date > $today) {
        return 'Vul dit morgenochtend in, nadat je hebt gekeken.';
    }
    if ($date < $now->modify('-14 days')->format('Y-m-d')) {
        return 'Deze terugkoppeling is verlopen.';
    }
    $archive = is_file($archivePath)
        ? json_decode((string) file_get_contents($archivePath), true) : null;
    if (!is_array($archive) || ($archive['telegram_message_id'] ?? null) !== $messageId) {
        return 'Ik kan deze melding niet aan een voorspelling koppelen.';
    }
    $mistLabels = [
        'none' => 'geen mist',
        'light' => 'mist',
        'dense' => 'dichte mist',
        'forest' => 'bosmist',
        'dense_forest' => 'dichte bosmist',
        'unseen' => 'niet gekeken',
    ];
    if (($kind === 'mist' && !isset($mistLabels[$value]))
        || ($kind === 'photo' && !in_array($value, ['yes', 'no'], true))
        || !in_array($kind, ['mist', 'photo'], true)) {
        return 'Deze keuze herken ik niet.';
    }

    $path = $privateDir . '/fog-feedback/' . $date . '.json';
    $hasExisting = is_file($path);
    $existing = $hasExisting ? json_decode((string) file_get_contents($path), true) : null;
    if ($hasExisting && !is_array($existing)) {
        throw new RuntimeException('Existing feedback cannot be read');
    }
    $record = is_array($existing) ? $existing : [
        'date' => $date,
        'place' => 'Vries',
        'telegram_message_id' => $messageId,
        'mist' => null,
        'photo_worthy' => null,
    ];
    if (($record['date'] ?? null) !== $date
        || ($record['telegram_message_id'] ?? null) !== $messageId) {
        throw new RuntimeException('Existing feedback does not match forecast');
    }
    if (!array_key_exists('events', $record)) {
        // Older files contain only the last known choices; earlier clicks cannot be recovered.
        if ($hasExisting) {
            $record['history_baseline'] = [
                'mist' => $record['mist'] ?? null,
                'photo_worthy' => $record['photo_worthy'] ?? null,
                'updated_at' => $record['updated_at'] ?? null,
            ];
        }
        $record['events'] = [];
    }
    if (!is_array($record['events'])) {
        throw new RuntimeException('Existing feedback history cannot be read');
    }
    foreach ($record['events'] as $event) {
        if (!is_array($event)) {
            throw new RuntimeException('Existing feedback history cannot be read');
        }
        if (($event['callback_query_id'] ?? null) === $callbackId) {
            return (string) ($event['reply'] ?? 'Al genoteerd.');
        }
    }
    if ($kind === 'mist') {
        $record['mist'] = $value;
        if ($value === 'unseen') {
            $record['photo_worthy'] = null;
        }
        $reply = 'Genoteerd: ' . $mistLabels[$value] . '.';
    } else {
        $record['photo_worthy'] = $value === 'yes';
        $reply = $value === 'yes' ? 'Genoteerd: foto waard.' : 'Genoteerd: foto niet waard.';
    }
    $record['updated_at'] = $now->format(DATE_ATOM);
    $record['events'][] = [
        'callback_query_id' => $callbackId,
        'recorded_at' => $record['updated_at'],
        'forecast_date' => $date,
        'telegram_message_id' => $messageId,
        'kind' => $kind,
        'value' => $value,
        'reply' => $reply,
    ];
    fogSaveJson($path, $record);
    return $reply;
}

function fogAnswerCallback(array $config, string $callbackId, string $message): void
{
    $body = http_build_query([
        'callback_query_id' => $callbackId,
        'text' => $message,
    ], '', '&', PHP_QUERY_RFC3986);
    [$status, $response] = fogRequest(
        'https://api.telegram.org/bot' . $config['telegram_bot_token'] . '/answerCallbackQuery',
        $body,
        ['Content-Type: application/x-www-form-urlencoded'],
        8
    );
    $result = json_decode($response, true);
    if ($status !== 200 || !is_array($result) || ($result['ok'] ?? null) !== true) {
        throw new RuntimeException('Telegram callback answer returned HTTP ' . $status);
    }
}
