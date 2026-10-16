<?php
declare(strict_types=1);

// Installed at ~/www/fog-alert.php. Keep all private files outside the web root.
$privateDir = dirname(__DIR__) . '/private';
$configPath = $privateDir . '/fog-alert-config.php';
if (!is_file($configPath)) {
    http_response_code(503);
    exit("Configuration missing\n");
}
$config = require $configPath;
if (!is_array($config) || !isset($config['cron_key'], $config['ntfy_topic'])) {
    http_response_code(503);
    exit("Invalid configuration\n");
}

$suppliedKey = $_GET['key'] ?? '';
if (!is_string($suppliedKey) || !hash_equals($config['cron_key'], $suppliedKey)) {
    http_response_code(403);
    exit("Forbidden\n");
}

$zone = new DateTimeZone('Europe/Amsterdam');
$now = new DateTimeImmutable('now', $zone);
if ((int) $now->format('G') !== 20 && ($_GET['check'] ?? '') !== 'now') {
    exit("Outside evening check window\n");
}

function request(string $url, ?string $body = null, array $headers = []): array
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

try {
    $lockPath = $privateDir . '/fog-alert.lock';
    $lock = fopen($lockPath, 'c');
    if ($lock === false || !flock($lock, LOCK_EX)) {
        throw new RuntimeException('Could not lock the alert job');
    }

    $tomorrow = $now->modify('+1 day')->format('Y-m-d');
    $sentPath = $privateDir . '/fog-alert-last-sent.txt';
    if (is_file($sentPath) && trim((string) file_get_contents($sentPath)) === $tomorrow) {
        exit("Alert already sent for tomorrow\n");
    }

    $url = 'https://ensemble-api.open-meteo.com/v1/ensemble?latitude=53.07165252'
        . '&longitude=6.57674584&hourly=visibility,weather_code&models=gfs025'
        . '&forecast_days=2&timezone=Europe%2FAmsterdam';
    [$status, $response] = request($url);
    if ($status !== 200) {
        throw new RuntimeException('Forecast returned HTTP ' . $status);
    }
    $forecast = json_decode($response, true, 512, JSON_THROW_ON_ERROR);
    $hourly = $forecast['hourly'] ?? null;
    if (!is_array($hourly) || !isset($hourly['time']) || !is_array($hourly['time'])) {
        throw new RuntimeException('Forecast has no hourly data');
    }

    $indices = [];
    foreach (range(6, 10) as $hour) {
        $time = $tomorrow . 'T' . sprintf('%02d', $hour) . ':00';
        $index = array_search($time, $hourly['time'], true);
        if ($index === false) {
            throw new RuntimeException('Forecast is missing ' . $time);
        }
        $indices[] = $index;
    }

    $suffixes = [''];
    foreach (array_keys($hourly) as $key) {
        if (preg_match('/^visibility(_member\d+)$/', $key, $match)) {
            $suffixes[] = $match[1];
        }
    }
    $complete = 0;
    $fog = 0;
    foreach ($suffixes as $suffix) {
        $visibility = $hourly['visibility' . $suffix] ?? null;
        $codes = $hourly['weather_code' . $suffix] ?? null;
        if (!is_array($visibility) || !is_array($codes)) {
            continue;
        }
        foreach ($indices as $index) {
            if (!isset($visibility[$index], $codes[$index])
                || !is_numeric($visibility[$index]) || !is_numeric($codes[$index])) {
                continue 2;
            }
        }
        $complete++;
        foreach ($indices as $index) {
            if ((float) $visibility[$index] < 200.0
                && in_array((int) $codes[$index], [45, 48], true)) {
                $fog++;
                break;
            }
        }
    }
    if ($complete < 25) {
        throw new RuntimeException('Too few usable ensemble members: ' . $complete);
    }
    $chance = $fog / $complete;
    $checkStatus = sprintf(
        "%s; morgen=%s; kans=%.1f%%; scenario's=%d\n",
        $now->format('Y-m-d H:i:sP'),
        $tomorrow,
        $chance * 100,
        $complete
    );
    if (@file_put_contents($privateDir . '/fog-alert-last-check.txt', $checkStatus, LOCK_EX) === false) {
        error_log('Fog alert: could not save last check status');
    }
    if ($chance < 0.8) {
        exit(sprintf("No alert: %.1f%% of %d model runs\n", $chance * 100, $complete));
    }

    $message = sprintf(
        'Morgen %s van 06:00–10:00 uur kans op dichte mist in Vries: %.0f%% volgens weerscenario’s (modelschatting).',
        $now->modify('+1 day')->format('d-m-Y'),
        $chance * 100
    );
    [$notifyStatus] = request(
        'https://ntfy.sh/' . rawurlencode($config['ntfy_topic']),
        $message,
        ['Title: Dichte mist verwacht in Vries', 'Priority: 4', 'Content-Type: text/plain; charset=utf-8']
    );
    if ($notifyStatus < 200 || $notifyStatus >= 300) {
        throw new RuntimeException('Notification returned HTTP ' . $notifyStatus);
    }
    if (file_put_contents($sentPath, $tomorrow . "\n", LOCK_EX) === false) {
        throw new RuntimeException('Notification sent, but delivery state could not be saved');
    }
    echo "Alert sent for $tomorrow\n";
} catch (Throwable $error) {
    error_log('Fog alert: ' . $error->getMessage());
    http_response_code(502);
    echo "Fog alert failed; see server error log\n";
}
