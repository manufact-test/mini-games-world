<?php
declare(strict_types=1);

final class PdoDatabaseConnection implements DatabaseConnectionInterface
{
    private int $transactionDepth = 0;

    public function __construct(private PDO $pdo)
    {
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    }

    public function driver(): string
    {
        return strtolower((string)$this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME));
    }

    public function execute(string $sql, array $parameters = []): int
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($parameters);
        return $statement->rowCount();
    }

    public function fetchAll(string $sql, array $parameters = []): array
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($parameters);
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
        if (!is_array($rows)) return [];

        if ($this->driver() === 'mysql') {
            foreach ($rows as &$row) {
                if (!is_array($row)) continue;
                foreach ($row as $column => &$value) {
                    if (!is_string($column) || !str_ends_with(strtolower($column), '_json')) continue;
                    if (!is_string($value) || trim($value) === '') continue;
                    $value = $this->canonicalJson($value);
                }
                unset($value);
            }
            unset($row);
        }

        return $rows;
    }

    public function fetchValue(string $sql, array $parameters = []): mixed
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($parameters);
        $value = $statement->fetchColumn();
        return $value === false ? null : $value;
    }

    public function transaction(callable $callback): mixed
    {
        $depth = $this->transactionDepth;
        $savepoint = 'mgw_sp_' . $depth;

        if ($depth === 0) {
            $this->pdo->beginTransaction();
        } else {
            $this->pdo->exec('SAVEPOINT ' . $savepoint);
        }
        $this->transactionDepth++;

        try {
            $result = $callback($this);
            $this->transactionDepth--;

            if ($depth === 0) {
                $this->pdo->commit();
            } else {
                $this->pdo->exec('RELEASE SAVEPOINT ' . $savepoint);
            }

            return $result;
        } catch (Throwable $error) {
            $this->transactionDepth--;

            if ($depth === 0) {
                if ($this->pdo->inTransaction()) {
                    $this->pdo->rollBack();
                }
            } else {
                $this->pdo->exec('ROLLBACK TO SAVEPOINT ' . $savepoint);
            }

            throw $error;
        }
    }

    private function canonicalJson(string $json): string
    {
        try {
            $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
            return json_encode(
                $this->canonicalize($decoded),
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
            );
        } catch (JsonException) {
            return $json;
        }
    }

    private function canonicalize(mixed $value): mixed
    {
        if (!is_array($value)) return $value;
        if (!array_is_list($value)) ksort($value, SORT_STRING);
        foreach ($value as $key => $item) $value[$key] = $this->canonicalize($item);
        return $value;
    }
}
