<?php
declare(strict_types=1);

// Nightly forecast snapshots and optional observations stay outside the web root.
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
    $directory = dirname($path);
    if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
        throw new RuntimeException('Could not create forecast archive directory');
    }
    $data = [
        'date' => $forecast['date'],
        'sent_at' => $now->format(DATE_ATOM),
        'place' => 'Vries',
        'forecast' => $forecast,
        'telegram_message_id' => $messageId,
        'telegram_text' => $telegramText,
    ];
    $encoded = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
    if (file_put_contents($path, $encoded, LOCK_EX) !== strlen($encoded)) {
        throw new RuntimeException('Could not save forecast archive');
    }
    @chmod($path, 0600);
}

function fogRecordFeedback(
    string $privateDir,
    string $date,
    int $messageId,
    string $kind,
    string $value,
    DateTimeImmutable $now
): string {
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

    $directory = $privateDir . '/fog-feedback';
    if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
        throw new RuntimeException('Could not create feedback directory');
    }
    $path = $directory . '/' . $date . '.json';
    $existing = is_file($path) ? json_decode((string) file_get_contents($path), true) : null;
    $record = is_array($existing) ? $existing : [
        'date' => $date,
        'place' => 'Vries',
        'telegram_message_id' => $messageId,
        'mist' => null,
        'photo_worthy' => null,
    ];
    if ($kind === 'mist') {
        $record['mist'] = $value;
        if ($value === 'unseen') {
            $record['photo_worthy'] = null;
        }
        $reply = 'Genoteerd: ' . $mistLabels[$value] . '.';
    } else {
        if ($record['mist'] === null || $record['mist'] === 'unseen') {
            return 'Kies eerst wat je zelf hebt gezien.';
        }
        $record['photo_worthy'] = $value === 'yes';
        $reply = $value === 'yes' ? 'Genoteerd: foto waard.' : 'Genoteerd: foto niet waard.';
    }
    $record['updated_at'] = $now->format(DATE_ATOM);
    $encoded = json_encode($record, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
    $temporary = tempnam($directory, '.feedback-');
    if ($temporary === false) {
        throw new RuntimeException('Could not create feedback file');
    }
    if (file_put_contents($temporary, $encoded) !== strlen($encoded)) {
        @unlink($temporary);
        throw new RuntimeException('Could not write feedback file');
    }
    @chmod($temporary, 0600);
    if (!rename($temporary, $path)) {
        @unlink($temporary);
        throw new RuntimeException('Could not save feedback file');
    }
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
