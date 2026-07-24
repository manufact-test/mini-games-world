<?php
declare(strict_types=1);

/**
 * Production authentication can refresh normalized account metadata before the
 * compatibility-state callback starts. The strict staging projector remains the
 * mutation authority, while this production audit verifies the stable identity
 * and ownership contract without treating auth-owned profile timestamps/avatar
 * refreshes as state corruption.
 */
final class ProductionRuntimeAccountsModuleProjector implements RuntimePrimaryModuleProjectorInterface
{
    private const LEGACY_PROVIDER = 'legacy_import';
    private const AUTH_PENDING_MAX_AGE_SECONDS = 900;

    private RuntimePrimaryAccountsModuleProjector $exactProjector;

    public function __construct(private DatabaseConnectionInterface $database)
    {
        if ($this->database->driver() !== 'mysql') {
            throw new RuntimeException('Production accounts projector requires MySQL/MariaDB.');
        }
        $this->exactProjector = new RuntimePrimaryAccountsModuleProjector($this->database);
    }

    public function module(): string
    {
        return 'accounts';
    }

    public function project(array $snapshot, int $stateRevision, string $stateSha256): array
    {
        $this->alignAuthProvisionedCreatedTimestamps($snapshot);
        return $this->exactProjector->project($snapshot, $stateRevision, $stateSha256);
    }

    public function audit(array $snapshot, int $stateRevision, string $stateSha256): array
    {
        $audit = $this->inspectStableContract($snapshot);

        return [
            'ok' => $audit['blockers'] === [],
            'parity' => $audit['blockers'] === [],
            'read_only' => true,
            'module' => 'accounts',
            'state_revision' => $stateRevision,
            'state_sha256' => strtolower(trim($stateSha256)),
            'source_fingerprint' => $audit['source_fingerprint'],
            'database_fingerprint' => $audit['database_fingerprint'],
            'summary' => [
                'source_user_count' => $audit['source_user_count'],
                'database_user_count' => $audit['database_user_count'],
                'auth_pending_count' => $audit['auth_pending_count'],
                'profile_fields_owned_by_auth' => true,
            ],
            'blockers' => $audit['blockers'],
        ];
    }

