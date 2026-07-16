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
            'json' => new JsonStorageAdapter((string)($config['data_dir'] ?? '')),
            default => throw new RuntimeException('Unsupported storage driver: ' . $driver),
        };
    }
}
