<?php
declare(strict_types=1);

// Shared forecast and delivery code for the evening job and Telegram bot.
function fogRequest(string $url, ?string $body = null, array $headers = []): array
{
    if (function_exists('curl_init')) {
        $curl = curl_init($url);
        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 30,
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
            'timeout' => 30,
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

function fogBuildForecast(DateTimeImmutable $now, DateTimeZone $zone): array
{
    $tomorrow = $now->modify('+1 day')->format('Y-m-d');
    try {
        // Use the local sunrise so the photo window follows the seasons.
        $utcNoon = new DateTimeImmutable($tomorrow . ' 12:00', new DateTimeZone('UTC'));
        $sun = date_sun_info($utcNoon->getTimestamp(), 53.07165252, 6.57674584);
        if (!is_array($sun) || !is_int($sun['sunrise'] ?? null)) {
            throw new RuntimeException('Could not calculate sunrise');
        }
        $sunrise = (new DateTimeImmutable('@' . $sun['sunrise']))->setTimezone($zone);
        if ($sunrise->format('Y-m-d') !== $tomorrow) {
            throw new RuntimeException('Sunrise date does not match tomorrow');
        }
        $sunriseHour = (int) $sunrise->format('G');
        $hours = range(max(0, $sunriseHour - 2), min(23, $sunriseHour + 2));

        $url = 'https://ensemble-api.open-meteo.com/v1/ensemble?latitude=53.07165252'
            . '&longitude=6.57674584'
            . '&hourly=visibility,weather_code,cloud_cover,relative_humidity_2m,wind_speed_10m,temperature_2m'
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
            $time = $tomorrow . 'T' . sprintf('%02d', $hour) . ':00';
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
            'visibility', 'weather_code', 'cloud_cover',
            'relative_humidity_2m', 'wind_speed_10m', 'temperature_2m',
        ];
        $complete = 0;
        $mist = 0;
        $dense = 0;
        $freezingMist = 0;
        $forestMistSignal = 0;
        $liftingMist = 0;
        $openSky = 0;
        $windSum = 0.0;
        $mistByHour = array_fill_keys($hours, 0);
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

            $beforeHour = max(0, $sunriseHour - 1);
            $beforeIndex = $indices[$beforeHour];
            // An indirect cue for local fog in sheltered woodland, not a fog probability.
            if ((float) $series['relative_humidity_2m'][$beforeIndex] >= 95.0
                && (float) $series['wind_speed_10m'][$beforeIndex] <= 10.0
                && (float) $series['cloud_cover'][$beforeIndex] <= 60.0) {
                $forestMistSignal++;
            }
            $windSum += (float) $series['wind_speed_10m'][$indices[$sunriseHour]];
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
        $liftingChance = $liftingMist / $complete;
        $bestHour = null;
        $bestCount = 0;
        foreach ($mistByHour as $hour => $count) {
            if ($count > $bestCount) {
                $bestHour = $hour;
                $bestCount = $count;
            }
        }

        $title = $denseChance >= 0.8
            ? 'Dichte mist verwacht bij Vries'
            : 'Fotoweer morgenochtend bij Vries';
        $priority = $denseChance >= 0.8 ? 4 : 3;
        $lines = [
            sprintf('Morgen %s · zon op %s.', $sunrise->format('d-m-Y'), $sunrise->format('H:i')),
            sprintf(
                'Mist (<1 km): %d%% · dichte mist (<200 m): %d%% (%d modelscenario’s).',
                (int) round($mistChance * 100),
                (int) round($denseChance * 100),
                $complete
            ),
        ];
        if ($bestHour !== null) {
            $lines[] = sprintf('Grootste mistkans rond %02d:00.', $bestHour);
        }
        $lines[] = sprintf(
            'Ruimte voor zon na zonsopkomst: %d%% · wind rond %d km/u.',
            (int) round($openSkyChance * 100),
            (int) round($windSum / $complete)
        );
        $lines[] = sprintf(
            'Bosmist-signaal: %d/%d scenario’s met zeer vochtige, rustige nacht.',
            $forestMistSignal,
            $complete
        );
        if ($denseChance >= 0.5) {
            $lines[] = 'Dichte mist is kansrijk; ook in het bos.';
        } elseif ($mistChance >= 0.4 && $liftingChance >= 0.25
            && $openSkyChance >= 0.25) {
            $lines[] = 'Mist kan optrekken met ruimte voor ochtendlicht.';
        } elseif ($mistChance >= 0.4) {
            $lines[] = 'Kansrijk voor mist rond zonsopkomst.';
        } elseif ($forestSignalShare >= 0.4) {
            $lines[] = 'Condities voor lokale bosmist; dichtheid onzeker.';
        } else {
            $lines[] = 'Weinig aanwijzingen voor mist.';
        }
        if ($freezingMist / $complete >= 0.2) {
            $lines[] = 'Mist bij vorst is mogelijk.';
        }
        $lines[] = 'Bosmist blijft lokaal lastig te voorspellen, ook zonder modelsignaal.';
        $message = implode("\n", $lines);

        $checkStatus = sprintf(
            "%s; morgen=%s; zon=%s; mist=%.1f%%; dicht=%.1f%%; bosmist-signaal=%.1f%%; scenario's=%d\n",
            $now->format('Y-m-d H:i:sP'),
            $tomorrow,
            $sunrise->format('H:i'),
            $mistChance * 100,
            $denseChance * 100,
            $forestSignalShare * 100,
            $complete
        );
    } catch (Throwable $forecastError) {
        error_log('Fog forecast: ' . $forecastError->getMessage());
        $title = 'Fotoweer Vries: gegevens ontbreken';
        $priority = 3;
        $message = 'De fotoweerverwachting voor morgen kan nu niet worden berekend. Er is geen betrouwbare mistinschatting.';
        $checkStatus = $now->format('Y-m-d H:i:sP') . '; morgen=' . $tomorrow
            . '; fout=' . $forecastError->getMessage() . "\n";
    }
    return [
        'date' => $tomorrow,
        'title' => $title,
        'message' => $message,
        'priority' => $priority,
        'check_status' => $checkStatus,
    ];
}

function fogSendTelegram(array $config, string $chatId, string $text): void
{
    $token = $config['telegram_bot_token'] ?? null;
    if (!is_string($token) || $token === '') {
        throw new RuntimeException('Telegram bot is not configured');
    }
    $body = http_build_query([
        'chat_id' => $chatId,
        'text' => $text,
    ], '', '&', PHP_QUERY_RFC3986);
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
}
