<?php
declare(strict_types=1);

// Public, read-only data for the /weer page. Without ?plaats= it serves the Vries
// forecast that ~/private/fog-web-cron.php writes; with it, a Dutch place is looked up.
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
$privateDir = dirname(__DIR__) . '/private';

function weerRespond(int $status, array $body, int $maxAge = 0): void
{
    http_response_code($status);
    header($maxAge > 0 ? 'Cache-Control: public, max-age=' . $maxAge : 'Cache-Control: no-store');
    echo json_encode($body, JSON_UNESCAPED_UNICODE);
    exit;
}

$query = $_GET['plaats'] ?? '';
if (!is_string($query) || trim($query) === '' || strcasecmp(trim($query), 'Vries') === 0) {
    $data = is_file($privateDir . '/fog-web.json')
        ? file_get_contents($privateDir . '/fog-web.json') : false;
    if ($data === false) {
        weerRespond(503, ['status' => 'error', 'message' => 'Nog geen verwachting beschikbaar.']);
    }
    header('Cache-Control: public, max-age=300');
    echo $data;
    exit;
}

require_once __DIR__ . '/fog-web.php';
try {
    $zone = new DateTimeZone('Europe/Amsterdam');
    $result = fogWebSearch($query, $privateDir, new DateTimeImmutable('now', $zone), $zone,
        (string) ($_SERVER['REMOTE_ADDR'] ?? ''));
} catch (Throwable $error) {
    error_log('Fog web search: ' . $error->getMessage());
    weerRespond(502, ['status' => 'error',
        'message' => 'Zoeken lukt nu niet. Probeer het later opnieuw.']);
}
switch ($result['status']) {
    case 'ok':
        weerRespond(200, $result['data'], 600);
    case 'choose':
        weerRespond(200, ['status' => 'choose', 'places' => $result['places']]);
    case 'not_found':
        weerRespond(404, ['status' => 'not_found', 'suggestions' => $result['suggestions'],
            'message' => 'Geen Nederlandse plaats gevonden met deze naam.']);
    case 'limited':
        weerRespond(429, ['status' => 'limited',
            'message' => 'Even te veel zoekopdrachten. Probeer het over een uur opnieuw.']);
    default:
        weerRespond(400, ['status' => 'invalid', 'message' => 'Typ een Nederlandse plaatsnaam.']);
}
