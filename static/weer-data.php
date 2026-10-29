<?php
declare(strict_types=1);

// Public, read-only data for the /weer page. ~/private/fog-web-cron.php writes it.
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
$path = dirname(__DIR__) . '/private/fog-web.json';
$data = is_file($path) ? file_get_contents($path) : false;
if ($data === false) {
    http_response_code(503);
    header('Cache-Control: no-store');
    echo '{"error":"Nog geen verwachting beschikbaar"}';
    exit;
}
header('Cache-Control: public, max-age=300');
echo $data;
