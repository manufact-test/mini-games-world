<?php
declare(strict_types=1);

final class LegacyOpeningBalanceResidualRecoveryService
{
    private const OPENING_META_KEY = 'legacy_economy_opening_import_v1';
    private const ACCOUNT_META_KEY = 'legacy_account_import_v1';
    private const OWNERSHIP_META_KEY = 'legacy_account_ownership_link_v1';
    private const CATEGORY = 'legacy_opening_balance';
    private const SOURCE_TYPE = 'legacy_json';
    private const ASSETS = [
        'gold_coin' => 'balance_gold',
        'match_coin' => 'balance_match',
    ];

    public function __construct(
        private StorageAdapterInterface $storage,
        private DatabaseConnectionInterface $database,
        private LedgerIntegrityVerifier $verifier
    ) {}

    public function preview(string $legacyUserId, string $expectedMgwId): array
    {
        $plan = $this->inspect($this->database, $legacyUserId, $expectedMgwId, false);
        return $plan + [
            'dry_run' => true,
            'database_write_executed' => false,
        ];
    }

    public function run(
        string $legacyUserId,
        string $expectedMgwId,
        string $expectedPlanFingerprint
    ): array {
        $expectedPlanFingerprint = strtolower(trim($expectedPlanFingerprint));
        if (preg_match('/\A[a-f0-9]{64}\z/', $expectedPlanFingerprint) !== 1) {
            throw new RuntimeException('Opening residual recovery plan fingerprint is invalid.');
        }

        $preview = $this->inspect($this->database, $legacyUserId, $expectedMgwId, false);
        $this->assertExecutablePlan($preview, $expectedPlanFingerprint, 'current');

        $deleted = $this->database->transaction(function (DatabaseConnectionInterface $database) use (
            $legacyUserId,
            $expectedMgwId,
            $expectedPlanFingerprint
        ): array {
            $locked = $this->inspect($database, $legacyUserId, $expectedMgwId, true);
            $this->assertExecutablePlan($locked, $expectedPlanFingerprint, 'locked');

            $mgwRef = (string)$locked['mgw_account_ref'];
            $operationKeys = array_values($locked['operation_keys']);
            $deletedIdempotency = 0;
            foreach ($operationKeys as $operationKey) {
                $deletedIdempotency += $database->execute(
                    'DELETE FROM mgw_idempotency_keys
                     WHERE operation_key = :operation_key
                       AND operation_type = :operation_type
                       AND owner_ref = :owner_ref
                       AND status = :status',
                    [
                        'operation_key' => $operationKey,
                        'operation_type' => 'available_delta',
                        'owner_ref' => $mgwRef,
                        'status' => 'completed',
                    ]
                );
            }
            if ($deletedIdempotency !== count($operationKeys)) {
                throw new RuntimeException('Opening residual recovery idempotency delete count is unexpected.');
            }

            $deletedLedger = $database->execute(
                'DELETE FROM mgw_ledger_entries
                 WHERE account_ref = :account_ref
                   AND mgw_id = :mgw_id
                   AND legacy_user_id = :legacy_user_id
                   AND category = :category
                   AND source_type = :source_type',
                [
                    'account_ref' => $mgwRef,
                    'mgw_id' => $expectedMgwId,
                    'legacy_user_id' => $legacyUserId,
                    'category' => self::CATEGORY,
                    'source_type' => self::SOURCE_TYPE,
                ]
            );
            if ($deletedLedger !== count($operationKeys)) {
                throw new RuntimeException('Opening residual recovery ledger delete count is unexpected.');
            }

            $deletedBalances = $database->execute(
                "DELETE FROM mgw_balances
                 WHERE account_ref = :account_ref
                   AND mgw_id = :mgw_id
                   AND legacy_user_id = :legacy_user_id
                   AND asset_code IN ('gold_coin', 'match_coin')",
                [
                    'account_ref' => $mgwRef,
                    'mgw_id' => $expectedMgwId,
                    'legacy_user_id' => $legacyUserId,
                ]
            );
            if ($deletedBalances !== 2) {
                throw new RuntimeException('Opening residual recovery balance delete count is unexpected.');
            }

            $deletedMeta = $database->execute(
                'DELETE FROM mgw_meta WHERE meta_key = :meta_key',
                ['meta_key' => self::OPENING_META_KEY]
            );
            if ($deletedMeta !== 1) {
                throw new RuntimeException('Opening residual recovery metadata delete count is unexpected.');
            }

            foreach ([
                'balances' => [
                    'SELECT COUNT(*) FROM mgw_balances
                     WHERE account_ref = :account_ref OR legacy_user_id = :legacy_user_id',
                    ['account_ref' => $mgwRef, 'legacy_user_id' => $legacyUserId],
                ],
                'ledger' => [
                    'SELECT COUNT(*) FROM mgw_ledger_entries
                     WHERE account_ref = :account_ref OR legacy_user_id = :legacy_user_id',
                    ['account_ref' => $mgwRef, 'legacy_user_id' => $legacyUserId],
                ],
                'idempotency' => [
                    'SELECT COUNT(*) FROM mgw_idempotency_keys WHERE owner_ref = :owner_ref',
                    ['owner_ref' => $mgwRef],
                ],
                'opening metadata' => [
                    'SELECT COUNT(*) FROM mgw_meta WHERE meta_key = :meta_key',
                    ['meta_key' => self::OPENING_META_KEY],
                ],
            ] as $label => [$sql, $parameters]) {
                if ((int)$database->fetchValue($sql, $parameters) !== 0) {
                    throw new RuntimeException('Opening residual recovery target ' . $label . ' remain after deletion.');
                }
            }

            return [
                'balance_rows_deleted' => $deletedBalances,
                'ledger_rows_deleted' => $deletedLedger,
                'idempotency_rows_deleted' => $deletedIdempotency,
                'opening_meta_rows_deleted' => $deletedMeta,
            ];
        });

        return [
            'ok' => true,
            'dry_run' => false,
            'status' => 'completed',
            'legacy_user_id' => $legacyUserId,
            'mgw_id' => $expectedMgwId,
            'plan_fingerprint' => $expectedPlanFingerprint,
            'deleted' => $deleted,
            'preserved' => [
                'mgw_user' => true,
                'identities' => true,
                'account_import_meta' => true,
                'json_source' => true,
                'shadow_source' => true,
            ],
            'database_write_executed' => true,
        ];
    }

