<?php
declare(strict_types=1);

define('FOG_WEB_LIBRARY_ONLY', true);
$root = getenv('FOG_BOT_ROOT') ?: getenv('HOME');
require rtrim($root, '/') . '/private/fog-web-cron.php';

function webCheck(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(fopen('php://stderr', 'w'), $message . "\n");
        exit(1);
    }
}

$zone = new DateTimeZone('Europe/Amsterdam');
$place = fogDefaultPlace();
$metrics = [
    'score' => 8, 'photo_reason' => 'mist kan optrekken in ochtendlicht',
    'models_disagree' => false, 'photo_route' => 'mist', 'knmi' => ['fog' => true],
    'mist_share' => 0.55, 'dense_share' => 0.2, 'best_mist_hour' => 8,
    'ground_favorable' => false, 'forest_favorable' => true,
    'photo_shares' => ['rime' => 0.7, 'dew' => 0.0, 'calm' => 0.8],
    'min_temp' => -3.2, 'sun_share' => 0.6, 'snow_share' => 0.0,
    'weather_summary' => 'Droog',
];
$day = fogWebDay(['date' => '2026-12-11', 'metrics' => $metrics], $place, $zone);
webCheck($day['weekday'] === 'vr' && $day['day_label'] === '11 dec', 'Day label is wrong');
webCheck($day['advice'] === 'go' && $day['advice_label'] === 'Gaan'
    && is_string($day['wake']) && $day['wake'] < $day['first_light'], 'Advice or alarm is wrong');
webCheck($day['mist'] === ['percent' => 55, 'dense_percent' => 20, 'best_hour' => '08:00'],
    'Mist block is wrong');
webCheck($day['rime'] === ['percent' => 70, 'min_temp' => -3] && $day['dew'] === null
    && $day['calm'] === true && $day['forest'] === true && $day['preliminary'] === false
    && $day['snow_percent'] === null, 'Extra signals are wrong');

// Quiet mornings hide the details the Telegram message also leaves out.
$quiet = $metrics;
$quiet['score'] = 2;
$quiet['mist_share'] = 0.05;
$quiet['dense_share'] = 0.0;
$quiet['photo_shares'] = ['rime' => 0.1, 'dew' => 0.2, 'calm' => 0.3];
$quiet['knmi'] = null;
$quietDay = fogWebDay(['date' => '2026-12-11', 'metrics' => $quiet], $place, $zone);
webCheck($quietDay['advice'] === 'skip' && $quietDay['wake'] === null
    && $quietDay['mist'] === null && $quietDay['rime'] === null && $quietDay['dew'] === null
    && $quietDay['calm'] === false && $quietDay['preliminary'] === true, 'Quiet morning is wrong');

$failed = fogWebDay(['date' => '2026-12-11', 'metrics' => null], $place, $zone);
webCheck(isset($failed['error']) && !isset($failed['score']), 'Missing data should show an error');

// The live run should deliver the coming mornings, starting today or tomorrow.
$now = new DateTimeImmutable('now', $zone);
$data = fogWebData($now, $zone, $place);
$dates = array_column($data['days'], 'date');
webCheck($data['place'] === 'Vries' && count($dates) >= 3 && count($dates) <= 4
    && $dates[0] >= $now->format('Y-m-d')
    && $dates[count($dates) - 1] === $now->modify('+3 days')->format('Y-m-d'),
    'Live web data has the wrong mornings');
webCheck(isset($data['days'][0]['score']) && !isset($data['days'][0]['error']),
    'Live web data for the first morning failed');
echo "Web data checks passed.\n";
