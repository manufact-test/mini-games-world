<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
require_once $root . '/bot/baseline/JsonBehaviorBaselineNormalizer.php';
require_once $root . '/bot/baseline/JsonBehaviorBaselineFixture.php';
require_once $root . '/bot/baseline/JsonBehaviorBaselineResult.php';
$files = [
    'normalizer' => $root . '/bot/baseline/JsonBehaviorBaselineNormalizer.php',
    'fixture' => $root . '/bot/baseline/JsonBehaviorBaselineFixture.php',
    'result' => $root . '/bot/baseline/JsonBehaviorBaselineResult.php',
    'focused_check' => $root . '/ops/checks/mvp14r2-json-baseline-local.sh',
    'docs' => $root . '/docs/MVP-14R-2-JSON-BASELINE.md',
    'bootstrap' => $root . '/bot/core/bootstrap.php',
    'fixture_json' => $root . '/bot/tests/fixtures/mvp14r2/foundation.json',
];
$sources = [];
foreach ($files as $name => $path) {
    $source = file_get_contents($path);
    if (!is_string($source) || $source === '') {
        throw new RuntimeException('MVP-14R.2 source is unavailable: ' . $name);
    }
    $sources[$name] = $source;
}

$assertions = 0;
$assertTrue = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

foreach (['normalizer', 'fixture', 'result'] as $name) {
    $assertTrue(
        str_contains($sources[$name], 'declare(strict_types=1);')
            && str_contains($sources[$name], 'final class JsonBehaviorBaseline'),
        'Baseline class must be strict and isolated: ' . $name
    );
}

foreach ([
    'JsonBehaviorBaselineNormalizer.php',
    'JsonBehaviorBaselineFixture.php',
    'JsonBehaviorBaselineResult.php',
] as $file) {
    $assertTrue(
        !str_contains($sources['bootstrap'], $file),
        'Production bootstrap must not load baseline-only class: ' . $file
    );
}

$combined = $sources['normalizer'] . "\n" . $sources['fixture'] . "\n" . $sources['result'];
foreach ([
    'StorageFactory',
    'PdoConnectionFactory',
    'new PDO',
    'mysqli',
    'curl_',
    'TelegramService',
    'PaymentService',
    'file_put_contents(',
    'fopen(',
    'rename(',
    'unlink(',
    'shell_exec(',
    'exec(',
] as $forbidden) {
    $assertTrue(
        !str_contains($combined, $forbidden),
        'Baseline foundation must not contact production/network or write files: ' . $forbidden
    );
}
$assertTrue(
    !preg_match('/https?:\/\//i', $combined),
    'Baseline foundation classes must not contain network URLs'
);

foreach ([
    'MGW_MVP14R2_BASELINE_FOUNDATION=PASSED',
    'DETERMINISTIC_FIXTURE=PASSED',
    'NORMALIZATION_AND_FINGERPRINTING=PASSED',
    'PRODUCTION_CONTACTED=false',
    'NETWORK_CONTACTED=false',
    'DATABASE_WRITE_EXECUTED=false',
    'LIVE_JSON_CHANGED=false',
    'WEBHOOK_CHANGED=false',
    'CRON_CHANGED=false',
] as $marker) {
    $assertTrue(
        str_contains($sources['focused_check'], $marker),
        'Focused baseline check is missing marker: ' . $marker
    );
}
foreach ([
    '4295f42c84d28b02eae25fb9aa069ed186bde5ac',
    'MVP-14R.2.1',
    'no production runtime change',
    'all eight games',
] as $required) {
    $assertTrue(
        str_contains($sources['docs'], $required),
        'Baseline documentation is missing required boundary: ' . $required
    );
}

$fixture = json_decode($sources['fixture_json'], true, 512, JSON_THROW_ON_ERROR);
$assertTrue(
    is_array($fixture)
        && ($fixture['contract_version'] ?? null) === JsonBehaviorBaselineFixture::CONTRACT_VERSION
        && ($fixture['clock']['timezone'] ?? null) === 'UTC'
        && ($fixture['random_seed'] ?? null) === 140201,
    'Foundation fixture must freeze contract, UTC clock and random seed'
);

fwrite(STDOUT, "Mvp14r2JsonBaselineContractTest passed: {$assertions} assertions.\n");