    private function inspect(
        DatabaseConnectionInterface $database,
        string $legacyUserId,
        string $expectedMgwId,
        bool $forUpdate
    ): array {
        $legacyUserId = trim($legacyUserId);
        $expectedMgwId = trim($expectedMgwId);
        if ($legacyUserId === ''
            || strlen($legacyUserId) > 191
            || preg_match('/[\x00-\x1F\x7F]/', $legacyUserId) === 1) {
            throw new RuntimeException('Opening residual recovery legacy user ID is invalid.');
        }
        if (!MgwIdGenerator::isValid($expectedMgwId)) {
            throw new RuntimeException('Opening residual recovery MGW ID is invalid.');
        }

        $blocking = [];
        $lock = $forUpdate && $database->driver() !== 'sqlite' ? ' FOR UPDATE' : '';
        $mgwRef = 'mgw:' . $expectedMgwId;
        $legacyRef = 'legacy:' . $legacyUserId;
        $sourceRecord = $this->sourceRecord($legacyUserId);
        $sourceRecordHash = hash('sha256', LedgerIntegrity::canonicalJson($sourceRecord));
        $sourceBalances = [];
        foreach (self::ASSETS as $asset => $field) {
            $sourceBalances[$asset] = $this->nonNegativeInteger(
                $sourceRecord[$field] ?? 0,
                $field,
                $legacyUserId
            );
        }
        ksort($sourceBalances, SORT_STRING);

        $identityRows = $database->fetchAll(
            'SELECT mgw_id FROM mgw_identities
             WHERE provider = :provider AND provider_subject = :provider_subject' . $lock,
            ['provider' => 'legacy_import', 'provider_subject' => $legacyUserId]
        );
        if (count($identityRows) !== 1
            || !hash_equals($expectedMgwId, trim((string)($identityRows[0]['mgw_id'] ?? '')))) {
            $blocking[] = 'Internal legacy_import identity does not match the approved MGW ID.';
        }
        if ((int)$database->fetchValue(
            'SELECT COUNT(*) FROM mgw_users WHERE mgw_id = :mgw_id',
            ['mgw_id' => $expectedMgwId]
        ) !== 1) {
            $blocking[] = 'Approved MGW user row is missing or ambiguous.';
        }

        [$shadowPayload, $shadowHash] = $this->shadow(
            $database,
            $legacyUserId,
            $sourceRecordHash,
            $sourceBalances,
            $lock,
            $blocking
        );

        $balanceRows = $database->fetchAll(
            'SELECT account_ref, mgw_id, legacy_user_id, asset_code,
                    available_amount, reserved_amount, version, created_at_utc, updated_at_utc
             FROM mgw_balances
             WHERE account_ref IN (:mgw_ref, :legacy_ref)
                OR legacy_user_id = :legacy_user_id
             ORDER BY account_ref, asset_code' . $lock,
            [
                'mgw_ref' => $mgwRef,
                'legacy_ref' => $legacyRef,
                'legacy_user_id' => $legacyUserId,
            ]
        );
        $balanceAssets = [];
        if (count($balanceRows) !== 2) {
            $blocking[] = 'Recovery target must contain exactly two balance rows.';
        }
        foreach ($balanceRows as $row) {
            $asset = (string)($row['asset_code'] ?? '');
            if (!isset($sourceBalances[$asset])
                || isset($balanceAssets[$asset])
                || !hash_equals($mgwRef, (string)($row['account_ref'] ?? ''))
                || !hash_equals($expectedMgwId, (string)($row['mgw_id'] ?? ''))
                || !hash_equals($legacyUserId, (string)($row['legacy_user_id'] ?? ''))
                || (int)($row['available_amount'] ?? -1) !== $sourceBalances[$asset]
                || (int)($row['reserved_amount'] ?? -1) !== 0
                || (int)($row['version'] ?? -1) !== 1) {
                $blocking[] = 'Recovery balance row does not exactly match the legacy opening import.';
                continue;
            }
            $balanceAssets[$asset] = true;
        }
        if (!$this->sameStringSet(array_keys($balanceAssets), array_keys($sourceBalances))) {
            $blocking[] = 'Recovery target does not contain one separate Gold and Match balance.';
        }

        $operationKeys = [];
        foreach ($sourceBalances as $asset => $amount) {
            if ($amount > 0) {
                $operationKeys[$asset] = 'legacy_opening:v1:'
                    . substr(hash('sha256', $legacyUserId . '|' . $asset), 0, 48);
            }
        }
        ksort($operationKeys, SORT_STRING);

        $ledgerRows = $database->fetchAll(
            'SELECT ledger_sequence, entry_id, idempotency_key, account_ref, mgw_id,
                    legacy_user_id, asset_code, available_delta, reserved_delta,
                    available_before, available_after, reserved_before, reserved_after,
                    category, source_type, source_ref, reservation_id, metadata_json,
                    previous_entry_sha256, entry_sha256, created_at_utc
             FROM mgw_ledger_entries
             WHERE account_ref IN (:mgw_ref, :legacy_ref)
                OR legacy_user_id = :legacy_user_id
             ORDER BY asset_code, ledger_sequence' . $lock,
            [
                'mgw_ref' => $mgwRef,
                'legacy_ref' => $legacyRef,
                'legacy_user_id' => $legacyUserId,
            ]
        );
        $ledgerAssets = [];
        if (count($ledgerRows) !== count($operationKeys)) {
            $blocking[] = 'Recovery target ledger row count does not match positive opening balances.';
        }
        foreach ($ledgerRows as $row) {
            $asset = (string)($row['asset_code'] ?? '');
            $operationKey = $operationKeys[$asset] ?? '';
            $entryHash = strtolower(trim((string)($row['entry_sha256'] ?? '')));
            if ($operationKey === ''
                || isset($ledgerAssets[$asset])
                || !hash_equals($operationKey, (string)($row['idempotency_key'] ?? ''))
                || !hash_equals($mgwRef, (string)($row['account_ref'] ?? ''))
                || !hash_equals($expectedMgwId, (string)($row['mgw_id'] ?? ''))
                || !hash_equals($legacyUserId, (string)($row['legacy_user_id'] ?? ''))
                || (int)($row['available_delta'] ?? 0) !== $sourceBalances[$asset]
                || (int)($row['reserved_delta'] ?? -1) !== 0
                || (int)($row['available_before'] ?? -1) !== 0
                || (int)($row['available_after'] ?? -1) !== $sourceBalances[$asset]
                || (int)($row['reserved_before'] ?? -1) !== 0
                || (int)($row['reserved_after'] ?? -1) !== 0
                || !hash_equals(self::CATEGORY, (string)($row['category'] ?? ''))
                || !hash_equals(self::SOURCE_TYPE, (string)($row['source_type'] ?? ''))
                || !hash_equals(
                    'economy_user_balance:' . $this->safeSourceRef($legacyUserId),
                    (string)($row['source_ref'] ?? '')
                )
                || ($row['reservation_id'] ?? null) !== null
                || preg_match('/\A[a-f0-9]{64}\z/', $entryHash) !== 1) {
                $blocking[] = 'Recovery ledger row does not exactly match the opening import contract.';
                continue;
            }
            $metadata = json_decode((string)($row['metadata_json'] ?? ''), true);
            if (!is_array($metadata)
                || !hash_equals($legacyUserId, (string)($metadata['legacy_user_id'] ?? ''))
                || (int)($metadata['opening_import_version'] ?? 0) !== 1
                || !hash_equals($shadowHash, (string)($metadata['shadow_payload_sha256'] ?? ''))
                || !hash_equals(self::ASSETS[$asset], (string)($metadata['source_balance_field'] ?? ''))) {
                $blocking[] = 'Recovery ledger metadata does not match the verified source.';
            }
            if (empty($this->verifier->verifyAccountAsset($mgwRef, $asset)['ok'])) {
                $blocking[] = 'Recovery ledger integrity verification failed for ' . $asset . '.';
            }
            $ledgerAssets[$asset] = true;
        }
        if (!$this->sameStringSet(array_keys($ledgerAssets), array_keys($operationKeys))) {
            $blocking[] = 'Recovery ledger assets do not match positive source assets.';
        }

        $idempotencyRows = $database->fetchAll(
            'SELECT operation_key, operation_type, owner_ref, request_sha256, status,
                    result_json, created_at_utc, updated_at_utc, expires_at_utc
             FROM mgw_idempotency_keys
             WHERE owner_ref = :owner_ref
             ORDER BY operation_key' . $lock,
            ['owner_ref' => $mgwRef]
        );
        $idempotencyKeys = [];
        if (count($idempotencyRows) !== count($operationKeys)) {
            $blocking[] = 'Recovery idempotency row count does not match opening ledger rows.';
        }
        foreach ($idempotencyRows as $row) {
            $key = (string)($row['operation_key'] ?? '');
            $asset = array_search($key, $operationKeys, true);
            $requestHash = strtolower(trim((string)($row['request_sha256'] ?? '')));
            if (!is_string($asset)
                || isset($idempotencyKeys[$key])
                || !hash_equals('available_delta', (string)($row['operation_type'] ?? ''))
                || !hash_equals($mgwRef, (string)($row['owner_ref'] ?? ''))
                || !hash_equals('completed', (string)($row['status'] ?? ''))
                || preg_match('/\A[a-f0-9]{64}\z/', $requestHash) !== 1
                || trim((string)($row['expires_at_utc'] ?? '')) !== '') {
                $blocking[] = 'Recovery idempotency row does not exactly match the opening import contract.';
                continue;
            }
            $result = json_decode((string)($row['result_json'] ?? ''), true);
            $balance = is_array($result) && is_array($result['balance'] ?? null)
                ? $result['balance']
                : [];
            if (!is_array($result)
                || !hash_equals($key, (string)($result['operation_key'] ?? ''))
                || !empty($result['replayed'])
                || !hash_equals($mgwRef, (string)($balance['account_ref'] ?? ''))
                || !hash_equals($asset, (string)($balance['asset_code'] ?? ''))
                || (int)($balance['available_amount'] ?? -1) !== $sourceBalances[$asset]
                || (int)($balance['reserved_amount'] ?? -1) !== 0) {
                $blocking[] = 'Recovery idempotency result does not match the opening balance.';
            }
            $idempotencyKeys[$key] = true;
        }
        if (!$this->sameStringSet(array_keys($idempotencyKeys), array_values($operationKeys))) {
            $blocking[] = 'Recovery idempotency keys do not match opening operation keys.';
        }

        $reservationCount = (int)$database->fetchValue(
            'SELECT COUNT(*) FROM mgw_reservations
             WHERE account_ref IN (:mgw_ref, :legacy_ref)
                OR mgw_id = :mgw_id
                OR legacy_user_id = :legacy_user_id',
            [
                'mgw_ref' => $mgwRef,
                'legacy_ref' => $legacyRef,
                'mgw_id' => $expectedMgwId,
                'legacy_user_id' => $legacyUserId,
            ]
        );
        if ($reservationCount !== 0) $blocking[] = 'Recovery target has reservation rows.';

        $ownershipCount = (int)$database->fetchValue(
            'SELECT COUNT(*) FROM mgw_account_ownership
             WHERE account_ref IN (:mgw_ref, :legacy_ref)
                OR mgw_id = :mgw_id
                OR legacy_user_id = :legacy_user_id',
            [
                'mgw_ref' => $mgwRef,
                'legacy_ref' => $legacyRef,
                'mgw_id' => $expectedMgwId,
                'legacy_user_id' => $legacyUserId,
            ]
        );
        if ($ownershipCount !== 0) $blocking[] = 'Recovery target already has account ownership.';

        $meta = $this->meta($database, $lock);
        if (($meta[self::OPENING_META_KEY]['status'] ?? '') !== 'completed') {
            $blocking[] = 'Opening balance import metadata is not completed.';
        }
        if (($meta[self::ACCOUNT_META_KEY]['status'] ?? '') !== 'completed') {
            $blocking[] = 'Legacy account import metadata is not completed.';
        }
        if (isset($meta[self::OWNERSHIP_META_KEY])
            && ($meta[self::OWNERSHIP_META_KEY]['status'] ?? '') !== 'not_started') {
            $blocking[] = 'Ownership metadata already records a non-empty execution state.';
        }

        $evidence = [
            'legacy_user_id' => $legacyUserId,
            'mgw_id' => $expectedMgwId,
            'mgw_account_ref' => $mgwRef,
            'legacy_account_ref' => $legacyRef,
            'source_record_sha256' => $sourceRecordHash,
            'shadow_payload_sha256' => $shadowHash,
            'shadow_payload' => $shadowPayload,
            'source_balances' => $sourceBalances,
            'operation_keys' => $operationKeys,
            'balances' => $balanceRows,
            'ledger_entries' => $ledgerRows,
            'idempotency_rows' => $idempotencyRows,
            'meta' => $meta,
            'reservation_count' => $reservationCount,
            'ownership_count' => $ownershipCount,
        ];
        $blocking = array_values(array_unique($blocking));

        return [
            'ok' => $blocking === [],
            'ready' => $blocking === [],
            'status' => $blocking === [] ? 'ready' : 'blocked',
            'legacy_user_id' => $legacyUserId,
            'mgw_id' => $expectedMgwId,
            'mgw_account_ref' => $mgwRef,
            'legacy_account_ref' => $legacyRef,
            'plan_fingerprint' => hash('sha256', LedgerIntegrity::canonicalJson($evidence)),
            'balance_row_count' => count($balanceRows),
            'ledger_row_count' => count($ledgerRows),
            'idempotency_row_count' => count($idempotencyRows),
            'operation_keys' => $operationKeys,
            'blocking_reasons' => $blocking,
            'evidence' => $evidence,
        ];
    }

