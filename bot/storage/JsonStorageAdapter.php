<?php
declare(strict_types=1);

require_once __DIR__ . '/contracts/SelectiveReadStorageInterface.php';

final class JsonStorageAdapter implements StorageAdapterInterface, SelectiveReadStorageInterface
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

    public function driver(): string
    {
        return 'json';
    }
}
