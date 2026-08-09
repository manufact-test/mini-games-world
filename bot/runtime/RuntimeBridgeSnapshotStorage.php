<?php
declare(strict_types=1);

require_once __DIR__ . '/../storage/contracts/StorageAdapterInterface.php';
require_once __DIR__ . '/../storage/contracts/SelectiveReadStorageInterface.php';
require_once __DIR__ . '/../storage/contracts/ExclusiveSnapshotStorageInterface.php';

final class RuntimeBridgeSnapshotStorage implements StorageAdapterInterface, SelectiveReadStorageInterface, ExclusiveSnapshotStorageInterface
{
    public function __construct(private array $snapshot) {}

    public function transaction(callable $callback): mixed
    {
        throw new RuntimeException('Runtime bridge snapshot storage is read-only.');
    }

    public function readOnly(callable $callback): mixed
    {
        return $callback($this->snapshot);
    }

    public function readOnlySections(array $sections, callable $callback): mixed
    {
        return $callback($this->sections($sections));
    }

    public function exclusiveReadOnly(callable $callback): mixed
    {
        return $callback($this->snapshot);
    }

    public function exclusiveReadOnlySections(array $sections, callable $callback): mixed
    {
        return $callback($this->sections($sections));
    }

    public function driver(): string
    {
        return RuntimeStorageRouter::DRIVER_JSON;
    }

    private function sections(array $sections): array
    {
        $result = [];
        foreach ($sections as $section) {
            $section = trim((string)$section);
            if ($section === '' || !array_key_exists($section, $this->snapshot)) {
                throw new RuntimeException('Runtime bridge snapshot section is unavailable: ' . $section);
            }
            if (!array_key_exists($section, $result)) {
                $result[$section] = $this->snapshot[$section];
            }
        }
        return $result;
    }
}
