<?php
declare(strict_types=1);

$databaseDir = dirname(__DIR__) . '/database';
require $databaseDir . '/DatabaseConnectionInterface.php';
require $databaseDir . '/PdoDatabaseConnection.php';
require $databaseDir . '/DatabaseMigrationInterface.php';
require $databaseDir . '/MigrationRepository.php';
require $databaseDir . '/MigrationRunner.php';

$assertions = 0;
$assertTrue = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};
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

if (!extension_loaded('pdo_sqlite')) {
    throw new RuntimeException('MigrationRunnerTest requires pdo_sqlite.');
}

$root = sys_get_temp_dir() . '/mgw-migration-test-' . bin2hex(random_bytes(5));
$migrations = $root . '/migrations';
mkdir($migrations, 0755, true);
$sourceMigration = $databaseDir . '/migrations/20260716_0001_create_mgw_meta.php';
$firstMigration = $migrations . '/20260716_0001_create_mgw_meta.php';
copy($sourceMigration, $firstMigration);

$remove = static function (string $path) use (&$remove): void {
    if (!is_dir($path)) return;
    $items = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($items as $item) {
        $item->isDir() && !$item->isLink() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    }
    rmdir($path);
};

try {
    $pdo = new PDO('sqlite::memory:');
    $database = new PdoDatabaseConnection($pdo);
    $runner = new MigrationRunner($database, $migrations);

    $status = $runner->status();
    $assertSame(1, $status['available_count'], 'One initial migration must be discoverable');
    $assertSame(1, $status['pending_count'], 'Initial migration must be pending');

    $dryRun = $runner->migrate(true);
    $assertSame(true, $dryRun['dry_run'], 'Dry-run must be reported');
    $assertSame(1, $dryRun['pending_count'], 'Dry-run must list the pending migration');
    $metaBefore = $database->fetchValue("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='mgw_meta'");
    $assertSame(0, (int)$metaBefore, 'Dry-run must not execute migration SQL');

    $result = $runner->migrate(false);
    $assertSame(1, $result['executed_count'], 'Initial migration must execute exactly once');
    $metaAfter = $database->fetchValue("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='mgw_meta'");
    $assertSame(1, (int)$metaAfter, 'Initial migration must create mgw_meta');

    $secondRun = $runner->migrate(false);
    $assertSame(0, $secondRun['executed_count'], 'Repeated migration run must be idempotent');
    $statusAfter = $runner->status();
    $assertSame(1, $statusAfter['applied_count'], 'Applied migration must be recorded once');
    $assertSame(0, $statusAfter['pending_count'], 'No migration may remain pending after success');

    $database->execute('CREATE TABLE transaction_test (id INTEGER PRIMARY KEY, value TEXT NOT NULL)');
    $database->transaction(function (DatabaseConnectionInterface $outer): void {
        $outer->execute('INSERT INTO transaction_test (id, value) VALUES (1, :value)', ['value' => 'outer']);
        try {
            $outer->transaction(function (DatabaseConnectionInterface $inner): void {
                $inner->execute('INSERT INTO transaction_test (id, value) VALUES (2, :value)', ['value' => 'inner']);
                throw new RuntimeException('rollback-inner');
            });
        } catch (RuntimeException $error) {
            if ($error->getMessage() !== 'rollback-inner') throw $error;
        }
        $outer->execute('INSERT INTO transaction_test (id, value) VALUES (3, :value)', ['value' => 'after']);
    });
    $transactionRows = $database->fetchAll('SELECT id FROM transaction_test ORDER BY id');
    $assertSame([['id' => 1], ['id' => 3]], $transactionRows, 'Nested transaction rollback must preserve outer work');

    $failingMigration = $migrations . '/20260716_0002_failing_migration.php';
    file_put_contents($failingMigration, <<<'PHP'
<?php
declare(strict_types=1);
return new class implements DatabaseMigrationInterface {
    public function version(): string { return '20260716_0002_failing_migration'; }
    public function description(): string { return 'Intentional rollback test.'; }
    public function up(DatabaseConnectionInterface $database): void {
        $database->execute('CREATE TABLE must_rollback (id INTEGER PRIMARY KEY)');
        throw new RuntimeException('migration-failed');
    }
};
PHP);

    $assertThrows(
        static fn() => $runner->migrate(false),
        'migration-failed',
        'Failed migration must propagate its error'
    );
    $rolledBackTable = $database->fetchValue("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='must_rollback'");
    $assertSame(0, (int)$rolledBackTable, 'Failed migration DDL must roll back');
    $appliedRows = $database->fetchValue('SELECT COUNT(*) FROM mgw_schema_migrations');
    $assertSame(1, (int)$appliedRows, 'Failed migration must not be recorded');
    unlink($failingMigration);

    file_put_contents($firstMigration, "\n// checksum changed\n", FILE_APPEND);
    $assertThrows(
        static fn() => $runner->status(),
        'checksum mismatch',
        'Changing an applied migration must fail closed'
    );

    fwrite(STDOUT, "MigrationRunnerTest: {$assertions} assertions passed\n");
} finally {
    $remove($root);
}
