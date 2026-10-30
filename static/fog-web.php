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
    return $result + [
        'score' => $score,
        'advice' => $advice,
        'advice_label' => ['go' => 'Uitstekend', 'maybe' => 'Prima', 'skip' => 'Neutraal'][$advice],
        'wake' => $advice === 'skip' ? null : fogWakeTime($firstLight)->format('H:i'),
        'reason' => (string) $metrics['photo_reason'],
        'uncertain' => $metrics['models_disagree']
            && ($metrics['photo_route'] === 'mist' || ($metrics['sun_or_mist'] ?? false)),
        // Without the local KNMI run the morning rests on the coarse ensemble alone.
        'preliminary' => $metrics['knmi'] === null,
        'categories' => fogWebCategories($metrics),
        'weather' => (string) $metrics['weather_summary'],
    ];
}

// A Dutch number: a comma for decimals, a proper minus sign.
function fogWebNumber(float $value, int $decimals = 0): string
{
    return str_replace(['.', '-'], [',', '−'], number_format($value, $decimals, '.', ''));
}

// The weather values behind each photo chance, as short icon facts with a full label
// for hover and screen readers. Values the models do not give are left out.
function fogWebFacts(array $conditions): array
{
    $fact = static function (string $icon, string $label, ?string $text): ?array {
        return $text === null ? null : ['icon' => $icon, 'label' => $label, 'text' => $text];
    };
    $value = static function (string $key, callable $format) use ($conditions): ?string {
        // A formatter may return null to leave a value out.
        return is_numeric($conditions[$key] ?? null) ? $format((float) $conditions[$key]) : null;
    };
    $percent = static function (float $number): string {
        return fogWebNumber($number) . '%';
    };
    $kmh = static function (float $number): string {
        return fogWebNumber($number) . ' km/u';
    };
    $visibility = $value('visibility_m', static function (float $metres): string {
        // One decimal only where it helps: 2,3 km, but 24 km.
        if ($metres < 1000) {
            return fogWebNumber($metres) . ' m';
        }
        return fogWebNumber($metres / 1000, $metres < 10000 ? 1 : 0) . ' km';
    });
    $wind = $fact('wind', 'Wind', $value('wind', $kmh));
    $humidity = $fact('humidity', 'Luchtvochtigheid', $value('humidity', $percent));
    $layers = is_array($conditions['layers'] ?? null) ? $conditions['layers'] : null;
    $filter = static function (array $facts): array {
        return array_values(array_filter($facts));
    };
    $cloud = $value('cloud', $percent);
    $cloudLater = $value('cloud_later', $percent);
    return [
        'mist' => $filter([
            $fact('visibility', 'Zicht', $visibility),
            $humidity,
            $fact('spread', 'Verschil tussen temperatuur en dauwpunt',
                $value('spread', static function (float $degrees): string {
                    return fogWebNumber($degrees, 1) . '°';
                })),
            $wind,
            $fact('cloud', 'Lage bewolking', $value('low_cloud', static function (float $number): string {
                return 'L ' . fogWebNumber($number) . '%';
            })),
        ]),
        'color' => $layers === null ? [] : [
            $fact('cloud', 'Lage bewolking', 'L ' . fogWebNumber($layers['low']) . '%'),
            $fact('cloud', 'Middelhoge bewolking', 'M ' . fogWebNumber($layers['mid']) . '%'),
            $fact('cloud', 'Hoge bewolking', 'H ' . fogWebNumber($layers['high']) . '%'),
        ],
        'sky' => $filter([
            $fact('cloud', 'Bewolking bij zonsopkomst en een uur later',
                $cloud !== null && $cloudLater !== null ? $cloud . ' → ' . $cloudLater : null),
            $fact('gusts', 'Windstoten', $value('gusts', $kmh)),
            $fact('rain', 'Neerslag rond zonsopkomst', $value('precipitation', static function (float $mm): string {
                return fogWebNumber($mm, 1) . ' mm';
            })),
        ]),
        'sun' => $filter([
            // Sunshine minutes are left out: in the hour after sunrise the sun is up for
            // only part of it, and GFS then nearly always reports none.
            $fact('cloud', 'Bewolking een uur na zonsopkomst', $cloudLater),
            $wind,
        ]),
        'frost' => $filter([
            $fact('temperature', 'Laagste temperatuur rond zonsopkomst', $value('min_temp', static function (float $degrees): string {
                return 'min ' . fogWebNumber($degrees) . '°';
            })),
            $humidity,
            $fact('cloud', 'Bewolking in de nacht', $value('cloud_night', $percent)),
            $wind,
        ]),
        'snow' => $filter([
            // Snow amounts only when there is snow; "0 cm" twice is just noise.
            $fact('snow', 'Verse sneeuw sinds de avond', $value('fresh_snow_cm', static function (float $cm): ?string {
                return $cm >= 0.5 ? 'vers ' . fogWebNumber($cm) . ' cm' : null;
            })),
            $fact('snow', 'Sneeuwdek bij zonsopkomst', $value('snow_depth_cm', static function (float $cm): ?string {
                return $cm >= 0.5 ? 'dek ' . fogWebNumber($cm) . ' cm' : null;
            })),
            $fact('temperature', 'Temperatuur', $value('temperature', static function (float $degrees): string {
                return fogWebNumber($degrees) . '°';
            })),
        ]),
    ];
}

