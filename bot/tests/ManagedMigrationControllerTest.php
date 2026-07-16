<?php
declare(strict_types=1);

$databaseDir = dirname(__DIR__) . '/database';
require $databaseDir . '/DatabaseConnectionInterface.php';
require $databaseDir . '/PdoDatabaseConnection.php';
require $databaseDir . '/DatabaseMigrationInterface.php';
require $databaseDir . '/MigrationRepository.php';
require $databaseDir . '/MigrationRunner.php';
require $databaseDir . '/ManagedMigrationConfig.php';
require $databaseDir . '/ManagedMigrationController.php';

if (!extension_loaded('pdo_sqlite')) {
    throw new RuntimeException('ManagedMigrationControllerTest requires pdo_sqlite.');
}

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

$root = sys_get_temp_dir() . '/mgw-managed-migration-' . bin2hex(random_bytes(5));
mkdir($root, 0755, true);
$migrationFile = $root . '/20260716_9999_managed_migration_test.php';
file_put_contents($migrationFile, <<<'PHP'
<?php
declare(strict_types=1);
return new class implements DatabaseMigrationInterface {
    public function version(): string { return '20260716_9999_managed_migration_test'; }
    public function description(): string { return 'Managed migration test.'; }
    public function transactional(): bool { return true; }
    public function up(DatabaseConnectionInterface $database): void {
        $database->execute('CREATE TABLE managed_test (id INTEGER PRIMARY KEY, value TEXT NOT NULL)');
    }
};
PHP);

$remove = static function (string $path) use (&$remove): void {
    if (is_file($path) || is_link($path)) {
        unlink($path);
        return;
    }
    if (!is_dir($path)) return;
    foreach (array_diff(scandir($path) ?: [], ['.', '..']) as $item) {
        $remove($path . '/' . $item);
    }
    rmdir($path);
};

try {
    $stagingDatabase = new PdoDatabaseConnection(new PDO('sqlite::memory:'));
    $stagingRunner = new MigrationRunner($stagingDatabase, $root);
    $stagingPolicy = ManagedMigrationConfig::fromApplicationConfig(['environment' => 'staging']);
    $backupCalls = 0;
    $stagingController = new ManagedMigrationController(
        $stagingRunner,
        $stagingPolicy,
        static function () use (&$backupCalls): array {
            $backupCalls++;
            return ['ok' => true];
        }
    );

    $plan = $stagingController->inspect();
    $assertSame(1, $plan['pending_count'], 'Staging plan must detect one migration');
    $assertSame(64, strlen((string)$plan['plan_fingerprint']), 'Plan fingerprint must be SHA-256');

    $stagingResult = $stagingController->run();
    $assertSame('migrated', $stagingResult['action'], 'Staging must auto-run an approved managed migration');
    $assertSame(1, $stagingResult['executed_count'], 'Staging must execute the migration once');
    $assertSame(0, $stagingResult['after']['pending_count'], 'Staging schema must be current after execution');
    $assertSame(0, $backupCalls, 'Staging migration must not create a production backup');
    $assertSame(1, (int)$stagingDatabase->fetchValue("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='managed_test'"), 'Migration must create its table');

    $stagingRepeat = $stagingController->run();
    $assertSame('noop', $stagingRepeat['action'], 'Repeated managed migration must be a no-op');
    $assertSame(0, $stagingRepeat['executed_count'], 'Repeated managed migration must execute nothing');

    $disabledDatabase = new PdoDatabaseConnection(new PDO('sqlite::memory:'));
    $disabledRunner = new MigrationRunner($disabledDatabase, $root);
    $disabledPolicy = ManagedMigrationConfig::fromApplicationConfig(['environment' => 'production']);
    $assertThrows(
        static fn() => (new ManagedMigrationController($disabledRunner, $disabledPolicy))->run(),
        'disabled',
        'Production managed migrations must default disabled'
    );

    $productionDatabase = new PdoDatabaseConnection(new PDO('sqlite::memory:'));
    $productionRunner = new MigrationRunner($productionDatabase, $root);
    $planningPolicy = ManagedMigrationConfig::fromApplicationConfig([
        'environment' => 'production',
        'managed_migrations' => ['enabled' => true],
    ]);
    $productionPlan = (new ManagedMigrationController($productionRunner, $planningPolicy))->inspect();
    $fingerprint = (string)$productionPlan['plan_fingerprint'];

    $unapprovedPolicy = ManagedMigrationConfig::fromApplicationConfig([
        'environment' => 'production',
        'database_migrations_allow_production' => true,
        'managed_migrations' => [
            'enabled' => true,
            'production_approval_fingerprint' => str_repeat('b', 64),
            'production_approval_expires_at_utc' => '2030-01-01T00:00:00Z',
        ],
    ]);
    $assertThrows(
        static fn() => (new ManagedMigrationController($productionRunner, $unapprovedPolicy, null, strtotime('2029-01-01T00:00:00Z')))->run(),
        'not explicitly approved',
        'Production migration must require the exact plan fingerprint'
    );

    $approvedPolicy = ManagedMigrationConfig::fromApplicationConfig([
        'environment' => 'production',
        'database_migrations_allow_production' => true,
        'managed_migrations' => [
            'enabled' => true,
            'production_approval_fingerprint' => $fingerprint,
            'production_approval_expires_at_utc' => '2030-01-01T00:00:00Z',
            'require_production_backup' => true,
            'require_production_external_copy' => true,
        ],
    ]);
    $productionBackupCalls = 0;
    $approvedController = new ManagedMigrationController(
        $productionRunner,
        $approvedPolicy,
        static function () use (&$productionBackupCalls): array {
            $productionBackupCalls++;
            return [
                'ok' => true,
                'backup_id' => 'backup-test',
                'snapshot_sha256' => str_repeat('c', 64),
                'external_copy' => ['copied' => true],
            ];
        },
        strtotime('2029-01-01T00:00:00Z')
    );
    $productionResult = $approvedController->run();
    $assertSame(1, $productionBackupCalls, 'Approved production migration must create one backup');
    $assertSame(1, $productionResult['executed_count'], 'Approved production migration must execute once');
    $assertSame('backup-test', $productionResult['backup']['backup_id'], 'Production result must retain safe backup evidence');
    $assertSame(0, $productionResult['after']['pending_count'], 'Production schema must be current after execution');

    $missingExternalDatabase = new PdoDatabaseConnection(new PDO('sqlite::memory:'));
    $missingExternalRunner = new MigrationRunner($missingExternalDatabase, $root);
    $missingExternalPlan = (new ManagedMigrationController($missingExternalRunner, $planningPolicy))->inspect();
    $missingExternalFingerprint = (string)$missingExternalPlan['plan_fingerprint'];
    $missingExternalPolicy = ManagedMigrationConfig::fromApplicationConfig([
        'environment' => 'production',
        'database_migrations_allow_production' => true,
        'managed_migrations' => [
            'enabled' => true,
            'production_approval_fingerprint' => $missingExternalFingerprint,
            'production_approval_expires_at_utc' => '2030-01-01T00:00:00Z',
        ],
    ]);
    $assertThrows(
        static fn() => (new ManagedMigrationController(
            $missingExternalRunner,
            $missingExternalPolicy,
            static fn(): array => ['ok' => true, 'external_copy' => ['copied' => false]],
            strtotime('2029-01-01T00:00:00Z')
        ))->run(),
        'external backup copy',
        'Production migration must stop when the external backup copy fails'
    );

    fwrite(STDOUT, "ManagedMigrationControllerTest: {$assertions} assertions passed\n");
} finally {
    $remove($root);
}
