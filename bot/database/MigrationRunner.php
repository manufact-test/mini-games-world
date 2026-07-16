<?php
declare(strict_types=1);

final class MigrationRunner
{
    private const LOCK_NAME = 'mini_games_world_schema_migrations';

    public function __construct(
        private DatabaseConnectionInterface $database,
        private string $migrationDirectory
    ) {
        $this->migrationDirectory = rtrim(str_replace('\\', '/', $this->migrationDirectory), '/');
    }

    public function status(): array
    {
        $repository = new MigrationRepository($this->database);
        $repository->ensureTable();
        $available = $this->discover();
        $applied = $repository->applied();
        $pending = [];

        foreach ($available as $version => $item) {
            if (!isset($applied[$version])) {
                $pending[] = $this->publicMigration($item);
                continue;
            }
            if (!hash_equals((string)$applied[$version]['checksum'], (string)$item['checksum'])) {
                throw new RuntimeException('Applied migration checksum mismatch: ' . $version);
            }
        }

        $this->assertNoAppliedMigrationIsMissing($available, $applied);

        return [
            'ok' => true,
            'driver' => $this->database->driver(),
            'available_count' => count($available),
            'applied_count' => count($applied),
            'pending_count' => count($pending),
            'pending' => $pending,
        ];
    }

    public function migrate(bool $dryRun = false): array
    {
        return $this->withMigrationLock(function () use ($dryRun): array {
            $repository = new MigrationRepository($this->database);
            $repository->ensureTable();
            $available = $this->discover();
            $applied = $repository->applied();
            $pending = [];

            $this->assertNoAppliedMigrationIsMissing($available, $applied);

            foreach ($available as $version => $item) {
                if (isset($applied[$version])) {
                    if (!hash_equals((string)$applied[$version]['checksum'], (string)$item['checksum'])) {
                        throw new RuntimeException('Applied migration checksum mismatch: ' . $version);
                    }
                    continue;
                }
                $pending[$version] = $item;
            }

            if ($dryRun) {
                return [
                    'ok' => true,
                    'dry_run' => true,
                    'driver' => $this->database->driver(),
                    'pending_count' => count($pending),
                    'pending' => array_values(array_map($this->publicMigration(...), $pending)),
                ];
            }

            $executed = [];
            foreach ($pending as $version => $item) {
                /** @var DatabaseMigrationInterface $migration */
                $migration = $item['migration'];
                $this->database->transaction(function (DatabaseConnectionInterface $database) use ($repository, $migration, $item, $version): void {
                    $migration->up($database);
                    $repository->record($version, (string)$item['checksum'], $migration->description());
                });
                $executed[] = $this->publicMigration($item);
            }

            return [
                'ok' => true,
                'dry_run' => false,
                'driver' => $this->database->driver(),
                'executed_count' => count($executed),
                'executed' => $executed,
            ];
        });
    }

    private function discover(): array
    {
        if (!is_dir($this->migrationDirectory)) {
            throw new RuntimeException('Migration directory does not exist.');
        }

        $migrations = [];
        foreach (glob($this->migrationDirectory . '/*.php') ?: [] as $file) {
            $basename = basename($file, '.php');
            if (preg_match('/^\d{8}_\d{4}_[a-z0-9_]+$/', $basename) !== 1) {
                throw new RuntimeException('Invalid migration filename: ' . basename($file));
            }

            $migration = require $file;
            if (!$migration instanceof DatabaseMigrationInterface) {
                throw new RuntimeException('Migration must implement DatabaseMigrationInterface: ' . basename($file));
            }
            if ($migration->version() !== $basename) {
                throw new RuntimeException('Migration version does not match its filename: ' . basename($file));
            }
            if (isset($migrations[$basename])) {
                throw new RuntimeException('Duplicate migration version: ' . $basename);
            }

            $checksum = hash_file('sha256', $file);
            if (!is_string($checksum)) {
                throw new RuntimeException('Could not calculate migration checksum: ' . basename($file));
            }

            $migrations[$basename] = [
                'version' => $basename,
                'description' => trim($migration->description()),
                'checksum' => $checksum,
                'migration' => $migration,
            ];
        }

        ksort($migrations, SORT_STRING);
        return $migrations;
    }

    private function assertNoAppliedMigrationIsMissing(array $available, array $applied): void
    {
        foreach (array_keys($applied) as $version) {
            if (!isset($available[$version])) {
                throw new RuntimeException('Applied migration file is missing: ' . $version);
            }
        }
    }

    private function withMigrationLock(callable $callback): mixed
    {
        if ($this->database->driver() !== 'mysql') {
            return $callback();
        }

        $acquired = (int)$this->database->fetchValue(
            'SELECT GET_LOCK(:lock_name, :timeout_seconds)',
            ['lock_name' => self::LOCK_NAME, 'timeout_seconds' => 10]
        );
        if ($acquired !== 1) {
            throw new RuntimeException('Could not acquire the database migration lock.');
        }

        try {
            return $callback();
        } finally {
            try {
                $this->database->fetchValue(
                    'SELECT RELEASE_LOCK(:lock_name)',
                    ['lock_name' => self::LOCK_NAME]
                );
            } catch (Throwable) {
                // The database connection releases advisory locks when it closes.
            }
        }
    }

    private function publicMigration(array $item): array
    {
        return [
            'version' => (string)$item['version'],
            'description' => (string)$item['description'],
            'checksum' => (string)$item['checksum'],
        ];
    }
}
