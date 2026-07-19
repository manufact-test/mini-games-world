<?php
declare(strict_types=1);

final class RuntimeEconomySnapshotStorage implements StorageAdapterInterface
{
    public function __construct(private array $snapshot) {}

    public function transaction(callable $callback): mixed
    {
        throw new RuntimeException('Runtime economy snapshot storage is read-only.');
    }

    public function readOnly(callable $callback): mixed
    {
        $snapshot = $this->snapshot;
        return $callback($snapshot);
    }

    public function driver(): string
    {
        return RuntimeStorageRouter::DRIVER_JSON;
    }
}
