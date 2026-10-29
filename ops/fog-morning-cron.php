<?php
declare(strict_types=1);

// Install this file in ~/private. It runs only from the hosting account's cron.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__) . '/www/fog-weather.php';

// Returns [sunrise, first light] for the default place.
function fogMorningSun(string $date, DateTimeZone $zone): array
{
    $place = fogDefaultPlace();
    [$sunrise, , $firstLight] = fogSunTimes($date, $place['latitude'], $place['longitude'], $zone);
    return [$sunrise, $firstLight];
}

// Returns the new local dense fog state when it flipped since the evening, else null.
function fogMorningLocalDenseChanged(array $old, array $new): ?bool
{
    $oldKnmi = $old['knmi'] ?? null;
    $newKnmi = $new['knmi'] ?? null;
    if (!is_array($oldKnmi) || !is_array($newKnmi)) {
        return null;
    }
    $newDense = (bool) ($newKnmi['dense'] ?? false);
    return (bool) ($oldKnmi['dense'] ?? false) !== $newDense ? $newDense : null;
}

// Returns the new "local mist possible" state when it clearly flipped since the
// evening, else null. It combines the ground and forest cues the message shows.
function fogMorningLocalMistChanged(array $old, array $new): ?bool
{
    if (!is_numeric($old['forest_outlook_share'] ?? null)
        || !is_numeric($new['forest_outlook_share'] ?? null)) {
        return null;
    }
    // Older forecasts lack the ground cue; then compare the forest cue alone.
    $useGround = is_numeric($old['ground_conditions_share'] ?? null)
        && is_numeric($new['ground_conditions_share'] ?? null);
    $state = static function (array $metrics) use ($useGround): bool {
        return ($useGround && fogGroundMistFavorable((float) $metrics['ground_conditions_share'],
                is_array($metrics['knmi'] ?? null) ? $metrics['knmi'] : null))
            || ((float) $metrics['forest_outlook_share'] >= 0.5
                && !($metrics['models_disagree'] ?? false));
    };
    $before = $state($old);
    $after = $state($new);
    // A share hovering around a threshold should not flip-flop into a message.
    $shift = max(abs((float) $new['forest_outlook_share'] - (float) $old['forest_outlook_share']),
        $useGround ? abs((float) $new['ground_conditions_share']
            - (float) $old['ground_conditions_share']) : 0.0);
    return $before !== $after && $shift >= 0.3 ? $after : null;
}

function fogMorningDecision(?array $evening, array $morning): array
{
    $old = $evening['forecast']['metrics'] ?? null;
    $new = $morning['metrics'] ?? null;
    if (!is_array($evening) || !is_int($evening['telegram_message_id'] ?? null)
        || ($evening['date'] ?? null) !== ($morning['date'] ?? null)
        || !is_array($old) || !is_array($new)) {
        return ['send' => false, 'reasons' => ['no comparable evening forecast']];
    }
    foreach (['score', 'mist_share', 'dense_share', 'forest_conditions_share'] as $key) {
        if (!is_numeric($old[$key] ?? null) || !is_numeric($new[$key] ?? null)) {
            return ['send' => false, 'reasons' => ['incomplete forecast metrics']];
        }
    }

    $reasons = [];
    $oldScore = (int) $old['score'];
    $newScore = (int) $new['score'];
    $sameScoreVersion = ($old['score_version'] ?? 1) === ($new['score_version'] ?? 1);
    $scoreDelta = abs($newScore - $oldScore);
    $oldBand = $oldScore <= 3 ? 0 : ($oldScore <= 5 ? 1 : 2);
    $newBand = $newScore <= 3 ? 0 : ($newScore <= 5 ? 1 : 2);
    if ($sameScoreVersion
        && ($scoreDelta >= 3 || ($scoreDelta >= 2 && $oldBand !== $newBand))) {
        $reasons[] = 'outing advice changed';
    }
    if ($sameScoreVersion && ($new['score_version'] ?? 1) >= 2
        && is_string($old['photo_route'] ?? null)
        && is_string($new['photo_route'] ?? null)
        && $old['photo_route'] !== $new['photo_route']
        && min($oldScore, $newScore) >= 6
        && is_numeric($old['photo_route_margin'] ?? null)
        && is_numeric($new['photo_route_margin'] ?? null)
        && (float) $old['photo_route_margin'] >= 0.75
        && (float) $new['photo_route_margin'] >= 0.75) {
        $reasons[] = 'best photo opportunity changed';
    }
    if (abs((float) $new['mist_share'] - (float) $old['mist_share']) >= 0.35
        && max((float) $new['mist_share'], (float) $old['mist_share']) >= 0.4) {
        $reasons[] = 'fog chance changed substantially';
    }
    if (abs((float) $new['dense_share'] - (float) $old['dense_share']) >= 0.25
        && max((float) $new['dense_share'], (float) $old['dense_share']) >= 0.25) {
        $reasons[] = 'dense fog chance changed substantially';
    }
    if (fogMorningLocalMistChanged($old, $new) !== null) {
        $reasons[] = 'local mist conditions changed';
    }
    // Dense fog in the local run matters even when the score was already high.
    if (fogMorningLocalDenseChanged($old, $new) !== null) {
        $reasons[] = 'local dense fog signal changed';
    }
    return ['send' => count($reasons) > 0, 'reasons' => $reasons];
}