// The six photo chances in a fixed order, so mornings line up side by side. A value
// of null means nothing to expect; notes are short details, at most two. The
// category behind the score is marked as the lead.
function fogWebCategories(array $metrics): array
{
    $shares = $metrics['photo_shares'];
    $percent = static function (float $share): int {
        return (int) round($share * 100);
    };
    // The same minimum as in the facts below, so one row never shows two numbers.
    $minTemp = (int) round((float) ($metrics['conditions']['min_temp'] ?? $metrics['min_temp']));
    $degrees = str_replace('-', '−', (string) $minTemp) . '°';
    $mistPercent = $percent((float) $metrics['mist_share']);

    $mistNotes = [];
    if ($mistPercent < 10 && ($metrics['saturated'] ?? false)) {
        $mistNotes[] = 'verzadigde, windstille lucht';
    }
    if ($mistPercent >= 10) {
        if (($metrics['freezing_mist_share'] ?? 0) >= 0.3) {
            $mistNotes[] = 'bevroren mist';
        }
        if ($metrics['lifting_share'] >= 0.25) {
            $mistNotes[] = 'trekt op in zonlicht';
        }
        if ($percent((float) $metrics['dense_share']) >= 15) {
            $mistNotes[] = 'dicht ' . $percent((float) $metrics['dense_share']) . '%';
        }
        if ($metrics['best_mist_hour'] !== null) {
            $mistNotes[] = sprintf('rond %02d:00', $metrics['best_mist_hour']);
        }
    }
    $color = $metrics['color_share'] ?? null;
    $skyNotes = [];
    if (($shares['sky_light'] ?? 0) >= 0.3) {
        $skyNotes[] = 'licht tussen de wolken';
    }
    if (($shares['sky_wind'] ?? 0) >= 0.3) {
        $skyNotes[] = 'harde wind';
    }
    $frost = null;
    if ($shares['rime'] >= 0.3) {
        $frost = 'rijp ' . fogLikelihood($shares['rime']);
    } elseif ($shares['dew'] >= 0.5) {
        $frost = 'dauw ' . fogLikelihood($shares['dew']);
    }
    $frostNotes = $frost === null ? [] : array_merge(['min ' . $degrees],
        $minTemp <= FOG_HARD_FROST_TEMP && $shares['rime'] >= 0.3 ? ['strenge vorst'] : []);
    $snowFalling = is_numeric($metrics['snow_share'] ?? null) && $metrics['snow_share'] > 0
        ? $percent((float) $metrics['snow_share']) : 0;
    if (($metrics['fresh_snow_share'] ?? 0) >= 0.3) {
        $snow = 'vers, ongeveer ' . (int) round((float) $metrics['fresh_snow_cm']) . ' cm';
    } elseif ($snowFalling > 0) {
        $snow = 'sneeuwval ' . $snowFalling . '%';
    } elseif ($shares['snow_cover'] >= 0.3) {
        $snow = 'sneeuwdek';
    } else {
        $snow = null;
    }
    $snowNotes = [];
    if ($snow !== null && $snowFalling > 0 && strpos($snow, 'sneeuwval') !== 0) {
        $snowNotes[] = 'sneeuwval ' . $snowFalling . '%';
    }
    if (($shares['snow_wind_sky'] ?? 0) >= 0.4) {
        $snowNotes[] = 'sneeuwbuien met wind';
    }

    // Changing skies and a clear sunny morning share one score route; tell them apart.
    $lead = ['mist' => 'mist', 'color' => 'color', 'frost' => 'frost', 'winter' => 'snow',
        'sky' => ($shares['dynamic_sky'] ?? 0) >= 0.4 ? 'sky' : 'sun'][$metrics['photo_route']] ?? null;
    $categories = [
        ['key' => 'mist', 'label' => 'Mist',
            'value' => $mistPercent >= 10 ? $mistPercent . '%'
                : (($metrics['saturated'] ?? false) ? 'mogelijk'
                    : (($metrics['local_mist'] ?? false) ? 'lokaal mogelijk' : null)),
            'notes' => $mistNotes],
        ['key' => 'color', 'label' => 'Kleur',
            'value' => is_numeric($color) && $color >= 0.2 ? fogLikelihood((float) $color) : null,
            'notes' => []],
        ['key' => 'sky', 'label' => 'Wolkenlucht',
            'value' => ($shares['dynamic_sky'] ?? 0) >= 0.2 ? fogLikelihood($shares['dynamic_sky']) : null,
            'notes' => $skyNotes],
        ['key' => 'sun', 'label' => 'Zon',
            'value' => $metrics['sun_share'] >= 0.2 ? fogLikelihood((float) $metrics['sun_share']) : null,
            'notes' => $shares['calm'] >= 0.6 ? ['windstil · spiegelingen'] : []],
        ['key' => 'frost', 'label' => 'Rijp/dauw', 'value' => $frost, 'notes' => $frostNotes],
        ['key' => 'snow', 'label' => 'Sneeuw', 'value' => $snow, 'notes' => $snowNotes],
    ];
    $conditions = is_array($metrics['conditions'] ?? null) ? $metrics['conditions'] : [];
    $facts = fogWebFacts($conditions);
    // Near the ground KNMI leads where it reaches; colour is ECMWF; the rest is GFS.
    $sources = ['mist' => $conditions['source'] ?? null, 'color' => 'ECMWF', 'sky' => 'GFS',
        'sun' => 'GFS', 'frost' => $conditions['source'] ?? null, 'snow' => 'GFS'];
    foreach ($categories as $index => $category) {
        $categories[$index]['facts'] = $conditions ? $facts[$category['key']] : [];
        $categories[$index]['source'] = $categories[$index]['facts'] ? $sources[$category['key']] : null;
        $categories[$index]['notes'] = array_slice($category['notes'], 0, 2);
        $categories[$index]['lead'] = $category['key'] === $lead && $category['value'] !== null;
    }
    return $categories;
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
