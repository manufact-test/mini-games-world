<?php
declare(strict_types=1);

final class LegacyAccountOwnershipLinkService
{
    private const META_KEY = 'legacy_account_ownership_link_v1';
    private const ACCOUNT_IMPORT_META_KEY = 'legacy_account_import_v1';
    private const OPENING_IMPORT_META_KEY = 'legacy_economy_opening_import_v1';
    private const STAGING_PROVIDER = 'legacy_import';
    private const OWNERSHIP_SOURCE_TYPE = 'legacy_json';

    public function __construct(
        private StorageAdapterInterface $storage,
        private DatabaseConnectionInterface $database,
        private LedgerIntegrityVerifier $ledgerVerifier
    ) {}

    public function preview(): array
    {
        return $this->inspect();
    }

    public function run(): array
    {
        $plan = $this->inspect();
        if (!$plan['ready']) {
            throw new RuntimeException(
                'Legacy account ownership link is not ready: ' . implode('; ', $plan['blocking_reasons'])
            );
        }

        $source = $this->loadSource();
        if (!hash_equals((string)$plan['source_fingerprint'], $source['fingerprint'])) {
            throw new RuntimeException('Legacy user source changed before ownership linking started.');
        }

        $this->writeMeta('started', $source['fingerprint'], [
            'source_user_count' => count($source['items']),
            'started_at_utc' => $this->now(),
        ]);

        $createdOwnerships = 0;
        $createdProviderIdentities = 0;
        $reusedProviderIdentities = 0;
        $unchangedUsers = 0;

        foreach ($source['items'] as $item) {
            $result = $this->database->transaction(function (DatabaseConnectionInterface $database) use ($item): array {
                $state = $this->itemState($item, $database, true);
                if ($state['state'] === 'conflict') {
                    throw new RuntimeException(
                        $item['legacy_user_id'] . ': ' . implode('; ', $state['reasons'])
                    );
                }

                $createdOwnership = false;
                $createdProviderIdentity = false;
                $reusedProviderIdentity = !empty($state['provider_identity_exists']);

                if (empty($state['ownership_exists'])) {
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
                            'mgw_id' => $state['mgw_id'],
                            'legacy_user_id' => $item['legacy_user_id'],
                            'ownership_status' => 'active',
                            'source_type' => self::OWNERSHIP_SOURCE_TYPE,
                            'source_ref' => $item['source_ref'],
                            'source_sha256' => $item['source_record_sha256'],
                            'created_at_utc' => $now,
                            'verified_at_utc' => $now,
                        ]
                    );
                    $createdOwnership = true;
                }

                if (empty($state['provider_identity_exists'])) {
                    $database->execute(
                        'INSERT INTO mgw_identities (
                            mgw_id, provider, provider_subject, provider_username,
                            linked_at_utc, last_authenticated_at_utc
                         ) VALUES (
                            :mgw_id, :provider, :provider_subject, :provider_username,
                            :linked_at_utc, :last_authenticated_at_utc
                         )',
                        [
                            'mgw_id' => $state['mgw_id'],
                            'provider' => $item['provider'],
                            'provider_subject' => $item['provider_subject'],
                            'provider_username' => $item['username'],
                            'linked_at_utc' => $item['created_at_utc'],
                            'last_authenticated_at_utc' => $item['last_seen_at_utc'],
                        ]
                    );
                    $createdProviderIdentity = true;
                }

                $verified = $this->itemState($item, $database, true);
                if ($verified['state'] !== 'unchanged') {
                    throw new RuntimeException(
                        $item['legacy_user_id'] . ': ownership/provider link failed verification: '
                        . implode('; ', $verified['reasons'])
                    );
                }

                return [
                    'created_ownership' => $createdOwnership,
                    'created_provider_identity' => $createdProviderIdentity,
                    'reused_provider_identity' => $reusedProviderIdentity,
                ];
            });

            if ($result['created_ownership']) $createdOwnerships++;
            if ($result['created_provider_identity']) $createdProviderIdentities++;
            if ($result['reused_provider_identity']) $reusedProviderIdentities++;
            if (!$result['created_ownership'] && !$result['created_provider_identity']) $unchangedUsers++;
        }

        $current = $this->loadSource();
        if (!hash_equals($source['fingerprint'], $current['fingerprint'])) {
            throw new RuntimeException('Legacy user source changed during ownership linking.');
        }

        $verification = $this->verify($current['items']);
        if (!$verification['ok']) {
            throw new RuntimeException('Legacy account ownership link failed final verification.');
        }

        $completedAt = $this->now();
        $details = [
            'source_user_count' => count($current['items']),
            'created_ownership_count' => $createdOwnerships,
            'created_provider_identity_count' => $createdProviderIdentities,
            'reused_provider_identity_count' => $reusedProviderIdentities,
            'unchanged_user_count' => $unchangedUsers,
            'completed_at_utc' => $completedAt,
        ];
        $this->writeMeta('completed', $current['fingerprint'], $details);

        return [
            'ok' => true,
            'dry_run' => false,
            'status' => 'completed',
            'source_fingerprint' => $current['fingerprint'],
            'source_user_count' => count($current['items']),
            'created_ownership_count' => $createdOwnerships,
            'created_provider_identity_count' => $createdProviderIdentities,
            'reused_provider_identity_count' => $reusedProviderIdentities,
            'unchanged_user_count' => $unchangedUsers,
            'verification' => $verification,
            'completed_at_utc' => $completedAt,
        ];
    }

    private function inspect(): array
    {
        $source = $this->loadSource();
        $meta = $this->loadMeta(self::META_KEY);
        $accountMeta = $this->loadMeta(self::ACCOUNT_IMPORT_META_KEY);
        $openingMeta = $this->loadMeta(self::OPENING_IMPORT_META_KEY);
        $blocking = [];
        $plannedOwnerships = 0;
        $plannedProviderIdentities = 0;
        $reusedProviderIdentities = 0;
        $unchangedUsers = 0;
        $conflicts = 0;
        $samples = [];

        if ($source['items'] === []) {
            $blocking[] = 'No legacy users were found.';
        }
        if (($accountMeta['status'] ?? '') !== 'completed') {
            $blocking[] = 'Legacy account import is not completed.';
        } elseif (!hash_equals((string)($accountMeta['source_fingerprint'] ?? ''), $source['fingerprint'])) {
            $blocking[] = 'Legacy account import belongs to a different source fingerprint.';
        }
        if (($openingMeta['status'] ?? '') !== 'completed') {
            $blocking[] = 'Opening balance import is not completed.';
        }
        if ($meta !== null && !hash_equals((string)$meta['source_fingerprint'], $source['fingerprint'])) {
            $blocking[] = 'Ownership link metadata belongs to a different source fingerprint.';
        }

        $expectedAccountRefs = [];
        foreach ($source['items'] as $item) {
            $expectedAccountRefs[$item['account_ref']] = true;
            $state = $this->itemState($item, $this->database, false);
            if ($state['state'] === 'conflict') {
                $conflicts++;
                foreach ($state['reasons'] as $reason) {
                    $blocking[] = $item['legacy_user_id'] . ': ' . $reason;
                }
            } elseif ($state['state'] === 'create') {
                if (empty($state['ownership_exists'])) $plannedOwnerships++;
                if (empty($state['provider_identity_exists'])) $plannedProviderIdentities++;
                if (!empty($state['provider_identity_exists'])) $reusedProviderIdentities++;
            } else {
                $unchangedUsers++;
                if (!empty($state['provider_identity_exists'])) $reusedProviderIdentities++;
            }

            if (count($samples) < 50) {
                $samples[] = [
                    'legacy_user_id' => $item['legacy_user_id'],
                    'account_ref' => $item['account_ref'],
                    'provider' => $item['provider'],
                    'provider_subject' => $item['provider_subject'],
                    'mgw_id' => $state['mgw_id'],
                    'state' => $state['state'],
                    'ownership_exists' => $state['ownership_exists'],
                    'provider_identity_exists' => $state['provider_identity_exists'],
                    'reasons' => $state['reasons'],
                ];
            }
        }

        $unmanagedOwnerships = 0;
        foreach ($this->database->fetchAll('SELECT account_ref FROM mgw_account_ownership') as $row) {
            $accountRef = trim((string)($row['account_ref'] ?? ''));
            if ($accountRef !== '' && !isset($expectedAccountRefs[$accountRef])) {
                $unmanagedOwnerships++;
            }
        }
        if ($unmanagedOwnerships > 0) {
            $blocking[] = 'Account ownership rows exist outside the current source plan.';
        }

        $status = $meta['status'] ?? 'not_started';
        if ($status === 'completed'
            && $blocking === []
            && ($plannedOwnerships + $plannedProviderIdentities) > 0) {
            $blocking[] = 'Completed ownership link is missing expected target state.';
        }

        return [
            'ok' => $blocking === [],
            'dry_run' => true,
            'ready' => $blocking === [],
            'status' => $status,
            'source_fingerprint' => $source['fingerprint'],
            'source_user_count' => count($source['items']),
            'planned_ownership_create_count' => $plannedOwnerships,
            'planned_provider_identity_create_count' => $plannedProviderIdentities,
            'reused_provider_identity_count' => $reusedProviderIdentities,
            'unchanged_user_count' => $unchangedUsers,
            'conflict_count' => $conflicts,
            'unmanaged_ownership_count' => $unmanagedOwnerships,
            'blocking_reasons' => array_values(array_unique($blocking)),
            'items' => $samples,
            'meta' => $meta,
        ];
    }

    private function itemState(
        array $item,
        DatabaseConnectionInterface $database,
        bool $forUpdate
    ): array {
        $lock = $forUpdate && $database->driver() !== 'sqlite' ? ' FOR UPDATE' : '';
        $reasons = [];
        $mgwId = null;

        $legacyRows = $database->fetchAll(
            'SELECT mgw_id FROM mgw_identities
             WHERE provider = :provider AND provider_subject = :provider_subject' . $lock,
            [
                'provider' => self::STAGING_PROVIDER,
                'provider_subject' => $item['legacy_user_id'],
            ]
        );
        if ($legacyRows === []) {
            $reasons[] = 'Internal legacy_import identity is missing.';
        } else {
            $mgwId = trim((string)($legacyRows[0]['mgw_id'] ?? ''));
            if (!MgwIdGenerator::isValid($mgwId)) {
                $reasons[] = 'Internal legacy_import identity has an invalid MGW ID.';
            }
        }

        if ($mgwId !== null && $mgwId !== '') {
            $userCount = (int)$database->fetchValue(
                'SELECT COUNT(*) FROM mgw_users WHERE mgw_id = :mgw_id',
                ['mgw_id' => $mgwId]
            );
            if ($userCount !== 1) {
                $reasons[] = 'Mapped MGW user row is missing.';
            }
        }

        $ownershipRows = [];
        if ($mgwId !== null && $mgwId !== '') {
            $ownershipRows = $database->fetchAll(
                'SELECT account_ref, mgw_id, legacy_user_id, ownership_status,
                        source_type, source_ref, source_sha256
                 FROM mgw_account_ownership
                 WHERE account_ref = :account_ref OR mgw_id = :mgw_id OR legacy_user_id = :legacy_user_id' . $lock,
                [
                    'account_ref' => $item['account_ref'],
                    'mgw_id' => $mgwId,
                    'legacy_user_id' => $item['legacy_user_id'],
                ]
            );
        }
        $ownershipExists = false;
        if (count($ownershipRows) > 1) {
            $reasons[] = 'Multiple ownership rows collide with the expected account.';
        } elseif ($ownershipRows !== []) {
            $row = $ownershipRows[0];
            $ownershipExists = (string)($row['account_ref'] ?? '') === $item['account_ref'];
            if (!$ownershipExists
                || (string)($row['mgw_id'] ?? '') !== $mgwId
                || (string)($row['legacy_user_id'] ?? '') !== $item['legacy_user_id']
                || (string)($row['ownership_status'] ?? '') !== 'active'
                || (string)($row['source_type'] ?? '') !== self::OWNERSHIP_SOURCE_TYPE
                || (string)($row['source_ref'] ?? '') !== $item['source_ref']
                || !hash_equals(
                    strtolower((string)($row['source_sha256'] ?? '')),
                    $item['source_record_sha256']
                )) {
                $reasons[] = 'Existing ownership row does not match the verified source plan.';
            }
        }

        $providerIdentityExists = false;
        if ($mgwId !== null && $mgwId !== '') {
            $providerRows = $database->fetchAll(
                'SELECT mgw_id, provider_subject FROM mgw_identities
                 WHERE (provider = :provider AND provider_subject = :provider_subject)
                    OR (mgw_id = :mgw_id AND provider = :provider_for_user)' . $lock,
                [
                    'provider' => $item['provider'],
                    'provider_subject' => $item['provider_subject'],
                    'mgw_id' => $mgwId,
                    'provider_for_user' => $item['provider'],
                ]
            );
            foreach ($providerRows as $row) {
                if ((string)($row['mgw_id'] ?? '') !== $mgwId
                    || (string)($row['provider_subject'] ?? '') !== $item['provider_subject']) {
                    $reasons[] = 'Provider identity is already linked to a different MGW account or subject.';
                    continue;
                }
                $providerIdentityExists = true;
            }
        }

        if ($mgwId !== null && $mgwId !== '') {
            $balanceRows = $database->fetchAll(
                'SELECT mgw_id, legacy_user_id, asset_code, available_amount, reserved_amount
                 FROM mgw_balances WHERE account_ref = :account_ref ORDER BY asset_code' . $lock,
                ['account_ref' => $item['account_ref']]
            );
            $expectedBalances = [
                'gold_coin' => $item['balance_gold'],
                'match_coin' => $item['balance_match'],
            ];
            if (count($balanceRows) !== 2) {
                $reasons[] = 'Expected exactly two separate Match/Gold balance rows.';
            } else {
                foreach ($balanceRows as $row) {
                    $asset = (string)($row['asset_code'] ?? '');
                    if (!array_key_exists($asset, $expectedBalances)) {
                        $reasons[] = 'Unexpected balance asset exists for the legacy account.';
                        continue;
                    }
                    if ((int)($row['available_amount'] ?? -1) !== $expectedBalances[$asset]
                        || (int)($row['reserved_amount'] ?? -1) !== 0) {
                        $reasons[] = 'Current ' . $asset . ' balance differs from the verified JSON source.';
                    }
                    $balanceMgwId = trim((string)($row['mgw_id'] ?? ''));
                    if ($balanceMgwId !== '' && $balanceMgwId !== $mgwId) {
                        $reasons[] = 'Balance row points to a different MGW account.';
                    }
                    if ((string)($row['legacy_user_id'] ?? '') !== $item['legacy_user_id']) {
                        $reasons[] = 'Balance row points to a different legacy user.';
                    }
                    $chain = $this->ledgerVerifier->verifyAccountAsset($item['account_ref'], $asset);
                    if (empty($chain['ok'])) {
                        $reasons[] = 'Ledger integrity verification failed for ' . $asset . '.';
                    }
                }
            }
        }

        $reasons = array_values(array_unique($reasons));
        $state = $reasons !== []
            ? 'conflict'
            : (($ownershipExists && $providerIdentityExists) ? 'unchanged' : 'create');

        return [
            'state' => $state,
            'mgw_id' => $mgwId,
            'ownership_exists' => $ownershipExists,
            'provider_identity_exists' => $providerIdentityExists,
            'reasons' => $reasons,
        ];
    }

    private function verify(array $items): array
    {
        $errors = [];
        $mgwIds = [];
        foreach ($items as $item) {
            $state = $this->itemState($item, $this->database, false);
            if ($state['state'] !== 'unchanged') {
                $errors[] = $item['legacy_user_id'] . ': '
                    . ($state['reasons'] === [] ? 'target is not unchanged' : implode('; ', $state['reasons']));
                continue;
            }
            $mgwIds[] = $state['mgw_id'];
        }

        return [
            'ok' => $errors === [],
            'verified_user_count' => count($items) - count($errors),
            'unique_mgw_id_count' => count(array_unique(array_filter($mgwIds))),
            'ownership_count' => (int)$this->database->fetchValue('SELECT COUNT(*) FROM mgw_account_ownership'),
            'provider_identity_count' => $this->countExpectedProviderIdentities($items),
            'error_count' => count($errors),
            'errors' => array_slice($errors, 0, 50),
        ];
    }

    private function countExpectedProviderIdentities(array $items): int
    {
        $count = 0;
        foreach ($items as $item) {
            $count += (int)$this->database->fetchValue(
                'SELECT COUNT(*) FROM mgw_identities
                 WHERE provider = :provider AND provider_subject = :provider_subject',
                [
                    'provider' => $item['provider'],
                    'provider_subject' => $item['provider_subject'],
                ]
            );
        }
        return $count;
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
            if (!is_array($payload)) {
                throw new RuntimeException('Economy shadow payload is invalid for ' . $legacyId . '.');
            }
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
            if (!isset($shadows[$legacyId])) {
                throw new RuntimeException('Economy shadow is missing for legacy user ' . $legacyId . '.');
            }

            $recordJson = LedgerIntegrity::canonicalJson($record);
            $recordHash = hash('sha256', $recordJson);
            $shadowPayload = $shadows[$legacyId]['payload'];
            $shadowRecordHash = strtolower(trim((string)($shadowPayload['source_record_sha256'] ?? '')));
            if (!hash_equals($recordHash, $shadowRecordHash)) {
                throw new RuntimeException(
                    'Legacy user JSON differs from the verified economy shadow for ' . $legacyId . '.'
                );
            }

            $provider = !empty($record['is_dev_user']) ? 'development' : 'telegram';
            $providerSubject = trim((string)($record['telegram_id'] ?? $record['id'] ?? $legacyId));
            if ($providerSubject === '') {
                throw new RuntimeException('Legacy provider subject is missing for ' . $legacyId . '.');
            }
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
                'account_ref' => 'legacy:' . $legacyId,
                'provider' => $provider,
                'provider_subject' => $providerSubject,
                'username' => $this->nullableText($record['username'] ?? null, 80),
                'balance_match' => $this->balance($record['balance_match'] ?? 0, 'balance_match', $legacyId),
                'balance_gold' => $this->balance($record['balance_gold'] ?? 0, 'balance_gold', $legacyId),
                'created_at_utc' => $createdAt,
                'last_seen_at_utc' => $lastSeenAt,
                'source_ref' => 'users.json:' . $legacyId,
                'source_record_sha256' => $recordHash,
            ];
            $fingerprintParts[] = $legacyId . "\0" . $recordHash;
        }

        foreach (array_keys($shadows) as $legacyId) {
            if (!isset($items[$legacyId])) {
                throw new RuntimeException(
                    'Verified economy shadow has no matching legacy user JSON record: ' . $legacyId . '.'
                );
            }
        }

        ksort($items, SORT_STRING);
        sort($fingerprintParts, SORT_STRING);
        return [
            'fingerprint' => hash('sha256', implode("\n", $fingerprintParts)),
            'items' => array_values($items),
        ];
    }

    private function loadMeta(string $key): ?array
    {
        $rows = $this->database->fetchAll(
            'SELECT meta_value, updated_at_utc FROM mgw_meta WHERE meta_key = :meta_key',
            ['meta_key' => $key]
        );
        if ($rows === []) return null;
        try {
            $value = json_decode((string)$rows[0]['meta_value'], true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new RuntimeException('Migration metadata is invalid for ' . $key . '.');
        }
        if (!is_array($value)) throw new RuntimeException('Migration metadata is invalid for ' . $key . '.');
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
            [
                'meta_value' => $value,
                'updated_at_utc' => $updatedAt,
                'meta_key' => self::META_KEY,
            ]
        );
        if ($updated === 0) {
            try {
                $this->database->execute(
                    'INSERT INTO mgw_meta (meta_key, meta_value, updated_at_utc)
                     VALUES (:meta_key, :meta_value, :updated_at_utc)',
                    [
                        'meta_key' => self::META_KEY,
                        'meta_value' => $value,
                        'updated_at_utc' => $updatedAt,
                    ]
                );
            } catch (Throwable) {
                $this->database->execute(
                    'UPDATE mgw_meta SET meta_value = :meta_value, updated_at_utc = :updated_at_utc
                     WHERE meta_key = :meta_key',
                    [
                        'meta_value' => $value,
                        'updated_at_utc' => $updatedAt,
                        'meta_key' => self::META_KEY,
                    ]
                );
            }
        }
    }

    private function balance(mixed $value, string $field, string $legacyId): int
    {
        if (is_int($value)) {
            $amount = $value;
        } elseif (is_string($value) && preg_match('/^\d+$/', trim($value)) === 1) {
            $amount = (int)trim($value);
        } elseif (is_float($value) && is_finite($value) && floor($value) === $value) {
            $amount = (int)$value;
        } else {
            throw new RuntimeException('Legacy user ' . $legacyId . ' has invalid ' . $field . '.');
        }
        if ($amount < 0) {
            throw new RuntimeException('Legacy user ' . $legacyId . ' has negative ' . $field . '.');
        }
        return $amount;
    }

    private function timestamp(mixed ...$values): string
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
        throw new RuntimeException('Legacy user record has no valid stable timestamp.');
    }

    private function nullableText(mixed $value, int $max): ?string
    {
        $value = trim(preg_replace('/[\x00-\x1F\x7F]/u', '', (string)($value ?? '')) ?? '');
        if ($value === '') return null;
        return function_exists('mb_substr') ? mb_substr($value, 0, $max) : substr($value, 0, $max);
    }

    private function now(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');
    }
}
