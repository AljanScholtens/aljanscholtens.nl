<?php
declare(strict_types=1);

$root = getenv('FOG_BOT_ROOT');
if (!is_string($root) || $root === '') {
    throw new RuntimeException('FOG_BOT_ROOT is required');
}
require_once rtrim($root, '/') . '/www/fog-feedback.php';

function feedbackCheck(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function feedbackRead(string $privateDir, string $date): array
{
    $path = $privateDir . '/fog-feedback/' . $date . '.json';
    return json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
}

$now = new DateTimeImmutable('now', new DateTimeZone('Europe/Amsterdam'));
$date = $now->format('Y-m-d');
$yesterday = $now->modify('-1 day')->format('Y-m-d');
$privateDir = rtrim($root, '/') . '/private/test-feedback-' . bin2hex(random_bytes(6));
if (!mkdir($privateDir, 0700)) {
    throw new RuntimeException('Could not create feedback test directory');
}

try {
    fogSaveForecastArchive($privateDir, ['date' => $date], $now, 123, 'Test');
    feedbackCheck(
        fogRecordFeedback($privateDir, $date, 123, 'photo', 'yes', $now, 'callback-photo-yes')
            === 'Genoteerd: foto waard.',
        'Photo feedback should work without a prior mist choice'
    );
    fogRecordFeedback($privateDir, $date, 123, 'mist', 'light', $now, 'callback-mist');
    fogRecordFeedback($privateDir, $date, 123, 'mist', 'dense', $now, 'callback-dense');
    fogRecordFeedback($privateDir, $date, 123, 'photo', 'no', $now, 'callback-photo-no');
    $record = feedbackRead($privateDir, $date);
    feedbackCheck(count($record['events']) === 4, 'Every accepted choice should remain in history');
    feedbackCheck(
        array_column($record['events'], 'value') === ['yes', 'light', 'dense', 'no'],
        'Feedback history should preserve choice order'
    );
    feedbackCheck($record['mist'] === 'dense' && $record['photo_worthy'] === false,
        'Daily summary should keep the latest choices');
    feedbackCheck($record['events'][0]['forecast_date'] === $date
        && $record['events'][0]['telegram_message_id'] === 123
        && $record['events'][0]['recorded_at'] === $now->format(DATE_ATOM),
        'Each event should remain linked to the forecast and time');

    feedbackCheck(
        fogRecordFeedback($privateDir, $date, 123, 'photo', 'yes', $now, 'callback-photo-yes')
            === 'Genoteerd: foto waard.',
        'A retried callback should receive its original acknowledgement'
    );
    feedbackCheck(count(feedbackRead($privateDir, $date)['events']) === 4,
        'A retried callback must not add an event or change the summary');
    feedbackCheck(
        fogRecordFeedback($privateDir, $date, 123, 'frost', 'yes', $now, 'callback-frost')
            === 'Genoteerd: rijp/dauw gezien.'
        && feedbackRead($privateDir, $date)['frost'] === true,
        'Rime or dew feedback should be stored');
    feedbackCheck(
        fogRecordFeedback($privateDir, $date, 123, 'color', 'yes', $now, 'callback-color')
            === 'Genoteerd: kleur in de lucht.'
        && feedbackRead($privateDir, $date)['color'] === true,
        'Colour feedback should be stored');
    fogRecordFeedback($privateDir, $date, 123, 'mist', 'unseen', $now, 'callback-unseen');
    $record = feedbackRead($privateDir, $date);
    feedbackCheck(count($record['events']) === 7 && $record['photo_worthy'] === null
        && $record['frost'] === null && $record['color'] === null,
        'Not looked should clear the summary but preserve earlier photo feedback');

    feedbackCheck(fogRecordFeedback($privateDir, $date, 124, 'mist', 'none', $now, 'wrong-message')
        === 'Ik kan deze melding niet aan een voorspelling koppelen.',
        'Feedback from another message should be rejected');
    feedbackCheck(fogRecordFeedback($privateDir, $date, 123, 'mist', 'invalid', $now, 'invalid-choice')
        === 'Deze keuze herken ik niet.', 'Unknown choices should be rejected');
    feedbackCheck(count(feedbackRead($privateDir, $date)['events']) === 7,
        'Rejected choices must not enter the history');

    fogSaveForecastArchive($privateDir, ['date' => $yesterday], $now, 321, 'Legacy test');
    $legacy = [
        'date' => $yesterday,
        'place' => 'Vries',
        'telegram_message_id' => 321,
        'mist' => 'forest',
        'photo_worthy' => true,
        'updated_at' => $now->modify('-1 day')->format(DATE_ATOM),
    ];
    file_put_contents($privateDir . '/fog-feedback/' . $yesterday . '.json',
        json_encode($legacy, JSON_THROW_ON_ERROR));
    fogRecordFeedback($privateDir, $yesterday, 321, 'mist', 'dense', $now, 'callback-legacy');
    $record = feedbackRead($privateDir, $yesterday);
    feedbackCheck(count($record['events']) === 1
        && $record['history_baseline']['mist'] === 'forest'
        && $record['history_baseline']['photo_worthy'] === true
        && $record['mist'] === 'dense',
        'An old summary should be preserved as a baseline without invented old events');

    feedbackCheck(fogRecordFeedback($privateDir, $now->modify('+1 day')->format('Y-m-d'),
        123, 'mist', 'none', $now, 'future') === 'Vul dit morgenochtend in, nadat je hebt gekeken.',
        'Feedback for a future morning should be rejected');
    feedbackCheck(fogRecordFeedback($privateDir, $now->modify('-15 days')->format('Y-m-d'),
        123, 'mist', 'none', $now, 'expired') === 'Deze terugkoppeling is verlopen.',
        'Old feedback should be rejected');
    echo "Feedback history checks passed.\n";
} finally {
    foreach (['fog-feedback', 'fog-forecasts'] as $folder) {
        foreach (glob($privateDir . '/' . $folder . '/*.json') ?: [] as $path) {
            unlink($path);
        }
        if (is_dir($privateDir . '/' . $folder)) {
            rmdir($privateDir . '/' . $folder);
        }
    }
    rmdir($privateDir);
}
