<?php
declare(strict_types=1);

final class ProductionPrimaryRollbackMaterializedStateConnection implements DatabaseConnectionInterface
{
    private int $stateSubstitutionCount = 0;
    private bool $sourceLockVerified = false;
    private int $sourceStateRevision;
    private string $sourceStateSha256;
    private string $materializedStateSha256;
    private string $materializedStateJson;

    public function __construct(
        private DatabaseConnectionInterface $database,
        array $materialization
    ) {
        if ($this->database->driver() !== 'mysql') {
            throw new RuntimeException('Materialized rollback connection requires MySQL/MariaDB.');
        }
        if (($materialization['ok'] ?? false) !== true
            || ($materialization['read_only'] ?? false) !== true
            || ($materialization['database_write_executed'] ?? null) !== false
            || ($materialization['contract_version'] ?? null)
                !== ProductionPrimaryRollbackSnapshotMaterializer::CONTRACT_VERSION) {
            throw new RuntimeException('Materialized rollback connection requires a verified read-only snapshot.');
        }

        $this->sourceStateRevision = (int)($materialization['source_state_revision'] ?? 0);
        $this->sourceStateSha256 = $this->exactSha($materialization['source_state_sha256'] ?? null);
        $this->materializedStateSha256 = $this->exactSha(
            $materialization['materialized_state_sha256'] ?? null
        );
        $snapshot = $materialization['snapshot'] ?? null;
        if ($this->sourceStateRevision < 1
            || $this->sourceStateSha256 === ''
            || $this->materializedStateSha256 === ''
            || !is_array($snapshot)
            || array_is_list($snapshot)) {
            throw new RuntimeException('Materialized rollback connection identity is incomplete.');
        }
        $this->materializedStateJson = $this->canonicalJson($snapshot);
        if (!hash_equals(
            $this->materializedStateSha256,
            hash('sha256', $this->materializedStateJson)
        )) {
            throw new RuntimeException('Materialized rollback connection snapshot fingerprint mismatch.');
        }
    }

    public function driver(): string
    {
        return $this->database->driver();
    }

    public function execute(string $sql, array $params = []): int
    {
        throw new RuntimeException('Materialized rollback export database connection is write-sealed.');
    }

    public function fetchAll(string $sql, array $params = []): array
    {
        if (!$this->isLockedStateRead($sql, $params)) {
            return $this->database->fetchAll($sql, $params);
        }

        $rows = $this->database->fetchAll($sql, $params);
        if (count($rows) !== 1 || !is_array($rows[0])) {
            throw new RuntimeException('Materialized rollback source state lock is unavailable.');
        }
        $row = $rows[0];
        $revision = (int)($row['revision'] ?? 0);
        $stateSha = $this->exactSha($row['state_sha256'] ?? null);
        $stateJson = trim((string)($row['state_json'] ?? ''));
        if ($revision !== $this->sourceStateRevision
            || $stateSha === ''
            || !hash_equals($this->sourceStateSha256, $stateSha)
            || $stateJson === '') {
            throw new RuntimeException('Materialized rollback source state changed before lock acquisition.');
        }
        try {
            $sourceSnapshot = json_decode($stateJson, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $error) {
            throw new RuntimeException('Materialized rollback locked source JSON is invalid.', 0, $error);
        }
        if (!is_array($sourceSnapshot)
            || !hash_equals(
                $this->sourceStateSha256,
                hash('sha256', $this->canonicalJson($sourceSnapshot))
            )) {
            throw new RuntimeException('Materialized rollback locked source fingerprint mismatch.');
        }

        $row['state_json'] = $this->materializedStateJson;
        $row['state_sha256'] = $this->materializedStateSha256;
        $this->sourceLockVerified = true;
        $this->stateSubstitutionCount++;

        return [$row];
    }

    public function transaction(callable $callback): mixed
    {
        return $this->database->transaction(
            function (...$_arguments) use ($callback): mixed {
                return $callback($this);
            }
        );
    }

    public function pdo(): PDO
    {
        return $this->database->pdo();
    }

    public function sourceLockVerified(): bool
    {
        return $this->sourceLockVerified;
    }

    public function stateSubstitutionCount(): int
    {
        return $this->stateSubstitutionCount;
    }

    private function isLockedStateRead(string $sql, array $params): bool
    {
        if ($params !== []) return false;
        $normalized = strtolower(preg_replace('/\s+/', ' ', trim($sql)) ?? '');
        $table = strtolower(RuntimePrimaryStateSchemaInstaller::TABLE);
        return str_contains($normalized, 'from ' . $table)
            && str_contains($normalized, 'where singleton_id = 1 for update')
            && str_contains($normalized, 'state_json')
            && str_contains($normalized, 'state_sha256');
    }

    private function exactSha(mixed $value): string
    {
        $value = strtolower(trim((string)$value));
        return preg_match('/\A[a-f0-9]{64}\z/', $value) === 1 ? $value : '';
    }

    private function canonicalJson(array $value): string
    {
        return json_encode(
            $this->canonicalize($value),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        );
    }

    private function canonicalize(mixed $value): mixed
    {
        if (!is_array($value)) return $value;
        if (!array_is_list($value)) ksort($value, SORT_STRING);
        foreach ($value as $key => $item) $value[$key] = $this->canonicalize($item);
        return $value;
    }
}
