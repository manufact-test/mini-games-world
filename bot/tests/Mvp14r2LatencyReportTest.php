<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
require_once $root . '/bot/baseline/JsonBaselineLatencyBootstrap.php';

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

$fixtureRoot = realpath($root . '/bot/tests/fixtures/mvp14r2');
$indexPath = realpath($root . '/bot/tests/fixtures/mvp14r2/scenario_index.json');
if (!is_string($fixtureRoot) || !is_string($indexPath)) {
    throw new RuntimeException('MVP-14R.2 latency inputs are unavailable.');
}

$catalog = JsonBaselineScenarioCatalog::load($indexPath);
$assert($catalog->foundation()['fixture_id'] === 'foundation', 'Foundation fixture identity changed.');
$assert($catalog->foundation()['timed'] === false, 'Foundation fixture must not be timed.');
$assert(count($catalog->allEntries()) === 27, 'Frozen scenario index must contain 27 total entries.');
$assert(count($catalog->timedEntries()) === 26, 'Frozen scenario index must contain 26 timed entries.');
$assert($catalog->groupCounts() === [
    'account_passive' => 4,
    'economy_supporting' => 8,
    'games' => 8,
    'invites_matchmaking' => 6,
], 'Frozen scenario group coverage changed.');

$known = JsonBaselineLatencyRunner::summarizeSamples([1.0, 2.0, 3.0, 4.0, 5.0]);
$assert($known === [
    'count' => 5,
    'min_ms' => 1.0,
    'median_ms' => 3.0,
    'p95_ms' => 5.0,
    'max_ms' => 5.0,
    'mean_ms' => 3.0,
], 'Latency summary arithmetic changed.');

$report = (new JsonBaselineLatencyRunner($fixtureRoot, $catalog))->run(2, 5);
$assert(($report['contract_version'] ?? null) === JsonBaselineLatencyRunner::CONTRACT_VERSION, 'Latency report contract changed.');
$assert(($report['measurement_scope'] ?? null) === 'local_or_ci_isolated_fixture_runner', 'Latency measurement scope changed.');
$assert(($report['production_evidence'] ?? true) === false, 'CI/local report must not claim production evidence.');
$assert(($report['index']['timed_scenario_count'] ?? null) === 26, 'Latency report scenario count changed.');
$assert(($report['samples']['cold_per_scenario'] ?? null) === 2, 'Cold sample count changed.');
$assert(($report['samples']['warm_per_scenario'] ?? null) === 5, 'Warm sample count changed.');
$assert(($report['samples']['cold_total'] ?? null) === 52, 'Cold total sample count changed.');
$assert(($report['samples']['warm_total'] ?? null) === 130, 'Warm total sample count changed.');
$assert(($report['guardrails']['passed'] ?? false) === true, 'Catastrophic latency guardrail failed.');
$assert(($report['guardrails']['classification'] ?? null) === 'catastrophic_regression_guard_only_not_product_slo', 'Guardrail classification changed.');

