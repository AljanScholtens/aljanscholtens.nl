<?php
declare(strict_types=1);

$root = getenv('FOG_BOT_ROOT') ?: getenv('HOME');
require $root . '/www/fog-weather.php';

// The one night test: clear, calm and damp gives local mist and dew; frost gives rime.
$night = static function (float $cloud, float $wind, float $rain, float $humidity, float $temp): string {
    $result = fogNightConditions($cloud, $wind, $rain, $humidity, $temp);
    return (int) $result['local_mist'] . (int) $result['dew'] . (int) $result['rime'];
};
if ($night(20, 5, 0, 97, 6) !== '110'
    || $night(20, 5, 0, 97, -1) !== '101'
    || $night(20, 5, 0, 90, -1) !== '001'
    // Saturated, still air counts as possible mist even under a cloud deck.
    || $night(60, 5, 0, 97, 6) !== '100'
    || $night(100, 3, 0, 99, 9) !== '100'
    || $night(60, 7, 0, 97, 6) !== '000'
    || $night(20, 12, 0, 97, 6) !== '000'
    || $night(20, 5, 0.3, 97, 6) !== '000'
    || $night(20, 5, 0, 90, 6) !== '000') {
    fwrite(STDERR, "Night conditions check failed\n");
    exit(1);
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
// Any mist, even on a grey morning, and a fresh layer of snow are both worth at least a 7.
$greyMistInput = $windOnly;
$greyMistInput['visibility'] = array_fill(0, 5, 500);
$greyMistInput['weather_code'] = array_fill(0, 5, 45);
$greyMistInput['wind_speed_10m'] = array_fill(0, 5, 3);
$greyMistInput['wind_gusts_10m'] = array_fill(0, 5, 10);
$greyMistInput['relative_humidity_2m'] = array_fill(0, 5, 99);
$greyMistDay = fogPhotoDayScore(array_fill(0, 31, fogMemberPhotoScenes($greyMistInput,
    $photoIndices, 8, fogMemberPhotoSignals($greyMistInput, $photoIndices, 8))));
$freshSnowInput = $windOnly;
$freshSnowInput['fresh_snow'] = 10.0;
$freshSnowDay = fogPhotoDayScore(array_fill(0, 31, fogMemberPhotoScenes($freshSnowInput,
    $photoIndices, 8, fogMemberPhotoSignals($freshSnowInput, $photoIndices, 8))));
$oldSnowInput = $windOnly;
$oldSnowInput['snow_depth'] = array_fill(0, 5, 0.05);
$oldSnowDay = fogPhotoDayScore(array_fill(0, 31, fogMemberPhotoScenes($oldSnowInput,
    $photoIndices, 8, fogMemberPhotoSignals($oldSnowInput, $photoIndices, 8))));
if ($greyMistDay['score'] !== 7 || $greyMistDay['route'] !== 'mist'
    || $freshSnowDay['score'] !== 7 || $freshSnowDay['route'] !== 'winter'
    || $freshSnowDay['fresh_snow_cm'] !== 10.0
    || $oldSnowDay['score'] !== 5 || $oldSnowDay['fresh_snow_cm'] !== 0.0) {
    fwrite(STDERR, "Mist and fresh snow minimum score check failed\n");
    exit(1);
}
$hourlyNight = ['time' => ['2026-12-10T18:00', '2026-12-10T23:00', '2026-12-11T03:00', '2026-12-11T08:00'],
    'snowfall' => [0.0, 4.0, 5.5, 0.5]];
if (fogMemberFreshSnow($hourlyNight, '', '2026-12-11', 8) !== 10.0
    || fogMemberFreshSnow(['time' => ['2026-12-11T08:00'], 'snowfall' => [null]], '', '2026-12-11', 8) !== null) {
    fwrite(STDERR, "Fresh snow total check failed\n");
    exit(1);
}
// Colour: mid or high cloud to light up and little low cloud, in the hour before
// or at sunrise. A closed high deck or a low grey layer blocks it.
$clouds = ['time' => ['2026-12-11T07:00', '2026-12-11T08:00']];
$layers = [
    '' => [[10, 50, 0], [10, 50, 0]],
    '_member01' => [[10, 0, 95], [10, 0, 95]],
    '_member02' => [[80, 50, 50], [80, 50, 50]],
    '_member03' => [[90, 0, 0], [20, 0, 30]],
];
foreach ($layers as $suffix => $hoursOfCloud) {
    foreach (['low', 'mid', 'high'] as $layer => $name) {
        $clouds['cloud_cover_' . $name . $suffix] = array_column($hoursOfCloud, $layer);
    }
}
// The other 27 scenarios are grey: a closed low deck.
for ($member = 4; $member <= 30; $member++) {
    $suffix = sprintf('_member%02d', $member);
    $clouds['cloud_cover_low' . $suffix] = [100, 100];
    $clouds['cloud_cover_mid' . $suffix] = [100, 100];
    $clouds['cloud_cover_high' . $suffix] = [0, 0];
}
$colorShare = fogColorShare($clouds, '2026-12-11', 8);
if (abs($colorShare - 2 / 31) > 0.0001 || fogColorShare(null, '2026-12-11', 8) !== null
    || fogColorShare($clouds, '2026-12-12', 8) !== null
    || fogBlendColor(4, 1.0, false) !== 7 || fogBlendColor(4, 1.0, true) !== 8
    || fogBlendColor(4, 0.5, false) !== 6 || fogBlendColor(4, 0.0, false) !== 4
    || fogBlendColor(8, 1.0, false) !== 8 || fogBlendColor(4, null, false) !== 4) {
    fwrite(STDERR, "Colour chance check failed\n");
    exit(1);
}
// Mist that lifts into sunlight or freezes is worth saying in the alert.
$messageMetrics = ['score' => 8, 'photo_route' => 'mist', 'photo_reason' => 'test',
    'models_disagree' => false, 'knmi' => null, 'mist_share' => 0.6, 'dense_share' => 0.0,
    'local_mist' => false, 'best_mist_hour' => null, 'lifting_share' => 0.3,
    'freezing_mist_share' => 0.4, 'photo_shares' => ['rime' => 0.0, 'dew' => 0.0, 'calm' => 0.0,
        'fresh_snow' => 0.0], 'min_temp' => 0.0, 'fresh_snow_cm' => 0.0, 'sun_share' => 0.5,
    'snow_share' => null, 'color_share' => null, 'weather_icon' => '⛅',
    'weather_summary' => 'Droog', 'outlook_scores' => []];
$testMessage = fogForecastMessage($messageMetrics, new DateTimeImmutable('2026-12-11 08:40', $roundZone),
    new DateTimeImmutable('2026-12-11 08:00', $roundZone), $roundZone);
$messageMetrics['mist_share'] = 0.05;
$messageMetrics['local_mist'] = true;
$messageMetrics['saturated'] = true;
$saturatedMessage = fogForecastMessage($messageMetrics, new DateTimeImmutable('2026-12-11 08:40', $roundZone),
    new DateTimeImmutable('2026-12-11 08:00', $roundZone), $roundZone);
$messageMetrics['photo_route'] = 'sky';
$messageMetrics['models_disagree'] = true;
$messageMetrics['sun_or_mist'] = true;
$messageMetrics['knmi'] = ['fog' => false];
if (strpos(fogForecastMessage($messageMetrics, new DateTimeImmutable('2026-12-11 08:40', $roundZone),
        new DateTimeImmutable('2026-12-11 08:00', $roundZone), $roundZone), 'Cijfer: 8/10 (onzeker)') === false) {
    fwrite(STDERR, "Sun or mist disagreement should be marked uncertain\n");
    exit(1);
}
$messageMetrics['photo_route'] = 'mist';
$messageMetrics['models_disagree'] = false;
$messageMetrics['sun_or_mist'] = false;
$messageMetrics['knmi'] = null;
$messageMetrics['local_mist'] = false;
$messageMetrics['saturated'] = false;
if (strpos($saturatedMessage, '🌁 Mist mogelijk: verzadigde, windstille lucht') === false) {
    fwrite(STDERR, "Saturated air line is missing\n");
    exit(1);
}
$noMistMessage = fogForecastMessage($messageMetrics, new DateTimeImmutable('2026-12-11 08:40', $roundZone),
    new DateTimeImmutable('2026-12-11 08:00', $roundZone), $roundZone);
if (strpos($testMessage, "🌁 Mist: 60% (zicht <1 km)\n↳ Trekt op in zonlicht\n↳ Bevroren mist: rijp op alles") === false
    || strpos($noMistMessage, 'Trekt op') !== false || strpos($noMistMessage, 'Bevroren') !== false) {
    fwrite(STDERR, "Lifting and freezing mist lines are wrong\n");
    exit(1);
}
// Dew point spread: saturated air has none, 10 degrees at 50% is about 10 degrees apart.
if (abs(fogDewPointSpread(10.0, 100.0)) > 0.01 || abs(fogDewPointSpread(10.0, 50.0) - 10.0) > 0.3
    || fogMedian([3.0, 1.0, 2.0]) !== 2.0 || fogMedian([]) !== null) {
    fwrite(STDERR, "Dew point spread or median check failed\n");
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
    || !is_numeric($metrics['local_mist_share'] ?? null)
    || !is_bool($metrics['local_mist'] ?? null)
    || !array_key_exists('color_share', $metrics)
    || !in_array($metrics['conditions']['source'] ?? null, ['KNMI', 'GFS'], true)
    || !is_numeric($metrics['conditions']['humidity'] ?? null)
    || !is_numeric($metrics['conditions']['spread'] ?? null)
    || ((strpos($forecast['message'], '🌅 Kleur in de lucht: ') !== false)
        !== ($metrics['color_share'] !== null && $metrics['color_share'] >= 0.2))
    || !is_numeric($metrics['dense_share'] ?? null)
    || !is_string($metrics['weather_summary'] ?? null)
    || ($metrics['score_version'] ?? null) !== 8
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