    private function shadow(
        DatabaseConnectionInterface $database,
        string $legacyUserId,
        string $sourceRecordHash,
        array $sourceBalances,
        string $lock,
        array &$blocking
    ): array {
        $rows = $database->fetchAll(
            "SELECT payload_json, payload_sha256
             FROM mgw_legacy_realtime_shadow
             WHERE entity_type = 'economy_user_balance' AND entity_key = :entity_key" . $lock,
            ['entity_key' => $legacyUserId]
        );
        if (count($rows) !== 1) {
            $blocking[] = 'Verified economy shadow row is missing or ambiguous.';
            return [[], ''];
        }
        $json = (string)($rows[0]['payload_json'] ?? '');
        $storedHash = strtolower(trim((string)($rows[0]['payload_sha256'] ?? '')));
        try {
            $payload = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $payload = null;
        }
        if (!is_array($payload)
            || preg_match('/\A[a-f0-9]{64}\z/', $storedHash) !== 1
            || !hash_equals($storedHash, hash('sha256', LedgerIntegrity::canonicalJson($payload)))) {
            $blocking[] = 'Verified economy shadow payload or hash is invalid.';
            return [is_array($payload) ? $payload : [], $storedHash];
        }
        if (!hash_equals($legacyUserId, trim((string)($payload['legacy_user_id'] ?? '')))) {
            $blocking[] = 'Verified economy shadow legacy user ID does not match.';
        }
        if (!hash_equals(
            $sourceRecordHash,
            strtolower(trim((string)($payload['source_record_sha256'] ?? '')))
        )) {
            $blocking[] = 'Current JSON user record differs from the verified economy shadow.';
        }
        foreach (self::ASSETS as $asset => $field) {
            if ($this->nonNegativeInteger($payload[$field] ?? 0, $field, $legacyUserId)
                !== $sourceBalances[$asset]) {
                $blocking[] = 'Current JSON and economy shadow balances differ for ' . $asset . '.';
            }
        }
        return [$payload, $storedHash];
    }

