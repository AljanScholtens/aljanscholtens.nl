<?php
declare(strict_types=1);

define('FOG_MORNING_LIBRARY_ONLY', true);
$root = getenv('FOG_BOT_ROOT') ?: getenv('HOME');
require $root . '/private/fog-morning-cron.php';

// $local is the share of scenarios with a clear, calm, damp night; from half on
// the forecast says "local mist possible".
function snapshot(int $score, float $mist, float $dense, float $local, bool $localDense = false): array
{
    return [
        'date' => '2026-10-19',
        'metrics' => [
            'score' => $score,
            'mist_share' => $mist,
            'dense_share' => $dense,
            'local_mist_share' => $local,
            'local_mist' => $local >= 0.5,
            'knmi' => ['fog' => $localDense, 'dense' => $localDense, 'local_mist' => false],
        ],
    ];
}
function evening(array $forecast): array
{
    return ['date' => $forecast['date'], 'telegram_message_id' => 123,
        'forecast' => $forecast];
}
$cases = [
    [snapshot(6, 0.4, 0.1, 0.1), snapshot(7, 0.45, 0.1, 0.15), false],
    [snapshot(1, 0.05, 0.0, 0.0), snapshot(3, 0.1, 0.0, 0.0), false],
    [snapshot(2, 0.15, 0.0, 0.0), snapshot(4, 0.60, 0.0, 0.0), true],
    [snapshot(3, 0.2, 0.0, 0.0), snapshot(6, 0.2, 0.0, 0.0), true],
    [snapshot(6, 0.4, 0.0, 0.0), snapshot(6, 0.4, 0.0, 0.0, true), true],
    [snapshot(3, 0.1, 0.0, 0.1), snapshot(3, 0.1, 0.0, 0.6), true],
    [snapshot(6, 0.8, 0.8, 0.6), snapshot(6, 0.8, 0.8, 0.9), false],
];
foreach ($cases as $index => $case) {
    $result = fogMorningDecision(evening($case[0]), $case[1]);
    if ($result['send'] !== $case[2]) {
        fwrite(STDERR, 'Decision check failed for case ' . $index . "\n");
        exit(1);
    }
}
if (fogMorningDecision(null, snapshot(8, 0.9, 0.7, 0.8))['send']) {
    fwrite(STDERR, "Missing baseline incorrectly triggered a message\n");
    exit(1);
}
$newScoreVersion = snapshot(9, 0.05, 0.0, 0.0);
$newScoreVersion['metrics']['score_version'] = 2;
if (fogMorningDecision(evening(snapshot(2, 0.05, 0.0, 0.0)), $newScoreVersion)['send']) {
    fwrite(STDERR, "A score formula change triggered a morning alert\n");
    exit(1);
}
$legacy = evening(snapshot(6, 0.2, 0.0, 0.1));
unset($legacy['forecast']['metrics']['local_mist']);
if (fogMorningDecision($legacy, snapshot(6, 0.2, 0.0, 0.8))['send']) {
    fwrite(STDERR, "A forecast without the local mist flag incorrectly triggered a message\n");
    exit(1);
}
$localEvening = evening(snapshot(6, 0.2, 0.0, 0.1));
$localMorning = snapshot(6, 0.2, 0.0, 0.6);
$localMessage = fogMorningMessage($localEvening, $localMorning);
if (!fogMorningDecision($localEvening, $localMorning)['send']
    || strpos($localMessage, '↳ Lokale mist: nu mogelijk') === false
    || strpos(fogMorningMessage(evening($localMorning), snapshot(6, 0.2, 0.0, 0.1)),
        '↳ Lokale mist: niet meer verwacht') === false) {
    fwrite(STDERR, "Local mist change was not reported clearly\n");
    exit(1);
}
$sample = snapshot(6, 0.6, 0.3, 0.2, true);
$sample['metrics']['weather_summary'] = 'Droog en bewolkt';
$message = fogMorningMessage(
    evening(snapshot(3, 0.1, 0.0, 0.2)),
    $sample
);
if (strpos($message, "(Vries)\n🟢 Uitstekend — wekker ") === false
    || strpos($message, 'Cijfer: 3 → 6/10') === false
    || strpos($message, '🌁 Mist: 10% → 60%') === false
    || strpos($message, 'KNMI') !== false
    || strpos($message, 'Droog en bewolkt') === false) {
    fwrite(STDERR, "Morning message format check failed\n");
    exit(1);
}
$oldPhoto = snapshot(3, 0.1, 0.0, 0.1);
$newPhoto = snapshot(8, 0.1, 0.0, 0.1);
$oldPhoto['metrics']['score_version'] = 2;
$newPhoto['metrics']['score_version'] = 2;
$oldPhoto['metrics']['photo_reason'] = 'weinig uitgesproken fotoweer';
$newPhoto['metrics']['photo_reason'] = 'sneeuw en bijzonder ochtendlicht';
if (!fogMorningDecision(evening($oldPhoto), $newPhoto)['send']
    || strpos(fogMorningMessage(evening($oldPhoto), $newPhoto),
        'Fotokans: sneeuw en bijzonder ochtendlicht') === false) {
    fwrite(STDERR, "Photo opportunity change was not reported\n");
    exit(1);
}
$oldRoute = snapshot(7, 0.2, 0.0, 0.1);
$newRoute = snapshot(7, 0.2, 0.0, 0.1);
$oldRoute['metrics']['score_version'] = 2;
$newRoute['metrics']['score_version'] = 2;
$oldRoute['metrics']['photo_route'] = 'mist';
$newRoute['metrics']['photo_route'] = 'winter';
$oldRoute['metrics']['photo_route_margin'] = 1.0;
$newRoute['metrics']['photo_route_margin'] = 1.0;
if (!fogMorningDecision(evening($oldRoute), $newRoute)['send']) {
    fwrite(STDERR, "Changed strong photo route was not reported\n");
    exit(1);
}
// A local dense fog flip on an already high score still explains itself, without naming KNMI.
$highEvening = evening(snapshot(7, 0.6, 0.3, 0.2));
$highMorning = snapshot(7, 0.6, 0.3, 0.2, true);
$highMessage = fogMorningMessage($highEvening, $highMorning);
if (!fogMorningDecision($highEvening, $highMorning)['send']
    || strpos($highMessage, '↳ Lokaal: nu dichte mist verwacht') === false
    || strpos($highMessage, 'KNMI') !== false) {
    fwrite(STDERR, "Local dense fog change was not reported\n");
    exit(1);
}
echo "Morning decision checks passed.\n";
