<?php
declare(strict_types=1);

// Shared forecast and delivery code for the evening job and Telegram bot.

/*
 * Thresholds, kept together so they can be tuned against feedback later.
 */
// Mist: visibility below 1 km with a fog weather code; dense mist below 200 m.
const FOG_MIST_VISIBILITY_M = 1000.0;
const FOG_DENSE_VISIBILITY_M = 200.0;
const FOG_WEATHER_CODES = [45, 48];
// The one night test, an hour before sunrise: clear, calm and damp. It is the cue
// for local mist the coarse model cannot see, for dew and, when cold, for rime.
const FOG_NIGHT_MAX_CLOUD = 40.0;
const FOG_NIGHT_MAX_WIND_KMH = 8.0;
const FOG_NIGHT_MIN_HUMIDITY = 95.0;
// Near freezing the air saturates over ice sooner, so rime needs less humidity.
const FOG_NIGHT_MIN_HUMIDITY_FROST = 85.0;
// 2 m air is often a degree warmer than the grass, hence frost from 1 degree.
const FOG_FROST_MAX_TEMP = 1.0;
const FOG_HARD_FROST_TEMP = -2.0;
// Morning light: after sunrise, few clouds and a clear view.
const FOG_LIGHT_MAX_CLOUD = 50.0;
const FOG_LIGHT_MIN_VISIBILITY_M = 1500.0;
const FOG_CALM_MAX_WIND_KMH = 5.0;
const FOG_STORM_GUSTS_KMH = 55.0;
const FOG_WET_MM = 0.1;
const FOG_SNOW_COVER_M = 0.02;
const FOG_FRESH_SNOW_CM = 3.0;
// Saturated, nearly still air at the ground is mist the models often show as low
// cloud, with too good a visibility. It counts as possible mist whatever the sky.
const FOG_SATURATED_MIN_HUMIDITY = 97.0;
const FOG_SATURATED_MAX_WIND_KMH = 5.0;
// When KNMI sees it, the morning is at least a 5: it may lie on the ground as mist
// or hang a hundred metres up as a grey deck, which the model cannot tell apart.
const FOG_SATURATED_SCORE = 5;
// Without the local KNMI run, local mist needs half of the GFS scenarios.
const FOG_LOCAL_MIST_SHARE = 0.5;
// Colour at sunrise: mid or high cloud to light up, but not a closed deck, and
// little low cloud in the way. A colourful sky is worth at least a 7, like mist.
const FOG_COLOR_MIN_UPPER_CLOUD = 20.0;
const FOG_COLOR_MAX_UPPER_CLOUD = 80.0;
const FOG_COLOR_MAX_LOW_CLOUD = 40.0;
const FOG_COLOR_SCORE = 7;
function fogRequest(string $url, ?string $body = null, array $headers = [], int $timeout = 30): array
{
    // Several mornings share one model download; remember successful reads for this run.
    static $memo = [];
    if ($body === null && isset($memo[$url])) {
        return $memo[$url];
    }
    // Tests replay recorded model downloads: FOG_FIXTURES names a folder of saved answers.
    $fixtures = $body === null ? getenv('FOG_FIXTURES') : false;
    if (is_string($fixtures) && $fixtures !== '') {
        $path = $fixtures . '/' . sha1($url) . '.json';
        if (is_file($path)) {
            return [200, (string) file_get_contents($path)];
        }
        $result = fogRequestUncached($url, $body, $headers, $timeout);
        if ($result[0] === 200) {
            file_put_contents($path, $result[1]);
        }
        return $result;
    }
    $result = fogRequestUncached($url, $body, $headers, $timeout);
    if ($body === null && $result[0] === 200) {
        $memo[$url] = $result;
    }
    return $result;
}

function fogRequestUncached(string $url, ?string $body, array $headers, int $timeout): array
{
    if (function_exists('curl_init')) {
        $curl = curl_init($url);
        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_HTTPHEADER => $headers,
        ]);
        if ($body !== null) {
            curl_setopt($curl, CURLOPT_POST, true);
            curl_setopt($curl, CURLOPT_POSTFIELDS, $body);
        }
        $response = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $error = curl_error($curl);
        curl_close($curl);
        if ($response === false) {
            throw new RuntimeException('HTTP request failed: ' . $error);
        }
        return [$status, $response];
    }

    $options = [
        'http' => [
            'method' => $body === null ? 'GET' : 'POST',
            'header' => implode("\r\n", $headers),
            'content' => $body ?? '',
            'timeout' => $timeout,
            'ignore_errors' => true,
        ],
    ];
    $response = @file_get_contents($url, false, stream_context_create($options));
    if ($response === false) {
        throw new RuntimeException('HTTP request failed');
    }
    $statusLine = $http_response_header[0] ?? '';
    preg_match('/^HTTP\/\S+\s+(\d{3})/', $statusLine, $match);
    return [(int) ($match[1] ?? 0), $response];
}

function fogDefaultPlace(): array
{
    return ['name' => 'Vries', 'latitude' => 53.07165252, 'longitude' => 6.57674584];
}

// Accepts "Assen", "Assen, Drenthe" or "Landgraaf, Sittard-Geleen": the part after
// the comma narrows duplicate names down by province or, within one province, by
// municipality. Ambiguous results carry that qualifier for each candidate.
function fogFindDutchPlace(string $query): array
{
    $parts = explode(',', $query, 2);
    $name = trim($parts[0]);
    $qualifier = trim($parts[1] ?? '');
    $url = 'https://geocoding-api.open-meteo.com/v1/search?'
        . http_build_query([
            'name' => $name,
            'count' => 20,
            'language' => 'nl',
            'countryCode' => 'NL',
            'format' => 'json',
        ], '', '&', PHP_QUERY_RFC3986);
    [$status, $response] = fogRequest($url);
    if ($status !== 200) {
        throw new RuntimeException('Place search returned HTTP ' . $status);
    }
    $data = json_decode($response, true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($data)) {
        throw new RuntimeException('Place search returned invalid data');
    }
    $results = $data['results'] ?? [];
    if (!is_array($results)) {
        throw new RuntimeException('Place search has no result list');
    }

    $fold = static function (string $value): string {
        return function_exists('mb_strtolower')
            ? mb_strtolower($value, 'UTF-8')
            : strtolower($value);
    };
    $matches = [];
    $suggestions = [];
    foreach ($results as $candidate) {
        if (!is_array($candidate) || !is_string($candidate['name'] ?? null)
            || !is_numeric($candidate['latitude'] ?? null)
            || !is_numeric($candidate['longitude'] ?? null)) {
            continue;
        }
        $place = [
            'name' => $candidate['name'],
            'latitude' => (float) $candidate['latitude'],
            'longitude' => (float) $candidate['longitude'],
            'province' => is_string($candidate['admin1'] ?? null)
                ? $candidate['admin1'] : '',
            'municipality' => is_string($candidate['admin2'] ?? null)
                ? $candidate['admin2'] : '',
        ];
        $suggestions[] = $place;
        if ($fold($place['name']) !== $fold($name)) {
            continue;
        }
        if ($qualifier !== '' && strpos($fold($place['province']), $fold($qualifier)) !== 0
            && strpos($fold($place['municipality']), $fold($qualifier)) !== 0) {
            continue;
        }
        // The geocoder sometimes lists one place twice; a kilometre apart is the same place.
        foreach ($matches as $match) {
            if (abs($match['latitude'] - $place['latitude']) < 0.01
                && abs($match['longitude'] - $place['longitude']) < 0.01) {
                continue 2;
            }
        }
        $matches[] = $place;
    }
    if (count($matches) === 1) {
        $match = $matches[0];
        $byMunicipality = $qualifier !== '' && $match['municipality'] !== $match['name']
            && strpos($fold($match['province']), $fold($qualifier)) !== 0;
        if ($byMunicipality) {
            $matches[0]['name'] .= ' (' . $match['municipality'] . ')';
        } elseif ($qualifier !== '' && $match['province'] !== '') {
            $matches[0]['name'] .= ', ' . $match['province'];
        }
        return ['status' => 'ok', 'place' => $matches[0]];
    }
    if (count($matches) > 1) {
        $provinces = array_count_values(array_column($matches, 'province'));
        foreach ($matches as $index => $match) {
            $matches[$index]['qualifier'] = $provinces[$match['province']] > 1
                && $match['municipality'] !== '' ? $match['municipality'] : $match['province'];
        }
        return ['status' => 'ambiguous', 'places' => $matches];
    }
    return ['status' => 'not_found', 'suggestions' => array_slice($suggestions, 0, 3)];
}