    private function meta(DatabaseConnectionInterface $database, string $lock): array
    {
        $rows = $database->fetchAll(
            'SELECT meta_key, meta_value, updated_at_utc
             FROM mgw_meta
             WHERE meta_key IN (:opening_key, :account_key, :ownership_key)
             ORDER BY meta_key' . $lock,
            [
                'opening_key' => self::OPENING_META_KEY,
                'account_key' => self::ACCOUNT_META_KEY,
                'ownership_key' => self::OWNERSHIP_META_KEY,
            ]
        );
        $meta = [];
        foreach ($rows as $row) {
            $decoded = json_decode((string)($row['meta_value'] ?? ''), true);
            $key = (string)($row['meta_key'] ?? '');
            $meta[$key] = [
                'status' => is_array($decoded) ? (string)($decoded['status'] ?? '') : 'invalid',
                'source_fingerprint' => is_array($decoded)
                    ? (string)($decoded['source_fingerprint'] ?? '')
                    : '',
                'updated_at_utc' => (string)($row['updated_at_utc'] ?? ''),
            ];
        }
        return $meta;
    }

    private function sourceRecord(string $legacyUserId): array
    {
        $matches = $this->storage->readOnly(static function (array $data) use ($legacyUserId): array {
            $result = [];
            foreach (is_array($data['users'] ?? null) ? $data['users'] : [] as $key => $record) {
                if (!is_array($record)) continue;
                if (trim((string)($record['id'] ?? $key)) === $legacyUserId) $result[] = $record;
            }
            return $result;
        });
        if (!is_array($matches) || count($matches) !== 1 || !is_array($matches[0])) {
            throw new RuntimeException('Opening residual recovery JSON user is missing or ambiguous.');
        }
        return $matches[0];
    }

