<?php
declare(strict_types=1);

final class LegacyOpeningBalanceImportService
{
    private const META_KEY = 'legacy_economy_opening_import_v1';
    private const SOURCE_TYPE = 'legacy_json';
    private const CATEGORY = 'legacy_opening_balance';
    private const ASSETS = [
        'match_coin' => 'balance_match',
        'gold_coin' => 'balance_gold',
    ];

    public function __construct(
        private DatabaseConnectionInterface $database,
        private LedgerWriteService $ledger,
        private LedgerIntegrityVerifier $verifier
    ) {}

    public function preview(): array
    {
        return $this->inspect(false);
    }

    public function run(): array
    {
        $plan = $this->inspect(true);
        if (!$plan['ready']) {
            throw new RuntimeException('Legacy opening balance import is not ready: ' . implode('; ', $plan['blocking_reasons']));
        }

        $fingerprint = (string)$plan['source_fingerprint'];
        $this->writeMeta('started', $fingerprint, [
            'source_user_count' => $plan['source_user_count'],
            'source_asset_count' => $plan['source_asset_count'],
            'started_at_utc' => $this->now(),
        ]);

        $createdBalanceCount = 0;
        $createdLedgerCount = 0;
        $replayedLedgerCount = 0;

        foreach ($this->loadSource()['items'] as $item) {
            if ($item['amount'] === 0) {
                if ($this->ensureZeroBalance($item)) $createdBalanceCount++;
                continue;
            }

            $result = $this->ledger->postAvailableDelta([
                'operation_key' => $item['operation_key'],
                'account_ref' => $item['account_ref'],
                'mgw_id' => $item['mgw_id'],
                'legacy_user_id' => $item['legacy_user_id'],
                'asset_code' => $item['asset_code'],
                'available_delta' => $item['amount'],
                'category' => self::CATEGORY,
                'source_type' => self::SOURCE_TYPE,
                'source_ref' => $item['source_ref'],
                'metadata' => [
                    'legacy_user_id' => $item['legacy_user_id'],
                    'shadow_payload_sha256' => $item['payload_sha256'],
                    'source_fingerprint' => $fingerprint,
                    'source_balance_field' => $item['source_balance_field'],
                    'opening_import_version' => 1,
                ],
                'occurred_at_utc' => $item['occurred_at_utc'],
            ]);
            if (!empty($result['replayed'])) $replayedLedgerCount++;
            else $createdLedgerCount++;
        }

        $current = $this->loadSource();
        if (!hash_equals($fingerprint, $current['fingerprint'])) {
            throw new RuntimeException('Legacy economy shadow changed during opening balance import.');
        }

        $verification = $this->verifyImportedState($current['items']);
        if (!$verification['ok']) {
            throw new RuntimeException('Imported opening balances failed verification.');
        }

        $completedAt = $this->now();
        $this->writeMeta('completed', $fingerprint, [
            'source_user_count' => $current['user_count'],
            'source_asset_count' => count($current['items']),
            'created_balance_count' => $createdBalanceCount,
            'created_ledger_count' => $createdLedgerCount,
            'replayed_ledger_count' => $replayedLedgerCount,
            'completed_at_utc' => $completedAt,
            'source_totals' => $verification['source_totals'],
            'database_totals' => $verification['database_totals'],
        ]);

        return [
            'ok' => true,
            'dry_run' => false,
            'status' => 'completed',
            'source_fingerprint' => $fingerprint,
            'source_user_count' => $current['user_count'],
            'source_asset_count' => count($current['items']),
            'created_balance_count' => $createdBalanceCount,
            'created_ledger_count' => $createdLedgerCount,
            'replayed_ledger_count' => $replayedLedgerCount,
            'verification' => $verification,
            'completed_at_utc' => $completedAt,
        ];
    }

