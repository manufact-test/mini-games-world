<?php
declare(strict_types=1);

final class RuntimePrimaryStagingApiMutatingSmokeIdentityCleanup
{
    private ?Closure $workerFactory;

    public function __construct(
        private DatabaseConnectionInterface $database,
        ?Closure $workerFactory = null
    ) {
        if ($this->database->driver() !== 'mysql') {
            throw new RuntimeException('Staging API mutating smoke identity cleanup requires MySQL/MariaDB.');
        }
        $this->workerFactory = $workerFactory;
    }

    public function assertFresh(string $provider, string $subject, string $legacyUserId, string $sessionId): array
    {
        $this->assertIdentity($provider, $subject, $legacyUserId, $sessionId);
        $sessionHash = hash('sha256', 'session|' . $sessionId);
        $accountRef = 'legacy:' . $legacyUserId;
        $checks = [
            'identity' => [
                'SELECT COUNT(*) FROM mgw_identities WHERE provider = :provider AND provider_subject = :subject',
                ['provider' => $provider, 'subject' => $subject],
            ],
            'ownership' => [
                'SELECT COUNT(*) FROM mgw_account_ownership WHERE legacy_user_id = :legacy_user_id OR account_ref = :account_ref',
                ['legacy_user_id' => $legacyUserId, 'account_ref' => $accountRef],
            ],
            'session' => [
                'SELECT COUNT(*) FROM mgw_sessions WHERE session_key_hash = :session_key_hash',
                ['session_key_hash' => $sessionHash],
            ],
            'balance' => [
                'SELECT COUNT(*) FROM mgw_balances WHERE legacy_user_id = :legacy_user_id OR account_ref = :account_ref',
                ['legacy_user_id' => $legacyUserId, 'account_ref' => $accountRef],
            ],
            'ledger' => [
                'SELECT COUNT(*) FROM mgw_ledger_entries WHERE legacy_user_id = :legacy_user_id OR account_ref = :account_ref',
                ['legacy_user_id' => $legacyUserId, 'account_ref' => $accountRef],
            ],
            'idempotency' => [
                'SELECT COUNT(*) FROM mgw_idempotency_keys WHERE owner_ref = :account_ref',
                ['account_ref' => $accountRef],
            ],
            'reservation' => [
                'SELECT COUNT(*) FROM mgw_reservations WHERE legacy_user_id = :legacy_user_id OR account_ref = :account_ref',
                ['legacy_user_id' => $legacyUserId, 'account_ref' => $accountRef],
            ],
        ];
        foreach ($checks as $label => [$sql, $parameters]) {
            if ((int)$this->database->fetchValue($sql, $parameters) !== 0) {
                throw new RuntimeException('Staging API mutating smoke synthetic ' . $label . ' already exists.');
            }
        }
        return [
            'provider' => $provider,
            'subject_sha256' => hash('sha256', $subject),
            'legacy_user_id_sha256' => hash('sha256', $legacyUserId),
            'session_sha256' => hash('sha256', $sessionId),
            'fresh' => true,
        ];
    }