$seenFixtures = [];
$seenScenarios = [];
foreach ($report['scenarios'] ?? [] as $scenario) {
    $fixtureId = (string)($scenario['fixture_id'] ?? '');
    $scenarioId = (string)($scenario['scenario_id'] ?? '');
    $assert($fixtureId !== '' && !isset($seenFixtures[$fixtureId]), 'Latency report fixture IDs must be unique.');
    $assert($scenarioId !== '' && !isset($seenScenarios[$scenarioId]), 'Latency report scenario IDs must be unique.');
    $seenFixtures[$fixtureId] = true;
    $seenScenarios[$scenarioId] = true;
    $assert(preg_match('/\A[a-f0-9]{64}\z/', (string)($scenario['fingerprint_sha256'] ?? '')) === 1, $fixtureId . ': fingerprint is invalid.');
    $assert(count($scenario['cold']['samples_ms'] ?? []) === 2, $fixtureId . ': cold samples changed.');
    $assert(count($scenario['warm']['samples_ms'] ?? []) === 5, $fixtureId . ': warm samples changed.');
    foreach (['cold', 'warm'] as $mode) {
        $stats = $scenario[$mode]['stats'] ?? [];
        $assert(($stats['count'] ?? 0) === ($mode === 'cold' ? 2 : 5), $fixtureId . ': ' . $mode . ' count changed.');
        $assert(is_float($stats['min_ms'] ?? null), $fixtureId . ': ' . $mode . ' min must be a float.');
        $assert(($stats['min_ms'] ?? -1) >= 0.0, $fixtureId . ': ' . $mode . ' min must be non-negative.');
        $assert(($stats['min_ms'] ?? 0) <= ($stats['median_ms'] ?? -1), $fixtureId . ': ' . $mode . ' median ordering changed.');
        $assert(($stats['median_ms'] ?? 0) <= ($stats['p95_ms'] ?? -1), $fixtureId . ': ' . $mode . ' p95 ordering changed.');
        $assert(($stats['p95_ms'] ?? 0) <= ($stats['max_ms'] ?? -1), $fixtureId . ': ' . $mode . ' max ordering changed.');
    }
    $assert(($scenario['guardrail_passed'] ?? false) === true, $fixtureId . ': scenario guardrail failed.');
}
$assert(count($seenFixtures) === 26, 'Latency report fixture coverage changed.');
$assert(count($seenScenarios) === 26, 'Latency report scenario coverage changed.');

foreach (['account_passive', 'economy_supporting', 'games', 'invites_matchmaking'] as $group) {
    $groupReport = $report['groups'][$group] ?? null;
    $assert(is_array($groupReport), 'Latency group is missing: ' . $group . '.');
    $assert(($groupReport['scenario_count'] ?? 0) === $catalog->groupCounts()[$group], 'Latency group count changed: ' . $group . '.');
    $assert(($groupReport['cold']['count'] ?? 0) === $catalog->groupCounts()[$group] * 2, 'Latency group cold count changed: ' . $group . '.');
    $assert(($groupReport['warm']['count'] ?? 0) === $catalog->groupCounts()[$group] * 5, 'Latency group warm count changed: ' . $group . '.');
}

foreach ([
    'network_contacted',
    'production_contacted',
    'database_contacted',
    'database_write_executed',
    'live_json_changed',
    'persistent_config_changed',
    'webhook_changed',
    'cron_changed',
    'production_changed',
] as $flag) {
    $assert(($report['safety'][$flag] ?? true) === false, 'Latency safety flag changed: ' . $flag . '.');
}

$slowest = null;
foreach ($report['scenarios'] as $scenario) {
    if ($slowest === null || $scenario['warm']['stats']['p95_ms'] > $slowest['warm']['stats']['p95_ms']) {
        $slowest = $scenario;
    }
}
$summary = [
    'timed_scenarios' => $report['index']['timed_scenario_count'],
    'cold_samples' => $report['samples']['cold_total'],
    'warm_samples' => $report['samples']['warm_total'],
    'cold_median_ms' => $report['aggregate']['cold']['median_ms'],
    'cold_p95_ms' => $report['aggregate']['cold']['p95_ms'],
    'warm_median_ms' => $report['aggregate']['warm']['median_ms'],
    'warm_p95_ms' => $report['aggregate']['warm']['p95_ms'],
    'warm_max_ms' => $report['aggregate']['warm']['max_ms'],
    'slowest_warm_scenario' => $slowest['scenario_id'] ?? null,
    'slowest_warm_p95_ms' => $slowest['warm']['stats']['p95_ms'] ?? null,
    'guardrails_passed' => $report['guardrails']['passed'],
];
fwrite(STDOUT, 'MVP14R2_LATENCY_SUMMARY=' . json_encode($summary, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL);
fwrite(STDOUT, "Mvp14r2LatencyReportTest passed: {$assertions} assertions.\n");
