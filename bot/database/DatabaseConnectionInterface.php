<?php
declare(strict_types=1);

interface DatabaseConnectionInterface
{
    public function driver(): string;

    public function execute(string $sql, array $parameters = []): int;

    public function fetchAll(string $sql, array $parameters = []): array;

    public function fetchValue(string $sql, array $parameters = []): mixed;

    public function transaction(callable $callback): mixed;
}