// The one night test. Values are for the hour before sunrise; the temperature is the
// lowest around sunrise. Local mist and dew need damp air; rime needs frost.
function fogNightConditions(
    float $cloud,
    float $windKmh,
    float $precipitation,
    float $humidity,
    float $minTemp
): array {
    $clearCalm = $cloud <= FOG_NIGHT_MAX_CLOUD && $windKmh <= FOG_NIGHT_MAX_WIND_KMH
        && $precipitation < FOG_WET_MM;
    $damp = $humidity >= FOG_NIGHT_MIN_HUMIDITY;
    $frost = $minTemp <= FOG_FROST_MAX_TEMP;
    $saturated = $humidity >= FOG_SATURATED_MIN_HUMIDITY && $windKmh <= FOG_SATURATED_MAX_WIND_KMH
        && $precipitation < FOG_WET_MM;
    return [
        'local_mist' => ($clearCalm && $damp) || $saturated,
        'saturated' => $saturated,
        'dew' => $clearCalm && $damp && !$frost,
        'rime' => $clearCalm && $frost && $humidity >= FOG_NIGHT_MIN_HUMIDITY_FROST,
        'min_temp' => $minTemp,
    ];
}

function fogSunTimes(string $date, float $latitude, float $longitude, DateTimeZone $zone): array
{
    $utcNoon = new DateTimeImmutable($date . ' 12:00', new DateTimeZone('UTC'));
    $sun = date_sun_info($utcNoon->getTimestamp(), $latitude, $longitude);
    if (!is_array($sun) || !is_int($sun['sunrise'] ?? null)
        || !is_int($sun['sunset'] ?? null)
        || !is_int($sun['civil_twilight_begin'] ?? null)) {
        throw new RuntimeException('Could not calculate sun times');
    }
    $sunrise = (new DateTimeImmutable('@' . $sun['sunrise']))->setTimezone($zone);
    $sunset = (new DateTimeImmutable('@' . $sun['sunset']))->setTimezone($zone);
    // First light is the start of civil twilight, when the blue hour begins.
    $firstLight = (new DateTimeImmutable('@' . $sun['civil_twilight_begin']))->setTimezone($zone);
    if ($sunrise->format('Y-m-d') !== $date || $sunset->format('Y-m-d') !== $date
        || $firstLight->format('Y-m-d') !== $date) {
        throw new RuntimeException('Sun date does not match forecast date');
    }
    return [$sunrise, $sunset, $firstLight];
}

// Getting up and walking out the door takes 30 minutes, the trip another 15.
const FOG_WAKE_MINUTES_BEFORE_FIRST_LIGHT = 45;

// The same bands the morning update uses: 1-3 Neutraal, 4-5 Prima, 6-10 Uitstekend.
// The internal keys stay go/maybe/skip so stored data keeps its meaning.
function fogAdvice(int $score): string
{
    return $score >= 6 ? 'go' : ($score >= 4 ? 'maybe' : 'skip');
}

// Words instead of exact shares: 31 coarse scenarios agreeing is not certainty.
function fogLikelihood(float $share): string
{
    return $share >= 0.8 ? 'zeer waarschijnlijk'
        : ($share >= 0.5 ? 'waarschijnlijk' : ($share >= 0.2 ? 'mogelijk' : 'weinig kans'));
}

function fogWakeTime(DateTimeImmutable $firstLight): DateTimeImmutable
{
    $wake = $firstLight->modify('-' . FOG_WAKE_MINUTES_BEFORE_FIRST_LIGHT . ' minutes');
    // Round the alarm down to whole five minutes.
    return $wake->setTime((int) $wake->format('G'), intdiv((int) $wake->format('i'), 5) * 5);
}

function fogAdviceLine(int $score, DateTimeImmutable $firstLight): string
{
    $advice = fogAdvice($score);
    if ($advice === 'skip') {
        return '⚪ Neutraal';
    }
    return sprintf('%s — wekker %s · eerste licht %s',
        $advice === 'go' ? '🟢 Uitstekend' : '🟡 Prima',
        fogWakeTime($firstLight)->format('H:i'),
        $firstLight->format('H:i'));
}

// Round to the nearest hour, so a 07:50 sunrise is judged at 08:00 instead of 07:00.
function fogSunriseHour(DateTimeImmutable $sunrise): int
{
    return min(23, (int) $sunrise->modify('+30 minutes')->format('G'));
}

function fogSaveJson(string $path, array $data): void
{
    $directory = dirname($path);
    if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
        throw new RuntimeException('Could not create directory for ' . basename($path));
    }
    $encoded = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
    $temporary = tempnam($directory, '.fog-');
    if ($temporary === false) {
        throw new RuntimeException('Could not create temporary file for ' . basename($path));
    }
    if (file_put_contents($temporary, $encoded) !== strlen($encoded)) {
        @unlink($temporary);
        throw new RuntimeException('Could not write ' . basename($path));
    }
    @chmod($temporary, 0600);
    // Rename replaces the file in one step, so readers never see half a file.
    if (!rename($temporary, $path)) {
        @unlink($temporary);
        throw new RuntimeException('Could not save ' . basename($path));
    }
}

function fogMemberPhotoSignals(array $series, array $indices, int $sunriseHour): array
{
    $memberMist = false;
    $memberDense = false;
    $memberFreezing = false;
    $fogAtHour = [];
    foreach ($indices as $hour => $index) {
        $fog = (float) $series['visibility'][$index] < FOG_MIST_VISIBILITY_M
            && in_array((int) $series['weather_code'][$index], FOG_WEATHER_CODES, true);
        $fogAtHour[$hour] = $fog;
        if ($fog) {
            $memberMist = true;
            if ((float) $series['visibility'][$index] < FOG_DENSE_VISIBILITY_M) {
                $memberDense = true;
            }
            if ((float) $series['temperature_2m'][$index] <= 0.0) {
                $memberFreezing = true;
            }
        }
    }
    $beforeHour = max(0, $sunriseHour - 1);
    $beforeIndex = $indices[$beforeHour];
    $night = fogNightConditions(
        (float) $series['cloud_cover'][$beforeIndex],
        (float) $series['wind_speed_10m'][$beforeIndex],
        (float) $series['precipitation'][$beforeIndex],
        (float) $series['relative_humidity_2m'][$beforeIndex],
        min((float) $series['temperature_2m'][$beforeIndex],
            (float) $series['temperature_2m'][$indices[$sunriseHour]])
    );
    $laterHour = min(23, $sunriseHour + 2);
    $laterIndex = $indices[$laterHour];
    $lifting = ($fogAtHour[$beforeHour] || $fogAtHour[$sunriseHour])
        && !$fogAtHour[$laterHour]
        && (float) $series['visibility'][$laterIndex] >= FOG_LIGHT_MIN_VISIBILITY_M;
    $openSky = false;
    foreach ([$sunriseHour + 1, $sunriseHour + 2] as $hour) {
        if (isset($indices[$hour])
            && (float) $series['cloud_cover'][$indices[$hour]] <= FOG_LIGHT_MAX_CLOUD
            && (float) $series['visibility'][$indices[$hour]] >= FOG_LIGHT_MIN_VISIBILITY_M
            && (float) $series['precipitation'][$indices[$hour]] < FOG_WET_MM) {
            $openSky = true;
            break;
        }
    }
    return [
        'mist' => $memberMist,
        'dense' => $memberDense,
        'freezing' => $memberFreezing,
        'night' => $night,
        'lifting' => $lifting,
        'open_sky' => $openSky,
        'fog_by_hour' => $fogAtHour,
    ];
}

function fogMemberSnowfall(array $hourly, string $suffix, array $indices, array $hours): ?bool
{
    $snowfall = $hourly['snowfall' . $suffix] ?? null;
    if (!is_array($snowfall)) {
        return null;
    }
    $snowing = false;
    foreach ($hours as $hour) {
        $value = $snowfall[$indices[$hour]] ?? null;
        if (!is_numeric($value)) {
            return null;
        }
        $snowing = $snowing || (float) $value >= 0.1;
    }
    return $snowing;
}

