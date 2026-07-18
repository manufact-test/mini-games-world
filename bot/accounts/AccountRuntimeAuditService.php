<?php
declare(strict_types=1);

final class AccountRuntimeAuditService
{
    public function __construct(private DatabaseConnectionInterface $database) {}

    public function audit(
        int $expectedUserCount = 1,
        int $recentAuthenticationMinutes = 20,
        bool $requireSessionEvidence = true
    ): array {
        $expectedUserCount = max(1, $expectedUserCount);
        $recentAuthenticationMinutes = max(1, min(1440, $recentAuthenticationMinutes));

        $counts = [
            'users' => (int)$this->database->fetchValue('SELECT COUNT(*) FROM mgw_users'),
            'telegram_identities' => (int)$this->database->fetchValue(
                "SELECT COUNT(*) FROM mgw_identities WHERE provider = 'telegram'"
            ),
            'ownerships' => (int)$this->database->fetchValue('SELECT COUNT(*) FROM mgw_account_ownership'),
            'devices' => (int)$this->database->fetchValue('SELECT COUNT(*) FROM mgw_devices'),
            'sessions' => (int)$this->database->fetchValue('SELECT COUNT(*) FROM mgw_sessions'),
            'active_sessions' => (int)$this->database->fetchValue(
                'SELECT COUNT(*) FROM mgw_sessions WHERE revoked_at_utc IS NULL'
            ),
        ];

        $duplicateProviderSubjects = (int)$this->database->fetchValue(
            'SELECT COUNT(*) FROM (
                SELECT provider, provider_subject
                FROM mgw_identities
                GROUP BY provider, provider_subject
                HAVING COUNT(*) > 1
             ) duplicate_provider_subjects'
        );
        $orphanIdentities = (int)$this->database->fetchValue(
            'SELECT COUNT(*)
             FROM mgw_identities identity_row
             LEFT JOIN mgw_users user_row ON user_row.mgw_id = identity_row.mgw_id
             WHERE user_row.mgw_id IS NULL'
        );
        $orphanOwnerships = (int)$this->database->fetchValue(
            'SELECT COUNT(*)
             FROM mgw_account_ownership ownership_row
             LEFT JOIN mgw_users user_row ON user_row.mgw_id = ownership_row.mgw_id
             WHERE user_row.mgw_id IS NULL'
        );

        $identityRows = $this->database->fetchAll(
            "SELECT mgw_id, provider_subject, last_authenticated_at_utc
             FROM mgw_identities
             WHERE provider = 'telegram'
             ORDER BY mgw_id"
        );
        $ownershipRows = $this->database->fetchAll(
            'SELECT mgw_id, account_ref, legacy_user_id, ownership_status
             FROM mgw_account_ownership
             ORDER BY mgw_id'
        );

        $identityMgwIds = [];
        $identityFingerprints = [];
        $latestAuthenticatedAt = null;
        foreach ($identityRows as $row) {
            $mgwId = trim((string)($row['mgw_id'] ?? ''));
            $subject = trim((string)($row['provider_subject'] ?? ''));
            if ($mgwId !== '') $identityMgwIds[$mgwId] = true;
            if ($subject !== '') {
                $identityFingerprints[] = hash('sha256', 'telegram|' . $subject);
            }
            $authenticatedAt = $this->timestamp($row['last_authenticated_at_utc'] ?? null);
            if ($authenticatedAt !== null
                && ($latestAuthenticatedAt === null || $authenticatedAt > $latestAuthenticatedAt)) {
                $latestAuthenticatedAt = $authenticatedAt;
            }
        }

        $ownershipMgwIds = [];
        $ownershipFingerprints = [];
        $inactiveOwnerships = 0;
        foreach ($ownershipRows as $row) {
            $mgwId = trim((string)($row['mgw_id'] ?? ''));
            if ($mgwId !== '') $ownershipMgwIds[$mgwId] = true;
            $accountRef = trim((string)($row['account_ref'] ?? ''));
            $legacyUserId = trim((string)($row['legacy_user_id'] ?? ''));
            if ($accountRef !== '' || $legacyUserId !== '') {
                $ownershipFingerprints[] = hash('sha256', $accountRef . '|' . $legacyUserId);
            }
            if ((string)($row['ownership_status'] ?? '') !== 'active') $inactiveOwnerships++;
        }

        sort($identityFingerprints, SORT_STRING);
        sort($ownershipFingerprints, SORT_STRING);
        $identityIds = array_keys($identityMgwIds);
        $ownershipIds = array_keys($ownershipMgwIds);
        sort($identityIds, SORT_STRING);
        sort($ownershipIds, SORT_STRING);
        $identityOwnershipSameMgwIds = $identityIds === $ownershipIds;

        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $cutoff = $now->modify('-' . $recentAuthenticationMinutes . ' minutes');
        $recentAuthentication = $latestAuthenticatedAt !== null && $latestAuthenticatedAt >= $cutoff;

        $blockers = [];
        if ($counts['users'] !== $expectedUserCount) {
            $blockers[] = 'Unexpected MGW user count.';
        }
        if ($counts['telegram_identities'] !== $expectedUserCount) {
            $blockers[] = 'Unexpected Telegram identity count.';
        }
        if ($counts['ownerships'] !== $expectedUserCount) {
            $blockers[] = 'Unexpected account ownership count.';
        }
        if (!$identityOwnershipSameMgwIds) {
            $blockers[] = 'Telegram identity and account ownership resolve to different MGW accounts.';
        }
        if ($duplicateProviderSubjects !== 0) {
            $blockers[] = 'Duplicate provider subjects exist.';
        }
        if ($orphanIdentities !== 0 || $orphanOwnerships !== 0) {
            $blockers[] = 'Orphan account identity or ownership rows exist.';
        }
        if ($inactiveOwnerships !== 0) {
            $blockers[] = 'Inactive account ownership rows exist.';
        }
        if (!$recentAuthentication) {
            $blockers[] = 'No recent staging Telegram authentication was observed.';
        }
        if ($requireSessionEvidence && ($counts['devices'] < 1 || $counts['active_sessions'] < 1)) {
            $blockers[] = 'Recent authentication did not register device and active session evidence.';
        }

        $result = [
            'ok' => $blockers === [],
            'read_only' => true,
            'expected_user_count' => $expectedUserCount,
            'recent_authentication_window_minutes' => $recentAuthenticationMinutes,
            'counts' => $counts,
            'identity_ownership_same_mgw_id' => $identityOwnershipSameMgwIds,
            'duplicate_provider_subject_count' => $duplicateProviderSubjects,
            'orphan_identity_count' => $orphanIdentities,
            'orphan_ownership_count' => $orphanOwnerships,
            'inactive_ownership_count' => $inactiveOwnerships,
            'recent_authentication_observed' => $recentAuthentication,
            'latest_telegram_auth_at_utc' => $latestAuthenticatedAt?->format(DATE_ATOM),
            'identity_fingerprints' => $identityFingerprints,
            'ownership_fingerprints' => $ownershipFingerprints,
            'sensitive_identifiers_exposed' => false,
            'blockers' => $blockers,
        ];
        $result['report_fingerprint'] = hash('sha256', $this->canonicalJson($result));
        return $result;
    }

    private function timestamp(mixed $value): ?DateTimeImmutable
    {
        $value = trim((string)$value);
        if ($value === '') return null;
        try {
            return new DateTimeImmutable($value, new DateTimeZone('UTC'));
        } catch (Throwable) {
            return null;
        }
    }

    private function canonicalJson(mixed $value): string
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
