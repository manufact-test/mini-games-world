<?php
declare(strict_types=1);

require_once __DIR__ . '/contracts/SelectiveReadStorageInterface.php';
require_once __DIR__ . '/contracts/ExclusiveSnapshotStorageInterface.php';
require_once __DIR__ . '/contracts/ProjectionDirtyStorageInterface.php';
require_once __DIR__ . '/contracts/ProjectionSnapshotStorageInterface.php';

final class JsonStorageAdapter implements StorageAdapterInterface, SelectiveReadStorageInterface, ExclusiveSnapshotStorageInterface, ProjectionDirtyStorageInterface, ProjectionSnapshotStorageInterface
{
    private JsonDatabase $database;

    public function __construct(string $dataDir)
    {
        $this->database = new JsonDatabase($dataDir);
    }

    public function transaction(callable $callback): mixed
    {
        return $this->database->transaction($callback);
    }

    public function readOnly(callable $callback): mixed
    {
        return $this->database->readOnly($callback);
    }

    public function readOnlySections(array $sections, callable $callback): mixed
    {
        return $this->database->readOnlySections($sections, $callback);
    }

    public function exclusiveReadOnly(callable $callback): mixed
    {
        return $this->database->exclusiveReadOnly($callback);
    }

    public function exclusiveReadOnlySections(array $sections, callable $callback): mixed
    {
        return $this->database->exclusiveReadOnlySections($sections, $callback);
    }

    public function projectionReadOnly(callable $callback): mixed
    {
        return $this->database->projectionReadOnly($callback);
    }

    public function projectionReadOnlySections(array $sections, callable $callback): mixed
    {
        return $this->database->projectionReadOnlySections($sections, $callback);
    }

    public function runtimeProjectionDirty(): bool
    {
        return $this->database->runtimeProjectionDirty();
    }

    public function clearRuntimeProjectionDirty(): void
    {
        $this->database->clearRuntimeProjectionDirty();
    }

    public function driver(): string
    {
        return 'json';
    }
}