// Snow that fell between the previous evening and sunrise, in cm: a fresh, untouched layer.
function fogMemberFreshSnow(array $hourly, string $suffix, string $date, int $sunriseHour): ?float
{
    $snowfall = $hourly['snowfall' . $suffix] ?? null;
    if (!is_array($snowfall)) {
        return null;
    }
    $eveningBefore = (new DateTimeImmutable($date . ' 12:00'))->modify('-1 day')->format('Y-m-d');
    // For a morning today the data starts at midnight; then the night is all there is.
    $start = array_search($eveningBefore . 'T18:00', $hourly['time'], true);
    if ($start === false) {
        $start = array_search($date . 'T00:00', $hourly['time'], true);
    }
    $end = array_search($date . 'T' . sprintf('%02d', $sunriseHour) . ':00', $hourly['time'], true);
    if ($start === false || $end === false) {
        return null;
    }
    $total = 0.0;
    for ($index = $start; $index <= $end; $index++) {
        if (!is_numeric($snowfall[$index] ?? null)) {
            return null;
        }
        $total += (float) $snowfall[$index];
    }
    return $total;
}

function fogOptionalSeries(array $hourly, string $variable, string $suffix, array $indices): ?array
{
    $values = $hourly[$variable . $suffix] ?? null;
    if (!is_array($values)) {
        return null;
    }
    foreach ($indices as $index) {
        if (!is_numeric($values[$index] ?? null)) {
            return null;
        }
    }
    return $values;
}

function fogMemberPhotoScenes(array $series, array $indices, int $sunriseHour,
    array $signals): array
{
    $snowing = false;
    $windy = false;
    $snowWind = false;
    foreach ($indices as $hour => $index) {
        $snowAtHour = is_array($series['snowfall'])
            && ($hour === $sunriseHour || $hour === $sunriseHour + 1)
            && (float) $series['snowfall'][$index] >= FOG_WET_MM;
        $windAtHour = is_array($series['wind_gusts_10m'])
            && (float) $series['wind_gusts_10m'][$index] >= FOG_STORM_GUSTS_KMH;
        $snowing = $snowing || $snowAtHour;
        $windy = $windy || $windAtHour;
        $snowWind = $snowWind || ($snowAtHour && $windAtHour);
    }
    $snowCover = is_array($series['snow_depth'])
        && (float) $series['snow_depth'][$indices[$sunriseHour]] >= FOG_SNOW_COVER_M;
    $light = $signals['open_sky'];
    $dynamicSky = false;
    $dynamicLight = false;
    $skyWind = false;
    $skyScore = 2;
    foreach ([$sunriseHour, $sunriseHour + 1, $sunriseHour + 2] as $hour) {
        if (!isset($indices[$hour], $indices[$hour - 1])) {
            continue;
        }
        $index = $indices[$hour];
        $beforeIndex = $indices[$hour - 1];
        $cloud = (float) $series['cloud_cover'][$index];
        $cloudBefore = (float) $series['cloud_cover'][$beforeIndex];
        $partlyCloudy = ($cloud >= 20.0 && $cloud <= 80.0)
            || ($cloudBefore >= 20.0 && $cloudBefore <= 80.0);
        $showery = in_array((int) $series['weather_code'][$index],
            [80, 81, 82, 95, 96, 99], true)
            || in_array((int) $series['weather_code'][$beforeIndex],
                [80, 81, 82, 95, 96, 99], true);
        $lightAtHour = $hour > $sunriseHour
            && ((is_array($series['sunshine_duration'])
                    && (float) $series['sunshine_duration'][$index] >= 300.0)
                || ($cloud <= FOG_LIGHT_MAX_CLOUD
                    && (float) $series['visibility'][$index] >= FOG_LIGHT_MIN_VISIBILITY_M
                    && (float) $series['precipitation'][$index] < FOG_WET_MM));
        $light = $light || $lightAtHour;
        // Adjacent hours may describe a shower followed by a sun break.
        $dynamicAtHour = $partlyCloudy
            && (abs($cloud - $cloudBefore) >= 25.0 || $showery);
        if (!$dynamicAtHour) {
            continue;
        }
        $dynamicSky = true;
        $dynamicLight = $dynamicLight || $lightAtHour;
        $windAtHour = is_array($series['wind_gusts_10m'])
            && (float) $series['wind_gusts_10m'][$index] >= FOG_STORM_GUSTS_KMH;
        $skyWind = $skyWind || $windAtHour;
        $skyScore = max($skyScore, 5 + 2 * (int) $lightAtHour
            + (int) ($showery && $lightAtHour)
            + (int) ($windAtHour && ($lightAtHour || $showery)));
    }
    // Calm air at sunrise gives reflections in water.
    $calm = (float) $series['wind_speed_10m'][$indices[$sunriseHour]] <= FOG_CALM_MAX_WIND_KMH;
    // These are cues for changing light, not a measured cloud-color probability.
    // A clear, sunny morning is a decent landscape morning, calm air makes it better.
    if (!$dynamicSky) {
        $skyScore = $light ? 4 + (int) $calm : 2;
    }
    $wetGrey = (float) $series['cloud_cover'][$indices[$sunriseHour]] >= 85.0
        && (float) $series['precipitation'][$indices[$sunriseHour]] >= FOG_WET_MM;
    if (!$dynamicSky && !$light && $wetGrey) {
        $skyScore = 1;
    }

    // Any real mist is worth getting up for, even on a grey morning.
    $mistScore = $signals['mist']
        ? 7 + (int) $signals['dense'] + (int) $signals['lifting'] + (int) $light
        : ($signals['night']['local_mist'] ? 3 + (int) $light : 1);
    $rime = $signals['night']['rime'];
    $dew = $signals['night']['dew'];
    $minTemp = $signals['night']['min_temp'];
    $frostScore = $rime ? 6 + (int) $light + (int) ($minTemp <= FOG_HARD_FROST_TEMP)
        : ($dew ? 4 + (int) $light : 1);
    // A fresh layer from the night is untouched and white, even under a grey sky;
    // older snow cover is often trodden, grey or melting.
    $freshSnowCm = isset($series['fresh_snow']) ? (float) $series['fresh_snow'] : 0.0;
    $freshSnow = $freshSnowCm >= FOG_FRESH_SNOW_CM;
    if ($freshSnow) {
        $winterScore = 7 + (int) $light + (int) $dynamicSky;
    } elseif ($snowCover || $snowing) {
        $winterScore = 5 + (int) ($snowCover && $snowing) + (int) $light
            + (int) $dynamicSky + (int) ($snowWind || ($snowCover && $skyWind));
    } else {
        $winterScore = 1;
    }
    return [
        'mist' => min(9, $mistScore),
        'winter' => min(9, $winterScore),
        'sky' => min(9, $skyScore),
        'frost' => min(9, $frostScore),
        'rime' => $rime,
        'dew' => $dew,
        'calm' => $calm,
        'min_temp' => $minTemp,
        'fresh_snow' => $freshSnow,
        'fresh_snow_cm' => $freshSnowCm,
        'snow_cover' => $snowCover,
        'snow_falling' => $snowing,
        'dynamic_sky' => $dynamicSky,
        'light' => $light,
        'windy' => $windy,
        'snow_landscape_light' => $snowCover && $dynamicLight,
        'snow_wind_sky' => $snowing && $snowWind && $dynamicSky,
        'sky_light' => $dynamicLight,
        'sky_wind' => $skyWind,
    ];
}

function fogPhotoDayScore(array $scenes): array
{
    if (!$scenes) {
        throw new RuntimeException('No photo scenarios available');
    }
    $routeTotals = ['mist' => 0, 'winter' => 0, 'sky' => 0, 'frost' => 0];
    $counts = ['snow_cover' => 0, 'snow_falling' => 0, 'dynamic_sky' => 0,
        'light' => 0, 'windy' => 0, 'snow_landscape_light' => 0,
        'snow_wind_sky' => 0, 'sky_light' => 0, 'sky_wind' => 0,
        'rime' => 0, 'dew' => 0, 'calm' => 0, 'fresh_snow' => 0];
    $total = 0;
    $minTemps = [];
    $freshSnow = [];
    foreach ($scenes as $scene) {
        $routes = [];
        foreach (array_keys($routeTotals) as $route) {
            $routes[] = $scene[$route];
            $routeTotals[$route] += $scene[$route];
        }
        rsort($routes, SORT_NUMERIC);
        $total += min(10, $routes[0] + (int) ($routes[0] >= 7 && $routes[1] >= 7));
        foreach (array_keys($counts) as $signal) {
            $counts[$signal] += (int) $scene[$signal];
        }
        $minTemps[] = (float) $scene['min_temp'];
        // The amount shown is that of the scenarios that do bring a fresh layer.
        if ($scene['fresh_snow']) {
            $freshSnow[] = (float) $scene['fresh_snow_cm'];
        }
    }
    sort($minTemps, SORT_NUMERIC);
    sort($freshSnow, SORT_NUMERIC);
    arsort($routeTotals, SORT_NUMERIC);
    $members = count($scenes);
    $routeValues = array_values($routeTotals);
    $shares = [];
    foreach ($counts as $signal => $count) {
        $shares[$signal] = $count / $members;
    }
    return [
        'score' => max(1, min(10, (int) round($total / $members))),
        'route' => (string) array_key_first($routeTotals),
        'route_margin' => ($routeValues[0] - $routeValues[1]) / $members,
        'shares' => $shares,
        // The middle scenario, so one outlier does not set the shown temperature.
        'min_temp' => $minTemps[intdiv(count($minTemps), 2)],
        'fresh_snow_cm' => $freshSnow ? $freshSnow[intdiv(count($freshSnow), 2)] : 0.0,
    ];
}