    public function resolveCreatedMgwId(string $provider, string $subject, string $legacyUserId): string
    {
        $this->assertProviderSubject($provider, $subject, $legacyUserId);
        $rows = $this->database->fetchAll(
            'SELECT mgw_id FROM mgw_identities
             WHERE provider = :provider AND provider_subject = :subject',
            ['provider' => $provider, 'subject' => $subject]
        );
        if (count($rows) !== 1) {
            throw new RuntimeException('Staging API mutating smoke synthetic identity is missing or ambiguous.');
        }
        $mgwId = (string)($rows[0]['mgw_id'] ?? '');
        if (!MgwIdGenerator::isValid($mgwId)) {
            throw new RuntimeException('Staging API mutating smoke synthetic MGW ID is invalid.');
        }
        $ownership = $this->database->fetchAll(
            'SELECT mgw_id, legacy_user_id, account_ref FROM mgw_account_ownership
             WHERE mgw_id = :mgw_id OR legacy_user_id = :legacy_user_id OR account_ref = :account_ref',
            [
                'mgw_id' => $mgwId,
                'legacy_user_id' => $legacyUserId,
                'account_ref' => 'legacy:' . $legacyUserId,
            ]
        );
        if (count($ownership) > 1) {
            throw new RuntimeException('Staging API mutating smoke synthetic ownership is ambiguous.');
        }
        if ($ownership !== []
            && (!hash_equals($mgwId, (string)($ownership[0]['mgw_id'] ?? ''))
                || !hash_equals($legacyUserId, (string)($ownership[0]['legacy_user_id'] ?? ''))
                || !hash_equals('legacy:' . $legacyUserId, (string)($ownership[0]['account_ref'] ?? '')))) {
            throw new RuntimeException('Staging API mutating smoke synthetic ownership conflicts with identity.');
        }
        return $mgwId;
    }

    public function removeMappingsBeforeCleanupProjection(
        string $provider,
        string $subject,
        string $legacyUserId,
        string $sessionId,
        string $mgwId
    ): array {
        $this->assertIdentity($provider, $subject, $legacyUserId, $sessionId);
        if (!MgwIdGenerator::isValid($mgwId)) {
            throw new RuntimeException('Staging API mutating smoke cleanup MGW ID is invalid.');
        }

        $earlierProjectionProof = $this->completeEarlierProjectionEventsBeforeMappingDeletion();
        $sessionHash = hash('sha256', 'session|' . $sessionId);

        $deleted = $this->database->transaction(function (DatabaseConnectionInterface $database) use (
            $provider,
            $subject,
            $legacyUserId,
            $sessionHash,
            $mgwId
        ): array {
            $identityRows = $database->fetchAll(
                'SELECT mgw_id FROM mgw_identities
                 WHERE provider = :provider AND provider_subject = :subject FOR UPDATE',
                ['provider' => $provider, 'subject' => $subject]
            );
            if (count($identityRows) !== 1
                || !hash_equals($mgwId, (string)($identityRows[0]['mgw_id'] ?? ''))) {
                throw new RuntimeException('Staging API mutating smoke cleanup identity no longer matches approval.');
            }
            $ownershipRows = $database->fetchAll(
                'SELECT mgw_id, legacy_user_id, account_ref FROM mgw_account_ownership
                 WHERE mgw_id = :mgw_id OR legacy_user_id = :legacy_user_id OR account_ref = :account_ref FOR UPDATE',
                [
                    'mgw_id' => $mgwId,
                    'legacy_user_id' => $legacyUserId,
                    'account_ref' => 'legacy:' . $legacyUserId,
                ]
            );
            if (count($ownershipRows) > 1) {
                throw new RuntimeException('Staging API mutating smoke cleanup ownership is ambiguous.');
            }
            if ($ownershipRows !== []
                && (!hash_equals($mgwId, (string)($ownershipRows[0]['mgw_id'] ?? ''))
                    || !hash_equals($legacyUserId, (string)($ownershipRows[0]['legacy_user_id'] ?? ''))
                    || !hash_equals('legacy:' . $legacyUserId, (string)($ownershipRows[0]['account_ref'] ?? '')))) {
                throw new RuntimeException('Staging API mutating smoke cleanup ownership no longer matches approval.');
            }

            $economyDeleted = $this->deleteEconomyArtifacts($database, $legacyUserId, $mgwId);

            $deletedSessions = $database->execute(
                'DELETE FROM mgw_sessions WHERE session_key_hash = :session_key_hash AND mgw_id = :mgw_id',
                ['session_key_hash' => $sessionHash, 'mgw_id' => $mgwId]
            );
            if ($deletedSessions < 0 || $deletedSessions > 1) {
                throw new RuntimeException('Staging API mutating smoke cleanup session row count is unexpected.');
            }
            $deletedDevices = $database->execute(
                'DELETE FROM mgw_devices WHERE mgw_id = :mgw_id',
                ['mgw_id' => $mgwId]
            );
            if ($deletedDevices < 0 || $deletedDevices > 1) {
                throw new RuntimeException('Staging API mutating smoke cleanup device row count is unexpected.');
            }
            $deletedOwnership = $database->execute(
                'DELETE FROM mgw_account_ownership
                 WHERE mgw_id = :mgw_id AND legacy_user_id = :legacy_user_id AND account_ref = :account_ref',
                [
                    'mgw_id' => $mgwId,
                    'legacy_user_id' => $legacyUserId,
                    'account_ref' => 'legacy:' . $legacyUserId,
                ]
            );
            if ($deletedOwnership < 0 || $deletedOwnership > 1) {
                throw new RuntimeException('Staging API mutating smoke cleanup ownership row count is unexpected.');
            }
            $deletedIdentities = $database->execute(
                'DELETE FROM mgw_identities WHERE mgw_id = :mgw_id',
                ['mgw_id' => $mgwId]
            );
            if ($deletedIdentities < 1 || $deletedIdentities > 2) {
                throw new RuntimeException('Staging API mutating smoke cleanup identity row count is unexpected.');
            }

            return $economyDeleted + [
                'session_rows_deleted' => $deletedSessions,
                'device_rows_deleted' => $deletedDevices,
                'ownership_rows_deleted' => $deletedOwnership,
                'identity_rows_deleted' => $deletedIdentities,
                'user_row_deferred' => true,
            ];
        });

        return $deleted + $earlierProjectionProof;
    }

