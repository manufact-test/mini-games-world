<?php
declare(strict_types=1);

require dirname(__DIR__) . '/database/ManagedMigrationConfig.php';

$assertions = 0;
$assertSame = static function (mixed $expected, mixed $actual, string $message) use (&$assertions): void {
    $assertions++;
    if ($expected !== $actual) {
        throw new RuntimeException($message . ': expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
};
$assertThrows = static function (callable $callback, string $contains, string $message) use (&$assertions): void {
    $assertions++;
    try {
        $callback();
    } catch (Throwable $error) {
        if (str_contains($error->getMessage(), $contains)) return;
        throw new RuntimeException($message . ': unexpected error ' . $error->getMessage());
    }
    throw new RuntimeException($message . ': no error was thrown');
};

$staging = ManagedMigrationConfig::fromApplicationConfig(['environment' => 'staging']);
$assertSame('staging', $staging->environment(), 'Staging environment must be normalized');
$assertSame(true, $staging->enabled(), 'Managed migrations must default on only in staging');
$assertSame(true, $staging->autoRunStaging(), 'Staging auto-run must default on');
$assertSame(false, $staging->productionCliAllowed(), 'Production approval must default off');

$production = ManagedMigrationConfig::fromApplicationConfig(['environment' => 'production']);
$assertSame(false, $production->enabled(), 'Production managed migrations must default off');
$assertSame(true, $production->requireProductionBackup(), 'Production backup must default required');
$assertSame(true, $production->requireProductionExternalCopy(), 'Production external copy must default required');

$fingerprint = str_repeat('a', 64);
$approved = ManagedMigrationConfig::fromApplicationConfig([
    'environment' => 'production',
    'database_migrations_allow_production' => true,
    'managed_migrations' => [
        'enabled' => true,
        'production_approval_fingerprint' => strtoupper($fingerprint),
        'production_approval_expires_at_utc' => '2030-01-01T00:00:00Z',
    ],
]);
$assertSame(true, $approved->enabled(), 'Production managed migrations require explicit enablement');
$assertSame(true, $approved->productionCliAllowed(), 'Private production CLI approval must be exposed');
$assertSame($fingerprint, $approved->productionApprovalFingerprint(), 'Approval fingerprint must be normalized');
$assertSame(true, $approved->productionApprovalIsCurrent(strtotime('2029-12-31T23:59:00Z')), 'Future approval must be current');
$assertSame(false, $approved->productionApprovalIsCurrent(strtotime('2030-01-01T00:00:01Z')), 'Expired approval must fail closed');

$summary = $approved->safeSummary();
$assertSame(true, $summary['production_approval_configured'], 'Safe summary may reveal only that approval exists');
$assertSame(false, array_key_exists('production_approval_fingerprint', $summary), 'Safe summary must not expose the approval fingerprint');

$assertThrows(
    static fn() => ManagedMigrationConfig::fromApplicationConfig([
        'environment' => 'production',
        'managed_migrations' => ['production_approval_fingerprint' => 'not-a-hash'],
    ]),
    'SHA-256',
    'Invalid approval fingerprint must fail closed'
);
$assertThrows(
    static fn() => ManagedMigrationConfig::fromApplicationConfig([
        'environment' => 'production',
        'managed_migrations' => ['production_approval_expires_at_utc' => 'not-a-date'],
    ]),
    'valid UTC',
    'Invalid approval expiry must fail closed'
);

fwrite(STDOUT, "ManagedMigrationConfigTest: {$assertions} assertions passed\n");