    private function inspect(bool $forRun): array
    {
        $source = $this->loadSource();
        $meta = $this->loadMeta();
        $blocking = [];
        $items = [];
        $plannedBalanceCreates = 0;
        $plannedLedgerCreates = 0;
        $unchangedCount = 0;
        $conflictCount = 0;

        if ($source['items'] === []) {
            $blocking[] = 'No economy_user_balance shadow records were found.';
        }
        if ($meta !== null && !hash_equals((string)$meta['source_fingerprint'], $source['fingerprint'])) {
            $blocking[] = 'Opening import metadata belongs to a different source fingerprint.';
        }

        $expectedScopes = [];
        $expectedOperationKeys = [];
        foreach ($source['items'] as $item) {
            $scope = $item['account_ref'] . "\0" . $item['asset_code'];
            $expectedScopes[$scope] = true;
            if ($item['amount'] > 0) $expectedOperationKeys[$item['operation_key']] = true;

            $balanceRows = $this->database->fetchAll(
                'SELECT account_ref, mgw_id, legacy_user_id, asset_code,
                        available_amount, reserved_amount, version
                 FROM mgw_balances
                 WHERE account_ref = :account_ref AND asset_code = :asset_code',
                ['account_ref' => $item['account_ref'], 'asset_code' => $item['asset_code']]
            );
            $entryRows = $this->database->fetchAll(
                'SELECT idempotency_key, account_ref, legacy_user_id, asset_code,
                        available_delta, reserved_delta, available_before, available_after,
                        reserved_before, reserved_after, category, source_type, source_ref
                 FROM mgw_ledger_entries WHERE idempotency_key = :idempotency_key',
                ['idempotency_key' => $item['operation_key']]
            );

            $state = 'pending';
            $reason = null;
            if ($item['amount'] === 0) {
                if ($entryRows !== []) {
                    $state = 'conflict';
                    $reason = 'Zero opening balance unexpectedly has a ledger entry.';
                } elseif ($balanceRows === []) {
                    $state = 'create_zero_balance';
                    $plannedBalanceCreates++;
                } elseif ($this->balanceMatches($balanceRows[0], $item)) {
                    $state = 'unchanged';
                    $unchangedCount++;
                } else {
                    $state = 'conflict';
                    $reason = 'Existing zero-balance target does not match the source.';
                }
            } else {
                if ($balanceRows === [] && $entryRows === []) {
                    $state = 'create_opening_entry';
                    $plannedBalanceCreates++;
                    $plannedLedgerCreates++;
                } elseif ($balanceRows !== [] && $entryRows !== []
                    && $this->balanceMatches($balanceRows[0], $item)
                    && $this->entryMatches($entryRows[0], $item)) {
                    $state = 'unchanged';
                    $unchangedCount++;
                } else {
                    $state = 'conflict';
                    $reason = 'Existing balance or opening ledger entry does not match the source.';
                }
            }

            if ($state === 'conflict') {
                $conflictCount++;
                $blocking[] = $item['legacy_user_id'] . '/' . $item['asset_code'] . ': ' . $reason;
            }

            if (count($items) < 50) {
                $items[] = [
                    'legacy_user_id' => $item['legacy_user_id'],
                    'account_ref' => $item['account_ref'],
                    'asset_code' => $item['asset_code'],
                    'source_amount' => $item['amount'],
                    'state' => $state,
                    'reason' => $reason,
                ];
            }
        }

        $unmanagedBalanceCount = 0;
        foreach ($this->database->fetchAll('SELECT account_ref, asset_code FROM mgw_balances') as $row) {
            $scope = (string)$row['account_ref'] . "\0" . (string)$row['asset_code'];
            if (!isset($expectedScopes[$scope])) $unmanagedBalanceCount++;
        }
        $unmanagedLedgerCount = 0;
        foreach ($this->database->fetchAll('SELECT idempotency_key FROM mgw_ledger_entries') as $row) {
            if (!isset($expectedOperationKeys[(string)$row['idempotency_key']])) $unmanagedLedgerCount++;
        }
        if ($unmanagedBalanceCount > 0) {
            $blocking[] = 'Target contains balances outside the opening import plan.';
        }
        if ($unmanagedLedgerCount > 0) {
            $blocking[] = 'Target contains ledger entries outside the opening import plan.';
        }

        $status = $meta['status'] ?? 'not_started';
        if ($status === 'completed' && $blocking === [] && ($plannedBalanceCreates > 0 || $plannedLedgerCreates > 0)) {
            $blocking[] = 'Completed opening import is missing expected target rows.';
        }
        if ($forRun && $status === 'completed' && $blocking === []) {
            // Re-running the same completed import is allowed and must be a no-op/replay.
        }

        return [
            'ok' => $blocking === [],
            'dry_run' => true,
            'ready' => $blocking === [],
            'status' => $status,
            'source_fingerprint' => $source['fingerprint'],
            'source_user_count' => $source['user_count'],
            'source_asset_count' => count($source['items']),
            'source_totals' => $source['totals'],
            'planned_balance_create_count' => $plannedBalanceCreates,
            'planned_ledger_create_count' => $plannedLedgerCreates,
            'unchanged_count' => $unchangedCount,
            'conflict_count' => $conflictCount,
            'unmanaged_balance_count' => $unmanagedBalanceCount,
            'unmanaged_ledger_count' => $unmanagedLedgerCount,
            'blocking_reasons' => array_values(array_unique($blocking)),
            'items' => $items,
            'meta' => $meta,
        ];
    }