    public function recoverOrphanEconomyArtifacts(string $legacyUserId, string $mgwId): array
    {
        $this->assertSyntheticLegacyUserId($legacyUserId);
        if (!MgwIdGenerator::isValid($mgwId)) {
            throw new RuntimeException('Staging API mutating smoke recovery MGW ID is invalid.');
        }

        return $this->database->transaction(function (DatabaseConnectionInterface $database) use (
            $legacyUserId,
            $mgwId
        ): array {
            $userCount = (int)$database->fetchValue(
                'SELECT COUNT(*) FROM mgw_users WHERE mgw_id = :mgw_id',
                ['mgw_id' => $mgwId]
            );
            if ($userCount !== 1) {
                throw new RuntimeException('Staging API mutating smoke recovery orphan user is missing or ambiguous.');
            }
            foreach ([
                'mgw_identities', 'mgw_account_ownership', 'mgw_devices', 'mgw_sessions',
            ] as $table) {
                $count = (int)$database->fetchValue(
                    'SELECT COUNT(*) FROM ' . $table . ' WHERE mgw_id = :mgw_id',
                    ['mgw_id' => $mgwId]
                );
                if ($count !== 0) {
                    throw new RuntimeException('Staging API mutating smoke recovery mappings are not fully absent.');
                }
            }
            return $this->deleteEconomyArtifacts($database, $legacyUserId, $mgwId)
                + ['recovery_orphan_verified' => true];
        });
    }

    public function removeOrphanUserAfterCleanupProjection(string $mgwId): array
    {
        if (!MgwIdGenerator::isValid($mgwId)) {
            throw new RuntimeException('Staging API mutating smoke orphan MGW ID is invalid.');
        }
        return $this->database->transaction(function (DatabaseConnectionInterface $database) use ($mgwId): array {
            foreach ([
                'mgw_sessions', 'mgw_devices', 'mgw_identities', 'mgw_account_ownership',
                'mgw_balances', 'mgw_ledger_entries', 'mgw_reservations',
            ] as $table) {
                $count = (int)$database->fetchValue(
                    'SELECT COUNT(*) FROM ' . $table . ' WHERE mgw_id = :mgw_id',
                    ['mgw_id' => $mgwId]
                );
                if ($count !== 0) {
                    throw new RuntimeException('Staging API mutating smoke orphan user still has dependent rows.');
                }
            }
            $deleted = $database->execute(
                'DELETE FROM mgw_users WHERE mgw_id = :mgw_id',
                ['mgw_id' => $mgwId]
            );
            if ($deleted < 0 || $deleted > 1) {
                throw new RuntimeException('Staging API mutating smoke cleanup user row count is unexpected.');
            }
            return ['user_rows_deleted' => $deleted, 'identity_cleanup_complete' => true];
        });
    }

