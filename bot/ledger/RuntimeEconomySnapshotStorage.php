<?php
declare(strict_types=1);

require_once __DIR__ . '/../storage/contracts/ExclusiveSnapshotStorageInterface.php';

final class RuntimeEconomySnapshotStorage implements StorageAdapterInterface, ExclusiveSnapshotStorageInterface
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

    public function exclusiveReadOnly(callable $callback): mixed
    {
        // This adapter owns one immutable in-memory snapshot and has no writer
        // path. Its read boundary is therefore already exclusive by construction.
        return $this->readOnly($callback);
    }

    public function exclusiveReadOnlySections(array $sections, callable $callback): mixed
    {
        $selected = [];
        foreach ($sections as $section) {
            $section = trim((string)$section);
            if ($section === '') {
                throw new InvalidArgumentException('Runtime economy snapshot section must not be empty.');
            }
            if (!array_key_exists($section, $this->snapshot)) {
                throw new RuntimeException('Runtime economy snapshot is missing requested section: ' . $section);
            }
            if (!array_key_exists($section, $selected)) {
                $selected[$section] = $this->snapshot[$section];
            }
        }
        return $callback($selected);
    }

    public function driver(): string
    {
        return RuntimeStorageRouter::DRIVER_JSON;
    }
}
