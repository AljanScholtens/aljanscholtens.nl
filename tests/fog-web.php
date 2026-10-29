<?php
declare(strict_types=1);

$root = getenv('FOG_BOT_ROOT') ?: getenv('HOME');
require rtrim($root, '/') . '/www/fog-web.php';

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
    'local_mist' => true,
    'fresh_snow_share' => 0.6, 'fresh_snow_cm' => 9.6,
    'photo_shares' => ['rime' => 0.7, 'dew' => 0.0, 'calm' => 0.8],
    'min_temp' => -3.2, 'sun_share' => 0.6, 'snow_share' => 0.0,
    'weather_summary' => 'Droog',
];
$day = fogWebDay(['date' => '2026-12-11', 'metrics' => $metrics], $place, $zone);
webCheck($day['weekday'] === 'vr' && $day['day_label'] === '11 dec', 'Day label is wrong');
webCheck($day['advice'] === 'go' && $day['advice_label'] === 'Uitstekend'
    && is_string($day['wake']) && $day['wake'] < $day['first_light'], 'Advice or alarm is wrong');
webCheck($day['mist'] === ['percent' => 55, 'dense_percent' => 20, 'best_hour' => '08:00'],
    'Mist block is wrong');
webCheck($day['rime'] === ['percent' => 70, 'min_temp' => -3] && $day['dew'] === null
    && $day['calm'] === true && $day['local_mist'] === true && $day['fresh_snow_cm'] === 10
    && $day['preliminary'] === false
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

// Duplicate place names need a province; the province must then pick one.
webCheck(fogFindDutchPlace('Hengelo')['status'] === 'ambiguous', 'Hengelo should be ambiguous');
$gelderland = fogFindDutchPlace('Hengelo, Gelderland');
webCheck($gelderland['status'] === 'ok' && $gelderland['place']['province'] === 'Gelderland',
    'A province should settle a duplicate place name');

// Search, cache and limits work in their own folder.
$privateDir = rtrim($root, '/') . '/private/test-web-' . bin2hex(random_bytes(6));
mkdir($privateDir, 0700);
try {
    webCheck(fogWebSearch('<script>', $privateDir, $now, $zone, 'a')['status'] === 'invalid',
        'Markup should be rejected');
    $choose = fogWebSearch('Hengelo', $privateDir, $now, $zone, 'a');
    webCheck($choose['status'] === 'choose'
        && in_array('Hengelo, Gelderland', array_column($choose['places'], 'query'), true),
        'Ambiguous search should offer choices');
    // Both places called Landgraaf lie in Limburg, so the municipality must tell them apart.
    $landgraaf = fogWebSearch('Landgraaf', $privateDir, $now, $zone, 'a');
    $queries = array_column($landgraaf['places'] ?? [], 'query');
    webCheck($landgraaf['status'] === 'choose'
        && in_array('Landgraaf, Sittard-Geleen', $queries, true)
        && in_array('Landgraaf (gemeente Sittard-Geleen)', array_column($landgraaf['places'], 'label'), true),
        'Landgraaf choices should name the municipality');
    foreach ($queries as $query) {
        webCheck(fogWebSearch($query, $privateDir, $now, $zone, 'a')['status'] === 'ok',
            'Each Landgraaf choice should lead to a forecast: ' . $query);
    }
    $assen = fogWebSearch('Assen', $privateDir, $now, $zone, 'a');
    webCheck($assen['status'] === 'ok' && $assen['data']['place'] === 'Assen'
        && count($assen['data']['days']) >= 3, 'Assen should get a forecast');
    $calculations = static function () use ($privateDir): int {
        return json_decode((string) file_get_contents($privateDir . '/fog-web-limits.json'),
            true)['calculations'];
    };
    $before = $calculations();
    $again = fogWebSearch('assen', $privateDir, $now, $zone, 'b');
    webCheck($again['status'] === 'ok' && $calculations() === $before,
        'A second search should use the cache');
    for ($i = 0; $i < FOG_WEB_SEARCHES_PER_CLIENT_HOUR; $i++) {
        fogWebAllow($privateDir, 'c', 'search', $now);
    }
    webCheck(fogWebSearch('Assen', $privateDir, $now, $zone, 'c')['status'] === 'limited'
        && fogWebSearch('Assen', $privateDir, $now, $zone, 'd')['status'] === 'ok',
        'The search limit should apply per visitor');
} finally {
    foreach (array_merge(glob($privateDir . '/fog-web-cache/*') ?: [], glob($privateDir . '/*.*') ?: []) as $path) {
        @unlink($path);
    }
    @rmdir($privateDir . '/fog-web-cache');
    @rmdir($privateDir);
}
echo "Web data checks passed.\n";