function fogMorningMessage(array $evening, array $morning): string
{
    $old = $evening['forecast']['metrics'];
    $new = $morning['metrics'];
    $sameScoreVersion = ($old['score_version'] ?? 1) === ($new['score_version'] ?? 1);
    $lines = [
        'Ochtendupdate ' . (new DateTimeImmutable($morning['date']))->format('d-m') . ' (Vries)',
        fogAdviceLine((int) $new['score'],
            fogMorningSun($morning['date'], new DateTimeZone('Europe/Amsterdam'))[1]),
        !$sameScoreVersion || (int) $old['score'] === (int) $new['score']
            ? sprintf('Cijfer: %d/10', (int) $new['score'])
            : sprintf('Cijfer: %d → %d/10', (int) $old['score'], (int) $new['score']),
        'Sinds gisteravond:',
    ];
    if ($sameScoreVersion && is_string($new['photo_reason'] ?? null)
        && ($new['photo_reason'] !== ($old['photo_reason'] ?? null))) {
        $lines[] = '• Fotokans: ' . $new['photo_reason'];
    }
    if (abs((float) $new['mist_share'] - (float) $old['mist_share']) >= 0.1) {
        $lines[] = sprintf('🌁 Mist: %d%% → %d%%',
            (int) round((float) $old['mist_share'] * 100),
            (int) round((float) $new['mist_share'] * 100));
    }
    if (abs((float) $new['dense_share'] - (float) $old['dense_share']) >= 0.1) {
        $lines[] = sprintf('↳ Dichte mist: %d%% → %d%%',
            (int) round((float) $old['dense_share'] * 100),
            (int) round((float) $new['dense_share'] * 100));
    }
    $localDense = fogMorningLocalDenseChanged($old, $new);
    if ($localDense !== null) {
        $lines[] = $localDense
            ? '↳ Lokaal: nu dichte mist verwacht' : '↳ Lokaal: geen dichte mist meer verwacht';
    }
    $localMist = fogMorningLocalMistChanged($old, $new);
    if ($localMist !== null) {
        $lines[] = '↳ Lokale mist: ' . ($localMist ? 'nu mogelijk' : 'niet meer verwacht');
    }
    [$sunrise] = fogMorningSun($morning['date'], new DateTimeZone('Europe/Amsterdam'));
    $lines[] = '☀️↑ ' . $sunrise->format('H:i');
    if (is_string($new['weather_summary'] ?? null)) {
        $lines[] = '• ' . $new['weather_summary'];
    }
    return implode("\n", $lines);
}