    private function inspectStableContract(array $snapshot): array
    {
        $source = $this->sourceUsers($snapshot);
        $expected = [];
        $actual = [];
        $blockers = [];
        $resolvedSourceIds = [];
        $seenMgwIds = [];
        $seenAccountRefs = [];

        foreach ($source as $legacyUserId => $item) {
            $ownership = $this->database->fetchAll(
                'SELECT account_ref, mgw_id, legacy_user_id, ownership_status
                 FROM mgw_account_ownership
                 WHERE legacy_user_id = :legacy_user_id',
                ['legacy_user_id' => $legacyUserId]
            );

            $mgwId = trim((string)($ownership[0]['mgw_id'] ?? ''));
            $expected[$legacyUserId] = $this->expectedRow($item, $mgwId);

            if (count($ownership) !== 1) {
                $blockers[] = 'Account ownership is missing or ambiguous for a compatibility user.';
                $actual[$legacyUserId] = $this->emptyActualRow($item);
                continue;
            }

            $ownershipRow = $ownership[0];
            $accountRef = trim((string)($ownershipRow['account_ref'] ?? ''));
            if ($accountRef !== $item['account_ref']
                || (string)($ownershipRow['legacy_user_id'] ?? '') !== $legacyUserId
                || (string)($ownershipRow['ownership_status'] ?? '') !== 'active'
                || !MgwIdGenerator::isValid($mgwId)) {
                $blockers[] = 'Account ownership is invalid for a compatibility user.';
            }
            if (isset($seenMgwIds[$mgwId]) || isset($seenAccountRefs[$accountRef])) {
                $blockers[] = 'Account ownership is not one-to-one.';
            }
            $seenMgwIds[$mgwId] = true;
            $seenAccountRefs[$accountRef] = true;
            $resolvedSourceIds[$legacyUserId] = true;

            $users = $this->database->fetchAll(
                'SELECT status FROM mgw_users WHERE mgw_id = :mgw_id',
                ['mgw_id' => $mgwId]
            );
            $providerIdentity = $this->identityRows(
                $item['provider'],
                $item['provider_subject']
            );
            $legacyIdentity = $this->identityRows(self::LEGACY_PROVIDER, $legacyUserId);

            if (count($users) !== 1
                || (string)($users[0]['status'] ?? '') !== 'active'
                || count($providerIdentity) !== 1
                || count($legacyIdentity) !== 1
                || (string)($providerIdentity[0]['mgw_id'] ?? '') !== $mgwId
                || (string)($legacyIdentity[0]['mgw_id'] ?? '') !== $mgwId) {
                $blockers[] = 'Account identity links are incomplete for a compatibility user.';
            }

            $actual[$legacyUserId] = [
                'legacy_user_id' => (string)($ownershipRow['legacy_user_id'] ?? ''),
                'account_ref' => $accountRef,
                'mgw_id' => $mgwId,
                'ownership_status' => (string)($ownershipRow['ownership_status'] ?? ''),
                'provider' => (string)($providerIdentity[0]['provider'] ?? ''),
                'provider_subject' => (string)($providerIdentity[0]['provider_subject'] ?? ''),
                'status' => (string)($users[0]['status'] ?? ''),
            ];
        }

        $authPendingCount = 0;
        foreach ($this->database->fetchAll(
            'SELECT account_ref, mgw_id, legacy_user_id, ownership_status
             FROM mgw_account_ownership
             WHERE legacy_user_id IS NOT NULL'
        ) as $row) {
            $legacyUserId = trim((string)($row['legacy_user_id'] ?? ''));
            if ($legacyUserId === '' || isset($resolvedSourceIds[$legacyUserId])) {
                continue;
            }

            if ($this->isRecentAuthProvisionedRow($row)) {
                $authPendingCount++;
                continue;
            }

            $blockers[] = 'Account database contains an unrecognized ownership row outside the compatibility state.';
        }

        ksort($expected, SORT_STRING);
        ksort($actual, SORT_STRING);
        $sourceFingerprint = hash('sha256', $this->canonicalJson($expected));
        $databaseFingerprint = hash('sha256', $this->canonicalJson($actual));
        if (!hash_equals($sourceFingerprint, $databaseFingerprint)) {
            $blockers[] = 'Account stable identity projection differs from the compatibility state.';
        }

        return [
            'source_fingerprint' => $sourceFingerprint,
            'database_fingerprint' => $databaseFingerprint,
            'source_user_count' => count($source),
            'database_user_count' => count($actual),
            'auth_pending_count' => $authPendingCount,
            'blockers' => array_values(array_unique($blockers)),
        ];
    }

    private function alignAuthProvisionedCreatedTimestamps(array $snapshot): void
    {
        foreach ($this->sourceUsers($snapshot) as $legacyUserId => $item) {
            $ownership = $this->database->fetchAll(
                'SELECT account_ref, mgw_id, legacy_user_id, ownership_status
                 FROM mgw_account_ownership
                 WHERE legacy_user_id = :legacy_user_id',
                ['legacy_user_id' => $legacyUserId]
            );
            if (count($ownership) !== 1) {
                continue;
            }

            $mgwId = trim((string)($ownership[0]['mgw_id'] ?? ''));
            if (!MgwIdGenerator::isValid($mgwId)
                || (string)($ownership[0]['account_ref'] ?? '') !== $item['account_ref']
                || (string)($ownership[0]['ownership_status'] ?? '') !== 'active') {
                continue;
            }

            $providerIdentity = $this->identityRows($item['provider'], $item['provider_subject']);
            $legacyIdentity = $this->identityRows(self::LEGACY_PROVIDER, $legacyUserId);
            if (count($providerIdentity) !== 1
                || (string)($providerIdentity[0]['mgw_id'] ?? '') !== $mgwId
                || $legacyIdentity !== []) {
                continue;
            }

            $updated = $this->database->execute(
                'UPDATE mgw_users
                 SET created_at_utc = :created_at_utc
                 WHERE mgw_id = :mgw_id',
                [
                    'created_at_utc' => $item['created_at_utc'],
                    'mgw_id' => $mgwId,
                ]
            );
            if ($updated > 1) {
                throw new RuntimeException('Auth-provisioned account timestamp alignment touched multiple users.');
            }
        }
    }

