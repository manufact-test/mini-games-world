<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
require_once $root . '/bot/baseline/JsonBehaviorBaselineNormalizer.php';
require_once $root . '/bot/baseline/JsonBehaviorBaselineFixture.php';
require_once $root . '/bot/baseline/JsonBehaviorBaselineResult.php';
require_once $root . '/bot/baseline/JsonBaselineScenarioCatalog.php';

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

$paths = [
    'catalog' => $root . '/bot/baseline/JsonBaselineScenarioCatalog.php',
    'runner' => $root . '/bot/baseline/JsonBaselineLatencyRunner.php',
    'bootstrap' => $root . '/bot/baseline/JsonBaselineLatencyBootstrap.php',
    'cli' => $root . '/ops/checks/mvp14r2-latency-report.php',
    'check' => $root . '/ops/checks/mvp14r2-latency-acceptance-local.sh',
    'docs' => $root . '/docs/MVP-14R-2-LATENCY-ACCEPTANCE.md',
    'acceptance' => $root . '/docs/MVP-14R-2-PRODUCT-ACCEPTANCE-CHECKLIST.md',
];
$sources = [];
foreach ($paths as $name => $path) {
    $source = file_get_contents($path);
    if (!is_string($source) || $source === '') throw new RuntimeException('Latency contract file is unavailable: ' . $name . '.');
    $sources[$name] = $source;
}

$indexPath = realpath($root . '/bot/tests/fixtures/mvp14r2/scenario_index.json');
$fixtureRoot = realpath($root . '/bot/tests/fixtures/mvp14r2');
if (!is_string($indexPath) || !is_string($fixtureRoot)) throw new RuntimeException('Latency index inputs are unavailable.');
$catalog = JsonBaselineScenarioCatalog::load($indexPath);
$assert(count($catalog->allEntries()) === 27, 'Scenario index total changed.');
$assert(count($catalog->timedEntries()) === 26, 'Timed scenario index total changed.');
$assert($catalog->groupCounts() === [
    'account_passive' => 4,
    'economy_supporting' => 8,
    'games' => 8,
    'invites_matchmaking' => 6,
], 'Scenario index group coverage changed.');

foreach ($catalog->allEntries() as $entry) {
    $fixture = JsonBehaviorBaselineFixture::load($fixtureRoot, (string)$entry['fixture_id']);
    $assert($fixture->scenario()['id'] === $entry['scenario_id'], 'Scenario index identity changed: ' . $entry['fixture_id'] . '.');
}

foreach ([
    'JsonAccountPassiveBaselineScenario',
    'JsonInviteMatchmakingBaselineScenario',
    'JsonGamesBaselineScenario',
    'JsonEconomySupportingBaselineScenario',
] as $runnerClass) {
    $assert(str_contains($sources['catalog'], "'" . $runnerClass . "'"), 'Allowed latency runner missing: ' . $runnerClass . '.');
    $assert(str_contains($sources['bootstrap'], '/' . $runnerClass . '.php'), 'Latency bootstrap runner missing: ' . $runnerClass . '.');
}

foreach ([
    'local_or_ci_isolated_fixture_runner',
    'production_evidence',
    'catastrophic_regression_guard_only_not_product_slo',
    'cold_per_scenario',
    'warm_per_scenario',
    'aggregate',
    'network_contacted',
    'production_contacted',
    'database_write_executed',
    'live_json_changed',
    'webhook_changed',
    'cron_changed',
] as $needle) {
    $assert(str_contains($sources['runner'], $needle), 'Latency report contract token missing: ' . $needle . '.');
}

$joinedRuntime = $sources['catalog'] . "\n" . $sources['runner'] . "\n" . $sources['bootstrap'] . "\n" . $sources['cli'];
foreach ([
    'curl_',
    'http://',
    'https://',
    'new PDO',
    'mysqli_',
    'shell_exec',
    'proc_open',
    'passthru',
    'ssh ',
    'Hostinger',
    'BOT_TOKEN',
    '/mgw_data',
    '_private_mgw',
] as $forbidden) {
    $assert(!str_contains($joinedRuntime, $forbidden), 'Latency runtime contains forbidden production/network token: ' . $forbidden . '.');
}

foreach ([
    'JsonAccountPassiveBaselineScenario.php',
    'JsonInviteMatchmakingBaselineScenario.php',
    'JsonGamesBaselineScenario.php',
    'JsonEconomySupportingBaselineScenario.php',
] as $scenarioFile) {
    $source = file_get_contents($root . '/bot/baseline/' . $scenarioFile);
    if (!is_string($source) || $source === '') throw new RuntimeException('Baseline source is unavailable: ' . $scenarioFile . '.');
    $assert(str_contains($source, "'measured' => false"), 'Pre-latency scenario must preserve measured=false: ' . $scenarioFile . '.');
}

$productionRefs = [];
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/bot', FilesystemIterator::SKIP_DOTS));
foreach ($iterator as $file) {
    if (!$file->isFile() || strtolower($file->getExtension()) !== 'php') continue;
    $path = $file->getPathname();
    $relative = str_replace($root . '/', '', $path);
    if (str_starts_with($relative, 'bot/baseline/')
        || str_starts_with($relative, 'bot/tests/')
        || str_starts_with($relative, 'bot/bin/')) continue;
    $source = file_get_contents($path);
    if (!is_string($source)) continue;
    foreach (['JsonBaselineLatencyBootstrap', 'JsonBaselineLatencyRunner', 'JsonBaselineScenarioCatalog'] as $class) {
        if (str_contains($source, $class)) $productionRefs[] = $relative . ':' . $class;
    }
}
$assert($productionRefs === [], 'Latency package must remain disconnected from production bootstrap.');

foreach ([
    'не является production latency',
    '26',
    'cold',
    'warm',
    'median',
    'p95',
    'max',
    'отдельного разрешения',
] as $needle) {
    $assert(str_contains(mb_strtolower($sources['docs']), mb_strtolower($needle)), 'Latency documentation token missing: ' . $needle . '.');
}
foreach ([
    '/start',
    'Mini App',
    'уведом',
    'приглаш',
    'матч',
    'игр',
    'баланс',
    'магаз',
    'не выполнять реальные платежи',
] as $needle) {
    $assert(str_contains(mb_strtolower($sources['acceptance']), mb_strtolower($needle)), 'Product acceptance step missing: ' . $needle . '.');
}

$assert(str_contains($sources['check'], 'PRODUCTION_CONTACTED=false'), 'Focused check safety output missing.');
$assert(str_contains($sources['check'], 'PRODUCTION_CHANGED=false'), 'Focused check production flag missing.');
$assert(str_contains($sources['cli'], 'chmod($outputPath, 0600)'), 'Latency CLI output mode contract missing.');

fwrite(STDOUT, "Mvp14r2LatencyReportContractTest passed: {$assertions} assertions.\n");
