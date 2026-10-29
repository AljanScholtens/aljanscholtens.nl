<?php
declare(strict_types=1);

// Shared forecast and delivery code for the evening job and Telegram bot.
function fogRequest(string $url, ?string $body = null, array $headers = [], int $timeout = 30): array
{
    // Several mornings share one model download; remember successful reads for this run.
    static $memo = [];
    if ($body === null && isset($memo[$url])) {
        return $memo[$url];
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

// Accepts "Assen" or "Assen, Drenthe"; the province narrows down duplicate names.
function fogFindDutchPlace(string $query): array
{
    $parts = explode(',', $query, 2);
    $name = trim($parts[0]);
    $province = trim($parts[1] ?? '');
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
        ];
        $suggestions[] = $place;
        if ($fold($place['name']) === $fold($name)
            && ($province === '' || strpos($fold($place['province']), $fold($province)) === 0)) {
            $matches[] = $place;
        }
    }
    if (count($matches) === 1) {
        if ($province !== '' && $matches[0]['province'] !== '') {
            $matches[0]['name'] .= ', ' . $matches[0]['province'];
        }
        return ['status' => 'ok', 'place' => $matches[0]];
    }
    if (count($matches) > 1) {
        return ['status' => 'ambiguous', 'places' => $matches];
    }
    return ['status' => 'not_found', 'suggestions' => array_slice($suggestions, 0, 3)];
}

function fogGroundMistConditions(array $series, array $indices, int $sunriseHour): bool
{
    $earlier = $indices[max(0, $sunriseHour - 2)] ?? null;
    $before = $indices[max(0, $sunriseHour - 1)] ?? null;
    $atSunrise = $indices[$sunriseHour] ?? null;
    if ($earlier === null || $before === null || $atSunrise === null) {
        return false;
    }

    // A cautious cue for shallow mist below eye level, not a fog probability.
    // Clear, moist, calm conditions must persist while eye-level visibility stays good.
    return (float) $series['relative_humidity_2m'][$before] >= 95.0
        && (float) $series['wind_speed_10m'][$before] <= 8.0
        && (float) $series['cloud_cover'][$earlier] <= 50.0
        && (float) $series['cloud_cover'][$before] <= 40.0
        && (float) $series['precipitation'][$before] < 0.1
        && (float) $series['visibility'][$before] >= 1000.0
        && (float) $series['visibility'][$atSunrise] >= 1000.0
        && !in_array((int) $series['weather_code'][$before], [45, 48], true)
        && !in_array((int) $series['weather_code'][$atSunrise], [45, 48], true);
}