    public function assertAbsent(
        string $provider,
        string $subject,
        string $legacyUserId,
        string $sessionId,
        string $mgwId
    ): array {
        $this->assertIdentity($provider, $subject, $legacyUserId, $sessionId);
        if (!MgwIdGenerator::isValid($mgwId)) {
            throw new RuntimeException('Staging API mutating smoke absence MGW ID is invalid.');
        }
        $sessionHash = hash('sha256', 'session|' . $sessionId);
        $accountRef = 'legacy:' . $legacyUserId;
        $checks = [
            ['SELECT COUNT(*) FROM mgw_users WHERE mgw_id = :mgw_id', ['mgw_id' => $mgwId]],
            ['SELECT COUNT(*) FROM mgw_identities WHERE mgw_id = :mgw_id OR (provider = :provider AND provider_subject = :subject)', ['mgw_id' => $mgwId, 'provider' => $provider, 'subject' => $subject]],
            ['SELECT COUNT(*) FROM mgw_account_ownership WHERE mgw_id = :mgw_id OR legacy_user_id = :legacy_user_id OR account_ref = :account_ref', ['mgw_id' => $mgwId, 'legacy_user_id' => $legacyUserId, 'account_ref' => $accountRef]],
            ['SELECT COUNT(*) FROM mgw_devices WHERE mgw_id = :mgw_id', ['mgw_id' => $mgwId]],
            ['SELECT COUNT(*) FROM mgw_sessions WHERE mgw_id = :mgw_id OR session_key_hash = :session_key_hash', ['mgw_id' => $mgwId, 'session_key_hash' => $sessionHash]],
            ['SELECT COUNT(*) FROM mgw_balances WHERE mgw_id = :mgw_id OR legacy_user_id = :legacy_user_id OR account_ref = :account_ref', ['mgw_id' => $mgwId, 'legacy_user_id' => $legacyUserId, 'account_ref' => $accountRef]],
            ['SELECT COUNT(*) FROM mgw_ledger_entries WHERE mgw_id = :mgw_id OR legacy_user_id = :legacy_user_id OR account_ref = :account_ref', ['mgw_id' => $mgwId, 'legacy_user_id' => $legacyUserId, 'account_ref' => $accountRef]],
            ['SELECT COUNT(*) FROM mgw_idempotency_keys WHERE owner_ref = :account_ref', ['account_ref' => $accountRef]],
            ['SELECT COUNT(*) FROM mgw_reservations WHERE mgw_id = :mgw_id OR legacy_user_id = :legacy_user_id OR account_ref = :account_ref', ['mgw_id' => $mgwId, 'legacy_user_id' => $legacyUserId, 'account_ref' => $accountRef]],
        ];
        foreach ($checks as [$sql, $parameters]) {
            if ((int)$this->database->fetchValue($sql, $parameters) !== 0) {
                throw new RuntimeException('Staging API mutating smoke synthetic account cleanup is incomplete.');
            }
        }
        return [
            'ok' => true,
            'action' => 'staging_api_mutating_smoke_identity_cleanup_verified',
            'identity_rows_remaining' => 0,
            'ownership_rows_remaining' => 0,
            'device_rows_remaining' => 0,
            'session_rows_remaining' => 0,
            'balance_rows_remaining' => 0,
            'ledger_rows_remaining' => 0,
            'idempotency_rows_remaining' => 0,
            'reservation_rows_remaining' => 0,
            'user_rows_remaining' => 0,
            'sensitive_identifiers_exposed' => false,
        ];
    }