function fogOutlookScores(
    array $hourly,
    array $suffixes,
    array $variables,
    string $forecastDate,
    float $latitude,
    float $longitude,
    DateTimeZone $zone,
    ?array $clouds = null
): array {
    $scores = [];
    $baseDate = new DateTimeImmutable($forecastDate . ' 12:00', $zone);
    foreach ([1, 2] as $daysAhead) {
        $date = $baseDate->modify('+' . $daysAhead . ' days')->format('Y-m-d');
        [$sunrise] = fogSunTimes($date, $latitude, $longitude, $zone);
        $sunriseHour = fogSunriseHour($sunrise);
        $hours = range(max(0, $sunriseHour - 2), min(23, $sunriseHour + 2));
        $indices = [];
        foreach ($hours as $hour) {
            $time = $date . 'T' . sprintf('%02d', $hour) . ':00';
            $index = array_search($time, $hourly['time'], true);
            if ($index === false) {
                throw new RuntimeException('Outlook is missing ' . $time);
            }
            $indices[$hour] = $index;
        }
        $scenes = [];
        foreach ($suffixes as $suffix) {
            $series = [];
            foreach ($variables as $variable) {
                $series[$variable] = $hourly[$variable . $suffix] ?? null;
                if (!is_array($series[$variable])) {
                    continue 2;
                }
            }
            foreach ($indices as $index) {
                foreach ($variables as $variable) {
                    if (!isset($series[$variable][$index])
                        || !is_numeric($series[$variable][$index])) {
                        continue 3;
                    }
                }
            }
            foreach (['snowfall', 'snow_depth', 'wind_gusts_10m',
                'sunshine_duration'] as $variable) {
                $series[$variable] = fogOptionalSeries($hourly, $variable, $suffix, $indices);
            }
            $series['fresh_snow'] = fogMemberFreshSnow($hourly, $suffix, $date, $sunriseHour);
            $signals = fogMemberPhotoSignals($series, $indices, $sunriseHour);
            $scenes[] = fogMemberPhotoScenes($series, $indices, $sunriseHour, $signals);
        }
        if (count($scenes) < 25) {
            throw new RuntimeException('Too few usable outlook members: ' . count($scenes));
        }
        $day = fogPhotoDayScore($scenes);
        $scores[$date] = fogBlendColor($day['score'],
            fogColorShare($clouds, $date, $sunriseHour), $day['shares']['calm'] >= 0.6);
    }
    return $scores;
}

function fogHarmonieCheck(
    float $latitude,
    float $longitude,
    string $date,
    array $hours,
    int $sunriseHour,
    int $forecastDays = 2
): ?array {
    try {
        $url = 'https://api.open-meteo.com/v1/forecast?'
            . http_build_query([
                'latitude' => $latitude,
                'longitude' => $longitude,
                'hourly' => 'visibility,weather_code,cloud_cover,cloud_cover_low,relative_humidity_2m,wind_speed_10m,precipitation,temperature_2m',
                'models' => 'knmi_harmonie_arome_netherlands',
                // A fixed length keeps the URL, and so the download, shared between mornings.
                'forecast_days' => max(3, $forecastDays),
                'timezone' => 'Europe/Amsterdam',
            ], '', '&', PHP_QUERY_RFC3986);
        [$status, $response] = fogRequest($url, null, [], 8);
        if ($status !== 200) {
            throw new RuntimeException('KNMI forecast returned HTTP ' . $status);
        }
        $data = json_decode($response, true, 512, JSON_THROW_ON_ERROR);
        $hourly = $data['hourly'] ?? null;
        $variables = ['visibility', 'weather_code', 'cloud_cover', 'cloud_cover_low',
            'relative_humidity_2m', 'wind_speed_10m', 'precipitation', 'temperature_2m'];
        if (!is_array($hourly) || !is_array($hourly['time'] ?? null)) {
            throw new RuntimeException('KNMI forecast has no hourly data');
        }
        foreach ($variables as $variable) {
            if (!is_array($hourly[$variable] ?? null)) {
                throw new RuntimeException('KNMI forecast is missing ' . $variable);
            }
        }
        $indices = [];
        foreach ($hours as $hour) {
            $index = array_search($date . 'T' . sprintf('%02d', $hour) . ':00', $hourly['time'], true);
            if ($index === false) {
                throw new RuntimeException('KNMI forecast is missing an hour');
            }
            foreach ($variables as $variable) {
                // The local model reaches about 2.5 days ahead; later hours are empty.
                if (!isset($hourly[$variable][$index])) {
                    return null;
                }
                if (!is_numeric($hourly[$variable][$index])) {
                    throw new RuntimeException('KNMI forecast has an incomplete hour');
                }
            }
            $indices[$hour] = $index;
        }

        $fog = false;
        $dense = false;
        $lowVisibility = false;
        $minVisibility = INF;
        foreach ($indices as $index) {
            $visibility = (float) $hourly['visibility'][$index];
            $minVisibility = min($minVisibility, $visibility);
            $lowVisibility = $lowVisibility || $visibility < FOG_MIST_VISIBILITY_M;
            if ($visibility < FOG_MIST_VISIBILITY_M
                && in_array((int) $hourly['weather_code'][$index], FOG_WEATHER_CODES, true)) {
                $fog = true;
                $dense = $dense || $visibility < FOG_DENSE_VISIBILITY_M;
            }
        }
        $before = $indices[max(0, $sunriseHour - 1)];
        $night = fogNightConditions(
            (float) $hourly['cloud_cover'][$before],
            (float) $hourly['wind_speed_10m'][$before],
            (float) $hourly['precipitation'][$before],
            (float) $hourly['relative_humidity_2m'][$before],
            min((float) $hourly['temperature_2m'][$before],
                (float) $hourly['temperature_2m'][$indices[$sunriseHour]])
        );
        return [
            'fog' => $fog,
            'dense' => $dense,
            'low_visibility' => $lowVisibility,
            'local_mist' => $night['local_mist'],
            'saturated' => $night['saturated'],
            'min_visibility_m' => (int) round($minVisibility),
            // The values behind the verdict, for the page's explanation.
            'values' => [
                'visibility_m' => (float) $hourly['visibility'][$indices[$sunriseHour]],
                'humidity' => (float) $hourly['relative_humidity_2m'][$before],
                'temperature' => (float) $hourly['temperature_2m'][$before],
                'min_temp' => $night['min_temp'],
                'wind' => (float) $hourly['wind_speed_10m'][$before],
                'cloud_night' => (float) $hourly['cloud_cover'][$before],
                'low_cloud' => (float) $hourly['cloud_cover_low'][$before],
            ],
        ];
    } catch (Throwable $error) {
        error_log('Fog KNMI check: ' . $error->getMessage());
        return null;
    }
}

