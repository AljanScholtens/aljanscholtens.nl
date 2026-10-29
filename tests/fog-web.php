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
    'lifting_share' => 0.3, 'freezing_mist_share' => 0.4, 'local_mist' => true,
    'fresh_snow_share' => 0.6, 'fresh_snow_cm' => 9.6, 'color_share' => 0.5,
    'photo_shares' => ['rime' => 0.7, 'dew' => 0.0, 'calm' => 0.8, 'dynamic_sky' => 0.1,
        'sky_light' => 0.0, 'sky_wind' => 0.0, 'snow_cover' => 0.6, 'snow_wind_sky' => 0.0],
    'min_temp' => -3.2, 'sun_share' => 0.6, 'snow_share' => 0.0,
    'weather_summary' => 'Droog',
];
$day = fogWebDay(['date' => '2026-12-11', 'metrics' => $metrics], $place, $zone);
webCheck($day['weekday'] === 'vr' && $day['day_label'] === '11 dec', 'Day label is wrong');
webCheck($day['advice'] === 'go' && $day['advice_label'] === 'Uitstekend'
    && is_string($day['wake']) && $day['wake'] < $day['first_light']
    && $day['preliminary'] === false, 'Advice or alarm is wrong');
$byKey = static function (array $day): array {
    return array_column($day['categories'], null, 'key');
};
$categories = $byKey($day);
// Always six categories in the same order, with at most two notes each.
webCheck(array_keys($categories) === ['mist', 'color', 'sky', 'sun', 'frost', 'snow'],
    'Categories should come in a fixed order');
webCheck($categories['mist']['value'] === '55%' && $categories['mist']['lead'] === true
    && $categories['mist']['notes'] === ['bevroren mist', 'trekt op in zonlicht'],
    'Mist category is wrong');
webCheck($categories['color']['value'] === 'waarschijnlijk' && $categories['color']['lead'] === false
    && $categories['sky']['value'] === null
    && $categories['sun']['value'] === 'waarschijnlijk'
    && $categories['sun']['notes'] === ['windstil · spiegelingen']
    && $categories['frost']['value'] === 'rijp waarschijnlijk'
    && $categories['frost']['notes'] === ['min −3°', 'strenge vorst']
    && $categories['snow']['value'] === 'vers, ongeveer 10 cm', 'Other categories are wrong');

// A quiet sunny morning: no mist, the sun is the lead, empty categories stay empty.
$quiet = $metrics;
$quiet['score'] = 4;
$quiet['photo_route'] = 'sky';
$quiet['mist_share'] = 0.05;
$quiet['dense_share'] = 0.0;
$quiet['local_mist'] = false;
$quiet['color_share'] = null;
$quiet['fresh_snow_share'] = 0.0;
$quiet['photo_shares'] = ['rime' => 0.1, 'dew' => 0.2, 'calm' => 0.3, 'dynamic_sky' => 0.1,
    'sky_light' => 0.0, 'sky_wind' => 0.0, 'snow_cover' => 0.0, 'snow_wind_sky' => 0.0];
$quiet['knmi'] = null;
$quietDay = fogWebDay(['date' => '2026-12-11', 'metrics' => $quiet], $place, $zone);
$quietCategories = $byKey($quietDay);
webCheck($quietDay['advice'] === 'maybe' && $quietDay['preliminary'] === true
    && $quietCategories['sun']['lead'] === true
    && $quietCategories['mist']['value'] === null && $quietCategories['color']['value'] === null
    && $quietCategories['frost']['value'] === null && $quietCategories['snow']['value'] === null,
    'Quiet morning is wrong');
$saturatedDay = $quiet;
$saturatedDay['saturated'] = true;
$saturatedDay['local_mist'] = true;
$saturatedMist = $byKey(fogWebDay(['date' => '2026-12-11', 'metrics' => $saturatedDay], $place, $zone))['mist'];
webCheck($saturatedMist['value'] === 'mogelijk'
    && $saturatedMist['notes'] === ['verzadigde, windstille lucht'], 'Saturated air should show as possible mist');
$neutral = $quiet;
$neutral['score'] = 2;
webCheck(fogWebDay(['date' => '2026-12-11', 'metrics' => $neutral], $place, $zone)['wake'] === null,
    'A neutral morning needs no alarm');

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