    private function deleteEconomyArtifacts(
        DatabaseConnectionInterface $database,
        string $legacyUserId,
        string $mgwId
    ): array {
        $this->assertSyntheticLegacyUserId($legacyUserId);
        $accountRef = 'legacy:' . $legacyUserId;

        $reservations = $database->fetchAll(
            'SELECT reservation_id FROM mgw_reservations
             WHERE account_ref = :account_ref OR mgw_id = :mgw_id OR legacy_user_id = :legacy_user_id
             FOR UPDATE',
            [
                'account_ref' => $accountRef,
                'mgw_id' => $mgwId,
                'legacy_user_id' => $legacyUserId,
            ]
        );
        if ($reservations !== []) {
            throw new RuntimeException('Staging API mutating smoke economy cleanup found an unexpected reservation.');
        }

        $balances = $database->fetchAll(
            'SELECT account_ref, mgw_id, legacy_user_id, asset_code, reserved_amount
             FROM mgw_balances
             WHERE account_ref = :account_ref OR mgw_id = :mgw_id OR legacy_user_id = :legacy_user_id
             ORDER BY asset_code ASC FOR UPDATE',
            [
                'account_ref' => $accountRef,
                'mgw_id' => $mgwId,
                'legacy_user_id' => $legacyUserId,
            ]
        );
        if (count($balances) !== 2) {
            throw new RuntimeException('Staging API mutating smoke economy cleanup requires exactly two balances.');
        }
        $balanceAssets = [];
        foreach ($balances as $balance) {
            $asset = (string)($balance['asset_code'] ?? '');
            if (!in_array($asset, ['gold_coin', 'match_coin'], true)
                || isset($balanceAssets[$asset])
                || !hash_equals($accountRef, (string)($balance['account_ref'] ?? ''))
                || !hash_equals($mgwId, (string)($balance['mgw_id'] ?? ''))
                || !hash_equals($legacyUserId, (string)($balance['legacy_user_id'] ?? ''))
                || (int)($balance['reserved_amount'] ?? -1) !== 0) {
                throw new RuntimeException('Staging API mutating smoke economy balance identity is invalid.');
            }
            $balanceAssets[$asset] = true;
        }
        if (array_keys($balanceAssets) !== ['gold_coin', 'match_coin']) {
            throw new RuntimeException('Staging API mutating smoke economy assets are incomplete.');
        }

        $ledgerRows = $database->fetchAll(
            'SELECT idempotency_key, account_ref, mgw_id, legacy_user_id, asset_code,
                    category, source_type, reservation_id
             FROM mgw_ledger_entries
             WHERE account_ref = :account_ref OR mgw_id = :mgw_id OR legacy_user_id = :legacy_user_id
             ORDER BY ledger_sequence ASC FOR UPDATE',
            [
                'account_ref' => $accountRef,
                'mgw_id' => $mgwId,
                'legacy_user_id' => $legacyUserId,
            ]
        );
        if (count($ledgerRows) > 2) {
            throw new RuntimeException('Staging API mutating smoke economy cleanup ledger bound was exceeded.');
        }
        $ledgerKeys = [];
        $ledgerAssets = [];
        foreach ($ledgerRows as $row) {
            $key = (string)($row['idempotency_key'] ?? '');
            $asset = (string)($row['asset_code'] ?? '');
            $reservationId = trim((string)($row['reservation_id'] ?? ''));
            if (preg_match('/\Aruntime_opening:v1:[a-f0-9]{48}\z/', $key) !== 1
                || !in_array($asset, ['gold_coin', 'match_coin'], true)
                || isset($ledgerAssets[$asset])
                || !hash_equals($accountRef, (string)($row['account_ref'] ?? ''))
                || !hash_equals($mgwId, (string)($row['mgw_id'] ?? ''))
                || !hash_equals($legacyUserId, (string)($row['legacy_user_id'] ?? ''))
                || (string)($row['category'] ?? '') !== 'legacy_runtime_opening'
                || (string)($row['source_type'] ?? '') !== 'legacy_json_runtime'
                || $reservationId !== '') {
                throw new RuntimeException('Staging API mutating smoke economy ledger identity is invalid.');
            }
            $ledgerKeys[$key] = true;
            $ledgerAssets[$asset] = true;
        }

        $idempotencyRows = $database->fetchAll(
            'SELECT operation_key, operation_type, owner_ref, status
             FROM mgw_idempotency_keys WHERE owner_ref = :account_ref
             ORDER BY operation_key ASC FOR UPDATE',
            ['account_ref' => $accountRef]
        );
        if (count($idempotencyRows) !== count($ledgerRows)) {
            throw new RuntimeException('Staging API mutating smoke economy idempotency count is invalid.');
        }
        foreach ($idempotencyRows as $row) {
            $key = (string)($row['operation_key'] ?? '');
            if (!isset($ledgerKeys[$key])
                || (string)($row['operation_type'] ?? '') !== 'available_delta'
                || !hash_equals($accountRef, (string)($row['owner_ref'] ?? ''))
                || (string)($row['status'] ?? '') !== 'completed') {
                throw new RuntimeException('Staging API mutating smoke economy idempotency identity is invalid.');
            }
        }

        $deletedLedger = $database->execute(
            'DELETE FROM mgw_ledger_entries
             WHERE account_ref = :account_ref AND mgw_id = :mgw_id AND legacy_user_id = :legacy_user_id',
            [
                'account_ref' => $accountRef,
                'mgw_id' => $mgwId,
                'legacy_user_id' => $legacyUserId,
            ]
        );
        if ($deletedLedger !== count($ledgerRows)) {
            throw new RuntimeException('Staging API mutating smoke economy ledger deletion count is invalid.');
        }
        $deletedIdempotency = $database->execute(
            'DELETE FROM mgw_idempotency_keys WHERE owner_ref = :account_ref',
            ['account_ref' => $accountRef]
        );
        if ($deletedIdempotency !== count($idempotencyRows)) {
            throw new RuntimeException('Staging API mutating smoke economy idempotency deletion count is invalid.');
        }
        $deletedBalances = $database->execute(
            'DELETE FROM mgw_balances
             WHERE account_ref = :account_ref AND mgw_id = :mgw_id AND legacy_user_id = :legacy_user_id',
            [
                'account_ref' => $accountRef,
                'mgw_id' => $mgwId,
                'legacy_user_id' => $legacyUserId,
            ]
        );
        if ($deletedBalances !== 2) {
            throw new RuntimeException('Staging API mutating smoke economy balance deletion count is invalid.');
        }

        return [
            'economy_balance_rows_deleted' => $deletedBalances,
            'economy_ledger_rows_deleted' => $deletedLedger,
            'economy_idempotency_rows_deleted' => $deletedIdempotency,
            'economy_reservation_rows_verified_zero' => true,
        ];
    }