function fogBuildForecast(
    DateTimeImmutable $now,
    DateTimeZone $zone,
    ?array $place = null,
    ?string $targetDate = null,
    bool $includeOutlook = true
): array
{
    $place = $place ?? fogDefaultPlace();
    $placeName = $place['name'];
    $latitude = (float) $place['latitude'];
    $longitude = (float) $place['longitude'];
    $forecastDate = $targetDate ?? $now->modify('+1 day')->format('Y-m-d');
    $dayLabel = $forecastDate === $now->format('Y-m-d') ? 'vandaag' : 'morgen';
    $metrics = null;
    try {
        // Use the local sunrise so the photo window follows the seasons.
        [$sunrise, , $firstLight] = fogSunTimes($forecastDate, $latitude, $longitude, $zone);
        [$hourly, $forecastDays, $baseDays, $outlookDays] = fogFetchEnsemble(
            $latitude, $longitude, $forecastDate, $now, $zone, $includeOutlook);
        $metrics = fogForecastMetrics($hourly, $forecastDate, $latitude, $longitude, $zone,
            $baseDays, $includeOutlook && $forecastDays === $outlookDays);
        $title = sprintf('Fotoweer %s (%s)', $sunrise->format('d-m'), $placeName);
        $priority = $metrics['dense_share'] >= 0.8 ? 4 : 3;
        $message = fogForecastMessage($metrics, $sunrise, $firstLight, $zone);
        $checkStatus = sprintf(
            "%s; datum=%s; zon=%s; cijfer=%d/10; mist=%.1f%%; dicht=%.1f%%; lokale-mist-signaal=%.1f%%; scenario's=%d\n",
            $now->format('Y-m-d H:i:sP'),
            $forecastDate,
            $sunrise->format('H:i'),
            $metrics['score'],
            $metrics['mist_share'] * 100,
            $metrics['dense_share'] * 100,
            $metrics['local_mist_share'] * 100,
            $metrics['gfs_members']
        );
    } catch (Throwable $forecastError) {
        error_log('Fog forecast: ' . $forecastError->getMessage());
        $metrics = null;
        if ($includeOutlook && isset($forecastDays, $outlookDays) && $forecastDays === $outlookDays) {
            // An incomplete extended response must not break tomorrow's forecast.
            return fogBuildForecast($now, $zone, $place, $targetDate, false);
        }
        $title = 'Fotoweer ' . $placeName . ': gegevens ontbreken';
        $priority = 3;
        $message = 'De fotoweerverwachting voor ' . $dayLabel
            . ' kan nu niet worden berekend. Er is geen betrouwbare weersinschatting.';
        $checkStatus = $now->format('Y-m-d H:i:sP') . '; datum=' . $forecastDate
            . '; fout=' . $forecastError->getMessage() . "\n";
    }
    return [
        'date' => $forecastDate,
        'title' => $title,
        'message' => $message,
        'priority' => $priority,
        'check_status' => $checkStatus,
        'metrics' => $metrics,
    ];
}

// Downloads cloud layers from the ECMWF ensemble; the GFS ensemble leaves them empty.
// Without this download the forecast simply has no colour chance.
function fogFetchCloudLayers(float $latitude, float $longitude, int $forecastDays): ?array
{
    try {
        // A fixed length keeps the URL, and so the download, shared between mornings.
        $url = 'https://ensemble-api.open-meteo.com/v1/ensemble?latitude=' . rawurlencode((string) $latitude)
            . '&longitude=' . rawurlencode((string) $longitude)
            . '&hourly=cloud_cover_low,cloud_cover_mid,cloud_cover_high'
            . '&models=ecmwf_ifs025&forecast_days=' . max(4, $forecastDays) . '&timezone=Europe%2FAmsterdam';
        [$status, $response] = fogRequest($url, null, [], 15);
        if ($status !== 200) {
            throw new RuntimeException('Cloud layers returned HTTP ' . $status);
        }
        $hourly = json_decode($response, true, 512, JSON_THROW_ON_ERROR)['hourly'] ?? null;
        if (!is_array($hourly) || !is_array($hourly['time'] ?? null)) {
            throw new RuntimeException('Cloud layers have no hourly data');
        }
        return $hourly;
    } catch (Throwable $error) {
        error_log('Fog cloud layers: ' . $error->getMessage());
        return null;
    }
}

function fogMedian(array $values): ?float
{
    if (!$values) {
        return null;
    }
    sort($values, SORT_NUMERIC);
    return (float) $values[intdiv(count($values), 2)];
}

// Dew point spread from temperature and humidity (Magnus formula), in degrees.
function fogDewPointSpread(float $temperature, float $humidity): float
{
    $gamma = log(max(1.0, $humidity) / 100) + 17.62 * $temperature / (243.12 + $temperature);
    return max(0.0, $temperature - 243.12 * $gamma / (17.62 - $gamma));
}

// The middle ECMWF scenario's low, mid and high cloud at sunrise, or null.
function fogCloudLayerMedians(?array $clouds, string $date, int $sunriseHour): ?array
{
    if ($clouds === null) {
        return null;
    }
    $index = array_search($date . 'T' . sprintf('%02d', $sunriseHour) . ':00', $clouds['time'], true);
    if ($index === false) {
        return null;
    }
    $layers = [];
    foreach (['low', 'mid', 'high'] as $layer) {
        $values = [];
        foreach ($clouds as $key => $series) {
            if (preg_match('/^cloud_cover_' . $layer . '(_member\d+)?$/', $key)
                && is_numeric($series[$index] ?? null)) {
                $values[] = (float) $series[$index];
            }
        }
        $layers[$layer] = fogMedian($values);
    }
    return in_array(null, $layers, true) ? null : $layers;
}

// Share of ECMWF scenarios with a colourful setup in the hour before or at sunrise,
// or null without enough data.
function fogColorShare(?array $clouds, string $date, int $sunriseHour): ?float
{
    if ($clouds === null) {
        return null;
    }
    $indices = [];
    foreach ([$sunriseHour - 1, $sunriseHour] as $hour) {
        $index = array_search($date . 'T' . sprintf('%02d', $hour) . ':00', $clouds['time'], true);
        if ($index === false) {
            return null;
        }
        $indices[] = $index;
    }
    $members = 0;
    $colourful = 0;
    foreach (array_keys($clouds) as $key) {
        if (!preg_match('/^cloud_cover_low((?:_member\d+)?)$/', $key, $match)) {
            continue;
        }
        $color = false;
        foreach ($indices as $index) {
            $low = $clouds['cloud_cover_low' . $match[1]][$index] ?? null;
            $mid = $clouds['cloud_cover_mid' . $match[1]][$index] ?? null;
            $high = $clouds['cloud_cover_high' . $match[1]][$index] ?? null;
            if (!is_numeric($low) || !is_numeric($mid) || !is_numeric($high)) {
                continue 2;
            }
            $upper = max((float) $mid, (float) $high);
            $color = $color || ((float) $low <= FOG_COLOR_MAX_LOW_CLOUD
                && $upper >= FOG_COLOR_MIN_UPPER_CLOUD && $upper <= FOG_COLOR_MAX_UPPER_CLOUD);
        }
        $members++;
        $colourful += (int) $color;
    }
    return $members >= 25 ? $colourful / $members : null;
}

// Mixes the colour chance into the score: in the share of scenarios with colour the
// morning is worth at least FOG_COLOR_SCORE (one more when calm). The colour comes
// from another ensemble, so this is a weighted average, not a per-scenario maximum.
function fogBlendColor(int $score, ?float $colorShare, bool $calm): int
{
    if ($colorShare === null) {
        return $score;
    }
    $value = FOG_COLOR_SCORE + (int) $calm;
    return max($score, (int) round($score + $colorShare * max(0, $value - $score)));
}

// Downloads the GFS ensemble. Returns [hourly data, days fetched, days needed for the
// morning, days needed for the outlook].
function fogFetchEnsemble(
    float $latitude,
    float $longitude,
    string $forecastDate,
    DateTimeImmutable $now,
    DateTimeZone $zone,
    bool $includeOutlook
): array {
    // The models count days from today, so a later morning needs a longer request.
    $baseDays = max(2, (new DateTimeImmutable($now->format('Y-m-d'), $zone))
        ->diff(new DateTimeImmutable($forecastDate, $zone))->days + 1);
    $outlookDays = $baseDays + 2;
    // Ask for two extra mornings in one call. If the extended request fails,
    // keep the main forecast available with the shorter request.
    // Without the outlook still ask for four days: the same URL serves every morning.
    $attempts = array_values(array_unique($includeOutlook
        ? [$outlookDays, $baseDays] : [max(4, $baseDays), $baseDays]));
    foreach ($attempts as $forecastDays) {
        try {
            $url = 'https://ensemble-api.open-meteo.com/v1/ensemble?latitude=' . rawurlencode((string) $latitude)
                . '&longitude=' . rawurlencode((string) $longitude)
                . '&hourly=visibility,weather_code,cloud_cover,precipitation,snowfall,snow_depth,wind_gusts_10m,sunshine_duration,relative_humidity_2m,wind_speed_10m,temperature_2m'
                . '&models=gfs025&forecast_days=' . $forecastDays . '&timezone=Europe%2FAmsterdam';
            [$status, $response] = fogRequest($url);
            if ($status !== 200) {
                throw new RuntimeException('Forecast returned HTTP ' . $status);
            }
            $forecast = json_decode($response, true, 512, JSON_THROW_ON_ERROR);
            $hourly = $forecast['hourly'] ?? null;
            if (!is_array($hourly) || !is_array($hourly['time'] ?? null)) {
                throw new RuntimeException('Forecast has no hourly data');
            }
            return [$hourly, $forecastDays, $baseDays, $outlookDays];
        } catch (Throwable $extendedError) {
            if ($forecastDays === $baseDays) {
                throw $extendedError;
            }
            error_log('Fog extended forecast: ' . $extendedError->getMessage());
        }
    }
    throw new RuntimeException('No forecast request was attempted');
}

