<?php
declare(strict_types=1);

$root = getenv('FOG_BOT_ROOT') ?: getenv('HOME');
require $root . '/www/fog-weather.php';

$zone = new DateTimeZone('Europe/Amsterdam');
$forecast = fogBuildForecast(new DateTimeImmutable('now', $zone), $zone);
$metrics = $forecast['metrics'] ?? null;
if (!is_array($metrics)
    || !is_numeric($metrics['forest_outlook_share'] ?? null)
    || !is_numeric($metrics['dense_share'] ?? null)
    || !is_numeric($metrics['forest_conditions_share'] ?? null)
    || (float) $metrics['forest_outlook_share'] + 0.00001
        < max((float) $metrics['dense_share'], (float) $metrics['forest_conditions_share'])
    || !is_string($metrics['weather_summary'] ?? null)
    || strpos($forecast['message'], 'Bosmist-signaal:') === false
    || strpos($forecast['message'], $metrics['weather_summary']) === false) {
    fwrite(STDERR, "Forecast smoke check failed\n");
    exit(1);
}
echo "Forecast smoke check passed.\n";
