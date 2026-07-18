<?php
declare(strict_types=1);

final class LegacyOpeningBalanceOwnershipReconciliationService
{
    private const META_KEY = 'legacy_economy_opening_import_v1';
    private const CATEGORY = 'legacy_opening_balance';
    private const SOURCE_TYPE = 'legacy_json';
    private const ASSETS = [
        'match_coin' => 'balance_match',
        'gold_coin' => 'balance_gold',
    ];

    public function __construct(private DatabaseConnectionInterface $database) {}

    public function preview(): array
    {
        $source = $this->loadSource();
        $meta = $this->loadMeta();
        $blocking = [];
        $conflicts = 0;
        $unchanged = 0;
        $items = [];
        $expectedScopes = [];
        $expectedOperationKeys = [];

        if ($source['items'] === []) {
            $blocking[] = 'No economy_user_balance shadow records were found.';
        }
        if ($meta === null) {
            $blocking[] = 'Opening import metadata is missing.';
        } else {
            if (($meta['status'] ?? '') !== 'completed') {
                $blocking[] = 'Opening balance import is not completed.';
            }
            if (!hash_equals((string)($meta['source_fingerprint'] ?? ''), $source['fingerprint'])) {
                $blocking[] = 'Opening import metadata belongs to a different source fingerprint.';
            }
        }

        foreach ($source['items'] as $item) {
            $scope = $item['account_ref'] . "\0" . $item['asset_code'];
            $expectedScopes[$scope] = true;
            if ($item['amount'] > 0) {
                $expectedOperationKeys[$item['operation_key']] = true;
            }

            $reasons = [];
            $balanceRows = $this->database->fetchAll(
                'SELECT account_ref, mgw_id, legacy_user_id, asset_code,
                        available_amount, reserved_amount
                 FROM mgw_balances
                 WHERE account_ref = :account_ref AND asset_code = :asset_code',
                [
                    'account_ref' => $item['account_ref'],
                    'asset_code' => $item['asset_code'],
                ]
            );
            if (count($balanceRows) !== 1) {
                $reasons[] = 'Expected exactly one balance row.';
            } elseif (!$this->balanceMatches($balanceRows[0], $item)) {
                $reasons[] = 'Existing balance does not match the source or ownership map.';
            }

            $entryRows = $this->database->fetchAll(
                'SELECT idempotency_key, account_ref, mgw_id, legacy_user_id, asset_code,
                        available_delta, reserved_delta, available_before, available_after,
                        reserved_before, reserved_after, category, source_type, source_ref
                 FROM mgw_ledger_entries
                 WHERE idempotency_key = :idempotency_key',
                ['idempotency_key' => $item['operation_key']]
            );
            if ($item['amount'] === 0) {
                if ($entryRows !== []) {
                    $reasons[] = 'Zero opening balance unexpectedly has a ledger entry.';
                }
            } elseif (count($entryRows) !== 1) {
                $reasons[] = 'Expected exactly one opening ledger entry.';
            } elseif (!$this->entryMatches($entryRows[0], $item)) {
                $reasons[] = 'Opening ledger entry does not match the source or ownership map.';
            }

            if ($reasons === []) {
                $unchanged++;
                $state = 'unchanged';
            } else {
                $conflicts++;
                $state = 'conflict';
                foreach ($reasons as $reason) {
                    $blocking[] = $item['legacy_user_id'] . '/' . $item['asset_code'] . ': ' . $reason;
                }
            }

            if (count($items) < 50) {
                $items[] = [
                    'legacy_user_id' => $item['legacy_user_id'],
                    'account_ref' => $item['account_ref'],
                    'asset_code' => $item['asset_code'],
                    'source_amount' => $item['amount'],
                    'state' => $state,
                    'reasons' => $reasons,
                ];
            }
        }

        $unmanagedBalanceCount = 0;
        foreach ($this->database->fetchAll('SELECT account_ref, asset_code FROM mgw_balances') as $row) {
            $scope = (string)($row['account_ref'] ?? '') . "\0" . (string)($row['asset_code'] ?? '');
            if (!isset($expectedScopes[$scope])) {
                $unmanagedBalanceCount++;
            }
        }
        if ($unmanagedBalanceCount > 0) {
            $blocking[] = 'Target contains balances outside the opening import plan.';
        }

        $unmanagedLedgerCount = 0;
        foreach ($this->database->fetchAll('SELECT idempotency_key FROM mgw_ledger_entries') as $row) {
            $key = (string)($row['idempotency_key'] ?? '');
            if (!isset($expectedOperationKeys[$key])) {
                $unmanagedLedgerCount++;
            }
        }
        if ($unmanagedLedgerCount > 0) {
            $blocking[] = 'Target contains ledger entries outside the opening import plan.';
        }

        return [
            'ok' => $blocking === [],
            'ready' => $blocking === [],
            'status' => $meta['status'] ?? 'not_started',
            'source_fingerprint' => $source['fingerprint'],
            'source_user_count' => $source['user_count'],
            'source_asset_count' => count($source['items']),
            'source_totals' => $source['totals'],
            'unchanged_count' => $unchanged,
            'conflict_count' => $conflicts,
            'unmanaged_balance_count' => $unmanagedBalanceCount,
            'unmanaged_ledger_count' => $unmanagedLedgerCount,
            'blocking_reasons' => array_values(array_unique($blocking)),
            'items' => $items,
        ];
    }

