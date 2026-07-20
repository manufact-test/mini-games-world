<?php
declare(strict_types=1);

final class RuntimePrimaryAccountsModuleProjector implements RuntimePrimaryModuleProjectorInterface
{
    public function __construct(private DatabaseConnectionInterface $database) {}

    public function module(): string
    {
        return 'accounts';
    }

    public function project(array $snapshot, int $stateRevision, string $stateSha256): array
    {
        $source = $this->sourceUsers($snapshot);
        $createdUsers = 0;
        $createdIdentities = 0;
        $createdOwnerships = 0;
        $updatedUsers = 0;
        $unchangedUsers = 0;

        foreach ($source as $item) {
            $result = $this->synchronizeUser($item);
            $createdUsers += !empty($result['created_user']) ? 1 : 0;
            $createdIdentities += (int)($result['created_identity_count'] ?? 0);
            $createdOwnerships += !empty($result['created_ownership']) ? 1 : 0;
            $updatedUsers += !empty($result['updated_user']) ? 1 : 0;
            if (empty($result['created_user'])
                && (int)($result['created_identity_count'] ?? 0) === 0
                && empty($result['created_ownership'])
                && empty($result['updated_user'])) {
                $unchangedUsers++;
            }
        }

        $audit = $this->inspect($snapshot);
        if (empty($audit['ok'])) {
            throw new RuntimeException(
                'DB-primary accounts projection failed parity: '
                . implode('; ', array_map('strval', (array)($audit['blockers'] ?? [])))
            );
        }

        return $this->moduleReport($audit, $stateRevision, $stateSha256, false, [
            'source_user_count' => count($source),
            'created_user_count' => $createdUsers,
            'created_identity_count' => $createdIdentities,
            'created_ownership_count' => $createdOwnerships,
            'updated_user_count' => $updatedUsers,
            'unchanged_user_count' => $unchangedUsers,
        ]);
    }

    public function audit(array $snapshot, int $stateRevision, string $stateSha256): array
    {
        return $this->moduleReport(
            $this->inspect($snapshot),
            $stateRevision,
            $stateSha256,
            true,
            []
        );
    }

