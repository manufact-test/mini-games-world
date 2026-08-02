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
    /** @var array<string, array{entity_type:string,entity_key:string,payload_json:string,payload_sha256:string}> */
    private array $materializedEconomyBalanceRows = [];
    private bool $economyShadowReadVerified = false;
    private int $economyShadowReadCount = 0;

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
        $this->materializedEconomyBalanceRows = $this->economyBalanceRows($snapshot);
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
        if ($this->isLockedStateRead($sql, $params)) {
            return $this->lockedMaterializedStateRows($sql, $params);
        }

        $rows = $this->database->fetchAll($sql, $params);
        if ($this->isCombinedEconomyShadowRead($sql, $params)) {
            return $this->materializedEconomyShadowRows($rows, true);
        }
        if ($this->isEconomyBalanceShadowRead($sql, $params)) {
            return $this->materializedEconomyShadowRows($rows, false);
        }
        return $rows;
    }

    public function fetchValue(string $sql, array $params = []): mixed
    {
        return $this->database->fetchValue($sql, $params);
    }

    public function transaction(callable $callback): mixed
    {
        return $this->database->transaction(
            function (...$_arguments) use ($callback): mixed {
                return $callback($this);
            }
        );
    }

    public function sourceLockVerified(): bool
    {
        return $this->sourceLockVerified;
    }

    public function stateSubstitutionCount(): int
    {
        return $this->stateSubstitutionCount;
    }

    public function economyShadowReadVerified(): bool
    {
        return $this->economyShadowReadVerified;
    }

    public function economyShadowReadCount(): int
    {
        return $this->economyShadowReadCount;
    }

    public function economyShadowMaterializedUserCount(): int
    {
        return count($this->materializedEconomyBalanceRows);
    }

    private function lockedMaterializedStateRows(string $sql, array $params): array
    {
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

    private function materializedEconomyShadowRows(array $rows, bool $combined): array
    {
        $existing = [];
        $transactions = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                throw new RuntimeException('Materialized rollback economy shadow row is invalid.');
            }
            $type = $combined
                ? strtolower(trim((string)($row['entity_type'] ?? '')))
                : 'economy_user_balance';
            if ($type === 'economy_transaction' && $combined) {
                $transactions[] = $row;
                continue;
            }
            if ($type !== 'economy_user_balance') {
                throw new RuntimeException('Materialized rollback economy shadow type is unsupported.');
            }
            $key = trim((string)($row['entity_key'] ?? ''));
            if ($key === '' || isset($existing[$key])) {
                throw new RuntimeException('Materialized rollback economy shadow identity is invalid.');
            }
            $existing[$key] = $row;
        }

        $expectedKeys = array_keys($this->materializedEconomyBalanceRows);
        $actualKeys = array_keys($existing);
        sort($expectedKeys, SORT_STRING);
        sort($actualKeys, SORT_STRING);
        if ($expectedKeys !== $actualKeys) {
            throw new RuntimeException('Materialized rollback economy shadow ownership set changed.');
        }

        $materializedRows = [];
        foreach ($expectedKeys as $key) {
            $storedPayload = $this->verifiedStoredPayload($existing[$key]);
            $expectedRow = $this->materializedEconomyBalanceRows[$key];
            $expectedPayload = json_decode(
                $expectedRow['payload_json'],
                true,
                512,
                JSON_THROW_ON_ERROR
            );
            if (!is_array($expectedPayload)) {
                throw new RuntimeException('Materialized rollback economy payload is invalid.');
            }
            foreach (['legacy_user_id', 'telegram_id', 'balance_match', 'balance_gold'] as $field) {
                if (($storedPayload[$field] ?? null) !== ($expectedPayload[$field] ?? null)) {
                    throw new RuntimeException(
                        'Materialized rollback economy shadow economic identity changed: ' . $field . '.'
                    );
                }
            }
            $row = $existing[$key];
            $row['entity_key'] = $expectedRow['entity_key'];
            $row['payload_json'] = $expectedRow['payload_json'];
            $row['payload_sha256'] = $expectedRow['payload_sha256'];
            if ($combined) $row['entity_type'] = 'economy_user_balance';
            $materializedRows[] = $row;
        }

        $this->economyShadowReadVerified = true;
        $this->economyShadowReadCount++;
        return $combined ? array_merge($transactions, $materializedRows) : $materializedRows;
    }

    /**
     * @return array<string, array{entity_type:string,entity_key:string,payload_json:string,payload_sha256:string}>
     */
    private function economyBalanceRows(array $snapshot): array
    {
        $users = $snapshot['users'] ?? null;
        if (!is_array($users)) {
            throw new RuntimeException('Materialized rollback economy users are unavailable.');
        }
        $rows = [];
        foreach ($users as $sourceKey => $record) {
            if (!is_array($record)) {
                throw new RuntimeException('Materialized rollback economy user is invalid.');
            }
            $legacyUserId = $this->legacyUserId($sourceKey, $record);
            if (isset($rows[$legacyUserId])) {
                throw new RuntimeException('Materialized rollback economy user is duplicated.');
            }
            $payload = [
                'legacy_user_id' => $legacyUserId,
                'telegram_id' => $this->nullableText($record['telegram_id'] ?? null, 191),
                'balance_match' => $this->nonNegativeInteger(
                    $record['balance_match'] ?? 0,
                    'balance_match',
                    $legacyUserId
                ),
                'balance_gold' => $this->nonNegativeInteger(
                    $record['balance_gold'] ?? 0,
                    'balance_gold',
                    $legacyUserId
                ),
                'registered_at' => $this->nullableText($record['registered_at'] ?? null, 64),
                'last_seen_at' => $this->nullableText($record['last_seen_at'] ?? null, 64),
                'source_record_sha256' => hash('sha256', $this->canonicalJson($record)),
            ];
            $payloadJson = $this->canonicalJson($payload);
            $rows[$legacyUserId] = [
                'entity_type' => 'economy_user_balance',
                'entity_key' => $legacyUserId,
                'payload_json' => $payloadJson,
                'payload_sha256' => hash('sha256', $payloadJson),
            ];
        }
        ksort($rows, SORT_STRING);
        return $rows;
    }

    private function verifiedStoredPayload(array $row): array
    {
        $raw = (string)($row['payload_json'] ?? '');
        $storedSha = $this->exactSha($row['payload_sha256'] ?? null);
        if ($raw === '' || $storedSha === '' || !hash_equals($storedSha, hash('sha256', $raw))) {
            throw new RuntimeException('Materialized rollback economy shadow payload integrity failed.');
        }
        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $error) {
            throw new RuntimeException('Materialized rollback economy shadow payload is invalid.', 0, $error);
        }
        if (!is_array($decoded)
            || !hash_equals($storedSha, hash('sha256', $this->canonicalJson($decoded)))) {
            throw new RuntimeException('Materialized rollback economy shadow payload is not canonical.');
        }
        return $decoded;
    }

    private function isLockedStateRead(string $sql, array $params): bool
    {
        if ($params !== []) return false;
        $normalized = $this->normalizedSql($sql);
        $table = strtolower(RuntimePrimaryStateSchemaInstaller::TABLE);
        return str_contains($normalized, 'from ' . $table)
            && str_contains($normalized, 'where singleton_id = 1 for update')
            && str_contains($normalized, 'state_json')
            && str_contains($normalized, 'state_sha256');
    }

    private function isCombinedEconomyShadowRead(string $sql, array $params): bool
    {
        if ($params !== []) return false;
        $normalized = $this->normalizedSql($sql);
        return str_contains($normalized, 'from mgw_legacy_realtime_shadow')
            && str_contains(
                $normalized,
                "where entity_type in ('economy_user_balance', 'economy_transaction')"
            )
            && str_contains($normalized, 'entity_type')
            && str_contains($normalized, 'entity_key')
            && str_contains($normalized, 'payload_json')
            && str_contains($normalized, 'payload_sha256');
    }

    private function isEconomyBalanceShadowRead(string $sql, array $params): bool
    {
        if ($params !== []) return false;
        $normalized = $this->normalizedSql($sql);
        return str_contains($normalized, 'from mgw_legacy_realtime_shadow')
            && str_contains($normalized, "where entity_type = 'economy_user_balance'")
            && str_contains($normalized, 'entity_key')
            && str_contains($normalized, 'payload_json')
            && str_contains($normalized, 'payload_sha256');
    }

    private function normalizedSql(string $sql): string
    {
        return strtolower(preg_replace('/\s+/', ' ', trim($sql)) ?? '');
    }

    private function legacyUserId(int|string $sourceKey, array $record): string
    {
        foreach ([$record['id'] ?? null, $record['telegram_id'] ?? null, $sourceKey] as $candidate) {
            $candidate = trim((string)$candidate);
            if ($candidate === '') continue;
            return $this->safeKey($candidate, 191);
        }
        throw new RuntimeException('Materialized rollback economy user has no stable ID.');
    }

    private function safeKey(string $value, int $maxLength): string
    {
        if (strlen($value) <= $maxLength && preg_match('/[\x00-\x1F\x7F]/', $value) !== 1) {
            return $value;
        }
        return 'sha256:' . hash('sha256', $value);
    }

    private function nonNegativeInteger(mixed $value, string $field, string $userId): int
    {
        if (is_int($value)) $number = $value;
        elseif (is_string($value) && preg_match('/^-?\d+$/', trim($value)) === 1) $number = (int)$value;
        elseif (is_float($value) && floor($value) === $value) $number = (int)$value;
        else throw new RuntimeException('Invalid ' . $field . ' for rollback user ' . $userId . '.');
        if ($number < 0) {
            throw new RuntimeException('Negative ' . $field . ' for rollback user ' . $userId . '.');
        }
        return $number;
    }

    private function nullableText(mixed $value, int $maxLength): ?string
    {
        $value = trim((string)($value ?? ''));
        if ($value === '') return null;
        return function_exists('mb_substr') ? mb_substr($value, 0, $maxLength) : substr($value, 0, $maxLength);
    }

    private function exactSha(mixed $value): string
    {
        $value = strtolower(trim((string)$value));
        return preg_match('/\A[a-f0-9]{64}\z/', $value) === 1 ? $value : '';
    }

    private function canonicalJson(mixed $value): string
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
