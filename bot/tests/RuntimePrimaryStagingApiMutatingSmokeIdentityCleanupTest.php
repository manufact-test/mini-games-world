<?php
declare(strict_types=1);

$projectRoot = dirname(__DIR__, 2);
require_once $projectRoot . '/bot/database/DatabaseConnectionInterface.php';
require_once $projectRoot . '/bot/accounts/MgwIdGenerator.php';
require_once $projectRoot . '/bot/runtime/RuntimePrimaryStagingApiMutatingSmokeIdentityCleanup.php';

final class ApiMutatingSmokeCleanupFakeDatabase implements DatabaseConnectionInterface
{
    public array $users = [];
    public array $identities = [];
    public array $ownership = [];
    public array $devices = [];
    public array $sessions = [];

    public function driver(): string
    {
        return 'mysql';
    }

    public function execute(string $sql, array $parameters = []): int
    {
        $sql = preg_replace('/\s+/', ' ', trim($sql)) ?? trim($sql);
        $mgwId = (string)($parameters['mgw_id'] ?? '');

        if (str_starts_with($sql, 'DELETE FROM mgw_sessions')) {
            $hash = (string)($parameters['session_key_hash'] ?? '');
            return $this->deleteWhere($this->sessions, static fn(array $row): bool =>
                (string)($row['mgw_id'] ?? '') === $mgwId
                && (string)($row['session_key_hash'] ?? '') === $hash
            );
        }
        if (str_starts_with($sql, 'DELETE FROM mgw_devices')) {
            return $this->deleteWhere($this->devices, static fn(array $row): bool =>
                (string)($row['mgw_id'] ?? '') === $mgwId
            );
        }
        if (str_starts_with($sql, 'DELETE FROM mgw_account_ownership')) {
            $legacy = (string)($parameters['legacy_user_id'] ?? '');
            $ref = (string)($parameters['account_ref'] ?? '');
            return $this->deleteWhere($this->ownership, static fn(array $row): bool =>
                (string)($row['mgw_id'] ?? '') === $mgwId
                && (string)($row['legacy_user_id'] ?? '') === $legacy
                && (string)($row['account_ref'] ?? '') === $ref
            );
        }
        if (str_starts_with($sql, 'DELETE FROM mgw_identities')) {
            return $this->deleteWhere($this->identities, static fn(array $row): bool =>
                (string)($row['mgw_id'] ?? '') === $mgwId
            );
        }
        if (str_starts_with($sql, 'DELETE FROM mgw_users')) {
            return $this->deleteWhere($this->users, static fn(array $row): bool =>
                (string)($row['mgw_id'] ?? '') === $mgwId
            );
        }

        throw new RuntimeException('Unexpected fake cleanup execute SQL: ' . $sql);
    }

    public function fetchAll(string $sql, array $parameters = []): array
    {
        $sql = preg_replace('/\s+/', ' ', trim($sql)) ?? trim($sql);
        if (str_contains($sql, 'FROM mgw_identities')
            && str_contains($sql, 'provider = :provider')
            && str_contains($sql, 'provider_subject = :subject')) {
            $provider = (string)($parameters['provider'] ?? '');
            $subject = (string)($parameters['subject'] ?? '');
            return array_values(array_filter($this->identities, static fn(array $row): bool =>
                (string)($row['provider'] ?? '') === $provider
                && (string)($row['provider_subject'] ?? '') === $subject
            ));
        }
        if (str_contains($sql, 'FROM mgw_account_ownership')) {
            $mgwId = (string)($parameters['mgw_id'] ?? '');
            $legacy = (string)($parameters['legacy_user_id'] ?? '');
            $ref = (string)($parameters['account_ref'] ?? '');
            return array_values(array_filter($this->ownership, static fn(array $row): bool =>
                ($mgwId !== '' && (string)($row['mgw_id'] ?? '') === $mgwId)
                || ($legacy !== '' && (string)($row['legacy_user_id'] ?? '') === $legacy)
                || ($ref !== '' && (string)($row['account_ref'] ?? '') === $ref)
            ));
        }

        throw new RuntimeException('Unexpected fake cleanup fetchAll SQL: ' . $sql);
    }