// Turns the ensemble (and the local KNMI run) into the numbers behind one morning:
// shares, score, photo route and reason. No text for people is made here except the
// reason and weather summary, which are stored with the forecast.
function fogForecastMetrics(
    array $hourly,
    string $forecastDate,
    float $latitude,
    float $longitude,
    DateTimeZone $zone,
    int $baseDays,
    bool $withOutlook
): array {
    [$sunrise] = fogSunTimes($forecastDate, $latitude, $longitude, $zone);
    $sunriseHour = fogSunriseHour($sunrise);
    $hours = range(max(0, $sunriseHour - 2), min(23, $sunriseHour + 2));

    $indices = [];
    foreach ($hours as $hour) {
        $time = $forecastDate . 'T' . sprintf('%02d', $hour) . ':00';
        $index = array_search($time, $hourly['time'], true);
        if ($index === false) {
            throw new RuntimeException('Forecast is missing ' . $time);
        }
        $indices[$hour] = $index;
    }

    $suffixes = [''];
    foreach (array_keys($hourly) as $key) {
        if (preg_match('/^visibility(_member\d+)$/', $key, $match)) {
            $suffixes[] = $match[1];
        }
    }
    $variables = [
        'visibility', 'weather_code', 'cloud_cover', 'precipitation',
        'relative_humidity_2m', 'wind_speed_10m', 'temperature_2m',
    ];
    $complete = 0;
    $mist = 0;
    $dense = 0;
    $freezingMist = 0;
    $localMistSignal = 0;
    $saturatedSignal = 0;
    $liftingMist = 0;
    $openSky = 0;
    $wetWeather = 0;
    $showeryWeather = 0;
    $snowyWeather = 0;
    $snowfallMembers = 0;
    $snowfallEvents = 0;
    $photoScenes = [];
    $cloudCoverTotal = 0.0;
    $mistByHour = array_fill_keys($hours, 0);
    $weatherHours = [$sunriseHour, min(23, $sunriseHour + 1)];
    $raw = [];
    foreach ($suffixes as $suffix) {
        $series = [];
        foreach ($variables as $variable) {
            $series[$variable] = $hourly[$variable . $suffix] ?? null;
            if (!is_array($series[$variable])) {
                continue 2;
            }
        }
        foreach ($indices as $index) {
            foreach ($variables as $variable) {
                if (!isset($series[$variable][$index])
                    || !is_numeric($series[$variable][$index])) {
                    continue 3;
                }
            }
        }
        foreach (['snowfall', 'snow_depth', 'wind_gusts_10m',
            'sunshine_duration'] as $variable) {
            $series[$variable] = fogOptionalSeries($hourly, $variable, $suffix, $indices);
        }
        $series['fresh_snow'] = fogMemberFreshSnow($hourly, $suffix, $forecastDate, $sunriseHour);

        $complete++;
        $memberSnowfall = fogMemberSnowfall($hourly, $suffix, $indices, $weatherHours);
        if ($memberSnowfall !== null) {
            $snowfallMembers++;
            $snowfallEvents += (int) $memberSnowfall;
        }
        $signals = fogMemberPhotoSignals($series, $indices, $sunriseHour);
        $photoScenes[] = fogMemberPhotoScenes($series, $indices, $sunriseHour, $signals);
        // Raw values behind the verdict; their medians explain the forecast on the page.
        $beforeIndex = $indices[max(0, $sunriseHour - 1)];
        $atIndex = $indices[$sunriseHour];
        $laterIndex = $indices[min(23, $sunriseHour + 1)];
        $raw['visibility_m'][] = (float) $series['visibility'][$atIndex];
        $raw['humidity'][] = (float) $series['relative_humidity_2m'][$beforeIndex];
        $raw['temperature'][] = (float) $series['temperature_2m'][$beforeIndex];
        $raw['min_temp'][] = $signals['night']['min_temp'];
        $raw['wind'][] = (float) $series['wind_speed_10m'][$beforeIndex];
        $raw['cloud_night'][] = (float) $series['cloud_cover'][$beforeIndex];
        $raw['cloud'][] = (float) $series['cloud_cover'][$atIndex];
        $raw['cloud_later'][] = (float) $series['cloud_cover'][$laterIndex];
        $raw['precipitation'][] = (float) $series['precipitation'][$atIndex]
            + (float) $series['precipitation'][$laterIndex];
        if (is_array($series['wind_gusts_10m'])) {
            $raw['gusts'][] = max(array_map(static function (int $index) use ($series): float {
                return (float) $series['wind_gusts_10m'][$index];
            }, array_values(array_filter($indices, static function (int $hour) use ($sunriseHour): bool {
                return $hour >= $sunriseHour;
            }, ARRAY_FILTER_USE_KEY))));
        }
        if (is_array($series['sunshine_duration'])) {
            $raw['sunshine_min'][] = (float) $series['sunshine_duration'][$laterIndex] / 60;
        }
        if (is_array($series['snow_depth'])) {
            $raw['snow_depth_cm'][] = (float) $series['snow_depth'][$atIndex] * 100;
        }
        if ($series['fresh_snow'] !== null) {
            $raw['fresh_snow_cm'][] = (float) $series['fresh_snow'];
        }
        foreach ($signals['fog_by_hour'] as $hour => $fog) {
            if ($fog) {
                $mistByHour[$hour]++;
            }
        }
        $mist += (int) $signals['mist'];
        $dense += (int) $signals['dense'];
        $freezingMist += (int) $signals['freezing'];

        $memberWet = false;
        $memberShowery = false;
        $memberSnowy = false;
        foreach ($weatherHours as $hour) {
            $index = $indices[$hour];
            $code = (int) $series['weather_code'][$index];
            $memberWet = $memberWet || (float) $series['precipitation'][$index] >= 0.1;
            $memberShowery = $memberShowery || in_array($code, [80, 81, 82, 95, 96, 99], true);
            $memberSnowy = $memberSnowy || in_array($code, [71, 73, 75, 77, 85, 86], true);
            $cloudCoverTotal += (float) $series['cloud_cover'][$index] / count($weatherHours);
        }
        $wetWeather += (int) $memberWet;
        $showeryWeather += (int) ($memberWet && $memberShowery);
        $snowyWeather += (int) ($memberWet && $memberSnowy);

        $localMistSignal += (int) $signals['night']['local_mist'];
        $saturatedSignal += (int) $signals['night']['saturated'];
        $liftingMist += (int) $signals['lifting'];
        $openSky += (int) $signals['open_sky'];
    }
    if ($complete < 25) {
        throw new RuntimeException('Too few usable ensemble members: ' . $complete);
    }

    $mistChance = $mist / $complete;
    $denseChance = $dense / $complete;
    $snowChance = $snowfallMembers >= 25 ? $snowfallEvents / $snowfallMembers : null;
    $openSkyChance = $openSky / $complete;
    $localMistShare = $localMistSignal / $complete;
    $saturatedShare = $saturatedSignal / $complete;
    $liftingChance = $liftingMist / $complete;
    $photoDay = fogPhotoDayScore($photoScenes);
    $ensembleScore = $photoDay['score'];
    $photoRoute = $photoDay['route'];
    $harmonie = fogHarmonieCheck($latitude, $longitude, $forecastDate, $hours, $sunriseHour,
        $baseDays);
    $fogModelsDisagree = $harmonie !== null && (
        ($harmonie['fog'] && $mistChance < 0.2)
        || (!$harmonie['fog'] && $mistChance >= 0.5)
        || ($harmonie['dense'] && $denseChance < 0.2)
        || (!$harmonie['dense'] && $denseChance >= 0.5)
    );
    $modelsDisagree = $fogModelsDisagree;
    // Mist forms first over open fields and low ground. KNMI judges the night when
    // it reaches this morning; otherwise half the GFS scenarios must agree.
    $localMist = $harmonie !== null ? $harmonie['local_mist'] : $localMistShare >= FOG_LOCAL_MIST_SHARE;
    // Saturated, still air near the ground: mist even under what models call low cloud.
    $saturated = $harmonie !== null ? (bool) ($harmonie['saturated'] ?? false)
        : $saturatedShare >= FOG_LOCAL_MIST_SHARE;
    // KNMI sees saturated air under a low deck while GFS sees a sunny morning: say so.
    $sunOrMist = $harmonie !== null && $saturated && $openSkyChance >= 0.5;
    $modelsDisagree = $modelsDisagree || $sunOrMist;
    $clouds = fogFetchCloudLayers($latitude, $longitude, $baseDays);
    $colorShare = fogColorShare($clouds, $forecastDate, $sunriseHour);
    $photoScore = fogBlendColor($ensembleScore, $colorShare, $photoDay['shares']['calm'] >= 0.6);
    if ($photoScore > $ensembleScore) {
        $photoRoute = 'color';
    }
    // KNMI leads for mist: its 2.5 km grid sees what the coarse ensemble misses.
    // It is a single scenario, so it lifts the score rather than replacing it.
    if ($harmonie !== null) {
        if ($harmonie['dense']) {
            if ($photoScore < 8) {
                $photoScore = 8;
                $photoRoute = 'mist';
            }
        } elseif ($harmonie['fog']) {
            if ($photoScore < 7) {
                $photoScore = 7;
                $photoRoute = 'mist';
            }
        } elseif ($harmonie['saturated'] ?? false) {
            if ($photoScore < FOG_SATURATED_SCORE) {
                $photoScore = FOG_SATURATED_SCORE;
                $photoRoute = 'mist';
            }
        }
    }
    $bestHour = null;
    $bestCount = 0;
    foreach ($mistByHour as $hour => $count) {
        if ($count > $bestCount) {
            $bestHour = $hour;
            $bestCount = $count;
        }
    }

    $photoShares = $photoDay['shares'];
    if ($photoRoute === 'winter') {
        if ($photoShares['fresh_snow'] >= 0.3) {
            $reason = 'vers sneeuwdek na een nacht met sneeuw';
        } elseif ($photoShares['snow_landscape_light'] >= 0.3) {
            $reason = 'sneeuwlandschap met kans op bijzonder ochtendlicht';
        } elseif ($photoShares['snow_cover'] >= 0.3) {
            $reason = 'kans op een sneeuwlandschap';
        } elseif ($photoShares['snow_wind_sky'] >= 0.4) {
            $reason = 'sneeuwbuien en dynamische lucht mogelijk';
        } else {
            $reason = 'kans op sneeuw rond zonsopkomst';
        }
    } elseif ($photoRoute === 'sky') {
        if ($photoShares['sky_light'] >= 0.4) {
            $reason = 'kans op bijzonder ochtendlicht tussen wolken';
        } elseif ($photoShares['dynamic_sky'] >= 0.4) {
            $reason = 'dynamische wolkenlucht mogelijk';
        } elseif ($openSkyChance >= 0.5 && $photoShares['calm'] >= 0.6) {
            $reason = 'zonnige, windstille ochtend';
        } elseif ($openSkyChance >= 0.5) {
            $reason = 'kans op ochtendzon';
        } else {
            $reason = 'weinig uitgesproken fotoweer';
        }
    } elseif ($photoRoute === 'color') {
        $reason = $colorShare >= 0.5 ? 'kans op kleur in de lucht bij zonsopkomst'
            : 'mogelijk kleur in de lucht bij zonsopkomst';
    } elseif ($photoRoute === 'frost') {
        if ($photoShares['rime'] >= 0.3) {
            $reason = 'rijp kansrijk rond zonsopkomst';
        } elseif ($photoShares['dew'] >= 0.3) {
            $reason = 'dauw met ochtendlicht mogelijk';
        } else {
            $reason = 'kans op rijp of dauw';
        }
    } elseif ($denseChance >= 0.5) {
        $reason = 'dichte mist is kansrijk';
    } elseif ($mistChance >= 0.4 && $liftingChance >= 0.25
        && $openSkyChance >= 0.25) {
        $reason = 'mist kan optrekken in ochtendlicht';
    } elseif ($mistChance >= 0.4) {
        $reason = 'kansrijk voor mist rond zonsopkomst';
    } elseif ($harmonie !== null && $harmonie['dense']) {
        $reason = 'lokaal model ziet dichte mist';
    } elseif ($harmonie !== null && $harmonie['fog']) {
        $reason = 'lokaal model ziet mist';
    } elseif ($saturated) {
        $reason = 'verzadigde, windstille lucht: mist mogelijk';
    } elseif ($localMist) {
        $reason = 'condities voor lokale mist';
    } elseif ($openSkyChance >= 0.5) {
        $reason = 'kans op ochtendzon';
    } else {
        $reason = 'weinig uitgesproken fotoweer';
    }
    if ($sunOrMist) {
        $reason = 'modellen verschillen: zon of mist';
    } elseif ($photoRoute === 'mist' && $fogModelsDisagree) {
        $reason = 'modellen verschillen over mist';
    }
    // The values behind the verdict. Near the ground KNMI leads where it reaches the
    // morning; colour comes from ECMWF; the rest is the middle GFS scenario.
    $knmiValues = $harmonie['values'] ?? null;
    $layers = fogCloudLayerMedians($clouds, $forecastDate, $sunriseHour);
    $ground = $knmiValues ?? [
        'visibility_m' => fogMedian($raw['visibility_m']),
        'humidity' => fogMedian($raw['humidity']),
        'temperature' => fogMedian($raw['temperature']),
        'min_temp' => fogMedian($raw['min_temp']),
        'wind' => fogMedian($raw['wind']),
        'cloud_night' => fogMedian($raw['cloud_night']),
        'low_cloud' => $layers['low'] ?? null,
    ];
    $conditions = $ground + [
        'source' => $knmiValues !== null ? 'KNMI' : 'GFS',
        'spread' => fogDewPointSpread((float) $ground['temperature'], (float) $ground['humidity']),
        'layers' => $layers,
        'cloud' => fogMedian($raw['cloud']),
        'cloud_later' => fogMedian($raw['cloud_later']),
        'precipitation' => fogMedian($raw['precipitation']),
        'gusts' => fogMedian($raw['gusts'] ?? []),
        'sunshine_min' => fogMedian($raw['sunshine_min'] ?? []),
        'snow_depth_cm' => fogMedian($raw['snow_depth_cm'] ?? []),
        'fresh_snow_cm' => fogMedian($raw['fresh_snow_cm'] ?? []),
    ];
    $bestMistHour = $bestHour !== null && $bestCount / $complete >= 0.2 ? $bestHour : null;
    $wetShare = $wetWeather / $complete;
    if ($wetShare >= 0.25) {
        $weatherType = $snowyWeather > $wetWeather / 2 ? 'sneeuw'
            : ($showeryWeather > $wetWeather / 2 ? 'buien' : 'regen');
        $weatherSummary = ($wetShare >= 0.65 ? 'Grote kans op ' : 'Kans op ') . $weatherType;
        $weatherIcon = $weatherType === 'sneeuw' ? '❄️'
            : ($weatherType === 'buien' ? '🌦️' : '🌧️');
    } else {
        $weatherSummary = $wetShare >= 0.1 || ($snowChance !== null && $snowChance > 0.0)
            ? 'Overwegend droog' : 'Droog';
        $weatherIcon = '⛅';
        $cloudCover = $cloudCoverTotal / $complete;
        if ($cloudCover >= 75.0) {
            $weatherSummary .= ' en bewolkt';
            $weatherIcon = '☁️';
        } elseif ($cloudCover <= 30.0 && $mistChance < 0.2
            && !$localMist
            && ($harmonie === null || !$harmonie['low_visibility'])) {
            $weatherSummary .= ' en vrij helder';
            $weatherIcon = '☀️';
        } elseif ($cloudCover > 30.0 && $cloudCover < 75.0) {
            $weatherSummary .= ' en halfbewolkt';
        }
    }
    $outlookScores = [];
    if ($withOutlook) {
        try {
            $outlookScores = fogOutlookScores(
                $hourly, $suffixes, $variables, $forecastDate,
                $latitude, $longitude, $zone, $clouds
            );
        } catch (Throwable $outlookError) {
            error_log('Fog outlook: ' . $outlookError->getMessage());
            $outlookScores = [];
        }
    }
    return [
        'gfs_members' => $complete,
        'mist_share' => $mistChance,
        'dense_share' => $denseChance,
        'local_mist_share' => $localMistShare,
        'saturated_share' => $saturatedShare,
        'saturated' => $saturated,
        'sun_share' => $openSkyChance,
        'wet_share' => $wetShare,
        'snow_share' => $snowChance,
        'weather_summary' => $weatherSummary,
        'lifting_share' => $liftingChance,
        'freezing_mist_share' => $freezingMist / $complete,
        'ensemble_score' => $ensembleScore,
        'score' => $photoScore,
        'score_version' => 8,
        'photo_route' => $photoRoute,
        'photo_route_margin' => $photoDay['route_margin'],
        'photo_reason' => $reason,
        'photo_shares' => $photoShares,
        'rime_share' => $photoShares['rime'],
        'dew_share' => $photoShares['dew'],
        'calm_share' => $photoShares['calm'],
        'min_temp' => $photoDay['min_temp'],
        'outlook_scores' => $outlookScores,
        'knmi' => $harmonie,
        'fog_models_disagree' => $fogModelsDisagree,
        'models_disagree' => $modelsDisagree,
        'sun_or_mist' => $sunOrMist,
        'local_mist' => $localMist,
        'fresh_snow_share' => $photoShares['fresh_snow'],
        'fresh_snow_cm' => $photoDay['fresh_snow_cm'],
        'best_mist_hour' => $bestMistHour,
        'weather_icon' => $weatherIcon,
        'color_share' => $colorShare,
        'conditions' => $conditions,
    ];

}

