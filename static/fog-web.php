<?php
declare(strict_types=1);

// Shared code for the public /weer page: display-ready mornings and place search.
// It only defines functions; ~/private/fog-web-cron.php and weer-data.php use it.
require_once __DIR__ . '/fog-weather.php';

const FOG_WEB_WEEKDAYS = ['zo', 'ma', 'di', 'wo', 'do', 'vr', 'za'];
const FOG_WEB_MONTHS = ['jan', 'feb', 'mrt', 'apr', 'mei', 'jun', 'jul', 'aug', 'sep', 'okt', 'nov', 'dec'];

// Turns one forecast into display-ready values. The thresholds match the Telegram
// message, so the page and the evening alert never tell a different story.
function fogWebDay(array $forecast, array $place, DateTimeZone $zone): array
{
    $date = (string) $forecast['date'];
    [$sunrise, , $firstLight] = fogSunTimes($date, (float) $place['latitude'],
        (float) $place['longitude'], $zone);
    $day = new DateTimeImmutable($date . ' 12:00', $zone);
    $result = [
        'date' => $date,
        'weekday' => FOG_WEB_WEEKDAYS[(int) $day->format('w')],
        'day_label' => $day->format('j') . ' ' . FOG_WEB_MONTHS[(int) $day->format('n') - 1],
        'sunrise' => $sunrise->format('H:i'),
        'sunrise_at' => $sunrise->format(DATE_ATOM),
        'first_light' => $firstLight->format('H:i'),
    ];
    $metrics = $forecast['metrics'] ?? null;
    if (!is_array($metrics)) {
        return $result + ['error' => 'Geen gegevens beschikbaar'];
    }

    $score = (int) $metrics['score'];
    $advice = fogAdvice($score);
    $mistPercent = (int) round((float) $metrics['mist_share'] * 100);
    $densePercent = (int) round((float) $metrics['dense_share'] * 100);
    $shares = $metrics['photo_shares'];
    $minTemp = (int) round((float) $metrics['min_temp']);
    $snow = $metrics['snow_share'];
    return $result + [
        'score' => $score,
        'advice' => $advice,
        'advice_label' => ['go' => 'Uitstekend', 'maybe' => 'Prima', 'skip' => 'Neutraal'][$advice],
        'wake' => $advice === 'skip' ? null : fogWakeTime($firstLight)->format('H:i'),
        'reason' => (string) $metrics['photo_reason'],
        'uncertain' => $metrics['models_disagree'] && $metrics['photo_route'] === 'mist',
        // Without the local KNMI run the morning rests on the coarse ensemble alone.
        'preliminary' => $metrics['knmi'] === null,
        'mist' => $mistPercent >= 10 ? [
            'percent' => $mistPercent,
            'dense_percent' => $densePercent >= 15 ? $densePercent : null,
            'best_hour' => $metrics['best_mist_hour'] === null
                ? null : sprintf('%02d:00', $metrics['best_mist_hour']),
        ] : null,
        'local_mist' => (bool) ($metrics['local_mist'] ?? false),
        'fresh_snow_cm' => ($metrics['fresh_snow_share'] ?? 0) >= 0.3
            ? (int) round((float) $metrics['fresh_snow_cm']) : null,
        'rime' => $shares['rime'] >= 0.3
            ? ['percent' => (int) round($shares['rime'] * 100), 'min_temp' => $minTemp] : null,
        'dew' => $shares['rime'] < 0.3 && $shares['dew'] >= 0.5
            ? ['label' => fogLikelihood($shares['dew']), 'min_temp' => $minTemp] : null,
        'calm' => $shares['calm'] >= 0.6,
        'sun' => fogLikelihood((float) $metrics['sun_share']),
        'snow_percent' => is_numeric($snow) && $snow > 0 ? (int) round($snow * 100) : null,
        'weather' => (string) $metrics['weather_summary'],
    ];
}

// Builds the coming mornings. Today is included until its sunrise has passed.
function fogWebData(DateTimeImmutable $now, DateTimeZone $zone, array $place, int $mornings = 3): array
{
    [$todaySunrise] = fogSunTimes($now->format('Y-m-d'), (float) $place['latitude'],
        (float) $place['longitude'], $zone);
    // Before sunrise today counts as an extra morning, so the page can drop it
    // itself once the sun is up, without waiting for the next run.
    $first = $now < $todaySunrise ? 0 : 1;
    $days = [];
    for ($offset = $first; $offset <= $mornings; $offset++) {
        $date = $now->modify('+' . $offset . ' days')->format('Y-m-d');
        $days[] = fogWebDay(fogBuildForecast($now, $zone, $place, $date, false), $place, $zone);
    }
    return [
        'place' => $place['name'],
        'generated_at' => $now->format(DATE_ATOM),
        'days' => $days,
    ];
}

const FOG_WEB_CACHE_SECONDS = 7200;
const FOG_WEB_SEARCHES_PER_CLIENT_HOUR = 20;
const FOG_WEB_CALCULATIONS_PER_DAY = 200;