function fogGroundMistFavorable(float $share, ?array $harmonie): bool
{
    return $share >= 0.6 && ($harmonie === null || !($harmonie['fog'] ?? false));
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
        $fog = (float) $series['visibility'][$index] < 1000.0
            && in_array((int) $series['weather_code'][$index], [45, 48], true);
        $fogAtHour[$hour] = $fog;
        if ($fog) {
            $memberMist = true;
            if ((float) $series['visibility'][$index] < 200.0) {
                $memberDense = true;
            }
            if ((float) $series['temperature_2m'][$index] <= 0.0) {
                $memberFreezing = true;
            }
        }
    }
    $beforeHour = max(0, $sunriseHour - 1);
    $beforeIndex = $indices[$beforeHour];
    $forestConditions = (float) $series['relative_humidity_2m'][$beforeIndex] >= 95.0
        && (float) $series['wind_speed_10m'][$beforeIndex] <= 10.0
        && (float) $series['cloud_cover'][$beforeIndex] <= 60.0;
    $laterHour = min(23, $sunriseHour + 2);
    $laterIndex = $indices[$laterHour];
    $lifting = ($fogAtHour[$beforeHour] || $fogAtHour[$sunriseHour])
        && !$fogAtHour[$laterHour]
        && (float) $series['visibility'][$laterIndex] >= 1500.0;
    $openSky = false;
    foreach ([$sunriseHour + 1, $sunriseHour + 2] as $hour) {
        if (isset($indices[$hour])
            && (float) $series['cloud_cover'][$indices[$hour]] <= 50.0
            && (float) $series['visibility'][$indices[$hour]] >= 1500.0
            && (float) $series['precipitation'][$indices[$hour]] < 0.1) {
            $openSky = true;
            break;
        }
    }
    return [
        'mist' => $memberMist,
        'dense' => $memberDense,
        'freezing' => $memberFreezing,
        'forest_conditions' => $forestConditions,
        'ground_conditions' => fogGroundMistConditions($series, $indices, $sunriseHour),
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
            && (float) $series['snowfall'][$index] >= 0.1;
        $windAtHour = is_array($series['wind_gusts_10m'])
            && (float) $series['wind_gusts_10m'][$index] >= 55.0;
        $snowing = $snowing || $snowAtHour;
        $windy = $windy || $windAtHour;
        $snowWind = $snowWind || ($snowAtHour && $windAtHour);
    }
    $snowCover = is_array($series['snow_depth'])
        && (float) $series['snow_depth'][$indices[$sunriseHour]] >= 0.02;
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
                || ($cloud <= 50.0 && (float) $series['visibility'][$index] >= 1500.0
                    && (float) $series['precipitation'][$index] < 0.1));
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
            && (float) $series['wind_gusts_10m'][$index] >= 55.0;
        $skyWind = $skyWind || $windAtHour;
        $skyScore = max($skyScore, 5 + 2 * (int) $lightAtHour
            + (int) ($showery && $lightAtHour)
            + (int) ($windAtHour && ($lightAtHour || $showery)));
    }
    // Calm air at sunrise gives reflections in water.
    $calm = (float) $series['wind_speed_10m'][$indices[$sunriseHour]] <= 5.0;
    // These are cues for changing light, not a measured cloud-color probability.
    // A clear, sunny morning is a decent landscape morning, calm air makes it better.
    if (!$dynamicSky) {
        $skyScore = $light ? 4 + (int) $calm : 2;
    }
    $wetGrey = (float) $series['cloud_cover'][$indices[$sunriseHour]] >= 85.0
        && (float) $series['precipitation'][$indices[$sunriseHour]] >= 0.1;
    if (!$dynamicSky && !$light && $wetGrey) {
        $skyScore = 1;
    }

    $mistScore = $signals['mist']
        ? 6 + (int) $signals['dense'] + (int) $signals['lifting'] + (int) $light
        : ($signals['forest_conditions'] ? 3 + (int) $light : 1);
    // A clear, calm night that cools to freezing leaves rime; above freezing, dew.
    // 2 m air is often a degree warmer than the grass, hence the 1 degree margin.
    $beforeIndex = $indices[max(0, $sunriseHour - 1)];
    $minTemp = min((float) $series['temperature_2m'][$beforeIndex],
        (float) $series['temperature_2m'][$indices[$sunriseHour]]);
    $clearCalmNight = (float) $series['wind_speed_10m'][$beforeIndex] <= 10.0
        && (float) $series['cloud_cover'][$beforeIndex] <= 40.0
        && (float) $series['precipitation'][$beforeIndex] < 0.1;
    $rime = $clearCalmNight && $minTemp <= 1.0
        && (float) $series['relative_humidity_2m'][$beforeIndex] >= 85.0;
    $dew = $clearCalmNight && !$rime
        && (float) $series['relative_humidity_2m'][$beforeIndex] >= 95.0;
    $frostScore = $rime ? 6 + (int) $light + (int) ($minTemp <= -2.0)
        : ($dew ? 4 + (int) $light : 1);
    $winterScore = ($snowCover || $snowing)
        ? 5 + (int) ($snowCover && $snowing) + (int) $light
            + (int) $dynamicSky + (int) ($snowWind || ($snowCover && $skyWind))
        : 1;
    return [
        'mist' => min(9, $mistScore),
        'winter' => min(9, $winterScore),
        'sky' => min(9, $skyScore),
        'frost' => min(9, $frostScore),
        'rime' => $rime,
        'dew' => $dew,
        'calm' => $calm,
        'min_temp' => $minTemp,
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
        'rime' => 0, 'dew' => 0, 'calm' => 0];
    $total = 0;
    $minTemps = [];
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
    }
    sort($minTemps, SORT_NUMERIC);
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
    ];
}