    private function completeEarlierProjectionEventsBeforeMappingDeletion(): array
    {
        $currentRevision = (int)$this->database->fetchValue(
            'SELECT revision FROM ' . RuntimePrimaryStateSchemaInstaller::TABLE . ' WHERE singleton_id = 1'
        );
        if ($currentRevision < 1) {
            throw new RuntimeException('Staging API mutating smoke cleanup current revision is invalid.');
        }

        $worker = null;
        $completed = [];
        for ($attempt = 0; $attempt < 4; $attempt++) {
            $rows = $this->database->fetchAll(
                'SELECT state_revision, status FROM ' . RuntimePrimaryProjectionOutboxSchemaInstaller::TABLE . "
                 WHERE status <> 'completed' AND state_revision < :current_revision
                 ORDER BY state_revision ASC LIMIT 1",
                ['current_revision' => $currentRevision]
            );
            if ($rows === []) {
                return [
                    'earlier_projection_events_completed' => count($completed),
                    'mapping_deleted_after_earlier_projection' => true,
                ];
            }
            if (count($rows) !== 1 || !is_array($rows[0])) {
                throw new RuntimeException('Staging API mutating smoke earlier projection ordering is ambiguous.');
            }
            $revision = (int)($rows[0]['state_revision'] ?? 0);
            $status = strtolower(trim((string)($rows[0]['status'] ?? '')));
            if ($revision < 1 || $revision >= $currentRevision
                || !in_array($status, ['pending', 'processing', 'failed'], true)) {
                throw new RuntimeException('Staging API mutating smoke earlier projection identity is invalid.');
            }

            if (in_array($status, ['processing', 'failed'], true)) {
                $reset = $this->database->execute(
                    'UPDATE ' . RuntimePrimaryProjectionOutboxSchemaInstaller::TABLE . "
                     SET status = 'pending', lease_token = '', lease_expires_at_utc = '',
                         available_at_utc = :available_at_utc, last_error = '', updated_at_utc = :updated_at_utc
                     WHERE state_revision = :state_revision AND status = :expected_status",
                    [
                        'available_at_utc' => gmdate(DATE_ATOM),
                        'updated_at_utc' => gmdate(DATE_ATOM),
                        'state_revision' => $revision,
                        'expected_status' => $status,
                    ]
                );
                if ($reset !== 1) {
                    throw new RuntimeException('Staging API mutating smoke earlier projection recovery lost its exact event.');
                }
            }

            if (!$worker instanceof RuntimePrimaryProjectionWorkerInterface) {
                $worker = $this->makeRecoveryWorker();
            }
            $tick = $worker->runOnce();
            if (($tick['ok'] ?? false) !== true
                || ($tick['action'] ?? '') !== 'projection_completed'
                || ($tick['claimed'] ?? false) !== true
                || (int)($tick['state_revision'] ?? 0) !== $revision
                || ($tick['parity_ok'] ?? false) !== true) {
                throw new RuntimeException('Staging API mutating smoke earlier projection could not be completed safely.');
            }
            $completed[] = $revision;
        }

        throw new RuntimeException('Staging API mutating smoke earlier projection recovery exceeded its bound.');
    }

