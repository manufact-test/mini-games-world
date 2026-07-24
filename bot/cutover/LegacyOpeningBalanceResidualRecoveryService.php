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
        $plan['dry_run'] = true;
        $plan['database_write_executed'] = false;
        return $plan;
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
        if (!$preview['ready']) {
            throw new RuntimeException(
                'Opening residual recovery is not ready: ' . implode('; ', $preview['blocking_reasons'])
            );
        }
        if (!hash_equals($expectedPlanFingerprint, (string)$preview['plan_fingerprint'])) {
            throw new RuntimeException('Opening residual recovery plan fingerprint does not match current state.');
        }

        $deleted = $this->database->transaction(function (DatabaseConnectionInterface $database) use (
            $legacyUserId,
            $expectedMgwId,
            $expectedPlanFingerprint
        ): array {
            $locked = $this->inspect($database, $legacyUserId, $expectedMgwId, true);
            if (!$locked['ready']) {
                throw new RuntimeException(
                    'Opening residual recovery changed before execution: '
                    . implode('; ', $locked['blocking_reasons'])
                );
            }
            if (!hash_equals($expectedPlanFingerprint, (string)$locked['plan_fingerprint'])) {
                throw new RuntimeException('Opening residual recovery locked plan fingerprint changed.');
            }

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

            $remainingBalances = (int)$database->fetchValue(
                'SELECT COUNT(*) FROM mgw_balances
                 WHERE account_ref = :account_ref OR legacy_user_id = :legacy_user_id',
                ['account_ref' => $mgwRef, 'legacy_user_id' => $legacyUserId]
            );
            $remainingLedger = (int)$database->fetchValue(
                'SELECT COUNT(*) FROM mgw_ledger_entries
                 WHERE account_ref = :account_ref OR legacy_user_id = :legacy_user_id',
                ['account_ref' => $mgwRef, 'legacy_user_id' => $legacyUserId]
            );
            $remainingIdempotency = (int)$database->fetchValue(
                'SELECT COUNT(*) FROM mgw_idempotency_keys WHERE owner_ref = :owner_ref',
                ['owner_ref' => $mgwRef]
            );
            $remainingMeta = (int)$database->fetchValue(
                'SELECT COUNT(*) FROM mgw_meta WHERE meta_key = :meta_key',
                ['meta_key' => self::OPENING_META_KEY]
            );
            if ($remainingBalances !== 0
                || $remainingLedger !== 0
                || $remainingIdempotency !== 0
                || $remainingMeta !== 0) {
                throw new RuntimeException('Opening residual recovery target rows remain after deletion.');
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
        $blocking = [];
        if ($legacyUserId === ''
            || strlen($legacyUserId) > 191
            || preg_match('/[\x00-\x1F\x7F]/', $legacyUserId) === 1) {
            throw new RuntimeException('Opening residual recovery legacy user ID is invalid.');
        }
        if (!MgwIdGenerator::isValid($expectedMgwId)) {
            throw new RuntimeException('Opening residual recovery MGW ID is invalid.');
        }

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

        $shadowRows = $database->fetchAll(
            "SELECT payload_json, payload_sha256
             FROM mgw_legacy_realtime_shadow
             WHERE entity_type = 'economy_user_balance' AND entity_key = :entity_key" . $lock,
            ['entity_key' => $legacyUserId]
        );
        $shadowPayload = [];
        $shadowHash = '';
        if (count($shadowRows) !== 1) {
            $blocking[] = 'Verified economy shadow row is missing or ambiguous.';
        } else {
            $shadowJson = (string)($shadowRows[0]['payload_json'] ?? '');
            $shadowHash = strtolower(trim((string)($shadowRows[0]['payload_sha256'] ?? '')));
            try {
                $decoded = json_decode($shadowJson, true, 512, JSON_THROW_ON_ERROR);
                $shadowPayload = is_array($decoded) ? $decoded : [];
            } catch (JsonException) {
                $blocking[] = 'Verified economy shadow payload is invalid.';
            }
            if ($shadowPayload === []
                || preg_match('/\A[a-f0-9]{64}\z/', $shadowHash) !== 1
                || !hash_equals($shadowHash, hash('sha256', LedgerIntegrity::canonicalJson($shadowPayload)))) {
                $blocking[] = 'Verified economy shadow hash does not match.';
            } else {
                if (!hash_equals($legacyUserId, trim((string)($shadowPayload['legacy_user_id'] ?? '')))) {
                    $blocking[] = 'Verified economy shadow legacy user ID does not match.';
                }
                if (!hash_equals(
                    $sourceRecordHash,
                    strtolower(trim((string)($shadowPayload['source_record_sha256'] ?? '')))
                )) {
                    $blocking[] = 'Current JSON user record differs from the verified economy shadow.';
                }
                foreach (self::ASSETS as $asset => $field) {
                    if ($this->nonNegativeInteger(
                        $shadowPayload[$field] ?? 0,
                        $field,
                        $legacyUserId
                    ) !== $sourceBalances[$asset]) {
                        $blocking[] = 'Current JSON and economy shadow balances differ for ' . $asset . '.';
                    }
                }
            }
        }

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
        $seenAssets = [];
        if (count($balanceRows) !== 2) {
            $blocking[] = 'Recovery target must contain exactly two balance rows.';
        }
        foreach ($balanceRows as $row) {
            $asset = (string)($row['asset_code'] ?? '');
            if (!array_key_exists($asset, $sourceBalances)
                || isset($seenAssets[$asset])
                || !hash_equals($mgwRef, (string)($row['account_ref'] ?? ''))
                || !hash_equals($expectedMgwId, (string)($row['mgw_id'] ?? ''))
                || !hash_equals($legacyUserId, (string)($row['legacy_user_id'] ?? ''))
                || (int)($row['available_amount'] ?? -1) !== ($sourceBalances[$asset] ?? -2)
                || (int)($row['reserved_amount'] ?? -1) !== 0
                || (int)($row['version'] ?? -1) !== 1) {
                $blocking[] = 'Recovery balance row does not exactly match the legacy opening import.';
                continue;
            }
            $seenAssets[$asset] = true;
        }
        if (array_keys($seenAssets) !== ['gold_coin', 'match_coin']) {
            $blocking[] = 'Recovery target does not contain one separate Gold and Match balance.';
        }

        $operationKeys = [];
        foreach ($sourceBalances as $asset => $amount) {
            if ($amount > 0) {
                $operationKeys[$asset] = 'legacy_opening:v1:'
                    . substr(hash('sha256', $legacyUserId . '|' . $asset), 0, 48);
            }
        }

        $ledgerRows = $database->fetchAll(
            'SELECT ledger_sequence, entry_id, idempotency_key, account_ref, mgw_id,
                    legacy_user_id, asset_code, available_delta, reserved_delta,
                    available_before, available_after, reserved_before, reserved_after,
                    category, source_type, source_ref, reservation_id, metadata_json,
                    previous_entry_sha256, entry_sha256, created_at_utc
             FROM mgw_ledger_entries
             WHERE account_ref IN (:mgw_ref, :legacy_ref)
                OR legacy_user_id = :legacy_user_id
             ORDER BY ledger_sequence' . $lock,
            [
                'mgw_ref' => $mgwRef,
                'legacy_ref' => $legacyRef,
                'legacy_user_id' => $legacyUserId,
            ]
        );
        $seenLedgerAssets = [];
        if (count($ledgerRows) !== count($operationKeys)) {
            $blocking[] = 'Recovery target ledger row count does not match positive opening balances.';
        }
        foreach ($ledgerRows as $row) {
            $asset = (string)($row['asset_code'] ?? '');
            $operationKey = $operationKeys[$asset] ?? '';
            $entryHash = strtolower(trim((string)($row['entry_sha256'] ?? '')));
            if ($operationKey === ''
                || isset($seenLedgerAssets[$asset])
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
                || !hash_equals((string)self::ASSETS[$asset], (string)($metadata['source_balance_field'] ?? ''))) {
                $blocking[] = 'Recovery ledger metadata does not match the verified source.';
            }
            $chain = $this->verifier->verifyAccountAsset($mgwRef, $asset);
            if (empty($chain['ok'])) {
                $blocking[] = 'Recovery ledger integrity verification failed for ' . $asset . '.';
            }
            $seenLedgerAssets[$asset] = true;
        }
        if (array_keys($seenLedgerAssets) !== array_keys($operationKeys)) {
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
        $idempotencyByKey = [];
        if (count($idempotencyRows) !== count($operationKeys)) {
            $blocking[] = 'Recovery idempotency row count does not match opening ledger rows.';
        }
        foreach ($idempotencyRows as $row) {
            $key = (string)($row['operation_key'] ?? '');
            $asset = array_search($key, $operationKeys, true);
            $requestHash = strtolower(trim((string)($row['request_sha256'] ?? '')));
            if (!is_string($asset)
                || isset($idempotencyByKey[$key])
                || !hash_equals('available_delta', (string)($row['operation_type'] ?? ''))
                || !hash_equals($mgwRef, (string)($row['owner_ref'] ?? ''))
                || !hash_equals('completed', (string)($row['status'] ?? ''))
                || preg_match('/\A[a-f0-9]{64}\z/', $requestHash) !== 1
                || trim((string)($row['expires_at_utc'] ?? '')) !== '') {
                $blocking[] = 'Recovery idempotency row does not exactly match the opening import contract.';
                continue;
            }
            $result = json_decode((string)($row['result_json'] ?? ''), true);
            $balance = is_array($result['balance'] ?? null) ? $result['balance'] : [];
            if (!is_array($result)
                || !hash_equals($key, (string)($result['operation_key'] ?? ''))
                || !empty($result['replayed'])
                || !hash_equals($mgwRef, (string)($balance['account_ref'] ?? ''))
                || !hash_equals($asset, (string)($balance['asset_code'] ?? ''))
                || (int)($balance['available_amount'] ?? -1) !== $sourceBalances[$asset]
                || (int)($balance['reserved_amount'] ?? -1) !== 0) {
                $blocking[] = 'Recovery idempotency result does not match the opening balance.';
            }
            $idempotencyByKey[$key] = $row;
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
        if ($reservationCount !== 0) {
            $blocking[] = 'Recovery target has reservation rows.';
        }
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
        if ($ownershipCount !== 0) {
            $blocking[] = 'Recovery target already has account ownership.';
        }

        $metaRows = $database->fetchAll(
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
        foreach ($metaRows as $row) {
            $value = json_decode((string)($row['meta_value'] ?? ''), true);
            $key = (string)($row['meta_key'] ?? '');
            $meta[$key] = [
                'status' => is_array($value) ? (string)($value['status'] ?? '') : 'invalid',
                'source_fingerprint' => is_array($value)
                    ? (string)($value['source_fingerprint'] ?? '')
                    : '',
                'updated_at_utc' => (string)($row['updated_at_utc'] ?? ''),
            ];
        }
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
            'source_balances' => $sourceBalances,
            'operation_keys' => $operationKeys,
            'balances' => $balanceRows,
            'ledger_entries' => $ledgerRows,
            'idempotency_rows' => $idempotencyRows,
            'meta' => $meta,
            'reservation_count' => $reservationCount,
            'ownership_count' => $ownershipCount,
        ];
        $fingerprint = hash('sha256', LedgerIntegrity::canonicalJson($evidence));
        $blocking = array_values(array_unique($blocking));

        return [
            'ok' => $blocking === [],
            'ready' => $blocking === [],
            'status' => $blocking === [] ? 'ready' : 'blocked',
            'legacy_user_id' => $legacyUserId,
            'mgw_id' => $expectedMgwId,
            'mgw_account_ref' => $mgwRef,
            'legacy_account_ref' => $legacyRef,
            'plan_fingerprint' => $fingerprint,
            'balance_row_count' => count($balanceRows),
            'ledger_row_count' => count($ledgerRows),
            'idempotency_row_count' => count($idempotencyRows),
            'operation_keys' => $operationKeys,
            'blocking_reasons' => $blocking,
            'evidence' => $evidence,
        ];
    }

    private function sourceRecord(string $legacyUserId): array
    {
        $matches = $this->storage->readOnly(static function (array $data) use ($legacyUserId): array {
            $matches = [];
            foreach (is_array($data['users'] ?? null) ? $data['users'] : [] as $key => $record) {
                if (!is_array($record)) continue;
                $id = trim((string)($record['id'] ?? $key));
                if ($id === $legacyUserId) $matches[] = $record;
            }
            return $matches;
        });
        if (!is_array($matches) || count($matches) !== 1 || !is_array($matches[0])) {
            throw new RuntimeException('Opening residual recovery JSON user is missing or ambiguous.');
        }
        return $matches[0];
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