    public function fetchValue(string $sql, array $parameters = []): mixed
    {
        $sql = preg_replace('/\s+/', ' ', trim($sql)) ?? trim($sql);
        if (str_contains($sql, 'FROM mgw_identities')) {
            $mgwId = (string)($parameters['mgw_id'] ?? '');
            $provider = (string)($parameters['provider'] ?? '');
            $subject = (string)($parameters['subject'] ?? '');
            return count(array_filter($this->identities, static fn(array $row): bool =>
                ($mgwId !== '' && (string)($row['mgw_id'] ?? '') === $mgwId)
                || ($provider !== '' && $subject !== ''
                    && (string)($row['provider'] ?? '') === $provider
                    && (string)($row['provider_subject'] ?? '') === $subject)
            ));
        }
        if (str_contains($sql, 'FROM mgw_account_ownership')) {
            $mgwId = (string)($parameters['mgw_id'] ?? '');
            $legacy = (string)($parameters['legacy_user_id'] ?? '');
            $ref = (string)($parameters['account_ref'] ?? '');
            return count(array_filter($this->ownership, static fn(array $row): bool =>
                ($mgwId !== '' && (string)($row['mgw_id'] ?? '') === $mgwId)
                || ($legacy !== '' && (string)($row['legacy_user_id'] ?? '') === $legacy)
                || ($ref !== '' && (string)($row['account_ref'] ?? '') === $ref)
            ));
        }
        if (str_contains($sql, 'FROM mgw_sessions')) {
            $mgwId = (string)($parameters['mgw_id'] ?? '');
            $hash = (string)($parameters['session_key_hash'] ?? '');
            return count(array_filter($this->sessions, static fn(array $row): bool =>
                ($mgwId !== '' && (string)($row['mgw_id'] ?? '') === $mgwId)
                || ($hash !== '' && (string)($row['session_key_hash'] ?? '') === $hash)
            ));
        }
        if (str_contains($sql, 'FROM mgw_devices')) {
            $mgwId = (string)($parameters['mgw_id'] ?? '');
            return count(array_filter($this->devices, static fn(array $row): bool =>
                (string)($row['mgw_id'] ?? '') === $mgwId
            ));
        }
        if (str_contains($sql, 'FROM mgw_users')) {
            $mgwId = (string)($parameters['mgw_id'] ?? '');
            return count(array_filter($this->users, static fn(array $row): bool =>
                (string)($row['mgw_id'] ?? '') === $mgwId
            ));
        }

        throw new RuntimeException('Unexpected fake cleanup fetchValue SQL: ' . $sql);
    }

    public function transaction(callable $callback): mixed
    {
        $snapshot = [$this->users, $this->identities, $this->ownership, $this->devices, $this->sessions];
        try {
            return $callback($this);
        } catch (Throwable $error) {
            [$this->users, $this->identities, $this->ownership, $this->devices, $this->sessions] = $snapshot;
            throw $error;
        }
    }

    private function deleteWhere(array &$rows, callable $predicate): int
    {
        $before = count($rows);
        $rows = array_values(array_filter($rows, static fn(array $row): bool => !$predicate($row)));
        return $before - count($rows);
    }
}

$assertions = 0;
$assertSame = static function (mixed $expected, mixed $actual, string $message) use (&$assertions): void {
    $assertions++;
    if ($expected !== $actual) {
        throw new RuntimeException($message . ' Expected ' . var_export($expected, true)
            . ', got ' . var_export($actual, true) . '.');
    }
};

