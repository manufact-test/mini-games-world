<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
require_once $root . '/bot/baseline/JsonBaselineLatencyBootstrap.php';

$options = getopt('', ['cold::', 'warm::', 'output::']);
$cold = isset($options['cold']) ? filter_var($options['cold'], FILTER_VALIDATE_INT) : 5;
$warm = isset($options['warm']) ? filter_var($options['warm'], FILTER_VALIDATE_INT) : 30;
$output = isset($options['output']) ? trim((string)$options['output']) : '';

if ($cold === false || $warm === false) {
    fwrite(STDERR, "Latency sample counts must be integers.\n");
    exit(2);
}

$fixtureRoot = realpath($root . '/bot/tests/fixtures/mvp14r2');
$indexPath = realpath($root . '/bot/tests/fixtures/mvp14r2/scenario_index.json');
if (!is_string($fixtureRoot) || !is_string($indexPath)) {
    fwrite(STDERR, "Latency inputs are unavailable.\n");
    exit(2);
}

$catalog = JsonBaselineScenarioCatalog::load($indexPath);
$report = (new JsonBaselineLatencyRunner($fixtureRoot, $catalog))->run((int)$cold, (int)$warm);
$json = json_encode(
    $report,
    JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR
) . PHP_EOL;

if ($output !== '') {
    $parent = dirname($output);
    if ($parent === '' || !is_dir($parent) || is_link($parent) || is_link($output)) {
        fwrite(STDERR, "Latency output path is unavailable or unsafe.\n");
        exit(2);
    }
    $outputPath = $parent . '/' . basename($output);
    $bytes = file_put_contents($outputPath, $json, LOCK_EX);
    if ($bytes !== strlen($json)) {
        fwrite(STDERR, "Latency report write failed.\n");
        exit(2);
    }
    chmod($outputPath, 0600);
    fwrite(STDOUT, "MVP14R2_LATENCY_REPORT_PATH={$outputPath}\n");
} else {
    fwrite(STDOUT, $json);
}

$aggregate = $report['aggregate'];
fwrite(STDOUT, 'MVP14R2_LATENCY_COLD_MEDIAN_MS=' . $aggregate['cold']['median_ms'] . PHP_EOL);
fwrite(STDOUT, 'MVP14R2_LATENCY_COLD_P95_MS=' . $aggregate['cold']['p95_ms'] . PHP_EOL);
fwrite(STDOUT, 'MVP14R2_LATENCY_WARM_MEDIAN_MS=' . $aggregate['warm']['median_ms'] . PHP_EOL);
fwrite(STDOUT, 'MVP14R2_LATENCY_WARM_P95_MS=' . $aggregate['warm']['p95_ms'] . PHP_EOL);
fwrite(STDOUT, 'MVP14R2_LATENCY_WARM_MAX_MS=' . $aggregate['warm']['max_ms'] . PHP_EOL);
fwrite(STDOUT, 'MVP14R2_LATENCY_GUARDRAILS=' . ($report['guardrails']['passed'] ? 'PASSED' : 'FAILED') . PHP_EOL);

exit($report['guardrails']['passed'] ? 0 : 1);