// The Telegram text for one morning, made only from its metrics.
function fogForecastMessage(
    array $metrics,
    DateTimeImmutable $sunrise,
    DateTimeImmutable $firstLight,
    DateTimeZone $zone
): string {
    $photoScore = (int) $metrics['score'];
    $photoRoute = $metrics['photo_route'];
    $reason = $metrics['photo_reason'];
    $modelsDisagree = $metrics['models_disagree'];
    $harmonie = $metrics['knmi'];
    $mistChance = $metrics['mist_share'];
    $denseChance = $metrics['dense_share'];
    $localMist = $metrics['local_mist'];
    $bestMistHour = $metrics['best_mist_hour'];
    $photoShares = $metrics['photo_shares'];
    $photoDay = ['min_temp' => $metrics['min_temp'], 'fresh_snow_cm' => $metrics['fresh_snow_cm']];
    $openSkyChance = $metrics['sun_share'];
    $snowChance = $metrics['snow_share'];
    $lines = [
        fogAdviceLine($photoScore, $firstLight),
        sprintf(
            'Cijfer: %d/10%s — %s.',
            $photoScore,
            $modelsDisagree && ($photoRoute === 'mist' || ($metrics['sun_or_mist'] ?? false))
                ? ' (onzeker)' : ($harmonie === null ? ' (alleen ensemble)' : ''),
            $reason
        ),
        '',
        '☀️↑ ' . $sunrise->format('H:i'),
    ];
    // A 0% mist line is noise; the details hang under it only when it is shown.
    $mistPercent = (int) round($mistChance * 100);
    $mistDetails = [];
    $densePercent = (int) round($denseChance * 100);
    if ($densePercent >= 15) {
        $mistDetails[] = sprintf('Dichte mist: %d%% (zicht <200 m)', $densePercent);
    }
    // With a mist line already shown, "local mist possible" adds nothing.
    if ($localMist && $mistPercent < 10) {
        $mistDetails[] = ($metrics['saturated'] ?? false)
            ? 'Mist mogelijk: verzadigde, windstille lucht' : 'Lokale mist: mogelijk';
    }
    if ($mistPercent >= 10) {
        $lines[] = sprintf('🌁 Mist: %d%% (zicht <1 km)', $mistPercent);
    }
    foreach ($mistDetails as $detail) {
        $lines[] = ($mistPercent >= 10 ? '↳ ' : '🌁 ') . $detail;
    }
    // Mist that lifts into sunlight, or freezes onto everything, is the best of mist.
    if ($mistPercent >= 10 && $metrics['lifting_share'] >= 0.25) {
        $lines[] = '↳ Trekt op in zonlicht';
    }
    if ($mistPercent >= 10 && ($metrics['freezing_mist_share'] ?? 0) >= 0.3) {
        $lines[] = '↳ Bevroren mist: rijp op alles';
    }
    if ($bestMistHour !== null) {
        $lines[] = sprintf('⏱️ Grootste mistkans: rond %02d:00', $bestMistHour);
    }
    $minTemp = (int) round($photoDay['min_temp']);
    if ($photoShares['rime'] >= 0.3) {
        $lines[] = sprintf('✨ Rijp: %d%% · min %s°', (int) round($photoShares['rime'] * 100),
            str_replace('-', '−', (string) $minTemp));
    } elseif ($photoShares['dew'] >= 0.5) {
        $lines[] = sprintf('💧 Dauw: %s · min %d°', fogLikelihood($photoShares['dew']), $minTemp);
    }
    if ($photoShares['calm'] >= 0.6) {
        $lines[] = '🪞 Windstil: spiegelingen mogelijk';
    }
    if (($metrics['color_share'] ?? null) !== null && $metrics['color_share'] >= 0.2) {
        $lines[] = '🌅 Kleur in de lucht: ' . fogLikelihood($metrics['color_share']);
    }
    $lines[] = '🌤️ Ochtendzon: ' . fogLikelihood($openSkyChance);
    if ($snowChance !== null && $snowChance > 0.0) {
        $lines[] = sprintf('❄️ Sneeuw: %d%%', (int) round($snowChance * 100));
    }
    if ($photoShares['fresh_snow'] >= 0.3) {
        $lines[] = sprintf('❄️ Verse sneeuw: %s · ongeveer %d cm',
            fogLikelihood($photoShares['fresh_snow']), (int) round($photoDay['fresh_snow_cm']));
    }
    $lines[] = $metrics['weather_icon'] . ' ' . $metrics['weather_summary'];
    if ($metrics['outlook_scores']) {
        $weekdays = ['Zo', 'Ma', 'Di', 'Wo', 'Do', 'Vr', 'Za'];
        $outlookLabels = [];
        foreach ($metrics['outlook_scores'] as $date => $score) {
            $weekday = (int) (new DateTimeImmutable($date . ' 12:00', $zone))->format('w');
            $outlookLabels[] = $weekdays[$weekday] . ' ' . $score . '/10';
        }
        $lines[] = '';
        $lines[] = 'Vooruitblik: ' . implode(' · ', $outlookLabels);
    }
    return implode("\n", $lines);
}

