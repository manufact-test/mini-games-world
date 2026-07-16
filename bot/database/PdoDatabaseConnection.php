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
        return is_array($rows) ? $rows : [];
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
}
