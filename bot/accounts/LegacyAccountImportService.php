<?php
declare(strict_types=1);

final class LegacyAccountImportService
{
    private const META_KEY = 'legacy_account_import_v1';
    private const STAGING_PROVIDER = 'legacy_import';

    public function __construct(
        private StorageAdapterInterface $storage,
        private DatabaseConnectionInterface $database
    ) {}

    public function preview(): array
    {
        return $this->inspect();
    }

    public function run(): array
    {
        $plan = $this->inspect();
        if (!$plan['ready']) {
            throw new RuntimeException('Legacy account import is not ready: ' . implode('; ', $plan['blocking_reasons']));
        }

        $source = $this->loadSource();
        if (!hash_equals((string)$plan['source_fingerprint'], $source['fingerprint'])) {
            throw new RuntimeException('Legacy user source changed before account import started.');
        }

        $this->writeMeta('started', $source['fingerprint'], [
            'source_user_count' => count($source['items']),
            'started_at_utc' => $this->now(),
        ]);

        $createdUsers = 0;
        $createdLinks = 0;
        $updatedUsers = 0;
        $unchangedUsers = 0;
        $reusedProviderIdentities = 0;

        foreach ($source['items'] as $item) {
            $result = $this->database->transaction(function (DatabaseConnectionInterface $database) use ($item): array {
                $state = $this->itemState($item, $database);
                if ($state['state'] === 'conflict') {
                    throw new RuntimeException($item['legacy_user_id'] . ': ' . ($state['reason'] ?? 'account conflict'));
                }

                $mgwId = $state['mgw_id'];
                $createdUser = false;
                $createdLink = false;
                $updatedUser = false;

                if ($mgwId === null) {
                    $mgwId = $this->newMgwId($database);
                    $this->insertUser($database, $mgwId, $item);
                    $createdUser = true;
                } elseif (empty($state['user_exists'])) {
                    throw new RuntimeException($item['legacy_user_id'] . ': mapped MGW user row is missing.');
                } elseif (!empty($state['profile_update_required'])) {
                    $this->updateUser($database, $mgwId, $item);
                    $updatedUser = true;
                }

                if (empty($state['legacy_link_exists'])) {
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
                            'provider' => self::STAGING_PROVIDER,
                            'provider_subject' => $item['legacy_user_id'],
                            'provider_username' => $item['username'],
                            'linked_at_utc' => $item['created_at_utc'],
                            'last_authenticated_at_utc' => $item['last_seen_at_utc'],
                        ]
                    );
                    $createdLink = true;
                }

                $verified = $this->itemState($item, $database);
                if ($verified['state'] !== 'unchanged') {
                    throw new RuntimeException($item['legacy_user_id'] . ': imported account failed verification.');
                }

                return [
                    'created_user' => $createdUser,
                    'created_link' => $createdLink,
                    'updated_user' => $updatedUser,
                    'reused_provider_identity' => !empty($state['real_identity_exists']),
                ];
            });