if (defined('FOG_MORNING_LIBRARY_ONLY')) {
    return;
}

try {
    $privateDir = __DIR__;
    $config = require $privateDir . '/fog-alert-config.php';
    $zone = new DateTimeZone('Europe/Amsterdam');
    $now = new DateTimeImmutable('now', $zone);
    $date = $now->format('Y-m-d');
    [$sunrise, $firstLight] = fogMorningSun($date, $zone);
    // Check 15 minutes before the alarm, so a changed advice arrives before it rings.
    $target = $firstLight->modify('-' . (FOG_WAKE_MINUTES_BEFORE_FIRST_LIGHT + 15) . ' minutes');
    $preview = in_array('--preview', $argv ?? [], true);
    if (!$preview && ($now < $target || $now >= $sunrise)) {
        exit;
    }

    $lock = fopen($privateDir . '/fog-morning.lock', 'c');
    if ($lock === false || !flock($lock, LOCK_EX)) {
        throw new RuntimeException('Could not lock morning check');
    }
    $archivePath = $privateDir . '/fog-morning-forecasts/' . $date . '.json';
    $eveningPath = $privateDir . '/fog-forecasts/' . $date . '.json';
    $evening = is_file($eveningPath)
        ? json_decode((string) file_get_contents($eveningPath), true) : null;
    $archiveExists = !$preview && file_exists($archivePath);
    $existing = $archiveExists && is_file($archivePath)
        ? json_decode((string) file_get_contents($archivePath), true) : null;
    if ($archiveExists && !is_array($existing)) {
        throw new RuntimeException('Morning archive exists but cannot be read');
    }
    if (is_array($existing)) {
        if (($existing['delivery'] ?? null) !== 'retryable'
            || !is_array($existing['forecast'] ?? null)
            || !is_array($existing['decision'] ?? null)) {
            exit;
        }
        $record = $existing;
        $morning = $record['forecast'];
        $decision = $record['decision'];
    } else {
        $morning = fogBuildForecast($now, $zone, null, $date, false);
        if (!is_array($morning['metrics'] ?? null)) {
            throw new RuntimeException('Morning forecast has no usable metrics');
        }
        $decision = fogMorningDecision(is_array($evening) ? $evening : null, $morning);
        $record = [
            'date' => $date,
            'checked_at' => $now->format(DATE_ATOM),
            'target_time' => $target->format(DATE_ATOM),
            'sunrise' => $sunrise->format(DATE_ATOM),
            'forecast' => $morning,
            'decision' => $decision,
            'telegram_message_id' => null,
            'delivery' => $decision['send'] ? 'pending' : 'not_needed',
        ];
    }
    if ($preview) {
        echo 'Target: ' . $target->format('Y-m-d H:i') . "\n";
        echo 'Send update: ' . ($decision['send'] ? 'yes' : 'no') . "\n";
        echo 'Reasons: ' . implode(', ', $decision['reasons']) . "\n\n";
        echo $morning['title'] . "\n" . $morning['message'] . "\n";
        exit;
    }

    // Save the comparison before delivery; ambiguous failures must not duplicate a push.
    fogSaveJson($archivePath, $record);
    if ($decision['send']) {
        try {
            if (!is_array($evening)) {
                throw new RuntimeException('Morning comparison lost its evening forecast');
            }
            $messageId = fogSendTelegram(
                $config,
                (string) $config['telegram_chat_id'],
                fogMorningMessage($evening, $morning)
            );
            $record['telegram_message_id'] = $messageId;
            $record['delivery'] = 'sent';
            fogSaveJson($archivePath, $record);
        } catch (Throwable $deliveryError) {
            $record['delivery'] = strpos($deliveryError->getMessage(), 'Telegram delivery returned HTTP ') === 0
                ? 'retryable' : 'uncertain';
            fogSaveJson($archivePath, $record);
            throw $deliveryError;
        }
    }
} catch (Throwable $error) {
    error_log('Fog morning check: ' . $error->getMessage());
    exit(1);
}
