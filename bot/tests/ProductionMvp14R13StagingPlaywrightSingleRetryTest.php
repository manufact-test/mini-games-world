<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$config = file_get_contents($root . '/e2e/playwright.config.mjs');
$spec = file_get_contents($root . '/e2e/staging/two-context.spec.mjs');
$workflow = file_get_contents($root . '/.github/workflows/staging-playwright-e2e.yml');
if (!is_string($config) || !is_string($spec) || !is_string($workflow)) {
    throw new RuntimeException('Missing staging Playwright retry source.');
}

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

$assert(substr_count($config, 'retries: 1') === 1
    && !str_contains($config, 'retries: 2')
    && !str_contains($config, 'retries: 3'),
    'The staging suite may retry each failed test exactly once and never more.');
$assert(str_contains($config, 'fullyParallel: false')
    && str_contains($config, 'workers: 1'),
    'The retry must remain serial and deterministic for the two fixed players.');
$assert(str_contains($config, "trace: 'retain-on-failure'")
    && str_contains($config, "screenshot: 'only-on-failure'")
    && str_contains($config, "video: 'retain-on-failure'"),
    'Both the first failure and final failure must retain browser evidence.');
$assert(str_contains($spec, 'finally {')
    && str_contains($spec, 'await cleanupPlayer(playerA)')
    && str_contains($spec, 'await cleanupPlayer(playerB)')
    && substr_count($spec, 'await revokeContext(') >= 4,
    'A retry must be protected by game/invite cleanup and session revocation.');
$assert(str_contains($workflow, "result='application_failure'")
    && str_contains($workflow, "needs.linux-route.outputs.result == 'network_unavailable'")
    && !str_contains($workflow, "needs.linux-route.outputs.result == 'application_failure'"),
    'One test retry must not cause application failures to be hidden by an OS fallback.');
$assert(!str_contains($config, 'production')
    && !str_contains($config, 'lemonchiffon-gerbil-545102.hostingersite.com')
    && str_contains($config, 'seashell-okapi-889488.hostingersite.com'),
    'The bounded retry must remain staging-only.');

fwrite(STDOUT, "ProductionMvp14R13StagingPlaywrightSingleRetryTest: {$assertions} assertions passed\n");