    private function makeRecoveryWorker(): RuntimePrimaryProjectionWorkerInterface
    {
        if ($this->workerFactory instanceof Closure) {
            $worker = ($this->workerFactory)($this->database);
            if (!$worker instanceof RuntimePrimaryProjectionWorkerInterface) {
                throw new RuntimeException('Staging API mutating smoke recovery worker factory returned an invalid worker.');
            }
            return $worker;
        }

        $config = $GLOBALS['config'] ?? null;
        if (!is_array($config)) {
            throw new RuntimeException('Staging API mutating smoke recovery config is unavailable.');
        }
        $projector = (new RuntimePrimaryRepositoryProjectorFactory($config, $this->database))->create();
        return new RuntimePrimaryProjectionWorkerAdapter(
            new RuntimePrimaryProjectionWorker($this->database, $projector, 60)
        );
    }

    private function assertIdentity(string $provider, string $subject, string $legacyUserId, string $sessionId): void
    {
        $this->assertProviderSubject($provider, $subject, $legacyUserId);
        if (preg_match('/\A[a-zA-Z0-9_-]{32,120}\z/', $sessionId) !== 1) {
            throw new RuntimeException('Staging API mutating smoke synthetic session is invalid.');
        }
    }

    private function assertProviderSubject(string $provider, string $subject, string $legacyUserId): void
    {
        if ($provider !== 'telegram' || !hash_equals($subject, $legacyUserId)) {
            throw new RuntimeException('Staging API mutating smoke synthetic identity is invalid.');
        }
        $this->assertSyntheticLegacyUserId($legacyUserId);
    }

    private function assertSyntheticLegacyUserId(string $legacyUserId): void
    {
        if (preg_match('/\A9\d{17}\z/', $legacyUserId) !== 1) {
            throw new RuntimeException('Staging API mutating smoke synthetic legacy user is invalid.');
        }
    }
}
