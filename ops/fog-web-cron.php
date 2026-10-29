<?php
declare(strict_types=1);

// Install this file in ~/private. Cron rebuilds the data for the public /weer page;
// www/weer-data.php serves the result, so visitors never wait for the models.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__) . '/www/fog-weather.php';

const FOG_WEB_WEEKDAYS = ['zo', 'ma', 'di', 'wo', 'do', 'vr', 'za'];
const FOG_WEB_MONTHS = ['jan', 'feb', 'mrt', 'apr', 'mei', 'jun', 'jul', 'aug', 'sep', 'okt', 'nov', 'dec'];

// Turns one forecast into display-ready values. The thresholds match the Telegram
// message, so the page and the evening alert never tell a different story.
function fogWebDay(array $forecast, array $place, DateTimeZone $zone): array
{
    $date = (string) $forecast['date'];
    [$sunrise, , $firstLight] = fogSunTimes($date, (float) $place['latitude'],
        (float) $place['longitude'], $zone);
    $day = new DateTimeImmutable($date . ' 12:00', $zone);
    $result = [
        'date' => $date,
        'weekday' => FOG_WEB_WEEKDAYS[(int) $day->format('w')],
        'day_label' => $day->format('j') . ' ' . FOG_WEB_MONTHS[(int) $day->format('n') - 1],
        'sunrise' => $sunrise->format('H:i'),
        'sunrise_at' => $sunrise->format(DATE_ATOM),
        'first_light' => $firstLight->format('H:i'),
    ];
    $metrics = $forecast['metrics'] ?? null;
    if (!is_array($metrics)) {
        return $result + ['error' => 'Geen gegevens beschikbaar'];
    }

    $score = (int) $metrics['score'];
    $advice = fogAdvice($score);
    $mistPercent = (int) round((float) $metrics['mist_share'] * 100);
    $densePercent = (int) round((float) $metrics['dense_share'] * 100);
    $shares = $metrics['photo_shares'];
    $minTemp = (int) round((float) $metrics['min_temp']);
    $snow = $metrics['snow_share'];
    return $result + [
        'score' => $score,
        'advice' => $advice,
        'advice_label' => ['go' => 'Gaan', 'maybe' => 'Twijfel', 'skip' => 'Uitslapen'][$advice],
        'wake' => $advice === 'skip' ? null : fogWakeTime($firstLight)->format('H:i'),
        'reason' => (string) $metrics['photo_reason'],
        'uncertain' => $metrics['models_disagree'] && $metrics['photo_route'] === 'mist',
        // Without the local KNMI run the morning rests on the coarse ensemble alone.
        'preliminary' => $metrics['knmi'] === null,
        'mist' => $mistPercent >= 10 ? [
            'percent' => $mistPercent,
            'dense_percent' => $densePercent >= 15 ? $densePercent : null,
            'best_hour' => $metrics['best_mist_hour'] === null
                ? null : sprintf('%02d:00', $metrics['best_mist_hour']),
        ] : null,
        'ground' => (bool) $metrics['ground_favorable'],
        'forest' => (bool) $metrics['forest_favorable'],
        'rime' => $shares['rime'] >= 0.3
            ? ['percent' => (int) round($shares['rime'] * 100), 'min_temp' => $minTemp] : null,
        'dew' => $shares['rime'] < 0.3 && $shares['dew'] >= 0.5
            ? ['percent' => (int) round($shares['dew'] * 100), 'min_temp' => $minTemp] : null,
        'calm' => $shares['calm'] >= 0.6,
        'sun_percent' => (int) round((float) $metrics['sun_share'] * 100),
        'snow_percent' => is_numeric($snow) && $snow > 0 ? (int) round($snow * 100) : null,
        'weather' => (string) $metrics['weather_summary'],
    ];
}

// Builds the coming mornings. Today is included until its sunrise has passed.
function fogWebData(DateTimeImmutable $now, DateTimeZone $zone, array $place, int $mornings = 3): array
{
    [$todaySunrise] = fogSunTimes($now->format('Y-m-d'), (float) $place['latitude'],
        (float) $place['longitude'], $zone);
    // Before sunrise today counts as an extra morning, so the page can drop it
    // itself once the sun is up, without waiting for the next run.
    $first = $now < $todaySunrise ? 0 : 1;
    $days = [];
    for ($offset = $first; $offset <= $mornings; $offset++) {
        $date = $now->modify('+' . $offset . ' days')->format('Y-m-d');
        $days[] = fogWebDay(fogBuildForecast($now, $zone, $place, $date, false), $place, $zone);
    }
    return [
        'place' => $place['name'],
        'generated_at' => $now->format(DATE_ATOM),
        'days' => $days,
    ];
}

if (defined('FOG_WEB_LIBRARY_ONLY')) {
    return;
}

try {
    $zone = new DateTimeZone('Europe/Amsterdam');
    $lock = fopen(__DIR__ . '/fog-web.lock', 'c');
    if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
        exit;
    }
    $data = fogWebData(new DateTimeImmutable('now', $zone), $zone, fogDefaultPlace());
    $usable = array_filter($data['days'], static function (array $day): bool {
        return !isset($day['error']);
    });
    // Keep the previous page data when every morning failed.
    if (!$usable) {
        throw new RuntimeException('No usable mornings');
    }
    if (in_array('--preview', $argv ?? [], true)) {
        echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), "\n";
        exit;
    }
    fogSaveJson(__DIR__ . '/fog-web.json', $data);
} catch (Throwable $error) {
    error_log('Fog web data: ' . $error->getMessage());
    exit(1);
}
