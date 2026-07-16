<?php
declare(strict_types=1);

final class StorageFactory
{
    public static function create(array $config): StorageAdapterInterface
    {
        $driver = strtolower(trim((string)($config['storage_driver'] ?? 'json')));
        if ($driver === '') {
            $driver = 'json';
        }

        return match ($driver) {
            'json' => self::createJson((string)($config['data_dir'] ?? '')),
            default => throw new RuntimeException('Unsupported storage driver: ' . $driver),
        };
    }

    /**
     * Compatibility factory for legacy call sites that currently know only data_dir.
     * These call sites can be migrated to create(array $config) independently.
     */
    public static function createJson(string $dataDir): StorageAdapterInterface
    {
        return new JsonStorageAdapter($dataDir);
    }
}
