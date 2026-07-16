<?php
declare(strict_types=1);

final class JsonStorageAdapter implements StorageAdapterInterface
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

    public function driver(): string
    {
        return 'json';
    }
}