    private function isRecentAuthProvisionedRow(array $ownership): bool
    {
        $legacyUserId = trim((string)($ownership['legacy_user_id'] ?? ''));
        $accountRef = trim((string)($ownership['account_ref'] ?? ''));
        $mgwId = trim((string)($ownership['mgw_id'] ?? ''));
        if ($legacyUserId === ''
            || $accountRef !== 'legacy:' . $legacyUserId
            || (string)($ownership['ownership_status'] ?? '') !== 'active'
            || !MgwIdGenerator::isValid($mgwId)) {
            return false;
        }

        $users = $this->database->fetchAll(
            'SELECT status FROM mgw_users WHERE mgw_id = :mgw_id',
            ['mgw_id' => $mgwId]
        );
        $legacyIdentity = $this->identityRows(self::LEGACY_PROVIDER, $legacyUserId);
        $providerRows = $this->database->fetchAll(
            'SELECT mgw_id, provider, provider_subject, last_authenticated_at_utc
             FROM mgw_identities
             WHERE mgw_id = :mgw_id AND provider IN (\'telegram\', \'development\')',
            ['mgw_id' => $mgwId]
        );
        if (count($users) !== 1
            || (string)($users[0]['status'] ?? '') !== 'active'
            || $legacyIdentity !== []
            || count($providerRows) !== 1
            || (string)($providerRows[0]['provider_subject'] ?? '') !== $legacyUserId) {
            return false;
        }

        $authenticatedAt = strtotime((string)($providerRows[0]['last_authenticated_at_utc'] ?? '')) ?: 0;
        return $authenticatedAt > 0
            && $authenticatedAt <= time() + 300
            && time() - $authenticatedAt <= self::AUTH_PENDING_MAX_AGE_SECONDS;
    }

    private function sourceUsers(array $snapshot): array
    {
        $users = is_array($snapshot['users'] ?? null) ? $snapshot['users'] : [];
        $items = [];
        foreach ($users as $key => $record) {
            if (!is_array($record)) {
                throw new RuntimeException('Production account source user record is invalid.');
            }
            $legacyId = trim((string)($record['id'] ?? (is_string($key) || is_int($key) ? $key : '')));
            if ($legacyId === '' || isset($items[$legacyId])) {
                throw new RuntimeException('Production account source user ID is missing or duplicated.');
            }
            $provider = !empty($record['is_dev_user']) ? 'development' : 'telegram';
            $providerSubject = trim((string)($record['telegram_id'] ?? $record['id'] ?? $legacyId));
            if ($providerSubject === '') {
                throw new RuntimeException('Production account provider subject is missing.');
            }
            $items[$legacyId] = [
                'legacy_user_id' => $legacyId,
                'account_ref' => 'legacy:' . $legacyId,
                'provider' => $provider,
                'provider_subject' => $providerSubject,
                'created_at_utc' => $this->sourceTimestamp(
                    $record['registered_at'] ?? $record['created_at'] ?? $record['last_seen_at'] ?? null
                ),
            ];
        }
        ksort($items, SORT_STRING);
        return $items;
    }

    private function expectedRow(array $item, string $mgwId): array
    {
        return [
            'legacy_user_id' => $item['legacy_user_id'],
            'account_ref' => $item['account_ref'],
            'mgw_id' => $mgwId,
            'ownership_status' => 'active',
            'provider' => $item['provider'],
            'provider_subject' => $item['provider_subject'],
            'status' => 'active',
        ];
    }

    private function emptyActualRow(array $item): array
    {
        return [
            'legacy_user_id' => $item['legacy_user_id'],
            'account_ref' => '',
            'mgw_id' => '',
            'ownership_status' => '',
            'provider' => '',
            'provider_subject' => '',
            'status' => '',
        ];
    }

    private function identityRows(string $provider, string $subject): array
    {
        return $this->database->fetchAll(
            'SELECT mgw_id, provider, provider_subject
             FROM mgw_identities
             WHERE provider = :provider AND provider_subject = :provider_subject',
            ['provider' => $provider, 'provider_subject' => $subject]
        );
    }

    private function sourceTimestamp(mixed $value): string
    {
        $value = trim((string)$value);
        return $value !== '' && strtotime($value) !== false
            ? gmdate(DATE_ATOM, (int)strtotime($value))
            : '1970-01-01T00:00:00+00:00';
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
