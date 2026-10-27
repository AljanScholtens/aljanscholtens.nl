<?php
declare(strict_types=1);

// Compares each evening forecast with the feedback given the next morning.
// Install in ~/private and run by hand: php ~/private/fog-evaluate.php [--days]
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

function fogEvaluateRead(string $path): ?array
{
    $data = json_decode((string) @file_get_contents($path), true);
    return is_array($data) ? $data : null;
}

function fogEvaluatePercent(int $part, int $total): string
{
    return $total > 0 ? sprintf('%3d%%', (int) round($part / $total * 100)) : '   -';
}

// Collect one row per day that has both a forecast and a usable observation.
function fogEvaluateRows(string $privateDir): array
{
    $rows = [];
    foreach (glob($privateDir . '/fog-feedback/*.json') ?: [] as $feedbackPath) {
        $feedback = fogEvaluateRead($feedbackPath);
        $date = $feedback['date'] ?? null;
        if (!is_string($date)) {
            continue;
        }
        $archive = fogEvaluateRead($privateDir . '/fog-forecasts/' . $date . '.json');
        $metrics = $archive['forecast']['metrics'] ?? null;
        if (!is_array($metrics) || !is_numeric($metrics['score'] ?? null)) {
            continue;
        }
        $mist = $feedback['mist'] ?? null;
        $rows[] = [
            'date' => $date,
            'version' => (int) ($metrics['score_version'] ?? 1),
            'score' => (int) $metrics['score'],
            'route' => is_string($metrics['photo_route'] ?? null) ? $metrics['photo_route'] : '-',
            'mist_share' => (float) ($metrics['mist_share'] ?? 0),
            'dense_share' => (float) ($metrics['dense_share'] ?? 0),
            'knmi_fog' => is_array($metrics['knmi'] ?? null)
                ? (bool) ($metrics['knmi']['fog'] ?? false) : null,
            // "Niet gekeken" says nothing about the weather, so it is not an observation.
            'seen' => is_string($mist) && $mist !== 'unseen',
            'mist' => in_array($mist, ['light', 'dense', 'forest', 'dense_forest'], true),
            'dense' => in_array($mist, ['dense', 'dense_forest'], true),
            'mist_label' => is_string($mist) ? $mist : '-',
            'photo_worthy' => is_bool($feedback['photo_worthy'] ?? null)
                ? $feedback['photo_worthy'] : null,
        ];
    }
    usort($rows, static function (array $a, array $b): int {
        return strcmp($a['date'], $b['date']);
    });
    return $rows;
}

function fogEvaluateReport(array $rows, bool $listDays): string
{
    $out = [];
    $seen = array_values(array_filter($rows, static function (array $row): bool {
        return $row['seen'];
    }));
    $rated = array_values(array_filter($rows, static function (array $row): bool {
        return $row['photo_worthy'] !== null;
    }));
    $out[] = sprintf('Dagen met voorspelling en feedback: %d (mist bekeken: %d, foto beoordeeld: %d)',
        count($rows), count($seen), count($rated));
    if (!$rows) {
        return implode("\n", $out) . "\n";
    }

    // Does a higher score mean more days worth a photo? Each score version
    // measures something different, so they are never mixed in one table.
    $versions = array_unique(array_column($rows, 'version'));
    sort($versions);
    foreach ($versions as $version) {
        $out[] = '';
        $out[] = 'Rekenversie ' . $version;
        $out[] = 'Cijfer      dagen  foto waard  mist gezien';
        foreach ([[1, 3], [4, 5], [6, 7], [8, 10]] as [$low, $high]) {
            $band = array_filter($rows, static function (array $row) use ($low, $high, $version): bool {
                return $row['version'] === $version
                    && $row['score'] >= $low && $row['score'] <= $high;
            });
            $bandRated = array_filter($band, static function (array $row): bool {
                return $row['photo_worthy'] !== null;
            });
            $bandSeen = array_filter($band, static function (array $row): bool {
                return $row['seen'];
            });
            $out[] = sprintf('%-10s  %5d  %4s (%2d)  %4s (%2d)',
                $low . '-' . $high,
                count($band),
                fogEvaluatePercent(count(array_filter($bandRated, static function (array $row): bool {
                    return $row['photo_worthy'];
                })), count($bandRated)),
                count($bandRated),
                fogEvaluatePercent(count(array_filter($bandSeen, static function (array $row): bool {
                    return $row['mist'];
                })), count($bandSeen)),
                count($bandSeen));
        }
    }

    // Is a 60% mist chance followed by mist on roughly 60% of mornings?
    if ($seen) {
        $out[] = '';
        $out[] = 'Mistkans    dagen  mist gezien';
        foreach ([[0.0, 0.2], [0.2, 0.4], [0.4, 0.6], [0.6, 0.8], [0.8, 1.01]] as [$low, $high]) {
            $bucket = array_filter($seen, static function (array $row) use ($low, $high): bool {
                return $row['mist_share'] >= $low && $row['mist_share'] < $high;
            });
            $out[] = sprintf('%3d-%3d%%    %5d  %4s',
                (int) round($low * 100), (int) min(100, round($high * 100)),
                count($bucket),
                fogEvaluatePercent(count(array_filter($bucket, static function (array $row): bool {
                    return $row['mist'];
                })), count($bucket)));
        }
        // Brier score: 0 is perfect, 0.25 is what always guessing 50% gives.
        // A rough cue only: the chance covers several hours, the feedback one look.
        $brier = static function (string $share, string $outcome) use ($seen): float {
            $sum = 0.0;
            foreach ($seen as $row) {
                $sum += ($row[$share] - ($row[$outcome] ? 1.0 : 0.0)) ** 2;
            }
            return $sum / count($seen);
        };
        $out[] = sprintf('Brier-score mist: %.3f · dichte mist: %.3f (0 = perfect, 0,25 = gokken; alleen een aanwijzing)',
            $brier('mist_share', 'mist'), $brier('dense_share', 'dense'));

        // How often was the local KNMI run right on its own?
        $knmi = array_filter($seen, static function (array $row): bool {
            return $row['knmi_fog'] !== null;
        });
        if ($knmi) {
            $agree = count(array_filter($knmi, static function (array $row): bool {
                return $row['knmi_fog'] === $row['mist'];
            }));
            $out[] = sprintf('KNMI-model mist ja/nee klopte: %s van %d dagen',
                fogEvaluatePercent($agree, count($knmi)), count($knmi));
        }
    }

    if ($listDays) {
        $out[] = '';
        $out[] = 'Datum       v  cijfer  route   mist%  dicht%  gezien        foto';
        foreach ($rows as $row) {
            $out[] = sprintf('%s  %d  %6d  %-6s  %4d%%  %5d%%  %-12s  %s',
                $row['date'], $row['version'], $row['score'], $row['route'],
                (int) round($row['mist_share'] * 100), (int) round($row['dense_share'] * 100),
                $row['mist_label'],
                $row['photo_worthy'] === null ? '-' : ($row['photo_worthy'] ? 'ja' : 'nee'));
        }
    }
    return implode("\n", $out) . "\n";
}

if (defined('FOG_EVALUATE_LIBRARY_ONLY')) {
    return;
}

echo fogEvaluateReport(fogEvaluateRows(__DIR__), in_array('--days', $argv ?? [], true));