    private function synchronizeUser(array $item): array
    {
        return $this->database->transaction(function (DatabaseConnectionInterface $database) use ($item): array {
            $lock = $database->driver() === 'mysql' ? ' FOR UPDATE' : '';
            $legacyIdentityRows = $database->fetchAll(
                'SELECT mgw_id FROM mgw_identities
                 WHERE provider = :provider AND provider_subject = :provider_subject' . $lock,
                ['provider' => 'legacy_import', 'provider_subject' => $item['legacy_user_id']]
            );
            $realIdentityRows = $database->fetchAll(
                'SELECT mgw_id FROM mgw_identities
                 WHERE provider = :provider AND provider_subject = :provider_subject' . $lock,
                ['provider' => $item['provider'], 'provider_subject' => $item['provider_subject']]
            );
            $ownershipRows = $database->fetchAll(
                'SELECT account_ref, mgw_id, legacy_user_id, ownership_status
                 FROM mgw_account_ownership
                 WHERE account_ref = :account_ref OR legacy_user_id = :legacy_user_id' . $lock,
                [
                    'account_ref' => $item['account_ref'],
                    'legacy_user_id' => $item['legacy_user_id'],
                ]
            );

            $this->assertSingleRow($legacyIdentityRows, 'legacy identity', $item['legacy_user_id']);
            $this->assertSingleRow($realIdentityRows, 'provider identity', $item['legacy_user_id']);
            $this->assertSingleRow($ownershipRows, 'ownership', $item['legacy_user_id']);

            $mgwIds = [];
            foreach ([$legacyIdentityRows[0]['mgw_id'] ?? null, $realIdentityRows[0]['mgw_id'] ?? null, $ownershipRows[0]['mgw_id'] ?? null] as $mgwId) {
                $mgwId = trim((string)$mgwId);
                if ($mgwId !== '') $mgwIds[$mgwId] = true;
            }
            if (count($mgwIds) > 1) {
                throw new RuntimeException('Account projection identity mappings collide for legacy user ' . $item['legacy_user_id'] . '.');
            }

            $mgwId = $mgwIds === [] ? $this->newMgwId($database) : (string)array_key_first($mgwIds);
            if (!MgwIdGenerator::isValid($mgwId)) {
                throw new RuntimeException('Account projection resolved an invalid MGW ID for legacy user ' . $item['legacy_user_id'] . '.');
            }

            $userRows = $database->fetchAll(
                'SELECT mgw_id, status, display_name, username, avatar_provider, avatar_external_ref,
                        created_at_utc, updated_at_utc, last_seen_at_utc
                 FROM mgw_users WHERE mgw_id = :mgw_id' . $lock,
                ['mgw_id' => $mgwId]
            );
            $this->assertSingleRow($userRows, 'MGW user', $item['legacy_user_id']);

            $createdUser = false;
            $updatedUser = false;
            if ($userRows === []) {
                if ($mgwIds !== []) {
                    throw new RuntimeException('Account projection mapping points to a missing MGW user for legacy user ' . $item['legacy_user_id'] . '.');
                }
                $database->execute(
                    'INSERT INTO mgw_users (
                        mgw_id, status, display_name, username, avatar_provider, avatar_external_ref,
                        created_at_utc, updated_at_utc, last_seen_at_utc
                     ) VALUES (
                        :mgw_id, :status, :display_name, :username, :avatar_provider, :avatar_external_ref,
                        :created_at_utc, :updated_at_utc, :last_seen_at_utc
                     )',
                    $this->userParameters($mgwId, $item)
                );
                $createdUser = true;
            } elseif (!$this->userMatches($userRows[0], $item)) {
                if (!$this->sameTimestamp($userRows[0]['created_at_utc'] ?? null, $item['created_at_utc'])) {
                    throw new RuntimeException('Account projection created timestamp conflicts for legacy user ' . $item['legacy_user_id'] . '.');
                }
                $database->execute(
                    'UPDATE mgw_users SET
                        status = :status,
                        display_name = :display_name,
                        username = :username,
                        avatar_provider = :avatar_provider,
                        avatar_external_ref = :avatar_external_ref,
                        updated_at_utc = :updated_at_utc,
                        last_seen_at_utc = :last_seen_at_utc
                     WHERE mgw_id = :mgw_id',
                    $this->userParameters($mgwId, $item)
                );
                $updatedUser = true;
            }

            $createdIdentityCount = 0;
            if ($legacyIdentityRows === []) {
                $this->insertIdentity($database, $mgwId, 'legacy_import', $item['legacy_user_id'], $item);
                $createdIdentityCount++;
            }
            if ($realIdentityRows === []) {
                $this->insertIdentity($database, $mgwId, $item['provider'], $item['provider_subject'], $item);
                $createdIdentityCount++;
            }

            $createdOwnership = false;
            if ($ownershipRows === []) {
                $now = $this->now();
                $database->execute(
                    'INSERT INTO mgw_account_ownership (
                        account_ref, mgw_id, legacy_user_id, ownership_status,
                        source_type, source_ref, source_sha256, created_at_utc, verified_at_utc
                     ) VALUES (
                        :account_ref, :mgw_id, :legacy_user_id, :ownership_status,
                        :source_type, :source_ref, :source_sha256, :created_at_utc, :verified_at_utc
                     )',
                    [
                        'account_ref' => $item['account_ref'],
                        'mgw_id' => $mgwId,
                        'legacy_user_id' => $item['legacy_user_id'],
                        'ownership_status' => 'active',
                        'source_type' => 'runtime_primary_projection',
                        'source_ref' => $item['provider'] . ':' . $item['provider_subject'],
                        'source_sha256' => $item['source_record_sha256'],
                        'created_at_utc' => $now,
                        'verified_at_utc' => $now,
                    ]
                );
                $createdOwnership = true;
            } else {
                $ownership = $ownershipRows[0];
                if ((string)($ownership['account_ref'] ?? '') !== $item['account_ref']
                    || (string)($ownership['mgw_id'] ?? '') !== $mgwId
                    || (string)($ownership['legacy_user_id'] ?? '') !== $item['legacy_user_id']
                    || (string)($ownership['ownership_status'] ?? '') !== 'active') {
                    throw new RuntimeException('Account projection ownership conflicts for legacy user ' . $item['legacy_user_id'] . '.');
                }
                $database->execute(
                    'UPDATE mgw_account_ownership
                     SET source_sha256 = :source_sha256, verified_at_utc = :verified_at_utc
                     WHERE account_ref = :account_ref AND mgw_id = :mgw_id',
                    [
                        'source_sha256' => $item['source_record_sha256'],
                        'verified_at_utc' => $this->now(),
                        'account_ref' => $item['account_ref'],
                        'mgw_id' => $mgwId,
                    ]
                );
            }

            return [
                'created_user' => $createdUser,
                'updated_user' => $updatedUser,
                'created_identity_count' => $createdIdentityCount,
                'created_ownership' => $createdOwnership,
            ];
        });
    }

    private function inspect(array $snapshot): array
    {
        $source = $this->sourceUsers($snapshot);
        $expected = [];
        $actual = [];
        $blockers = [];
        $seenMgwIds = [];
        $seenAccountRefs = [];

        foreach ($source as $legacyUserId => $item) {
            $ownershipRows = $this->database->fetchAll(
                'SELECT account_ref, mgw_id, legacy_user_id, ownership_status
                 FROM mgw_account_ownership WHERE legacy_user_id = :legacy_user_id',
                ['legacy_user_id' => $legacyUserId]
            );
            if (count($ownershipRows) !== 1) {
                $blockers[] = 'Account ownership is missing or ambiguous for legacy user ' . $legacyUserId . '.';
                $expected[$legacyUserId] = $item + ['mgw_id' => '', 'resolved_account_ref' => $item['account_ref']];
                continue;
            }
            $ownership = $ownershipRows[0];
            $accountRef = trim((string)($ownership['account_ref'] ?? ''));
            $mgwId = trim((string)($ownership['mgw_id'] ?? ''));
            if ($accountRef !== $item['account_ref']
                || (string)($ownership['legacy_user_id'] ?? '') !== $legacyUserId
                || (string)($ownership['ownership_status'] ?? '') !== 'active'
                || !MgwIdGenerator::isValid($mgwId)) {
                $blockers[] = 'Account ownership is invalid for legacy user ' . $legacyUserId . '.';
            }
            if (isset($seenMgwIds[$mgwId]) || isset($seenAccountRefs[$accountRef])) {
                $blockers[] = 'Account ownership is not one-to-one for legacy user ' . $legacyUserId . '.';
            }
            $seenMgwIds[$mgwId] = true;
            $seenAccountRefs[$accountRef] = true;

            $userRows = $this->database->fetchAll(
                'SELECT status, display_name, username, avatar_provider, avatar_external_ref,
                        created_at_utc, updated_at_utc, last_seen_at_utc
                 FROM mgw_users WHERE mgw_id = :mgw_id',
                ['mgw_id' => $mgwId]
            );
            $realIdentityRows = $this->database->fetchAll(
                'SELECT mgw_id, provider, provider_subject
                 FROM mgw_identities WHERE provider = :provider AND provider_subject = :provider_subject',
                ['provider' => $item['provider'], 'provider_subject' => $item['provider_subject']]
            );
            $legacyIdentityRows = $this->database->fetchAll(
                'SELECT mgw_id, provider, provider_subject
                 FROM mgw_identities WHERE provider = :provider AND provider_subject = :provider_subject',
                ['provider' => 'legacy_import', 'provider_subject' => $legacyUserId]
            );
            if (count($userRows) !== 1
                || count($realIdentityRows) !== 1
                || count($legacyIdentityRows) !== 1
                || (string)($realIdentityRows[0]['mgw_id'] ?? '') !== $mgwId
                || (string)($legacyIdentityRows[0]['mgw_id'] ?? '') !== $mgwId) {
                $blockers[] = 'Account user or identity links are incomplete for legacy user ' . $legacyUserId . '.';
            }

            $expected[$legacyUserId] = [
                'legacy_user_id' => $legacyUserId,
                'account_ref' => $item['account_ref'],
                'mgw_id' => $mgwId,
                'ownership_status' => 'active',
                'provider' => $item['provider'],
                'provider_subject' => $item['provider_subject'],
                'status' => 'active',
                'display_name' => $item['display_name'],
                'username' => $item['username'],
                'avatar_provider' => $item['avatar_provider'],
                'avatar_external_ref' => $item['avatar_external_ref'],
                'created_at_utc' => $item['created_at_utc'],
                'updated_at_utc' => $item['updated_at_utc'],
                'last_seen_at_utc' => $item['last_seen_at_utc'],
            ];
            $user = $userRows[0] ?? [];
            $actual[$legacyUserId] = [
                'legacy_user_id' => (string)($ownership['legacy_user_id'] ?? ''),
                'account_ref' => $accountRef,
                'mgw_id' => $mgwId,
                'ownership_status' => (string)($ownership['ownership_status'] ?? ''),
                'provider' => (string)($realIdentityRows[0]['provider'] ?? ''),
                'provider_subject' => (string)($realIdentityRows[0]['provider_subject'] ?? ''),
                'status' => (string)($user['status'] ?? ''),
                'display_name' => (string)($user['display_name'] ?? ''),
                'username' => $this->nullable($user['username'] ?? null),
                'avatar_provider' => $this->nullable($user['avatar_provider'] ?? null),
                'avatar_external_ref' => $this->nullable($user['avatar_external_ref'] ?? null),
                'created_at_utc' => $this->normalizedTimestamp($user['created_at_utc'] ?? null),
                'updated_at_utc' => $this->normalizedTimestamp($user['updated_at_utc'] ?? null),
                'last_seen_at_utc' => $this->normalizedTimestamp($user['last_seen_at_utc'] ?? null),
            ];
        }

        $sourceIds = array_keys($source);
        sort($sourceIds, SORT_STRING);
        $databaseIds = [];
        foreach ($this->database->fetchAll(
            'SELECT legacy_user_id FROM mgw_account_ownership WHERE legacy_user_id IS NOT NULL'
        ) as $row) {
            $legacyId = trim((string)($row['legacy_user_id'] ?? ''));
            if ($legacyId !== '') $databaseIds[$legacyId] = true;
        }
        $databaseIds = array_keys($databaseIds);
        sort($databaseIds, SORT_STRING);
        $extra = array_values(array_diff($databaseIds, $sourceIds));
        if ($extra !== []) {
            $blockers[] = 'Account database contains ownership rows outside the current snapshot.';
        }

        ksort($expected, SORT_STRING);
        ksort($actual, SORT_STRING);
        $sourceFingerprint = hash('sha256', $this->canonicalJson($expected));
        $databaseFingerprint = hash('sha256', $this->canonicalJson($actual));
        if (!hash_equals($sourceFingerprint, $databaseFingerprint)) {
            $blockers[] = 'Account database projection differs from the compatibility snapshot.';
        }
        $blockers = array_values(array_unique($blockers));

        return [
            'ok' => $blockers === [],
            'parity' => $blockers === [],
            'source_fingerprint' => $sourceFingerprint,
            'database_fingerprint' => $databaseFingerprint,
            'source_user_count' => count($source),
            'database_user_count' => count($databaseIds),
            'extra_ownership_count' => count($extra),
            'blockers' => $blockers,
        ];
    }

    private function moduleReport(
        array $audit,
        int $stateRevision,
        string $stateSha256,
        bool $readOnly,
        array $projectSummary
    ): array {
        return [
            'ok' => !empty($audit['ok']),
            'parity' => !empty($audit['parity']),
            'read_only' => $readOnly,
            'module' => 'accounts',
            'state_revision' => $stateRevision,
            'state_sha256' => strtolower(trim($stateSha256)),
            'source_fingerprint' => (string)($audit['source_fingerprint'] ?? ''),
            'database_fingerprint' => (string)($audit['database_fingerprint'] ?? ''),
            'summary' => $projectSummary + [
                'source_user_count' => (int)($audit['source_user_count'] ?? 0),
                'database_user_count' => (int)($audit['database_user_count'] ?? 0),
                'extra_ownership_count' => (int)($audit['extra_ownership_count'] ?? 0),
            ],
            'blockers' => array_values((array)($audit['blockers'] ?? [])),
        ];
    }

    private function sourceUsers(array $snapshot): array
    {
        $users = is_array($snapshot['users'] ?? null) ? $snapshot['users'] : [];
        $items = [];
        foreach ($users as $key => $record) {
            if (!is_array($record)) {
                throw new RuntimeException('Account projection user record is not an object.');
            }
            $legacyId = trim((string)($record['id'] ?? (is_string($key) || is_int($key) ? $key : '')));
            if ($legacyId === '' || isset($items[$legacyId])) {
                throw new RuntimeException('Account projection user ID is missing or duplicated.');
            }
            $provider = !empty($record['is_dev_user']) ? 'development' : 'telegram';
            $providerSubject = trim((string)($record['telegram_id'] ?? $record['id'] ?? $legacyId));
            if ($providerSubject === '') {
                throw new RuntimeException('Account projection provider subject is missing for legacy user ' . $legacyId . '.');
            }
            $displayName = $this->text($record['first_name'] ?? $record['username'] ?? 'Игрок', 80, 'Игрок');
            $username = $this->nullableText($record['username'] ?? null, 80);
            $avatar = $this->nullableText(
                $record['photo_url'] ?? $record['avatar_url'] ?? $record['avatar'] ?? null,
                2048
            );
            $createdAt = $this->sourceTimestamp(
                $record['registered_at'] ?? $record['created_at'] ?? $record['last_seen_at'] ?? null
            );
            $lastSeenAt = $this->sourceTimestamp(
                $record['last_seen_at'] ?? $record['updated_at'] ?? $createdAt,
                $createdAt
            );
            $items[$legacyId] = [
                'legacy_user_id' => $legacyId,
                'account_ref' => 'legacy:' . $legacyId,
                'provider' => $provider,
                'provider_subject' => $providerSubject,
                'display_name' => $displayName,
                'username' => $username,
                'avatar_provider' => $avatar === null ? null : $provider,
                'avatar_external_ref' => $avatar,
                'created_at_utc' => $createdAt,
                'updated_at_utc' => $lastSeenAt,
                'last_seen_at_utc' => $lastSeenAt,
                'source_record_sha256' => hash('sha256', $this->canonicalJson($record)),
            ];
        }
        ksort($items, SORT_STRING);
        return $items;
    }

    private function insertIdentity(
        DatabaseConnectionInterface $database,
        string $mgwId,
        string $provider,
        string $subject,
        array $item
    ): void {
        $database->execute(
            'INSERT INTO mgw_identities (
                mgw_id, provider, provider_subject, provider_username,
                linked_at_utc, last_authenticated_at_utc
             ) VALUES (
                :mgw_id, :provider, :provider_subject, :provider_username,
                :linked_at_utc, :last_authenticated_at_utc
             )',
            [
                'mgw_id' => $mgwId,
                'provider' => $provider,
                'provider_subject' => $subject,
                'provider_username' => $item['username'],
                'linked_at_utc' => $item['created_at_utc'],
                'last_authenticated_at_utc' => $item['last_seen_at_utc'],
            ]
        );
    }

    private function userParameters(string $mgwId, array $item): array
    {
        return [
            'mgw_id' => $mgwId,
            'status' => 'active',
            'display_name' => $item['display_name'],
            'username' => $item['username'],
            'avatar_provider' => $item['avatar_provider'],
            'avatar_external_ref' => $item['avatar_external_ref'],
            'created_at_utc' => $item['created_at_utc'],
            'updated_at_utc' => $item['updated_at_utc'],
            'last_seen_at_utc' => $item['last_seen_at_utc'],
        ];
    }

    private function userMatches(array $row, array $item): bool
    {
        return (string)($row['status'] ?? '') === 'active'
            && (string)($row['display_name'] ?? '') === $item['display_name']
            && $this->nullable($row['username'] ?? null) === $item['username']
            && $this->nullable($row['avatar_provider'] ?? null) === $item['avatar_provider']
            && $this->nullable($row['avatar_external_ref'] ?? null) === $item['avatar_external_ref']
            && $this->sameTimestamp($row['created_at_utc'] ?? null, $item['created_at_utc'])
            && $this->sameTimestamp($row['updated_at_utc'] ?? null, $item['updated_at_utc'])
            && $this->sameTimestamp($row['last_seen_at_utc'] ?? null, $item['last_seen_at_utc']);
    }

    private function newMgwId(DatabaseConnectionInterface $database): string
    {
        for ($attempt = 0; $attempt < 8; $attempt++) {
            $candidate = MgwIdGenerator::generate();
            $rows = $database->fetchAll('SELECT mgw_id FROM mgw_users WHERE mgw_id = :mgw_id', ['mgw_id' => $candidate]);
            if ($rows === []) return $candidate;
        }
        throw new RuntimeException('Account projection could not allocate a unique MGW ID.');
    }

    private function assertSingleRow(array $rows, string $label, string $legacyId): void
    {
        if (count($rows) > 1) {
            throw new RuntimeException('Account projection found multiple ' . $label . ' rows for legacy user ' . $legacyId . '.');
        }
    }

    private function text(mixed $value, int $maxLength, string $fallback): string
    {
        $text = trim((string)$value);
        if ($text === '') $text = $fallback;
        return mb_substr($text, 0, $maxLength);
    }

    private function nullableText(mixed $value, int $maxLength): ?string
    {
        $text = trim((string)$value);
        return $text === '' ? null : mb_substr($text, 0, $maxLength);
    }

    private function nullable(mixed $value): ?string
    {
        $text = trim((string)$value);
        return $text === '' ? null : $text;
    }

    private function sourceTimestamp(mixed $value, ?string $fallback = null): string
    {
        $value = trim((string)$value);
        if ($value !== '' && strtotime($value) !== false) {
            return gmdate(DATE_ATOM, (int)strtotime($value));
        }
        if ($fallback !== null && strtotime($fallback) !== false) {
            return gmdate(DATE_ATOM, (int)strtotime($fallback));
        }
        return '1970-01-01T00:00:00+00:00';
    }

    private function normalizedTimestamp(mixed $value): string
    {
        $value = trim((string)$value);
        return $value !== '' && strtotime($value) !== false
            ? gmdate(DATE_ATOM, (int)strtotime($value))
            : '';
    }

    private function sameTimestamp(mixed $left, mixed $right): bool
    {
        return $this->normalizedTimestamp($left) === $this->normalizedTimestamp($right);
    }

    private function now(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');
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
