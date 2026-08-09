<?php
declare(strict_types=1);

require_once __DIR__ . '/RuntimeBridgeSnapshotStorage.php';

final class RuntimeBridgeProjectionCoordinator
{
    /** @var array<string,array{handle:resource,depth:int}> */
    private static array $locks = [];

    public static function synchronize(
        array $config,
        StorageAdapterInterface $storage,
        callable $callback
    ): mixed {
        if ($storage->driver() !== RuntimeStorageRouter::DRIVER_JSON) {
            throw new RuntimeException('Runtime bridge coordinator requires JSON rollback storage.');
        }

        $dataDir = rtrim((string)($config['data_dir'] ?? ''), '/');
        if ($dataDir === '') {
            throw new RuntimeException('Runtime bridge coordinator requires data_dir.');
        }
        if (!is_dir($dataDir) && !mkdir($dataDir, 0755, true) && !is_dir($dataDir)) {
            throw new RuntimeException('Runtime bridge coordinator could not prepare data_dir.');
        }

        $lockPath = $dataDir . '/runtime-db-projection.lock';
        if (isset(self::$locks[$lockPath])) {
            self::$locks[$lockPath]['depth']++;
            try {
                return self::runWithFreshSnapshot($storage, $callback);
            } finally {
                self::$locks[$lockPath]['depth']--;
            }
        }

        $handle = fopen($lockPath, 'c+');
        if ($handle === false) {
            throw new RuntimeException('Runtime bridge coordinator could not open projection lock.');
        }

        try {
            if (!flock($handle, LOCK_EX)) {
                throw new RuntimeException('Runtime bridge coordinator could not lock DB projection.');
            }
            self::$locks[$lockPath] = ['handle' => $handle, 'depth' => 1];

            // Important ownership boundary:
            // app.lock is held only long enough to copy the latest JSON snapshot.
            // External SQL projection/parity work runs after that lock is released,
            // while this separate bridge lock keeps DB projections serialized.
            return self::runWithFreshSnapshot($storage, $callback);
        } finally {
            unset(self::$locks[$lockPath]);
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    private static function runWithFreshSnapshot(
        StorageAdapterInterface $storage,
        callable $callback
    ): mixed {
        $snapshot = $storage->readOnly(static fn(array $data): array => $data);
        if (!is_array($snapshot)) {
            throw new RuntimeException('Runtime bridge coordinator could not read JSON snapshot.');
        }

        return $callback(
            $snapshot,
            new RuntimeBridgeSnapshotStorage($snapshot)
        );
    }
}