    private function assertExecutablePlan(array $plan, string $fingerprint, string $label): void
    {
        if (empty($plan['ready'])) {
            throw new RuntimeException(
                'Opening residual recovery ' . $label . ' plan is not ready: '
                . implode('; ', $plan['blocking_reasons'] ?? [])
            );
        }
        if (!hash_equals($fingerprint, (string)($plan['plan_fingerprint'] ?? ''))) {
            throw new RuntimeException('Opening residual recovery ' . $label . ' plan fingerprint changed.');
        }
    }

    private function sameStringSet(array $left, array $right): bool
    {
        $left = array_values(array_unique(array_map('strval', $left)));
        $right = array_values(array_unique(array_map('strval', $right)));
        sort($left, SORT_STRING);
        sort($right, SORT_STRING);
        return $left === $right;
    }

    private function nonNegativeInteger(mixed $value, string $field, string $legacyUserId): int
    {
        if (is_int($value)) $number = $value;
        elseif (is_string($value) && preg_match('/\A\d+\z/', trim($value)) === 1) $number = (int)trim($value);
        elseif (is_float($value) && is_finite($value) && floor($value) === $value) $number = (int)$value;
        else throw new RuntimeException('Legacy user ' . $legacyUserId . ' has invalid ' . $field . '.');
        if ($number < 0) throw new RuntimeException('Legacy user ' . $legacyUserId . ' has negative ' . $field . '.');
        return $number;
    }

    private function safeSourceRef(string $legacyUserId): string
    {
        if (strlen($legacyUserId) <= 160 && preg_match('/[\x00-\x1F\x7F]/', $legacyUserId) !== 1) {
            return $legacyUserId;
        }
        return 'sha256:' . hash('sha256', $legacyUserId);
    }
}
