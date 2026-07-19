<?php
declare(strict_types=1);

$projectRoot = dirname(__DIR__, 2);
$entrypointPath = $projectRoot . '/ops/deploy/staging-operations-runner.php';
$runnerPath = $projectRoot . '/bot/operations/StagingOperationsRunner.php';

$entrypoint = file_get_contents($entrypointPath);
$runner = file_get_contents($runnerPath);
if (!is_string($entrypoint) || !is_string($runner)) {
    throw new RuntimeException('Staging operations runner sources are unavailable.');
}

$assertions = 0;
$assertTrue = static function (bool $value, string $message) use (&$assertions): void {
    $assertions++;
    if (!$value) throw new RuntimeException($message);
};

$assertTrue(
    preg_match("/FeatureFlagService::BUILD\\s*!==\\s*'v\\d+/", $entrypoint) !== 1,
    'Permanent staging runner must not be disabled by a stale hard-coded build literal'
);
$assertTrue(
    str_contains($entrypoint, 'trim(FeatureFlagService::BUILD)'),
    'Entrypoint must still reject a missing application build'
);
$assertTrue(
    str_contains($entrypoint, 'new StagingOperationsRunner(')
        && str_contains($entrypoint, 'FeatureFlagService::BUILD,'),
    'Entrypoint must pass the current application build to the runner'
);
$assertTrue(
    str_contains($runner, 'hash_equals($operation->build(), $this->build)'),
    'Immutable operation definitions must remain bound to their exact build'
);
$assertTrue(
    str_contains($entrypoint, "if (\$environment !== 'staging')"),
    'Permanent operations runner must remain staging-only'
);

fwrite(STDOUT, "StagingOperationsRunnerEntrypointContractTest passed: {$assertions} assertions.\n");