// Counts a search or a fresh calculation and says whether it is still allowed.
// Clients are kept as a short hash of their address, never the address itself.
function fogWebAllow(string $privateDir, string $client, string $kind, DateTimeImmutable $now): bool
{
    $path = $privateDir . '/fog-web-limits.json';
    $handle = fopen($path, 'c+');
    if ($handle === false || !flock($handle, LOCK_EX)) {
        throw new RuntimeException('Could not lock search limits');
    }
    try {
        $state = json_decode((string) stream_get_contents($handle), true);
        $today = $now->format('Y-m-d');
        if (!is_array($state) || ($state['day'] ?? null) !== $today) {
            $state = ['day' => $today, 'calculations' => 0, 'clients' => []];
        }
        $hourAgo = $now->getTimestamp() - 3600;
        foreach ($state['clients'] as $key => $times) {
            $state['clients'][$key] = array_values(array_filter((array) $times,
                static function ($time) use ($hourAgo): bool {
                    return is_int($time) && $time > $hourAgo;
                }));
            if (!$state['clients'][$key]) {
                unset($state['clients'][$key]);
            }
        }
        $key = substr(hash('sha256', $client), 0, 16);
        if ($kind === 'search') {
            $allowed = count($state['clients'][$key] ?? []) < FOG_WEB_SEARCHES_PER_CLIENT_HOUR;
            if ($allowed) {
                $state['clients'][$key][] = $now->getTimestamp();
            }
        } else {
            $allowed = $state['calculations'] < FOG_WEB_CALCULATIONS_PER_DAY;
            if ($allowed) {
                $state['calculations']++;
            }
        }
        ftruncate($handle, 0);
        rewind($handle);
        fwrite($handle, json_encode($state, JSON_THROW_ON_ERROR));
        @chmod($path, 0600);
        return $allowed;
    } finally {
        flock($handle, LOCK_UN);
        fclose($handle);
    }
}

function fogWebPlaceLabel(array $place): string
{
    return $place['province'] !== '' ? $place['name'] . ', ' . $place['province'] : $place['name'];
}

// Finds a Dutch place and returns its mornings, from the cache when it is fresh.
// Returns ['status' => ok|choose|not_found|invalid|limited, ...].
function fogWebSearch(
    string $query,
    string $privateDir,
    DateTimeImmutable $now,
    DateTimeZone $zone,
    string $client
): array {
    $query = trim((string) preg_replace('/\s+/u', ' ', $query));
    if ($query === '' || strlen($query) > 60 || preg_match('/[\x00-\x1F\x7F<>]/', $query)) {
        return ['status' => 'invalid'];
    }
    if (!fogWebAllow($privateDir, $client, 'search', $now)) {
        return ['status' => 'limited'];
    }
    $search = fogFindDutchPlace($query);
    if ($search['status'] === 'ambiguous') {
        // The query repeats the search with a qualifier; the label reads naturally.
        $choices = [];
        foreach (array_slice($search['places'], 0, 6) as $candidate) {
            $byMunicipality = $candidate['qualifier'] !== $candidate['province'];
            $choices[] = [
                'label' => $byMunicipality
                    ? $candidate['name'] . ' (gemeente ' . $candidate['qualifier'] . ')'
                    : $candidate['name'] . ', ' . $candidate['province'],
                'query' => $candidate['name'] . ', ' . $candidate['qualifier'],
            ];
        }
        return ['status' => 'choose', 'places' => $choices];
    }
    if ($search['status'] !== 'ok') {
        return ['status' => 'not_found',
            'suggestions' => array_map('fogWebPlaceLabel', $search['suggestions'])];
    }

    $place = $search['place'];
    // Two decimals is about a kilometre: close enough to share one forecast.
    $cachePath = sprintf('%s/fog-web-cache/%.2f_%.2f.json', $privateDir,
        $place['latitude'], $place['longitude']);
    $fresh = static function () use ($cachePath, $now): ?array {
        if (!is_file($cachePath) || filemtime($cachePath) < $now->getTimestamp() - FOG_WEB_CACHE_SECONDS) {
            return null;
        }
        $data = json_decode((string) file_get_contents($cachePath), true);
        return is_array($data) ? $data : null;
    };
    $data = $fresh();
    if ($data === null) {
        // One calculation at a time; a visitor who waited may find it already done.
        $lock = fopen($privateDir . '/fog-web-search.lock', 'c');
        if ($lock === false || !flock($lock, LOCK_EX)) {
            throw new RuntimeException('Could not lock place calculation');
        }
        $data = $fresh();
        if ($data === null) {
            if (!fogWebAllow($privateDir, $client, 'calculation', $now)) {
                return ['status' => 'limited'];
            }
            $data = fogWebData($now, $zone, $place);
            foreach ($data['days'] as $day) {
                if (!isset($day['error'])) {
                    fogSaveJson($cachePath, $data);
                    break;
                }
            }
        }
    }
    return ['status' => 'ok', 'data' => $data];
}

// Removes cached places nobody looked at for a day.
function fogWebPruneCache(string $privateDir, DateTimeImmutable $now): void
{
    foreach (glob($privateDir . '/fog-web-cache/*.json') ?: [] as $path) {
        if (filemtime($path) < $now->getTimestamp() - 86400) {
            @unlink($path);
        }
    }
}
