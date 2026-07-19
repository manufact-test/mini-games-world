<?php
declare(strict_types=1);

$projectRoot = dirname(__DIR__, 2);
$entrypointPath = $projectRoot . '/ops/deploy/managed-migrations.php';
$entrypoint = file_get_contents($entrypointPath);
if (!is_string($entrypoint)) {
    throw new RuntimeException('Managed migration entrypoint source is unavailable.');
}

$assertions = 0;
$assertTrue = static function (bool $value, string $message) use (&$assertions): void {
    $assertions++;
    if (!$value) throw new RuntimeException($message);
};

$assertTrue(
    str_contains($entrypoint, 'ProductionPreflightService.php')
        && str_contains($entrypoint, 'ProductionPreflightRunner.php'),
    'Permanent migration entrypoint must load the production preflight implementation'
);
$assertTrue(
    str_contains($entrypoint, "if (\$environment === 'production' && !\$policy->enabled())"),
    'Disabled production migrations must fall back to read-only preflight'
);
$assertTrue(
    str_contains($entrypoint, 'new ProductionPreflightRunner(')
        && str_contains($entrypoint, '$preflightRunner->run()'),
    'Fallback must execute the existing read-only production preflight runner'
);
$assertTrue(
    str_contains($entrypoint, "'action' => 'production_preflight'")
        && str_contains($entrypoint, "'mode' => 'read_only_preflight'"),
    'Fallback output must identify the read-only production preflight action'
);
$assertTrue(
    str_contains($entrypoint, "'production_changed' => false"),
    'Fallback must explicitly report that production was not changed'
);
$assertTrue(
    str_contains($entrypoint, '$controller = new ManagedMigrationController(')
        && str_contains($entrypoint, '$controller->run()'),
    'Managed migration execution path must remain available when explicitly enabled'
);

fwrite(STDOUT, "ProductionManagedMigrationPreflightFallbackContractTest passed: {$assertions} assertions.\n");
