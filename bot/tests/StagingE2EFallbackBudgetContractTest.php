<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$workflow = file_get_contents($root . '/.github/workflows/staging-playwright-e2e.yml');
$config = file_get_contents($root . '/e2e/playwright.config.mjs');
if (!is_string($workflow) || !is_string($config)) {
    throw new RuntimeException('Staging E2E sources are unavailable.');
}

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

$linuxStart = strpos($workflow, "  linux-route:\n");
$macStart = strpos($workflow, "  macos-route:\n");
$publishStart = strpos($workflow, "  publish-result:\n");
$assert($linuxStart !== false && $macStart !== false && $publishStart !== false, 'Expected staging route jobs must exist.');

$linuxBlock = substr($workflow, $linuxStart, $macStart - $linuxStart);
$macBlock = substr($workflow, $macStart, $publishStart - $macStart);
$assert(str_contains($linuxBlock, 'timeout-minutes: 17'), 'Primary Linux job budget must remain 17 minutes.');
$assert(str_contains($macBlock, 'timeout-minutes: 17'), 'macOS fallback must have the same 17-minute job budget as the primary route.');
$assert(str_contains($config, 'timeout: 90_000'), 'Per-test Playwright timeout must remain 90 seconds.');
$assert(str_contains($config, 'retries: 0'), 'Staging Playwright retries must remain disabled.');
$assert(!str_contains($config, 'retries: 1') && !str_contains($config, 'retries: 2'), 'No retry weakening may be introduced.');

fwrite(STDOUT, "StagingE2EFallbackBudgetContractTest: {$assertions} assertions passed\n");
