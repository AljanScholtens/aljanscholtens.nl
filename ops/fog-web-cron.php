<?php
declare(strict_types=1);

// Install this file in ~/private. Cron rebuilds the data for the public /weer page
// every two hours; www/weer-data.php serves it, so visitors never wait for the models.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__) . '/www/fog-web.php';

try {
    $zone = new DateTimeZone('Europe/Amsterdam');
    $now = new DateTimeImmutable('now', $zone);
    $lock = fopen(__DIR__ . '/fog-web.lock', 'c');
    if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
        exit;
    }
    fogWebPruneCache(__DIR__, $now);
    $data = fogWebData($now, $zone, fogWebDefaultPlace());
    $usable = array_filter($data['days'], static function (array $day): bool {
        return !isset($day['error']);
    });
    // Keep the previous page data when every morning failed.
    if (!$usable) {
        throw new RuntimeException('No usable mornings');
    }
    if (in_array('--preview', $argv ?? [], true)) {
        echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), "\n";
        exit;
    }
    fogSaveJson(__DIR__ . '/fog-web.json', $data);
} catch (Throwable $error) {
    error_log('Fog web data: ' . $error->getMessage());
    exit(1);
}
