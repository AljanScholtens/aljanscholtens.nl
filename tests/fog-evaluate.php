<?php
declare(strict_types=1);

define('FOG_EVALUATE_LIBRARY_ONLY', true);
$root = getenv('FOG_BOT_ROOT') ?: getenv('HOME');
require rtrim($root, '/') . '/private/fog-evaluate.php';

$privateDir = rtrim($root, '/') . '/private/test-evaluate-' . bin2hex(random_bytes(6));
mkdir($privateDir . '/fog-forecasts', 0700, true);
mkdir($privateDir . '/fog-feedback', 0700, true);

$days = [
    ['2026-10-01', 8, 0.8, 0.5, 'dense', true, 3],
    ['2026-10-02', 2, 0.1, 0.0, 'none', false, 2],
    ['2026-10-03', 5, 0.5, 0.1, 'unseen', null, 3],
];
foreach ($days as [$date, $score, $mist, $dense, $seen, $photo, $version]) {
    file_put_contents($privateDir . "/fog-forecasts/$date.json", json_encode([
        'date' => $date,
        'forecast' => ['metrics' => ['score' => $score, 'score_version' => $version,
            'mist_share' => $mist, 'dense_share' => $dense, 'photo_route' => 'mist',
            'rime_share' => $version >= 3 ? $dense : null, 'dew_share' => 0.0,
            'knmi' => ['fog' => $mist >= 0.5]]],
    ]));
    file_put_contents($privateDir . "/fog-feedback/$date.json", json_encode([
        'date' => $date, 'mist' => $seen, 'photo_worthy' => $photo, 'frost' => $photo,
    ]));
}

try {
    $rows = fogEvaluateRows($privateDir);
    $report = fogEvaluateReport($rows, true);
    if (count($rows) !== 3
        || $rows[0]['dense'] !== true || $rows[1]['mist'] !== false || $rows[2]['seen'] !== false
        || strpos($report, 'Dagen met voorspelling en feedback: 3 (mist bekeken: 2, foto beoordeeld: 2)') === false
        || !preg_match('/^8-10\s+1\s+100% \( 1\)\s+100% \( 1\)$/m', $report)
        || !preg_match('/^1-3\s+1\s+0% \( 1\)\s+0% \( 1\)$/m', $report)
        || strpos($report, 'KNMI-model mist ja/nee klopte: 100% van 2 dagen') === false
        || strpos($report, '2026-10-03') === false
        || !preg_match('/^verwacht\s+1\s+100%$/m', $report)
        || !preg_match('/^niet\s+0\s+-$/m', $report)
        // Scores from different versions must land in separate tables.
        || !preg_match('/Rekenversie 2\n.*\n1-3\s+1\s.*\n4-5\s+0\s.*\n6-7\s+0\s.*\n8-10\s+0\s/', $report)
        || !preg_match('/Rekenversie 3\n.*\n1-3\s+0\s.*\n4-5\s+1\s.*\n6-7\s+0\s.*\n8-10\s+1\s/', $report)) {
        fwrite(STDERR, "Evaluation report check failed:\n" . $report);
        exit(1);
    }
} finally {
    array_map('unlink', glob($privateDir . '/*/*.json'));
    rmdir($privateDir . '/fog-forecasts');
    rmdir($privateDir . '/fog-feedback');
    rmdir($privateDir);
}
echo "Evaluation checks passed.\n";
