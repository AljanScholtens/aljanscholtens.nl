<?php
declare(strict_types=1);

$root = getenv('FOG_BOT_ROOT') ?: getenv('HOME');
require $root . '/www/fog-weather.php';

$hours = [6 => 0, 7 => 1, 8 => 2];
$clearMorning = [
    'relative_humidity_2m' => [95, 97, 97],
    'wind_speed_10m' => [5, 5, 5],
    'cloud_cover' => [20, 20, 20],
    'precipitation' => [0, 0, 0],
    'visibility' => [2000, 2000, 2000],
    'weather_code' => [0, 0, 0],
];
if (!fogGroundMistConditions($clearMorning, $hours, 8)) {
    fwrite(STDERR, "Ground mist cue missed a clear, moist, calm morning\n");
    exit(1);
}
foreach ([
    ['visibility', 2, 100],
    ['relative_humidity_2m', 1, 80],
    ['wind_speed_10m', 1, 18],
    ['cloud_cover', 0, 90],
    ['precipitation', 1, 0.3],
    ['weather_code', 2, 45],
] as [$variable, $index, $value]) {
    $unfavorable = $clearMorning;
    $unfavorable[$variable][$index] = $value;
    if (fogGroundMistConditions($unfavorable, $hours, 8)) {
        fwrite(STDERR, "Ground mist cue accepted unfavorable conditions\n");
        exit(1);
    }
}
if (!fogGroundMistFavorable(0.6, null)
    || fogGroundMistFavorable(0.59, null)
    || fogGroundMistFavorable(0.8, ['fog' => true])) {
    fwrite(STDERR, "Ground mist display threshold is incorrect\n");
    exit(1);
}
if (fogMemberSnowfall(['snowfall' => [0.04, 0.09]], '', [7 => 0, 8 => 1], [7, 8]) !== false
    || fogMemberSnowfall(['snowfall' => [0.04, 0.11]], '', [7 => 0, 8 => 1], [7, 8]) !== true
    || fogMemberSnowfall(['snowfall' => [null, 0.11]], '', [7 => 0, 8 => 1], [7, 8]) !== null) {
    fwrite(STDERR, "Snowfall signal check failed\n");
    exit(1);
}

$zone = new DateTimeZone('Europe/Amsterdam');
$forecast = fogBuildForecast(new DateTimeImmutable('now', $zone), $zone);
$metrics = $forecast['metrics'] ?? null;
$outlook = $metrics['outlook_scores'] ?? null;
$forecastDate = new DateTimeImmutable($forecast['date'] . ' 12:00', $zone);
$expectedDates = [
    $forecastDate->modify('+1 day')->format('Y-m-d'),
    $forecastDate->modify('+2 days')->format('Y-m-d'),
];
$weekdays = ['Zo', 'Ma', 'Di', 'Wo', 'Do', 'Vr', 'Za'];
$outlookLabels = [];
$snowShare = $metrics['snow_share'] ?? null;
$snowLine = strpos($forecast['message'], '❄️ Sneeuw: ') !== false;
if (is_array($outlook)) {
    foreach ($outlook as $date => $score) {
        $weekday = (int) (new DateTimeImmutable($date . ' 12:00', $zone))->format('w');
        $outlookLabels[] = $weekdays[$weekday] . ' ' . $score . '/10';
    }
}
if (!is_array($metrics)
    || !is_numeric($metrics['forest_outlook_share'] ?? null)
    || !is_numeric($metrics['ground_conditions_share'] ?? null)
    || !is_numeric($metrics['dense_share'] ?? null)
    || !is_numeric($metrics['forest_conditions_share'] ?? null)
    || (float) $metrics['forest_outlook_share'] + 0.00001
        < max((float) $metrics['dense_share'], (float) $metrics['forest_conditions_share'])
    || !is_string($metrics['weather_summary'] ?? null)
    || strpos($forecast['message'], '☀️↑ ') === false
    || strpos($forecast['message'], '☀️↓ ') === false
    || strpos($forecast['message'], '🌁 Mist: ') === false
    || ((strpos($forecast['message'], '↳ Dichte mist: ') !== false)
        !== ((int) round((float) $metrics['dense_share'] * 100) >= 15))
    || strpos($forecast['message'], '🌤️ Kans op ochtendzon: ') === false
    || ($snowShare !== null && !is_numeric($snowShare))
    || ($snowLine !== ($snowShare !== null && $snowShare > 0))
    || ($snowLine && strpos($forecast['message'], sprintf('❄️ Sneeuw: %d%%',
        (int) round((float) $snowShare * 100))) === false)
    || strpos($forecast['message'], $metrics['weather_summary']) === false
    || !is_array($outlook)
    || array_keys($outlook) !== $expectedDates
    || count(array_filter($outlook, static function ($score): bool {
        return is_int($score) && $score >= 1 && $score <= 10;
    })) !== 2
    || strpos($forecast['message'], "\n\nVooruitblik: " . implode(' · ', $outlookLabels)) === false) {
    fwrite(STDERR, "Forecast smoke check failed\n");
    exit(1);
}
echo "Forecast smoke check passed.\n";
