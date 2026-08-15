<?php
declare(strict_types=1);

/**
 * One-time coordinator for the MVP-15.3 legacy Match/Gold -> mgw_coin cutover.
 * A durable completed marker prevents future native accounts from being
 * reinterpreted as legacy conversion input.
 */
final class UnifiedBalanceMigrationCoordinator
{
    private const MARKER_PREFIX = 'unified_balance_cutover:';

    public function __construct(
        private DatabaseConnectionInterface $database,
        private UnifiedBalanceMigrationRule $rule,
        private LedgerWriteService $ledger,
        private LedgerIntegrityVerifier $integrity
    ) {}

    /** Read-only marker/integrity status. Never starts migration. */
    public function preview(): array
    {
        $rows = $this->markerRows();
        if ($rows === []) {
            return [
                'ok' => false,
                'read_only' => true,
                'completed' => false,
                'migration_version' => $this->rule->version(),
                'blockers' => ['Unified balance cutover marker is not completed.'],
                'sensitive_identifiers_exposed' => false,
            ];
        }
        if (count($rows) !== 1 || !is_array($rows[0])) {
            return [
                'ok' => false,
                'read_only' => true,
                'completed' => false,
                'migration_version' => $this->rule->version(),
                'blockers' => ['Unified balance migration marker is ambiguous.'],
                'sensitive_identifiers_exposed' => false,
            ];
        }

        try {
            $verified = $this->verifyCompletedMarker($rows[0]);
        } catch (Throwable $error) {
            return [
                'ok' => false,
                'read_only' => true,
                'completed' => false,
                'migration_version' => $this->rule->version(),
                'blockers' => [$error->getMessage()],
                'sensitive_identifiers_exposed' => false,
            ];
        }

        return [
            'ok' => true,
            'read_only' => true,
            'completed' => true,
            'migration_version' => $this->rule->version(),
            'rule_fingerprint' => $this->rule->fingerprint(),
            'verified_migration_account_count' => (int)($verified['verified_migration_account_count'] ?? 0),
            'blockers' => [],
            'sensitive_identifiers_exposed' => false,
        ];
    }

    public function ensureMigrated(): array
    {
        return $this->database->transaction(function (DatabaseConnectionInterface $db): array {
            $operationKey = $this->markerOperationKey();
            $rows = $this->markerRows($db);

            if ($rows !== []) {
                if (count($rows) !== 1 || !is_array($rows[0])) {
                    throw new RuntimeException('Unified balance migration marker is ambiguous.');
                }
                return $this->verifyCompletedMarker($rows[0]);
            }

            $executor = new UnifiedBalanceMigrationExecutor(
                $db,
                $this->rule,
                $this->ledger,
                $this->integrity
            );
            $result = $executor->run();
            $result['marker_operation_key'] = $operationKey;
            $result['replayed'] = false;

            $now = gmdate('Y-m-d H:i:s.u');
            $requestHash = $this->rule->fingerprint();
            $encoded = json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            $inserted = $db->execute(
                'INSERT INTO mgw_idempotency_keys (
                    operation_key, operation_type, owner_ref, request_sha256,
                    status, result_json, created_at_utc, updated_at_utc, expires_at_utc
                 ) VALUES (
                    :operation_key, :operation_type, NULL, :request_sha256,
                    :status, :result_json, :created_at_utc, :updated_at_utc, NULL
                 )',
                [
                    'operation_key' => $operationKey,
                    'operation_type' => 'unified_balance_cutover',
                    'request_sha256' => $requestHash,
                    'status' => 'completed',
                    'result_json' => $encoded,
                    'created_at_utc' => $now,
                    'updated_at_utc' => $now,
                ]
            );
            if ($inserted !== 1) {
                throw new RuntimeException('Unified balance migration marker was not created exactly once.');
            }

            return $result;
        });
    }

    private function markerRows(?DatabaseConnectionInterface $database = null): array
    {
        $database ??= $this->database;
        return $database->fetchAll(
            'SELECT operation_key, request_sha256, status, result_json
             FROM mgw_idempotency_keys WHERE operation_key = :operation_key',
            ['operation_key' => $this->markerOperationKey()]
        );
    }

    private function markerOperationKey(): string
    {
        return self::MARKER_PREFIX . $this->rule->version();
    }

    private function verifyCompletedMarker(array $row): array
    {
        if (!hash_equals($this->rule->fingerprint(), strtolower(trim((string)($row['request_sha256'] ?? ''))))) {
            throw new RuntimeException('Unified balance migration marker belongs to a different rule.');
        }
        if ((string)($row['status'] ?? '') !== 'completed') {
            throw new RuntimeException('Unified balance migration marker is not completed.');
        }

        $raw = $row['result_json'] ?? null;
        if (is_array($raw)) {
            $result = $raw;
        } else {
            try {
                $result = json_decode((string)$raw, true, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException $error) {
                throw new RuntimeException('Unified balance migration marker result is invalid.', 0, $error);
            }
        }
        if (!is_array($result)
            || (string)($result['migration_version'] ?? '') !== $this->rule->version()
            || !hash_equals($this->rule->fingerprint(), (string)($result['rule_fingerprint'] ?? ''))) {
            throw new RuntimeException('Unified balance migration marker result does not match the approved rule.');
        }

        $checked = 0;
        foreach ($this->database->fetchAll(
            "SELECT DISTINCT account_ref FROM mgw_ledger_entries
             WHERE asset_code = 'mgw_coin'
               AND category = 'unified_balance_migration'
               AND source_type = 'mvp15_3_unified_balance'
               AND source_ref = :source_ref",
            ['source_ref' => $this->rule->version()]
        ) as $ledgerRow) {
            $accountRef = trim((string)($ledgerRow['account_ref'] ?? ''));
            if ($accountRef === '') {
                throw new RuntimeException('Unified balance migration ledger contains an empty account reference.');
            }
            $integrity = $this->integrity->verifyAccountAsset($accountRef, $this->rule->targetAsset());
            if (($integrity['ok'] ?? false) !== true) {
                throw new RuntimeException('Unified balance ledger integrity failed after completed migration.');
            }
            $checked++;
        }

        $result['ok'] = true;
        $result['replayed'] = true;
        $result['verified_migration_account_count'] = $checked;
        $result['sensitive_identifiers_exposed'] = false;
        return $result;
    }
}