function fogOutlookScores(
    array $hourly,
    array $suffixes,
    array $variables,
    string $forecastDate,
    float $latitude,
    float $longitude,
    DateTimeZone $zone
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
            $signals = fogMemberPhotoSignals($series, $indices, $sunriseHour);
            $scenes[] = fogMemberPhotoScenes($series, $indices, $sunriseHour, $signals);
        }
        if (count($scenes) < 25) {
            throw new RuntimeException('Too few usable outlook members: ' . count($scenes));
        }
        $scores[$date] = fogPhotoDayScore($scenes)['score'];
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
                'hourly' => 'visibility,weather_code,cloud_cover,relative_humidity_2m,wind_speed_10m',
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
        $variables = ['visibility', 'weather_code', 'cloud_cover', 'relative_humidity_2m', 'wind_speed_10m'];
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
            $lowVisibility = $lowVisibility || $visibility < 1000.0;
            if ($visibility < 1000.0
                && in_array((int) $hourly['weather_code'][$index], [45, 48], true)) {
                $fog = true;
                $dense = $dense || $visibility < 200.0;
            }
        }
        $before = $indices[max(0, $sunriseHour - 1)];
        $forestConditions = (float) $hourly['relative_humidity_2m'][$before] >= 95.0
            && (float) $hourly['wind_speed_10m'][$before] <= 10.0
            && (float) $hourly['cloud_cover'][$before] <= 60.0;
        return [
            'fog' => $fog,
            'dense' => $dense,
            'low_visibility' => $lowVisibility,
            'forest_conditions' => $forestConditions,
            'min_visibility_m' => (int) round($minVisibility),
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
        $sunriseHour = fogSunriseHour($sunrise);
        $hours = range(max(0, $sunriseHour - 2), min(23, $sunriseHour + 2));

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
                break;
            } catch (Throwable $extendedError) {
                if ($forecastDays === $baseDays) {
                    throw $extendedError;
                }
                error_log('Fog extended forecast: ' . $extendedError->getMessage());
            }
        }

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
        $forestMistSignal = 0;
        $forestOutlook = 0;
        $groundMistConditions = 0;
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

            $complete++;
            $memberSnowfall = fogMemberSnowfall($hourly, $suffix, $indices, $weatherHours);
            if ($memberSnowfall !== null) {
                $snowfallMembers++;
                $snowfallEvents += (int) $memberSnowfall;
            }
            $signals = fogMemberPhotoSignals($series, $indices, $sunriseHour);
            $photoScenes[] = fogMemberPhotoScenes($series, $indices, $sunriseHour, $signals);
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

            // An indirect cue for local fog in sheltered woodland, not a fog probability.
            $forestMistSignal += (int) $signals['forest_conditions'];
            // Modeled dense fog also makes mist in the woods plausible.
            // Count each member once even when both signals are present.
            $forestOutlook += (int) ($signals['dense'] || $signals['forest_conditions']);
            $groundMistConditions += (int) $signals['ground_conditions'];
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
        $forestSignalShare = $forestMistSignal / $complete;
        $forestOutlookShare = $forestOutlook / $complete;
        $groundMistConditionsShare = $groundMistConditions / $complete;
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
        $forestModelsDisagree = $harmonie !== null && (
            ($harmonie['forest_conditions'] && $forestSignalShare < 0.2)
            || (!$harmonie['forest_conditions'] && $forestSignalShare >= 0.5)
        );
        $modelsDisagree = $fogModelsDisagree || $forestModelsDisagree;
        $photoScore = $ensembleScore;
        // The local run is a single scenario, so it can lift a weak ensemble
        // score without being treated as a percentage or a certain outcome.
        if ($harmonie !== null) {
            if ($harmonie['dense']) {
                if ($photoScore < 6) {
                    $photoScore = 6;
                    $photoRoute = 'mist';
                }
            } elseif ($harmonie['fog']) {
                if ($photoScore < 5) {
                    $photoScore = 5;
                    $photoRoute = 'mist';
                }
            } elseif ($harmonie['forest_conditions']) {
                if ($photoScore < 3) {
                    $photoScore = 3;
                    $photoRoute = 'mist';
                }
            }
        }
        if ($forestSignalShare >= 0.8) {
            if ($photoScore < 4) {
                $photoScore = 4;
                $photoRoute = 'mist';
            }
        } elseif ($forestSignalShare >= 0.5) {
            if ($photoScore < 3) {
                $photoScore = 3;
                $photoRoute = 'mist';
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

        $title = sprintf('Fotoweer %s (%s)', $sunrise->format('d-m'), $placeName);
        $priority = $denseChance >= 0.8 ? 4 : 3;
        $photoShares = $photoDay['shares'];
        if ($photoRoute === 'winter') {
            if ($photoShares['snow_landscape_light'] >= 0.3) {
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
        } elseif ($forestSignalShare >= 0.4) {
            $reason = 'condities voor lokale bosmist';
        } elseif ($openSkyChance >= 0.5) {
            $reason = 'kans op ochtendzon';
        } else {
            $reason = 'weinig uitgesproken fotoweer';
        }
        if ($photoRoute === 'mist' && $fogModelsDisagree) {
            $reason = 'modellen verschillen over mist';
        } elseif ($photoRoute === 'mist' && $forestModelsDisagree) {
            $reason = 'modellen verschillen over bosmistcondities';
        }
        $lines = [
            fogAdviceLine($photoScore, $firstLight),
            sprintf(
                'Cijfer: %d/10%s — %s.',
                $photoScore,
                $modelsDisagree && $photoRoute === 'mist'
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
        $groundFavorable = fogGroundMistFavorable($groundMistConditionsShare, $harmonie);
        if ($groundFavorable) {
            $mistDetails[] = 'Grondmist: mogelijk';
        }
        $forestFavorable = $forestOutlookShare >= 0.5 && !$modelsDisagree;
        if ($forestFavorable) {
            $mistDetails[] = 'Bosmist: omstandigheden gunstig';
        }
        if ($mistPercent >= 10) {
            $lines[] = sprintf('🌁 Mist: %d%% (zicht <1 km)', $mistPercent);
        }
        foreach ($mistDetails as $detail) {
            $lines[] = ($mistPercent >= 10 ? '↳ ' : '🌁 ') . $detail;
        }
        $bestMistHour = $bestHour !== null && $bestCount / $complete >= 0.2 ? $bestHour : null;
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
        $lines[] = '🌤️ Ochtendzon: ' . fogLikelihood($openSkyChance);
        if ($snowChance !== null && $snowChance > 0.0) {
            $lines[] = sprintf('❄️ Sneeuw: %d%%', (int) round($snowChance * 100));
        }
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
                && $forestSignalShare < 0.2
                && ($harmonie === null || (!$harmonie['low_visibility']
                    && !$harmonie['forest_conditions']))) {
                $weatherSummary .= ' en vrij helder';
                $weatherIcon = '☀️';
            } elseif ($cloudCover > 30.0 && $cloudCover < 75.0) {
                $weatherSummary .= ' en halfbewolkt';
            }
        }
        $lines[] = $weatherIcon . ' ' . $weatherSummary;
        $outlookScores = [];
        if ($includeOutlook && $forecastDays === $outlookDays) {
            try {
                $outlookScores = fogOutlookScores(
                    $hourly, $suffixes, $variables, $forecastDate,
                    $latitude, $longitude, $zone
                );
                $weekdays = ['Zo', 'Ma', 'Di', 'Wo', 'Do', 'Vr', 'Za'];
                $outlookLabels = [];
                foreach ($outlookScores as $date => $score) {
                    $weekday = (int) (new DateTimeImmutable($date . ' 12:00', $zone))->format('w');
                    $outlookLabels[] = $weekdays[$weekday] . ' ' . $score . '/10';
                }
                $lines[] = '';
                $lines[] = 'Vooruitblik: ' . implode(' · ', $outlookLabels);
            } catch (Throwable $outlookError) {
                error_log('Fog outlook: ' . $outlookError->getMessage());
                $outlookScores = [];
            }
        }
        $message = implode("\n", $lines);

        $metrics = [
            'gfs_members' => $complete,
            'mist_share' => $mistChance,
            'dense_share' => $denseChance,
            'forest_conditions_share' => $forestSignalShare,
            'forest_outlook_share' => $forestOutlookShare,
            'ground_conditions_share' => $groundMistConditionsShare,
            'sun_share' => $openSkyChance,
            'wet_share' => $wetShare,
            'snow_share' => $snowChance,
            'weather_summary' => $weatherSummary,
            'lifting_share' => $liftingChance,
            'ensemble_score' => $ensembleScore,
            'score' => $photoScore,
            'score_version' => 4,
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
            'forest_models_disagree' => $forestModelsDisagree,
            'models_disagree' => $modelsDisagree,
            'ground_favorable' => $groundFavorable,
            'forest_favorable' => $forestFavorable,
            'best_mist_hour' => $bestMistHour,
        ];

        $checkStatus = sprintf(
            "%s; datum=%s; zon=%s; cijfer=%d/10; mist=%.1f%%; dicht=%.1f%%; bosmist-signaal=%.1f%%; scenario's=%d\n",
            $now->format('Y-m-d H:i:sP'),
            $forecastDate,
            $sunrise->format('H:i'),
            $photoScore,
            $mistChance * 100,
            $denseChance * 100,
            $forestSignalShare * 100,
            $complete
        );
    } catch (Throwable $forecastError) {
        error_log('Fog forecast: ' . $forecastError->getMessage());
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