    private function loadSource(): array
    {
        $rows = $this->database->fetchAll(
            "SELECT entity_key, payload_json, payload_sha256
             FROM mgw_legacy_realtime_shadow
             WHERE entity_type = 'economy_user_balance'
             ORDER BY entity_key"
        );
        $identities = $this->identityMap();
        $ownerships = $this->ownershipMap();
        $items = [];
        $fingerprintParts = [];
        $totals = ['match_coin' => 0, 'gold_coin' => 0];

        foreach ($rows as $row) {
            $legacyUserId = trim((string)($row['entity_key'] ?? ''));
            if ($legacyUserId === '') {
                throw new RuntimeException('Economy shadow row has no entity key.');
            }
            $payloadJson = (string)($row['payload_json'] ?? '');
            try {
                $payload = json_decode($payloadJson, true, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                throw new RuntimeException('Economy shadow payload is invalid for legacy user ' . $legacyUserId . '.');
            }
            if (!is_array($payload)) {
                throw new RuntimeException('Economy shadow payload is not an object.');
            }

            $canonical = LedgerIntegrity::canonicalJson($payload);
            $actualHash = hash('sha256', $canonical);
            $storedHash = strtolower(trim((string)($row['payload_sha256'] ?? '')));
            if (preg_match('/^[a-f0-9]{64}$/', $storedHash) !== 1 || !hash_equals($storedHash, $actualHash)) {
                throw new RuntimeException('Economy shadow hash mismatch for legacy user ' . $legacyUserId . '.');
            }
            if (trim((string)($payload['legacy_user_id'] ?? '')) !== $legacyUserId) {
                throw new RuntimeException('Economy shadow legacy user ID mismatch for ' . $legacyUserId . '.');
            }

            $identityMgwId = $identities[$legacyUserId] ?? null;
            $ownership = $ownerships[$legacyUserId] ?? null;
            if (is_array($ownership)) {
                if ($identityMgwId !== null && $identityMgwId !== $ownership['mgw_id']) {
                    throw new RuntimeException(
                        'Legacy user identity and account ownership disagree for ' . $legacyUserId . '.'
                    );
                }
                $accountRef = $ownership['account_ref'];
                $acceptedMgwIds = str_starts_with($accountRef, 'legacy:')
                    ? [null, $ownership['mgw_id']]
                    : [$ownership['mgw_id']];
            } else {
                $accountRef = $identityMgwId === null
                    ? 'legacy:' . $legacyUserId
                    : 'mgw:' . $identityMgwId;
                $acceptedMgwIds = [$identityMgwId];
            }

            foreach (self::ASSETS as $assetCode => $field) {
                $amount = $this->nonNegativeInteger($payload[$field] ?? null, $field, $legacyUserId);
                $items[] = [
                    'legacy_user_id' => $legacyUserId,
                    'account_ref' => $accountRef,
                    'accepted_mgw_ids' => $acceptedMgwIds,
                    'asset_code' => $assetCode,
                    'amount' => $amount,
                    'operation_key' => 'legacy_opening:v1:'
                        . substr(hash('sha256', $legacyUserId . '|' . $assetCode), 0, 48),
                    'source_ref' => 'economy_user_balance:' . $this->safeSourceRef($legacyUserId),
                ];
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

    private function ownershipMap(): array
    {
        $map = [];
        foreach ($this->database->fetchAll(
            'SELECT account_ref, mgw_id, legacy_user_id, ownership_status
             FROM mgw_account_ownership'
        ) as $row) {
            $legacyUserId = trim((string)($row['legacy_user_id'] ?? ''));
            $accountRef = trim((string)($row['account_ref'] ?? ''));
            $mgwId = trim((string)($row['mgw_id'] ?? ''));
            $status = trim((string)($row['ownership_status'] ?? ''));
            if ($legacyUserId === '' || $accountRef === '' || $mgwId === '') {
                throw new RuntimeException('Account ownership row is incomplete.');
            }
            if ($status !== 'active') {
                throw new RuntimeException('Account ownership is not active for legacy user ' . $legacyUserId . '.');
            }
            if (isset($map[$legacyUserId])) {
                throw new RuntimeException('Legacy user has multiple account ownership rows: ' . $legacyUserId . '.');
            }
            $map[$legacyUserId] = [
                'account_ref' => $accountRef,
                'mgw_id' => $mgwId,
            ];
        }
        return $map;
    }

    private function balanceMatches(array $balance, array $item): bool
    {
        return (string)($balance['account_ref'] ?? '') === $item['account_ref']
            && (string)($balance['asset_code'] ?? '') === $item['asset_code']
            && in_array($this->nullable((string)($balance['mgw_id'] ?? '')), $item['accepted_mgw_ids'], true)
            && $this->nullable((string)($balance['legacy_user_id'] ?? '')) === $item['legacy_user_id']
            && (int)($balance['available_amount'] ?? -1) === $item['amount']
            && (int)($balance['reserved_amount'] ?? -1) === 0;
    }

    private function entryMatches(array $entry, array $item): bool
    {
        return (string)($entry['idempotency_key'] ?? '') === $item['operation_key']
            && (string)($entry['account_ref'] ?? '') === $item['account_ref']
            && in_array($this->nullable((string)($entry['mgw_id'] ?? '')), $item['accepted_mgw_ids'], true)
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
            'SELECT meta_value FROM mgw_meta WHERE meta_key = :meta_key',
            ['meta_key' => self::META_KEY]
        );
        if ($rows === []) return null;
        try {
            $value = json_decode((string)$rows[0]['meta_value'], true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new RuntimeException('Opening import metadata is invalid.');
        }
        if (!is_array($value)) {
            throw new RuntimeException('Opening import metadata is invalid.');
        }
        return [
            'status' => (string)($value['status'] ?? ''),
            'source_fingerprint' => (string)($value['source_fingerprint'] ?? ''),
        ];
    }

    private function nonNegativeInteger(mixed $value, string $field, string $legacyUserId): int
    {
        if (is_int($value)) $number = $value;
        elseif (is_string($value) && preg_match('/^\d+$/', trim($value)) === 1) $number = (int)$value;
        elseif (is_float($value) && floor($value) === $value && $value >= 0) $number = (int)$value;
        else throw new RuntimeException('Invalid ' . $field . ' for legacy user ' . $legacyUserId . '.');
        if ($number < 0) {
            throw new RuntimeException('Negative ' . $field . ' for legacy user ' . $legacyUserId . '.');
        }
        return $number;
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
}
