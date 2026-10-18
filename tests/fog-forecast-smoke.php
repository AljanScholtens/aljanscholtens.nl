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

$zone = new DateTimeZone('Europe/Amsterdam');
$forecast = fogBuildForecast(new DateTimeImmutable('now', $zone), $zone);
$metrics = $forecast['metrics'] ?? null;
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
    || strpos($forecast['message'], '↳ Dichte mist: ') === false
    || strpos($forecast['message'], '🌤️ Kans op ochtendzon: ') === false
    || strpos($forecast['message'], $metrics['weather_summary']) === false) {
    fwrite(STDERR, "Forecast smoke check failed\n");
    exit(1);
}
echo "Forecast smoke check passed.\n";
