<?php
declare(strict_types=1);

final class RuntimePrimaryStagingApiMutatingSmokeIdentityCleanup
{
    public function __construct(private DatabaseConnectionInterface $database)
    {
        if ($this->database->driver() !== 'mysql') {
            throw new RuntimeException('Staging API mutating smoke identity cleanup requires MySQL/MariaDB.');
        }
    }

    public function assertFresh(string $provider, string $subject, string $legacyUserId, string $sessionId): array
    {
        $this->assertIdentity($provider, $subject, $legacyUserId, $sessionId);
        $sessionHash = hash('sha256', 'session|' . $sessionId);
        $checks = [
            'identity' => ['SELECT COUNT(*) FROM mgw_identities WHERE provider = :provider AND provider_subject = :subject', ['provider' => $provider, 'subject' => $subject]],
            'ownership' => ['SELECT COUNT(*) FROM mgw_account_ownership WHERE legacy_user_id = :legacy_user_id OR account_ref = :account_ref', ['legacy_user_id' => $legacyUserId, 'account_ref' => 'legacy:' . $legacyUserId]],
            'session' => ['SELECT COUNT(*) FROM mgw_sessions WHERE session_key_hash = :session_key_hash', ['session_key_hash' => $sessionHash]],
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
        $sessionHash = hash('sha256', 'session|' . $sessionId);

        return $this->database->transaction(function (DatabaseConnectionInterface $database) use (
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

            $deletedSessions = $database->execute(
                'DELETE FROM mgw_sessions WHERE session_key_hash = :session_key_hash AND mgw_id = :mgw_id',
                ['session_key_hash' => $sessionHash, 'mgw_id' => $mgwId]
            );
            if ($deletedSessions !== 1) {
                throw new RuntimeException('Staging API mutating smoke cleanup did not delete exactly one session.');
            }
            $deletedDevices = $database->execute(
                'DELETE FROM mgw_devices WHERE mgw_id = :mgw_id',
                ['mgw_id' => $mgwId]
            );
            if ($deletedDevices !== 1) {
                throw new RuntimeException('Staging API mutating smoke cleanup did not delete exactly one device.');
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

            return [
                'session_rows_deleted' => $deletedSessions,
                'device_rows_deleted' => $deletedDevices,
                'ownership_rows_deleted' => $deletedOwnership,
                'identity_rows_deleted' => $deletedIdentities,
                'user_row_deferred' => true,
            ];
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
            if ($deleted !== 1) {
                throw new RuntimeException('Staging API mutating smoke cleanup did not delete exactly one MGW user.');
            }
            return ['user_rows_deleted' => 1, 'identity_cleanup_complete' => true];
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
        $checks = [
            ['SELECT COUNT(*) FROM mgw_users WHERE mgw_id = :mgw_id', ['mgw_id' => $mgwId]],
            ['SELECT COUNT(*) FROM mgw_identities WHERE mgw_id = :mgw_id OR (provider = :provider AND provider_subject = :subject)', ['mgw_id' => $mgwId, 'provider' => $provider, 'subject' => $subject]],
            ['SELECT COUNT(*) FROM mgw_account_ownership WHERE mgw_id = :mgw_id OR legacy_user_id = :legacy_user_id OR account_ref = :account_ref', ['mgw_id' => $mgwId, 'legacy_user_id' => $legacyUserId, 'account_ref' => 'legacy:' . $legacyUserId]],
            ['SELECT COUNT(*) FROM mgw_devices WHERE mgw_id = :mgw_id', ['mgw_id' => $mgwId]],
            ['SELECT COUNT(*) FROM mgw_sessions WHERE mgw_id = :mgw_id OR session_key_hash = :session_key_hash', ['mgw_id' => $mgwId, 'session_key_hash' => $sessionHash]],
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
            'user_rows_remaining' => 0,
            'sensitive_identifiers_exposed' => false,
        ];
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
        if ($provider !== 'telegram'
            || preg_match('/\A9\d{17}\z/', $subject) !== 1
            || !hash_equals($subject, $legacyUserId)) {
            throw new RuntimeException('Staging API mutating smoke synthetic identity is invalid.');
        }
    }
}