function fogSendTelegram(array $config, string $chatId, string $text, ?array $replyMarkup = null): int
{
    $token = $config['telegram_bot_token'] ?? null;
    if (!is_string($token) || $token === '') {
        throw new RuntimeException('Telegram bot is not configured');
    }
    $parameters = [
        'chat_id' => $chatId,
        'text' => $text,
    ];
    if ($replyMarkup !== null) {
        $parameters['reply_markup'] = json_encode($replyMarkup, JSON_THROW_ON_ERROR);
    }
    $body = http_build_query($parameters, '', '&', PHP_QUERY_RFC3986);
    try {
        [$status, $response] = fogRequest(
            'https://api.telegram.org/bot' . $token . '/sendMessage',
            $body,
            ['Content-Type: application/x-www-form-urlencoded']
        );
    } catch (Throwable $error) {
        throw new RuntimeException('Telegram delivery request failed', 0, $error);
    }
    $result = json_decode($response, true);
    if ($status !== 200 || !is_array($result) || ($result['ok'] ?? null) !== true) {
        throw new RuntimeException('Telegram delivery returned HTTP ' . $status);
    }
    $messageId = $result['result']['message_id'] ?? null;
    if (!is_int($messageId)) {
        throw new RuntimeException('Telegram delivery had no message ID');
    }
    return $messageId;
}