            if ($result['created_user']) $createdUsers++;
            if ($result['created_link']) $createdLinks++;
            if ($result['updated_user']) $updatedUsers++;
            if (!$result['created_user'] && !$result['created_link'] && !$result['updated_user']) $unchangedUsers++;
            if ($result['reused_provider_identity']) $reusedProviderIdentities++;
        }

        $current = $this->loadSource();
        if (!hash_equals($source['fingerprint'], $current['fingerprint'])) {
            throw new RuntimeException('Legacy user source changed during account import.');
        }

        $verification = $this->verify($current['items']);
        if (!$verification['ok']) {
            throw new RuntimeException('Legacy account import failed final verification.');
        }

        $completedAt = $this->now();
        $details = [
            'source_user_count' => count($current['items']),
            'created_user_count' => $createdUsers,
            'created_legacy_link_count' => $createdLinks,
            'updated_user_count' => $updatedUsers,
            'unchanged_user_count' => $unchangedUsers,
            'reused_provider_identity_count' => $reusedProviderIdentities,
            'completed_at_utc' => $completedAt,
        ];
        $this->writeMeta('completed', $current['fingerprint'], $details);

        return [
            'ok' => true,
            'dry_run' => false,
            'status' => 'completed',
            'source_fingerprint' => $current['fingerprint'],
            'source_user_count' => count($current['items']),
            'created_user_count' => $createdUsers,
            'created_legacy_link_count' => $createdLinks,
            'updated_user_count' => $updatedUsers,
            'unchanged_user_count' => $unchangedUsers,
            'reused_provider_identity_count' => $reusedProviderIdentities,
            'verification' => $verification,
            'completed_at_utc' => $completedAt,
        ];
    }

    private function inspect(): array
    {
        $source = $this->loadSource();
        $meta = $this->loadMeta();
        $blocking = [];
        $plannedUsers = 0;
        $plannedLinks = 0;
        $plannedUpdates = 0;
        $unchanged = 0;
        $conflicts = 0;
        $reusedProviderIdentities = 0;
        $samples = [];

        if ($source['items'] === []) {
            $blocking[] = 'No legacy users were found.';
        }
        if ($meta !== null && !hash_equals((string)$meta['source_fingerprint'], $source['fingerprint'])) {
            $blocking[] = 'Account import metadata belongs to a different source fingerprint.';
        }

        $expectedSubjects = [];
        foreach ($source['items'] as $item) {
            $expectedSubjects[$item['legacy_user_id']] = true;
            $state = $this->itemState($item, $this->database);
            if ($state['state'] === 'conflict') {
                $conflicts++;
                $blocking[] = $item['legacy_user_id'] . ': ' . ($state['reason'] ?? 'account conflict');
            } elseif ($state['state'] === 'create') {
                if (empty($state['user_exists'])) $plannedUsers++;
                if (empty($state['legacy_link_exists'])) $plannedLinks++;
                if (!empty($state['profile_update_required'])) $plannedUpdates++;
            } else {
                $unchanged++;
            }
            if (!empty($state['real_identity_exists'])) $reusedProviderIdentities++;

            if (count($samples) < 50) {
                $samples[] = [
                    'legacy_user_id' => $item['legacy_user_id'],
                    'provider' => $item['real_provider'],
                    'provider_subject' => $item['provider_subject'],
                    'mgw_id' => $state['mgw_id'],
                    'state' => $state['state'],
                    'reason' => $state['reason'],
                ];
            }
        }

        $unmanagedLinks = 0;
        foreach ($this->database->fetchAll(
            'SELECT provider_subject FROM mgw_identities WHERE provider = :provider',
            ['provider' => self::STAGING_PROVIDER]
        ) as $row) {
            $subject = trim((string)($row['provider_subject'] ?? ''));
            if ($subject !== '' && !isset($expectedSubjects[$subject])) $unmanagedLinks++;
        }
        if ($unmanagedLinks > 0) {
            $blocking[] = 'Legacy import identities exist outside the current source plan.';
        }

        $status = $meta['status'] ?? 'not_started';
        if ($status === 'completed' && $blocking === [] && ($plannedUsers + $plannedLinks + $plannedUpdates) > 0) {
            $blocking[] = 'Completed account import is missing expected target state.';
        }

        return [
            'ok' => $blocking === [],
            'dry_run' => true,
            'ready' => $blocking === [],
            'status' => $status,
            'source_fingerprint' => $source['fingerprint'],
            'source_user_count' => count($source['items']),
            'planned_user_create_count' => $plannedUsers,
            'planned_legacy_link_create_count' => $plannedLinks,
            'planned_user_update_count' => $plannedUpdates,
            'unchanged_user_count' => $unchanged,
            'reused_provider_identity_count' => $reusedProviderIdentities,
            'conflict_count' => $conflicts,
            'unmanaged_legacy_link_count' => $unmanagedLinks,
            'blocking_reasons' => array_values(array_unique($blocking)),
            'items' => $samples,
            'meta' => $meta,
        ];
    }

    private function loadSource(): array
    {
        $users = $this->storage->readOnly(static function (array $data): array {
            return is_array($data['users'] ?? null) ? $data['users'] : [];
        });
        $shadowRows = $this->database->fetchAll(
            "SELECT entity_key, payload_json, payload_sha256, source_updated_at_utc, synced_at_utc
             FROM mgw_legacy_realtime_shadow
             WHERE entity_type = 'economy_user_balance' ORDER BY entity_key"
        );
        $shadows = [];
        foreach ($shadowRows as $row) {
            $legacyId = trim((string)($row['entity_key'] ?? ''));
            if ($legacyId === '') throw new RuntimeException('Economy shadow user has no entity key.');
            $payloadJson = (string)($row['payload_json'] ?? '');
            try {
                $payload = json_decode($payloadJson, true, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                throw new RuntimeException('Economy shadow payload is invalid for ' . $legacyId . '.');
            }
            if (!is_array($payload)) throw new RuntimeException('Economy shadow payload is invalid for ' . $legacyId . '.');
            $canonical = LedgerIntegrity::canonicalJson($payload);
            $storedHash = strtolower(trim((string)($row['payload_sha256'] ?? '')));
            if (preg_match('/^[a-f0-9]{64}$/', $storedHash) !== 1
                || !hash_equals($storedHash, hash('sha256', $canonical))) {
                throw new RuntimeException('Economy shadow hash mismatch for ' . $legacyId . '.');
            }
            $shadows[$legacyId] = ['payload' => $payload, 'row' => $row];
        }

        $items = [];
        $fingerprintParts = [];
        foreach ($users as $sourceKey => $record) {
            if (!is_array($record)) throw new RuntimeException('Legacy user record is not an object.');
            $legacyId = trim((string)($record['id'] ?? (is_string($sourceKey) ? $sourceKey : '')));
            if ($legacyId === '') throw new RuntimeException('Legacy user record has no stable ID.');
            if (isset($items[$legacyId])) throw new RuntimeException('Duplicate legacy user ID: ' . $legacyId . '.');
            if (!isset($shadows[$legacyId])) throw new RuntimeException('Economy shadow is missing for legacy user ' . $legacyId . '.');

            $recordJson = LedgerIntegrity::canonicalJson($record);
            $recordHash = hash('sha256', $recordJson);
            $shadowPayload = $shadows[$legacyId]['payload'];
            $shadowRecordHash = strtolower(trim((string)($shadowPayload['source_record_sha256'] ?? '')));
            if (!hash_equals($recordHash, $shadowRecordHash)) {
                throw new RuntimeException('Legacy user JSON differs from the verified economy shadow for ' . $legacyId . '.');
            }

            $isDev = !empty($record['is_dev_user']);
            $realProvider = $isDev ? 'development' : 'telegram';
            $providerSubject = trim((string)($record['telegram_id'] ?? $record['id'] ?? $legacyId));
            if ($providerSubject === '') throw new RuntimeException('Legacy provider subject is missing for ' . $legacyId . '.');
            $displayName = $this->text($record['first_name'] ?? $record['username'] ?? 'Игрок', 80, 'Игрок');
            $username = $this->nullableText($record['username'] ?? null, 80);
            $avatar = $this->nullableText(
                $record['photo_url'] ?? $record['avatar_url'] ?? $record['avatar'] ?? null,
                2048
            );
            $shadowRow = $shadows[$legacyId]['row'];
            $createdAt = $this->timestamp(
                $record['registered_at'] ?? $shadowPayload['registered_at'] ?? null,
                $shadowRow['source_updated_at_utc'] ?? null,
                $shadowRow['synced_at_utc'] ?? null
            );
            $lastSeenAt = $this->timestamp(
                $record['last_seen_at'] ?? $shadowPayload['last_seen_at'] ?? null,
                $shadowRow['source_updated_at_utc'] ?? null,
                $shadowRow['synced_at_utc'] ?? null
            );

            $items[$legacyId] = [
                'legacy_user_id' => $legacyId,
                'real_provider' => $realProvider,
                'provider_subject' => $providerSubject,
                'display_name' => $displayName,
                'username' => $username,
                'avatar_provider' => $avatar === null ? null : $realProvider,
                'avatar_external_ref' => $avatar,
                'created_at_utc' => $createdAt,
                'updated_at_utc' => $lastSeenAt,
                'last_seen_at_utc' => $lastSeenAt,
                'source_record_sha256' => $recordHash,
            ];
            $fingerprintParts[] = $legacyId . "\0" . $recordHash;
        }

        foreach (array_keys($shadows) as $legacyId) {
            if (!isset($items[$legacyId])) {
                throw new RuntimeException('Verified economy shadow has no matching legacy user JSON record: ' . $legacyId . '.');
            }
        }

        ksort($items, SORT_STRING);
        sort($fingerprintParts, SORT_STRING);
        return [
            'fingerprint' => hash('sha256', implode("\n", $fingerprintParts)),
            'items' => array_values($items),
        ];
    }

    private function itemState(array $item, DatabaseConnectionInterface $database): array
    {
        $legacyRows = $database->fetchAll(
            'SELECT mgw_id FROM mgw_identities WHERE provider = :provider AND provider_subject = :subject',
            ['provider' => self::STAGING_PROVIDER, 'subject' => $item['legacy_user_id']]
        );
        $realRows = $database->fetchAll(
            'SELECT mgw_id FROM mgw_identities WHERE provider = :provider AND provider_subject = :subject',
            ['provider' => $item['real_provider'], 'subject' => $item['provider_subject']]
        );
        $legacyMgwId = $legacyRows === [] ? null : trim((string)$legacyRows[0]['mgw_id']);
        $realMgwId = $realRows === [] ? null : trim((string)$realRows[0]['mgw_id']);
        if ($legacyMgwId !== null && $realMgwId !== null && $legacyMgwId !== $realMgwId) {
            return $this->state('conflict', null, false, true, true, false, 'Legacy and provider identities map to different MGW IDs.');
        }

        $mgwId = $legacyMgwId ?? $realMgwId;
        if ($mgwId === null || $mgwId === '') {
            return $this->state('create', null, false, false, false, false, null);
        }
        if (!MgwIdGenerator::isValid($mgwId)) {
            return $this->state('conflict', $mgwId, false, $legacyMgwId !== null, $realMgwId !== null, false, 'Mapped MGW ID has an invalid format.');
        }

        $userRows = $database->fetchAll(
            'SELECT status, display_name, username, avatar_provider, avatar_external_ref,
                    created_at_utc, updated_at_utc, last_seen_at_utc
             FROM mgw_users WHERE mgw_id = :mgw_id',
            ['mgw_id' => $mgwId]
        );
        if ($userRows === []) {
            return $this->state('conflict', $mgwId, false, $legacyMgwId !== null, $realMgwId !== null, false, 'Mapped MGW user row is missing.');
        }
        $update = !$this->userMatches($userRows[0], $item);
        $unchanged = $legacyMgwId !== null && !$update;
        return $this->state(
            $unchanged ? 'unchanged' : 'create',
            $mgwId,
            true,
            $legacyMgwId !== null,
            $realMgwId !== null,
            $update,
            null
        );
    }

    private function state(
        string $state,
        ?string $mgwId,
        bool $userExists,
        bool $legacyLinkExists,
        bool $realIdentityExists,
        bool $profileUpdateRequired,
        ?string $reason
    ): array {
        return compact(
            'state', 'mgwId', 'userExists', 'legacyLinkExists',
            'realIdentityExists', 'profileUpdateRequired', 'reason'
        ) + [
            'mgw_id' => $mgwId,
            'user_exists' => $userExists,
            'legacy_link_exists' => $legacyLinkExists,
            'real_identity_exists' => $realIdentityExists,
            'profile_update_required' => $profileUpdateRequired,
        ];
    }

    private function insertUser(DatabaseConnectionInterface $database, string $mgwId, array $item): void
    {
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
    }

    private function updateUser(DatabaseConnectionInterface $database, string $mgwId, array $item): void
    {
        $database->execute(
            'UPDATE mgw_users SET status = :status, display_name = :display_name,
                username = :username, avatar_provider = :avatar_provider,
                avatar_external_ref = :avatar_external_ref, created_at_utc = :created_at_utc,
                updated_at_utc = :updated_at_utc, last_seen_at_utc = :last_seen_at_utc
             WHERE mgw_id = :mgw_id',
            $this->userParameters($mgwId, $item)
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
            && $this->nullable((string)($row['username'] ?? '')) === $item['username']
            && $this->nullable((string)($row['avatar_provider'] ?? '')) === $item['avatar_provider']
            && $this->nullable((string)($row['avatar_external_ref'] ?? '')) === $item['avatar_external_ref']
            && $this->sameTimestamp($row['created_at_utc'] ?? null, $item['created_at_utc'])
            && $this->sameTimestamp($row['updated_at_utc'] ?? null, $item['updated_at_utc'])
            && $this->sameTimestamp($row['last_seen_at_utc'] ?? null, $item['last_seen_at_utc']);
    }

    private function verify(array $items): array
    {
        $errors = [];
        $mgwIds = [];
        foreach ($items as $item) {
            $state = $this->itemState($item, $this->database);
            if ($state['state'] !== 'unchanged') {
                $errors[] = $item['legacy_user_id'] . ': ' . ($state['reason'] ?? 'target is not unchanged');
                continue;
            }
            $mgwIds[] = $state['mgw_id'];
        }
        return [
            'ok' => $errors === [],
            'verified_user_count' => count($items) - count($errors),
            'unique_mgw_id_count' => count(array_unique(array_filter($mgwIds))),
            'error_count' => count($errors),
            'errors' => array_slice($errors, 0, 50),
        ];
    }

    private function newMgwId(DatabaseConnectionInterface $database): string
    {
        for ($attempt = 0; $attempt < 8; $attempt++) {
            $candidate = MgwIdGenerator::generate();
            if ((int)$database->fetchValue('SELECT COUNT(*) FROM mgw_users WHERE mgw_id = :mgw_id', ['mgw_id' => $candidate]) === 0) {
                return $candidate;
            }
        }
        throw new RuntimeException('Unable to allocate a unique MGW ID for legacy account import.');
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
            throw new RuntimeException('Legacy account import metadata is invalid.');
        }
        if (!is_array($value)) throw new RuntimeException('Legacy account import metadata is invalid.');
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
            'UPDATE mgw_meta SET meta_value = :meta_value, updated_at_utc = :updated_at_utc WHERE meta_key = :meta_key',
            ['meta_value' => $value, 'updated_at_utc' => $updatedAt, 'meta_key' => self::META_KEY]
        );
        if ($updated === 0) {
            try {
                $this->database->execute(
                    'INSERT INTO mgw_meta (meta_key, meta_value, updated_at_utc) VALUES (:meta_key, :meta_value, :updated_at_utc)',
                    ['meta_key' => self::META_KEY, 'meta_value' => $value, 'updated_at_utc' => $updatedAt]
                );
            } catch (Throwable) {
                $this->database->execute(
                    'UPDATE mgw_meta SET meta_value = :meta_value, updated_at_utc = :updated_at_utc WHERE meta_key = :meta_key',
                    ['meta_value' => $value, 'updated_at_utc' => $updatedAt, 'meta_key' => self::META_KEY]
                );
            }
        }
    }

    private function timestamp(mixed ...$values): string
    {
        foreach ($values as $value) {
            $value = trim((string)($value ?? ''));
            if ($value === '') continue;
            try {
                return (new DateTimeImmutable($value))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
            } catch (Throwable) {
                continue;
            }
        }
        throw new RuntimeException('Legacy user record has no valid stable timestamp.');
    }

    private function sameTimestamp(mixed $left, string $right): bool
    {
        try {
            return (new DateTimeImmutable((string)$left))->format('Y-m-d H:i:s.u')
                === (new DateTimeImmutable($right))->format('Y-m-d H:i:s.u');
        } catch (Throwable) {
            return false;
        }
    }

    private function text(mixed $value, int $max, string $fallback): string
    {
        $value = trim(preg_replace('/[\x00-\x1F\x7F]/u', '', (string)$value) ?? '');
        if ($value === '') $value = $fallback;
        return function_exists('mb_substr') ? mb_substr($value, 0, $max) : substr($value, 0, $max);
    }

    private function nullableText(mixed $value, int $max): ?string
    {
        $value = trim(preg_replace('/[\x00-\x1F\x7F]/u', '', (string)($value ?? '')) ?? '');
        if ($value === '') return null;
        return function_exists('mb_substr') ? mb_substr($value, 0, $max) : substr($value, 0, $max);
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