    private function loadSource(): array
    {
        $rows = $this->database->fetchAll(
            "SELECT entity_key, payload_json, payload_sha256, source_updated_at_utc, synced_at_utc
             FROM mgw_legacy_realtime_shadow
             WHERE entity_type = 'economy_user_balance'
             ORDER BY entity_key"
        );
        $identityMap = $this->identityMap();
        $items = [];
        $fingerprintParts = [];
        $totals = ['match_coin' => 0, 'gold_coin' => 0];

        foreach ($rows as $row) {
            $legacyUserId = trim((string)($row['entity_key'] ?? ''));
            if ($legacyUserId === '') throw new RuntimeException('Economy shadow row has no entity key.');
            $payloadJson = (string)($row['payload_json'] ?? '');
            try {
                $payload = json_decode($payloadJson, true, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                throw new RuntimeException('Economy shadow payload is invalid for legacy user ' . $legacyUserId . '.');
            }
            if (!is_array($payload)) throw new RuntimeException('Economy shadow payload is not an object.');
            $canonical = LedgerIntegrity::canonicalJson($payload);
            $actualHash = hash('sha256', $canonical);
            $storedHash = strtolower(trim((string)($row['payload_sha256'] ?? '')));
            if (preg_match('/^[a-f0-9]{64}$/', $storedHash) !== 1 || !hash_equals($storedHash, $actualHash)) {
                throw new RuntimeException('Economy shadow hash mismatch for legacy user ' . $legacyUserId . '.');
            }
            if (trim((string)($payload['legacy_user_id'] ?? '')) !== $legacyUserId) {
                throw new RuntimeException('Economy shadow legacy user ID mismatch for ' . $legacyUserId . '.');
            }

            $mgwId = $identityMap[$legacyUserId] ?? null;
            $accountRef = $mgwId === null ? 'legacy:' . $legacyUserId : 'mgw:' . $mgwId;
            $occurredAt = $this->stableTimestamp(
                $payload['registered_at'] ?? null,
                $row['source_updated_at_utc'] ?? null,
                $row['synced_at_utc'] ?? null
            );

            foreach (self::ASSETS as $assetCode => $field) {
                $amount = $this->nonNegativeInteger($payload[$field] ?? null, $field, $legacyUserId);
                $operationKey = 'legacy_opening:v1:' . substr(hash('sha256', $legacyUserId . '|' . $assetCode), 0, 48);
                $item = [
                    'legacy_user_id' => $legacyUserId,
                    'mgw_id' => $mgwId,
                    'account_ref' => $accountRef,
                    'asset_code' => $assetCode,
                    'source_balance_field' => $field,
                    'amount' => $amount,
                    'payload_sha256' => $storedHash,
                    'operation_key' => $operationKey,
                    'source_ref' => 'economy_user_balance:' . $this->safeSourceRef($legacyUserId),
                    'occurred_at_utc' => $occurredAt,
                ];
                $items[] = $item;
                $totals[$assetCode] += $amount;
                $fingerprintParts[] = $legacyUserId . "\0" . $assetCode . "\0" . $amount . "\0" . $storedHash;
            }
        }

        sort($fingerprintParts, SORT_STRING);
        return [
            'fingerprint' => hash('sha256', implode("\n", $fingerprintParts)),
            'user_count' => count($rows),
            'items' => $items,
            'totals' => $totals,
        ];
    }

    private function identityMap(): array
    {
        $map = [];
        foreach ($this->database->fetchAll(
            "SELECT mgw_id, provider_subject FROM mgw_identities
             WHERE provider IN ('telegram', 'development')"
        ) as $row) {
            $subject = trim((string)($row['provider_subject'] ?? ''));
            $mgwId = trim((string)($row['mgw_id'] ?? ''));
            if ($subject === '' || $mgwId === '') continue;
            if (isset($map[$subject]) && $map[$subject] !== $mgwId) {
                throw new RuntimeException('Legacy user identity maps to multiple MGW IDs: ' . $subject . '.');
            }
            $map[$subject] = $mgwId;
        }
        return $map;
    }

    private function ensureZeroBalance(array $item): bool
    {
        return $this->database->transaction(function (DatabaseConnectionInterface $db) use ($item): bool {
            $parameters = [
                'account_ref' => $item['account_ref'],
                'mgw_id' => $item['mgw_id'],
                'legacy_user_id' => $item['legacy_user_id'],
                'asset_code' => $item['asset_code'],
                'created_at_utc' => $item['occurred_at_utc'],
                'updated_at_utc' => $item['occurred_at_utc'],
            ];
            $inserted = $db->driver() === 'sqlite'
                ? $db->execute(
                    'INSERT INTO mgw_balances (
                        account_ref, mgw_id, legacy_user_id, asset_code,
                        available_amount, reserved_amount, version, created_at_utc, updated_at_utc
                     ) VALUES (
                        :account_ref, :mgw_id, :legacy_user_id, :asset_code,
                        0, 0, 0, :created_at_utc, :updated_at_utc
                     ) ON CONFLICT(account_ref, asset_code) DO NOTHING',
                    $parameters
                )
                : $db->execute(
                    'INSERT IGNORE INTO mgw_balances (
                        account_ref, mgw_id, legacy_user_id, asset_code,
                        available_amount, reserved_amount, version, created_at_utc, updated_at_utc
                     ) VALUES (
                        :account_ref, :mgw_id, :legacy_user_id, :asset_code,
                        0, 0, 0, :created_at_utc, :updated_at_utc
                     )',
                    $parameters
                );

            $rows = $db->fetchAll(
                'SELECT account_ref, mgw_id, legacy_user_id, asset_code,
                        available_amount, reserved_amount
                 FROM mgw_balances
                 WHERE account_ref = :account_ref AND asset_code = :asset_code',
                ['account_ref' => $item['account_ref'], 'asset_code' => $item['asset_code']]
            );
            if ($rows === [] || !$this->balanceMatches($rows[0], $item)) {
                throw new RuntimeException('Zero opening balance could not be initialized safely.');
            }
            return $inserted === 1;
        });
    }

    private function verifyImportedState(array $items): array
    {
        $errors = [];
        $sourceTotals = ['match_coin' => 0, 'gold_coin' => 0];
        $databaseTotals = ['match_coin' => 0, 'gold_coin' => 0];
        $verifiedChains = 0;

        foreach ($items as $item) {
            $sourceTotals[$item['asset_code']] += $item['amount'];
            $rows = $this->database->fetchAll(
                'SELECT available_amount, reserved_amount FROM mgw_balances
                 WHERE account_ref = :account_ref AND asset_code = :asset_code',
                ['account_ref' => $item['account_ref'], 'asset_code' => $item['asset_code']]
            );
            if ($rows === []) {
                $errors[] = $item['legacy_user_id'] . '/' . $item['asset_code'] . ': balance missing';
                continue;
            }
            $available = (int)$rows[0]['available_amount'];
            $reserved = (int)$rows[0]['reserved_amount'];
            $databaseTotals[$item['asset_code']] += $available;
            if ($available !== $item['amount'] || $reserved !== 0) {
                $errors[] = $item['legacy_user_id'] . '/' . $item['asset_code'] . ': balance mismatch';
            }

            $chain = $this->verifier->verifyAccountAsset($item['account_ref'], $item['asset_code']);
            $verifiedChains++;
            if (!$chain['ok']) {
                $errors[] = $item['legacy_user_id'] . '/' . $item['asset_code'] . ': ledger integrity failed';
            }
        }

        if ($sourceTotals !== $databaseTotals) $errors[] = 'Source and database totals do not match.';
        return [
            'ok' => $errors === [],
            'verified_chain_count' => $verifiedChains,
            'source_totals' => $sourceTotals,
            'database_totals' => $databaseTotals,
            'error_count' => count($errors),
            'errors' => array_slice($errors, 0, 50),
        ];
    }

    private function balanceMatches(array $balance, array $item): bool
    {
        return (string)($balance['account_ref'] ?? '') === $item['account_ref']
            && (string)($balance['asset_code'] ?? '') === $item['asset_code']
            && $this->nullable((string)($balance['mgw_id'] ?? '')) === $item['mgw_id']
            && $this->nullable((string)($balance['legacy_user_id'] ?? '')) === $item['legacy_user_id']
            && (int)($balance['available_amount'] ?? -1) === $item['amount']
            && (int)($balance['reserved_amount'] ?? -1) === 0;
    }

    private function entryMatches(array $entry, array $item): bool
    {
        return (string)($entry['idempotency_key'] ?? '') === $item['operation_key']
            && (string)($entry['account_ref'] ?? '') === $item['account_ref']
            && $this->nullable((string)($entry['legacy_user_id'] ?? '')) === $item['legacy_user_id']
            && (string)($entry['asset_code'] ?? '') === $item['asset_code']
            && (int)($entry['available_delta'] ?? 0) === $item['amount']
            && (int)($entry['reserved_delta'] ?? 0) === 0
            && (int)($entry['available_before'] ?? -1) === 0
            && (int)($entry['available_after'] ?? -1) === $item['amount']
            && (int)($entry['reserved_before'] ?? -1) === 0
            && (int)($entry['reserved_after'] ?? -1) === 0
            && (string)($entry['category'] ?? '') === self::CATEGORY
            && (string)($entry['source_type'] ?? '') === self::SOURCE_TYPE
            && (string)($entry['source_ref'] ?? '') === $item['source_ref'];
    }

    private function loadMeta(): ?array
    {
        $rows = $this->database->fetchAll(
            'SELECT meta_value, updated_at_utc FROM mgw_meta WHERE meta_key = :meta_key',
            ['meta_key' => self::META_KEY]
        );
        if ($rows === []) return null;
        try {
            $value = json_decode((string)$rows[0]['meta_value'], true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new RuntimeException('Opening import metadata is invalid.');
        }
        if (!is_array($value)) throw new RuntimeException('Opening import metadata is invalid.');
        return [
            'status' => (string)($value['status'] ?? ''),
            'source_fingerprint' => (string)($value['source_fingerprint'] ?? ''),
            'details' => is_array($value['details'] ?? null) ? $value['details'] : [],
            'updated_at_utc' => (string)$rows[0]['updated_at_utc'],
        ];
    }

    private function writeMeta(string $status, string $fingerprint, array $details): void
    {
        $updatedAt = $this->now();
        $value = LedgerIntegrity::canonicalJson([
            'status' => $status,
            'source_fingerprint' => $fingerprint,
            'details' => $details,
        ]);
        $updated = $this->database->execute(
            'UPDATE mgw_meta SET meta_value = :meta_value, updated_at_utc = :updated_at_utc
             WHERE meta_key = :meta_key',
            ['meta_value' => $value, 'updated_at_utc' => $updatedAt, 'meta_key' => self::META_KEY]
        );
        if ($updated === 0) {
            try {
                $this->database->execute(
                    'INSERT INTO mgw_meta (meta_key, meta_value, updated_at_utc)
                     VALUES (:meta_key, :meta_value, :updated_at_utc)',
                    ['meta_key' => self::META_KEY, 'meta_value' => $value, 'updated_at_utc' => $updatedAt]
                );
            } catch (Throwable) {
                $this->database->execute(
                    'UPDATE mgw_meta SET meta_value = :meta_value, updated_at_utc = :updated_at_utc
                     WHERE meta_key = :meta_key',
                    ['meta_value' => $value, 'updated_at_utc' => $updatedAt, 'meta_key' => self::META_KEY]
                );
            }
        }
    }

    private function nonNegativeInteger(mixed $value, string $field, string $legacyUserId): int
    {
        if (is_int($value)) $number = $value;
        elseif (is_string($value) && preg_match('/^\d+$/', trim($value)) === 1) $number = (int)$value;
        elseif (is_float($value) && floor($value) === $value && $value >= 0) $number = (int)$value;
        else throw new RuntimeException('Invalid ' . $field . ' for legacy user ' . $legacyUserId . '.');
        if ($number < 0) throw new RuntimeException('Negative ' . $field . ' for legacy user ' . $legacyUserId . '.');
        return $number;
    }

    private function stableTimestamp(mixed ...$values): string
    {
        foreach ($values as $value) {
            $value = trim((string)($value ?? ''));
            if ($value === '') continue;
            try {
                return (new DateTimeImmutable($value))
                    ->setTimezone(new DateTimeZone('UTC'))
                    ->format('Y-m-d H:i:s.u');
            } catch (Throwable) {
                continue;
            }
        }
        throw new RuntimeException('Economy shadow row has no valid stable timestamp.');
    }

    private function safeSourceRef(string $legacyUserId): string
    {
        if (strlen($legacyUserId) <= 160 && preg_match('/[\x00-\x1F\x7F]/', $legacyUserId) !== 1) {
            return $legacyUserId;
        }
        return 'sha256:' . hash('sha256', $legacyUserId);
    }

    private function nullable(string $value): ?string
    {
        $value = trim($value);
        return $value === '' ? null : $value;
    }

    private function now(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');
    }
}
