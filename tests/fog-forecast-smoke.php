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
$roundZone = new DateTimeZone('Europe/Amsterdam');
if (fogSunriseHour(new DateTimeImmutable('2026-12-21 08:50', $roundZone)) !== 9
    || fogSunriseHour(new DateTimeImmutable('2026-10-28 07:29', $roundZone)) !== 7) {
    fwrite(STDERR, "Sunrise hour is not rounded to the nearest hour\n");
    exit(1);
}
$adviceLight = new DateTimeImmutable('2026-10-28 06:43', $roundZone);
if (fogAdviceLine(3, $adviceLight) !== '⚪ Neutraal'
    || fogAdviceLine(4, $adviceLight) !== '🟡 Prima — wekker 05:55 · eerste licht 06:43'
    || fogAdviceLine(6, $adviceLight) !== '🟢 Uitstekend — wekker 05:55 · eerste licht 06:43'
    || fogLikelihood(1) !== 'zeer waarschijnlijk' || fogLikelihood(0.5) !== 'waarschijnlijk'
    || fogLikelihood(0.2) !== 'mogelijk' || fogLikelihood(0.19) !== 'weinig kans') {
    fwrite(STDERR, "Advice line check failed\n");
    exit(1);
}
// The morning check (an hour before first light) must stay inside the 03:00 cron window.
$vries = fogDefaultPlace();
[, , $midsummerLight] = fogSunTimes('2026-06-21', $vries['latitude'], $vries['longitude'], $roundZone);
if ($midsummerLight->modify('-' . (FOG_WAKE_MINUTES_BEFORE_FIRST_LIGHT + 15) . ' minutes')->format('H:i') < '03:00') {
    fwrite(STDERR, "Midsummer morning check falls before the cron window\n");
    exit(1);
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
$photoIndices = [6 => 0, 7 => 1, 8 => 2, 9 => 3, 10 => 4];
$windOnly = [
    'visibility' => array_fill(0, 5, 10000),
    'weather_code' => array_fill(0, 5, 3),
    'cloud_cover' => array_fill(0, 5, 95),
    'precipitation' => array_fill(0, 5, 0),
    'relative_humidity_2m' => array_fill(0, 5, 80),
    'wind_speed_10m' => array_fill(0, 5, 30),
    'temperature_2m' => array_fill(0, 5, 5),
    'snowfall' => array_fill(0, 5, 0),
    'snow_depth' => array_fill(0, 5, 0),
    'wind_gusts_10m' => array_fill(0, 5, 65),
    'sunshine_duration' => array_fill(0, 5, 0),
];
$windSignals = fogMemberPhotoSignals($windOnly, $photoIndices, 8);
$windScene = fogMemberPhotoScenes($windOnly, $photoIndices, 8, $windSignals);
$snowSceneInput = $windOnly;
$snowSceneInput['weather_code'] = [3, 3, 71, 71, 2];
$snowSceneInput['cloud_cover'] = [90, 70, 45, 50, 55];
$snowSceneInput['precipitation'] = [0, 0, 0.2, 0.2, 0];
$snowSceneInput['snowfall'] = [0, 0, 0.2, 0.2, 0];
$snowSceneInput['snow_depth'] = array_fill(0, 5, 0.03);
$snowSceneInput['sunshine_duration'] = [0, 0, 0, 600, 300];
$snowSignals = fogMemberPhotoSignals($snowSceneInput, $photoIndices, 8);
$snowScene = fogMemberPhotoScenes($snowSceneInput, $photoIndices, 8, $snowSignals);
$skySceneInput = $windOnly;
$skySceneInput['weather_code'] = [3, 3, 80, 2, 3];
$skySceneInput['cloud_cover'] = [85, 70, 35, 50, 65];
$skySceneInput['precipitation'] = [0, 0, 0.2, 0, 0];
$skySceneInput['sunshine_duration'] = [0, 0, 0, 600, 0];
$skySignals = fogMemberPhotoSignals($skySceneInput, $photoIndices, 8);
$skyScene = fogMemberPhotoScenes($skySceneInput, $photoIndices, 8, $skySignals);
$earlySnowInput = $windOnly;
$earlySnowInput['snowfall'] = [0.2, 0, 0, 0, 0];
$earlySnowScene = fogMemberPhotoScenes($earlySnowInput, $photoIndices, 8,
    fogMemberPhotoSignals($earlySnowInput, $photoIndices, 8));
$separateWeatherInput = $windOnly;
$separateWeatherInput['weather_code'] = [80, 3, 3, 3, 3];
$separateWeatherInput['cloud_cover'] = [50, 95, 95, 95, 50];
$separateWeatherInput['precipitation'] = [0.2, 0, 0, 0, 0];
$separateWeatherInput['sunshine_duration'] = [0, 0, 0, 0, 600];
$separateWeatherScene = fogMemberPhotoScenes($separateWeatherInput, $photoIndices, 8,
    fogMemberPhotoSignals($separateWeatherInput, $photoIndices, 8));
if (fogPhotoDayScore(array_fill(0, 31, $windScene))['score'] > 3
    || fogPhotoDayScore(array_fill(0, 31, $snowScene))['score'] < 9
    || fogPhotoDayScore(array_fill(0, 31, $skyScene))['score'] < 9
    || fogPhotoDayScore(array_fill(0, 31, $earlySnowScene))['score'] > 3
    || fogPhotoDayScore(array_fill(0, 31, $separateWeatherScene))['score'] > 8
    || fogPhotoDayScore(array_merge(array_fill(0, 16, $snowScene),
        array_fill(0, 15, $windScene)))['score'] > 6) {
    fwrite(STDERR, "Photo scenario score check failed\n");
    exit(1);
}
$sunnyCalmInput = $windOnly;
$sunnyCalmInput['cloud_cover'] = array_fill(0, 5, 10);
$sunnyCalmInput['wind_speed_10m'] = array_fill(0, 5, 3);
$sunnyCalmInput['wind_gusts_10m'] = array_fill(0, 5, 10);
$sunnyCalmInput['relative_humidity_2m'] = array_fill(0, 5, 70);
$sunnyCalmInput['temperature_2m'] = array_fill(0, 5, 8);
$sunnyCalmInput['sunshine_duration'] = array_fill(0, 5, 3600);
$sunnyCalmScene = fogMemberPhotoScenes($sunnyCalmInput, $photoIndices, 8,
    fogMemberPhotoSignals($sunnyCalmInput, $photoIndices, 8));
$rimeInput = $sunnyCalmInput;
$rimeInput['temperature_2m'] = array_fill(0, 5, -3);
$rimeInput['relative_humidity_2m'] = array_fill(0, 5, 92);
$rimeScene = fogMemberPhotoScenes($rimeInput, $photoIndices, 8,
    fogMemberPhotoSignals($rimeInput, $photoIndices, 8));
$sunnyCalmDay = fogPhotoDayScore(array_fill(0, 31, $sunnyCalmScene));
$rimeDay = fogPhotoDayScore(array_fill(0, 31, $rimeScene));
if ($sunnyCalmDay['score'] !== 5 || $sunnyCalmDay['shares']['calm'] != 1
    || $sunnyCalmDay['shares']['rime'] != 0
    || $rimeDay['score'] !== 8 || $rimeDay['route'] !== 'frost'
    || $rimeDay['shares']['rime'] != 1 || $rimeDay['min_temp'] !== -3.0) {
    fwrite(STDERR, "Rime and sunny calm morning check failed\n");
    exit(1);
}

$zone = new DateTimeZone('Europe/Amsterdam');
$place = fogDefaultPlace();
$outlookDates = ['2026-10-28', '2026-10-29'];
$syntheticHourly = ['time' => []];
foreach ($outlookDates as $date) {
    [$sunrise] = fogSunTimes($date, $place['latitude'], $place['longitude'], $zone);
    $sunriseHour = fogSunriseHour($sunrise);
    foreach (range($sunriseHour - 2, $sunriseHour + 2) as $hour) {
        $syntheticHourly['time'][] = $date . 'T' . sprintf('%02d', $hour) . ':00';
    }
}
$suffixes = [''];
for ($member = 1; $member <= 30; $member++) {
    $suffixes[] = sprintf('_member%02d', $member);
}
foreach ($suffixes as $suffix) {
    foreach (array_keys($windOnly) as $variable) {
        $syntheticHourly[$variable . $suffix] = array_merge(
            $snowSceneInput[$variable], $windOnly[$variable]);
    }
}
$coreVariables = ['visibility', 'weather_code', 'cloud_cover', 'precipitation',
    'relative_humidity_2m', 'wind_speed_10m', 'temperature_2m'];
$syntheticOutlook = fogOutlookScores($syntheticHourly, $suffixes, $coreVariables,
    '2026-10-27', $place['latitude'], $place['longitude'], $zone);
if ($syntheticOutlook['2026-10-28'] < 9 || $syntheticOutlook['2026-10-29'] > 3) {
    fwrite(STDERR, "General photo outlook score check failed\n");
    exit(1);
}

$forecast = fogBuildForecast(new DateTimeImmutable('now', $zone), $zone);
$metrics = $forecast['metrics'] ?? null;
$outlook = $metrics['outlook_scores'] ?? null;
$forecastDate = new DateTimeImmutable($forecast['date'] . ' 12:00', $zone);
[, , $forecastFirstLight] = fogSunTimes($forecast['date'], $place['latitude'], $place['longitude'], $zone);
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
    || ($metrics['score_version'] ?? null) !== 4
    || !in_array($metrics['photo_route'] ?? null, ['mist', 'winter', 'sky', 'frost'], true)
    || !is_string($metrics['photo_reason'] ?? null)
    || strpos($forecast['message'], '☀️↑ ') === false
    || strpos($forecast['message'], '☀️↓ ') !== false
    || ((strpos($forecast['message'], '🌁 Mist: ') !== false)
        !== ((int) round((float) $metrics['mist_share'] * 100) >= 10))
    || strpos($forecast['message'], fogAdviceLine((int) $metrics['score'], $forecastFirstLight)) !== 0
    || strpos($forecast['message'], 'KNMI') !== false
    || ((strpos($forecast['message'], '↳ Dichte mist: ') !== false)
        !== ((int) round((float) $metrics['dense_share'] * 100) >= 15))
    || strpos($forecast['message'], '🌤️ Ochtendzon: ' . fogLikelihood((float) $metrics['sun_share'])) === false
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
