<?php
declare(strict_types=1);

// Shared forecast and delivery code for the evening job and Telegram bot.
function fogRequest(string $url, ?string $body = null, array $headers = [], int $timeout = 30): array
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

function fogFindDutchPlace(string $query): array
{
    $url = 'https://geocoding-api.open-meteo.com/v1/search?'
        . http_build_query([
            'name' => $query,
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

    $name = trim(explode(',', $query, 2)[0]);
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
        if ($fold($place['name']) === $fold($name)) {
            $matches[] = $place;
        }
    }
    if (count($matches) === 1) {
        if (strpos($query, ',') !== false && $matches[0]['province'] !== '') {
            $matches[0]['name'] .= ', ' . $matches[0]['province'];
        }
        return ['status' => 'ok', 'place' => $matches[0]];
    }
    if (count($matches) > 1) {
        return ['status' => 'ambiguous', 'places' => $matches];
    }
    return ['status' => 'not_found', 'suggestions' => array_slice($suggestions, 0, 3)];
}

function fogPhotoScore(
    float $mistChance,
    float $denseChance,
    float $forestSignalShare,
    float $openSkyChance,
    float $liftingChance
): int {
    // A personal, mist-focused outing score; forest conditions are only an indirect cue.
    return max(1, min(10, (int) round(
        1.0 + 5.0 * $mistChance + 2.0 * $denseChance
        + $forestSignalShare + 0.5 * $openSkyChance
        + 0.3 * $liftingChance
    )));
}

function fogHarmonieCheck(float $latitude, float $longitude, string $date, array $hours, int $sunriseHour): ?array
{
    try {
        $url = 'https://api.open-meteo.com/v1/forecast?'
            . http_build_query([
                'latitude' => $latitude,
                'longitude' => $longitude,
                'hourly' => 'visibility,weather_code,cloud_cover,relative_humidity_2m,wind_speed_10m',
                'models' => 'knmi_harmonie_arome_netherlands',
                'forecast_days' => 2,
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
                if (!isset($hourly[$variable][$index]) || !is_numeric($hourly[$variable][$index])) {
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
    ?string $targetDate = null
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
        $utcNoon = new DateTimeImmutable($forecastDate . ' 12:00', new DateTimeZone('UTC'));
        $sun = date_sun_info($utcNoon->getTimestamp(), $latitude, $longitude);
        if (!is_array($sun) || !is_int($sun['sunrise'] ?? null)) {
            throw new RuntimeException('Could not calculate sunrise');
        }
        $sunrise = (new DateTimeImmutable('@' . $sun['sunrise']))->setTimezone($zone);
        if ($sunrise->format('Y-m-d') !== $forecastDate) {
            throw new RuntimeException('Sunrise date does not match forecast date');
        }
        $sunriseHour = (int) $sunrise->format('G');
        $hours = range(max(0, $sunriseHour - 2), min(23, $sunriseHour + 2));

        $url = 'https://ensemble-api.open-meteo.com/v1/ensemble?latitude=' . rawurlencode((string) $latitude)
            . '&longitude=' . rawurlencode((string) $longitude)
            . '&hourly=visibility,weather_code,cloud_cover,precipitation,relative_humidity_2m,wind_speed_10m,temperature_2m'
            . '&models=gfs025&forecast_days=2&timezone=Europe%2FAmsterdam';
        [$status, $response] = fogRequest($url);
        if ($status !== 200) {
            throw new RuntimeException('Forecast returned HTTP ' . $status);
        }
        $forecast = json_decode($response, true, 512, JSON_THROW_ON_ERROR);
        $hourly = $forecast['hourly'] ?? null;
        if (!is_array($hourly) || !isset($hourly['time']) || !is_array($hourly['time'])) {
            throw new RuntimeException('Forecast has no hourly data');
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
        $liftingMist = 0;
        $openSky = 0;
        $wetWeather = 0;
        $showeryWeather = 0;
        $snowyWeather = 0;
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

            $complete++;
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
                    $mistByHour[$hour]++;
                    if ((float) $series['visibility'][$index] < 200.0) {
                        $memberDense = true;
                    }
                    if ((float) $series['temperature_2m'][$index] <= 0.0) {
                        $memberFreezing = true;
                    }
                }
            }
            $mist += (int) $memberMist;
            $dense += (int) $memberDense;
            $freezingMist += (int) $memberFreezing;

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

            $beforeHour = max(0, $sunriseHour - 1);
            $beforeIndex = $indices[$beforeHour];
            // An indirect cue for local fog in sheltered woodland, not a fog probability.
            $forestConditions = (float) $series['relative_humidity_2m'][$beforeIndex] >= 95.0
                && (float) $series['wind_speed_10m'][$beforeIndex] <= 10.0
                && (float) $series['cloud_cover'][$beforeIndex] <= 60.0;
            if ($forestConditions) {
                $forestMistSignal++;
            }
            // Modeled dense fog also makes mist in the woods plausible.
            // Count each member once even when both signals are present.
            if ($memberDense || $forestConditions) {
                $forestOutlook++;
            }
            $laterHour = min(23, $sunriseHour + 2);
            $laterIndex = $indices[$laterHour];
            if (($fogAtHour[$beforeHour] || $fogAtHour[$sunriseHour])
                && !$fogAtHour[$laterHour]
                && (float) $series['visibility'][$laterIndex] >= 1500.0) {
                $liftingMist++;
            }
            foreach ([$sunriseHour + 1, $sunriseHour + 2] as $hour) {
                if (isset($indices[$hour])
                    && (float) $series['cloud_cover'][$indices[$hour]] <= 50.0) {
                    $openSky++;
                    break;
                }
            }
        }
        if ($complete < 25) {
            throw new RuntimeException('Too few usable ensemble members: ' . $complete);
        }

        $mistChance = $mist / $complete;
        $denseChance = $dense / $complete;
        $openSkyChance = $openSky / $complete;
        $forestSignalShare = $forestMistSignal / $complete;
        $forestOutlookShare = $forestOutlook / $complete;
        $liftingChance = $liftingMist / $complete;
        $ensembleScore = fogPhotoScore(
            $mistChance,
            $denseChance,
            $forestSignalShare,
            $openSkyChance,
            $liftingChance
        );
        $harmonie = fogHarmonieCheck($latitude, $longitude, $forecastDate, $hours, $sunriseHour);
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
                $photoScore = max($photoScore, 6);
            } elseif ($harmonie['fog']) {
                $photoScore = max($photoScore, 5);
            } elseif ($harmonie['forest_conditions']) {
                $photoScore = max($photoScore, 3);
            }
        }
        if ($forestSignalShare >= 0.8) {
            $photoScore = max($photoScore, 4);
        } elseif ($forestSignalShare >= 0.5) {
            $photoScore = max($photoScore, 3);
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
        if ($denseChance >= 0.5) {
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
            $reason = 'weinig mist, wel kans op ochtendzon';
        } else {
            $reason = 'weinig aanleiding voor mistfoto’s';
        }
        if ($fogModelsDisagree) {
            $reason = 'modellen verschillen over mist';
        } elseif ($forestModelsDisagree) {
            $reason = 'modellen verschillen over bosmistcondities';
        }
        $lines = [
            sprintf(
                'Cijfer: %d/10%s — %s.',
                $photoScore,
                $modelsDisagree ? ' (onzeker)' : ($harmonie === null ? ' (alleen ensemble)' : ''),
                $reason
            ),
            '',
            sprintf('• Zonsopkomst: %s', $sunrise->format('H:i')),
            sprintf('• Mist (zicht <1 km): %d%%', (int) round($mistChance * 100)),
            sprintf('• Dichte mist (zicht <200 m): %d%%', (int) round($denseChance * 100)),
            sprintf(
                '• Bosmist-signaal: %d%%',
                (int) round($forestOutlookShare * 100)
            ),
        ];
        if ($bestHour !== null && $bestCount / $complete >= 0.2) {
            $lines[] = sprintf(
                '• Hoogste mistkans: %d%% rond %02d:00',
                (int) round($bestCount / $complete * 100),
                $bestHour
            );
        }
        if ($liftingChance >= 0.2) {
            $lines[] = sprintf(
                '• Mist die binnen 2 uur optrekt: %d%%',
                (int) round($liftingChance * 100)
            );
        }
        if ($freezingMist / $complete >= 0.2) {
            $lines[] = sprintf(
                '• Mist bij vorst: %d%%',
                (int) round($freezingMist / $complete * 100)
            );
        }
        if ($harmonie !== null) {
            $localLabel = $harmonie['dense'] ? 'dichte mist'
                : ($harmonie['fog'] ? 'mist'
                    : ($harmonie['low_visibility'] ? 'laag zicht' : 'geen mist'));
            if ($harmonie['forest_conditions'] && !$harmonie['fog']) {
                $localLabel .= ', gunstige boscondities';
            }
            $lines[] = '• KNMI-model (2 km): ' . $localLabel;
        } else {
            $lines[] = '• KNMI-model (2 km): nu niet beschikbaar';
        }
        $lines[] = sprintf('• Ruimte voor ochtendzon: %d%%', (int) round($openSkyChance * 100));
        $wetShare = $wetWeather / $complete;
        if ($wetShare >= 0.25) {
            $weatherType = $snowyWeather > $wetWeather / 2 ? 'sneeuw'
                : ($showeryWeather > $wetWeather / 2 ? 'buien' : 'regen');
            $weatherSummary = ($wetShare >= 0.65 ? 'Grote kans op ' : 'Kans op ') . $weatherType;
        } else {
            $weatherSummary = $wetShare >= 0.1 ? 'Overwegend droog' : 'Droog';
            $cloudCover = $cloudCoverTotal / $complete;
            if ($cloudCover >= 75.0) {
                $weatherSummary .= ' en bewolkt';
            } elseif ($cloudCover <= 30.0 && $mistChance < 0.2
                && $forestSignalShare < 0.2
                && ($harmonie === null || (!$harmonie['low_visibility']
                    && !$harmonie['forest_conditions']))) {
                $weatherSummary .= ' en vrij helder';
            } elseif ($cloudCover > 30.0 && $cloudCover < 75.0) {
                $weatherSummary .= ' en halfbewolkt';
            }
        }
        $lines[] = '• ' . $weatherSummary;
        $message = implode("\n", $lines);

        $metrics = [
            'gfs_members' => $complete,
            'mist_share' => $mistChance,
            'dense_share' => $denseChance,
            'forest_conditions_share' => $forestSignalShare,
            'forest_outlook_share' => $forestOutlookShare,
            'sun_share' => $openSkyChance,
            'wet_share' => $wetShare,
            'weather_summary' => $weatherSummary,
            'lifting_share' => $liftingChance,
            'ensemble_score' => $ensembleScore,
            'score' => $photoScore,
            'knmi' => $harmonie,
            'fog_models_disagree' => $fogModelsDisagree,
            'forest_models_disagree' => $forestModelsDisagree,
            'models_disagree' => $modelsDisagree,
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
        $title = 'Fotoweer ' . $placeName . ': gegevens ontbreken';
        $priority = 3;
        $message = 'De fotoweerverwachting voor ' . $dayLabel
            . ' kan nu niet worden berekend. Er is geen betrouwbare mistinschatting.';
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