$provider = 'telegram';
$subject = '912345678901234567';
$sessionId = str_repeat('a', 64);
$sessionHash = hash('sha256', 'session|' . $sessionId);
$mgwId = 'MGW-0123456789ABCDEF';

$full = new ApiMutatingSmokeCleanupFakeDatabase();
$full->users = [['mgw_id' => $mgwId]];
$full->identities = [
    ['mgw_id' => $mgwId, 'provider' => 'telegram', 'provider_subject' => $subject],
    ['mgw_id' => $mgwId, 'provider' => 'legacy_import', 'provider_subject' => $subject],
];
$full->ownership = [[
    'mgw_id' => $mgwId,
    'legacy_user_id' => $subject,
    'account_ref' => 'legacy:' . $subject,
]];
$full->devices = [['mgw_id' => $mgwId]];
$full->sessions = [['mgw_id' => $mgwId, 'session_key_hash' => $sessionHash]];
$cleanup = new RuntimePrimaryStagingApiMutatingSmokeIdentityCleanup($full);
$assertSame($mgwId, $cleanup->resolveCreatedMgwId($provider, $subject, $subject), 'Full cleanup MGW ID resolution failed.');
$removed = $cleanup->removeMappingsBeforeCleanupProjection($provider, $subject, $subject, $sessionId, $mgwId);
$assertSame(1, $removed['session_rows_deleted'] ?? null, 'Full cleanup session count mismatch.');
$assertSame(1, $removed['device_rows_deleted'] ?? null, 'Full cleanup device count mismatch.');
$assertSame(1, $removed['ownership_rows_deleted'] ?? null, 'Full cleanup ownership count mismatch.');
$assertSame(2, $removed['identity_rows_deleted'] ?? null, 'Full cleanup identity count mismatch.');
$userRemoved = $cleanup->removeOrphanUserAfterCleanupProjection($mgwId);
$assertSame(1, $userRemoved['user_rows_deleted'] ?? null, 'Full cleanup user count mismatch.');
$assertSame(true, $cleanup->assertAbsent($provider, $subject, $subject, $sessionId, $mgwId)['ok'] ?? false, 'Full cleanup absence proof failed.');

$partial = new ApiMutatingSmokeCleanupFakeDatabase();
$partial->users = [['mgw_id' => $mgwId]];
$partial->identities = [[
    'mgw_id' => $mgwId,
    'provider' => 'telegram',
    'provider_subject' => $subject,
]];
$partialCleanup = new RuntimePrimaryStagingApiMutatingSmokeIdentityCleanup($partial);
$assertSame($mgwId, $partialCleanup->resolveCreatedMgwId($provider, $subject, $subject), 'Partial cleanup MGW ID resolution failed.');
$partialRemoved = $partialCleanup->removeMappingsBeforeCleanupProjection(
    $provider,
    $subject,
    $subject,
    $sessionId,
    $mgwId
);
$assertSame(0, $partialRemoved['session_rows_deleted'] ?? null, 'Partial cleanup session count mismatch.');
$assertSame(0, $partialRemoved['device_rows_deleted'] ?? null, 'Partial cleanup device count mismatch.');
$assertSame(0, $partialRemoved['ownership_rows_deleted'] ?? null, 'Partial cleanup ownership count mismatch.');
$assertSame(1, $partialRemoved['identity_rows_deleted'] ?? null, 'Partial cleanup identity count mismatch.');
$partialUserRemoved = $partialCleanup->removeOrphanUserAfterCleanupProjection($mgwId);
$assertSame(1, $partialUserRemoved['user_rows_deleted'] ?? null, 'Partial cleanup user count mismatch.');
$assertSame(true, $partialCleanup->assertAbsent($provider, $subject, $subject, $sessionId, $mgwId)['ok'] ?? false, 'Partial cleanup absence proof failed.');

fwrite(STDOUT, "RuntimePrimaryStagingApiMutatingSmokeIdentityCleanupTest passed: {$assertions} assertions.\n");
