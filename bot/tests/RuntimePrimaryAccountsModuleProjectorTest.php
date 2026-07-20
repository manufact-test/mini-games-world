<?php
declare(strict_types=1);

interface DatabaseConnectionInterface
{
    public function driver(): string;
    public function execute(string $sql, array $params = []): int;
    public function fetchAll(string $sql, array $params = []): array;
    public function transaction(callable $callback): mixed;
    public function pdo(): PDO;
}
interface RuntimePrimaryModuleProjectorInterface
{
    public function module(): string;
    public function project(array $snapshot, int $stateRevision, string $stateSha256): array;
    public function audit(array $snapshot, int $stateRevision, string $stateSha256): array;
}
final class MgwIdGenerator
{
    private static int $sequence = 1;
    public static function generate(): string
    {
        return 'mgw_' . str_pad((string)self::$sequence++, 16, '0', STR_PAD_LEFT);
    }
    public static function isValid(string $value): bool
    {
        return preg_match('/^mgw_\d{16}$/', $value) === 1;
    }
}

final class RuntimePrimaryAccountsTestDatabase implements DatabaseConnectionInterface
{
    public array $users = [];
    public array $identities = [];
    public array $ownerships = [];

    public function driver(): string { return 'sqlite'; }

    public function execute(string $sql, array $params = []): int
    {
        $normalized = strtolower(preg_replace('/\s+/', ' ', trim($sql)) ?? '');
        if (str_starts_with($normalized, 'insert into mgw_users')) {
            $mgwId = (string)$params['mgw_id'];
            if (isset($this->users[$mgwId])) throw new RuntimeException('duplicate mgw user');
            $this->users[$mgwId] = [
                'mgw_id' => $mgwId,
                'status' => (string)$params['status'],
                'display_name' => (string)$params['display_name'],
                'username' => $params['username'],
                'avatar_provider' => $params['avatar_provider'],
                'avatar_external_ref' => $params['avatar_external_ref'],
                'created_at_utc' => (string)$params['created_at_utc'],
                'updated_at_utc' => (string)$params['updated_at_utc'],
                'last_seen_at_utc' => (string)$params['last_seen_at_utc'],
            ];
            return 1;
        }
        if (str_starts_with($normalized, 'update mgw_users set')) {
            $mgwId = (string)$params['mgw_id'];
            if (!isset($this->users[$mgwId])) return 0;
            foreach ([
                'status', 'display_name', 'username', 'avatar_provider',
                'avatar_external_ref', 'updated_at_utc', 'last_seen_at_utc',
            ] as $field) {
                $this->users[$mgwId][$field] = $params[$field];
            }
            if (array_key_exists('created_at_utc', $params)) {
                $this->users[$mgwId]['created_at_utc'] = $params['created_at_utc'];
            }
            return 1;
        }
        if (str_starts_with($normalized, 'insert into mgw_identities')) {
            $provider = (string)$params['provider'];
            $subject = (string)$params['provider_subject'];
            $key = $provider . '|' . $subject;
            if (isset($this->identities[$key])) throw new RuntimeException('duplicate identity');
            $this->identities[$key] = [
                'mgw_id' => (string)$params['mgw_id'],
                'provider' => $provider,
                'provider_subject' => $subject,
                'provider_username' => $params['provider_username'],
                'linked_at_utc' => (string)$params['linked_at_utc'],
                'last_authenticated_at_utc' => (string)$params['last_authenticated_at_utc'],
            ];
            return 1;
        }
        if (str_starts_with($normalized, 'insert into mgw_account_ownership')) {
            $accountRef = (string)$params['account_ref'];
            if (isset($this->ownerships[$accountRef])) throw new RuntimeException('duplicate ownership');
            $this->ownerships[$accountRef] = [
                'account_ref' => $accountRef,
                'mgw_id' => (string)$params['mgw_id'],
                'legacy_user_id' => (string)$params['legacy_user_id'],
                'ownership_status' => (string)$params['ownership_status'],
                'source_type' => (string)$params['source_type'],
                'source_ref' => (string)$params['source_ref'],
                'source_sha256' => (string)$params['source_sha256'],
                'created_at_utc' => (string)$params['created_at_utc'],
                'verified_at_utc' => (string)$params['verified_at_utc'],
            ];
            return 1;
        }
        if (str_starts_with($normalized, 'update mgw_account_ownership')) {
            $accountRef = (string)$params['account_ref'];
            if (!isset($this->ownerships[$accountRef])
                || $this->ownerships[$accountRef]['mgw_id'] !== (string)$params['mgw_id']) {
                return 0;
            }
            $this->ownerships[$accountRef]['source_sha256'] = (string)$params['source_sha256'];
            $this->ownerships[$accountRef]['verified_at_utc'] = (string)$params['verified_at_utc'];
            return 1;
        }
        throw new RuntimeException('Unexpected accounts test execute SQL: ' . $normalized);
    }

    public function fetchAll(string $sql, array $params = []): array
    {
        $normalized = strtolower(preg_replace('/\s+/', ' ', trim($sql)) ?? '');
        if (str_contains($normalized, 'from mgw_identities')) {
            $key = (string)($params['provider'] ?? '') . '|' . (string)($params['provider_subject'] ?? '');
            return isset($this->identities[$key]) ? [$this->identities[$key]] : [];
        }
        if (str_contains($normalized, 'from mgw_account_ownership')
            && str_contains($normalized, 'where account_ref = :account_ref or legacy_user_id = :legacy_user_id')) {
            $rows = [];
            foreach ($this->ownerships as $row) {
                if ($row['account_ref'] === (string)$params['account_ref']
                    || $row['legacy_user_id'] === (string)$params['legacy_user_id']) {
                    $rows[] = $row;
                }
            }
            return $rows;
        }
        if (str_contains($normalized, 'from mgw_account_ownership')
            && str_contains($normalized, 'where legacy_user_id = :legacy_user_id')) {
            return array_values(array_filter(
                $this->ownerships,
                static fn(array $row): bool => $row['legacy_user_id'] === (string)$params['legacy_user_id']
            ));
        }
        if (str_starts_with($normalized, 'select legacy_user_id from mgw_account_ownership')) {
            return array_map(
                static fn(array $row): array => ['legacy_user_id' => $row['legacy_user_id']],
                array_values($this->ownerships)
            );
        }
        if (str_contains($normalized, 'from mgw_users where mgw_id = :mgw_id')) {
            $mgwId = (string)$params['mgw_id'];
            return isset($this->users[$mgwId]) ? [$this->users[$mgwId]] : [];
        }
        throw new RuntimeException('Unexpected accounts test fetch SQL: ' . $normalized);
    }

    public function transaction(callable $callback): mixed
    {
        $before = [$this->users, $this->identities, $this->ownerships];
        try {
            return $callback($this);
        } catch (Throwable $error) {
            [$this->users, $this->identities, $this->ownerships] = $before;
            throw $error;
        }
    }

    public function pdo(): PDO { throw new RuntimeException('unused'); }
}

$projectRoot = dirname(__DIR__, 2);
require $projectRoot . '/bot/runtime/RuntimePrimaryAccountsModuleProjector.php';

$assertions = 0;
$assertTrue = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};
$assertThrows = static function (callable $callback, string $messagePart) use (&$assertions): void {
    $assertions++;
    try {
        $callback();
    } catch (Throwable $error) {
        if (str_contains(strtolower($error->getMessage()), strtolower($messagePart))) return;
        throw new RuntimeException('Unexpected exception: ' . $error->getMessage());
    }
    throw new RuntimeException('Expected exception was not thrown.');
};
$canonical = static function (array $value): string {
    $sort = static function (mixed $item) use (&$sort): mixed {
        if (!is_array($item)) return $item;
        if (!array_is_list($item)) ksort($item, SORT_STRING);
        foreach ($item as $key => $child) $item[$key] = $sort($child);
        return $item;
    };
    return json_encode($sort($value), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
};

$snapshot = [
    'users' => [
        '100' => [
            'id' => '100',
            'telegram_id' => '100',
            'first_name' => 'Илья',
            'username' => 'ilya',
            'photo_url' => 'https://example.test/avatar.jpg',
            'registered_at' => '2026-07-01T10:00:00+00:00',
            'last_seen_at' => '2026-07-20T10:00:00+00:00',
        ],
    ],
];
$stateSha = hash('sha256', $canonical($snapshot));
$database = new RuntimePrimaryAccountsTestDatabase();
$subject = new RuntimePrimaryAccountsModuleProjector($database);

$created = $subject->project($snapshot, 1, $stateSha);
$assertTrue(($created['ok'] ?? false) === true, 'Accounts projection must succeed from an empty database');
$assertTrue(($created['parity'] ?? false) === true, 'Accounts projection must prove parity');
$assertTrue(($created['summary']['created_user_count'] ?? 0) === 1, 'Accounts projection must create one MGW user');
$assertTrue(($created['summary']['created_identity_count'] ?? 0) === 2, 'Accounts projection must create real and legacy identities');
$assertTrue(($created['summary']['created_ownership_count'] ?? 0) === 1, 'Accounts projection must create ownership');
$assertTrue(count($database->users) === 1, 'Accounts database must contain one user');
$assertTrue(count($database->identities) === 2, 'Accounts database must contain two identities');
$assertTrue(count($database->ownerships) === 1, 'Accounts database must contain one ownership');
$assertTrue(
    hash_equals((string)$created['source_fingerprint'], (string)$created['database_fingerprint']),
    'Accounts projection fingerprints must match'
);

$idempotent = $subject->project($snapshot, 1, $stateSha);
$assertTrue(($idempotent['summary']['unchanged_user_count'] ?? 0) === 1, 'Repeated accounts projection must be idempotent');
$assertTrue(($idempotent['summary']['created_user_count'] ?? -1) === 0, 'Repeated accounts projection must not create another user');
$assertTrue(count($database->users) === 1 && count($database->identities) === 2, 'Repeated projection must not duplicate rows');

$updatedSnapshot = $snapshot;
$updatedSnapshot['users']['100']['first_name'] = 'Илья Новый';
$updatedSnapshot['users']['100']['last_seen_at'] = '2026-07-20T11:00:00+00:00';
$updatedSha = hash('sha256', $canonical($updatedSnapshot));
$updated = $subject->project($updatedSnapshot, 2, $updatedSha);
$assertTrue(($updated['summary']['updated_user_count'] ?? 0) === 1, 'Changed profile must update the MGW user');
$assertTrue(array_values($database->users)[0]['display_name'] === 'Илья Новый', 'Updated display name must persist');
$assertTrue(($subject->audit($updatedSnapshot, 2, $updatedSha)['ok'] ?? false) === true, 'Updated profile audit must pass');

$collisionDatabase = new RuntimePrimaryAccountsTestDatabase();
$collisionDatabase->identities['legacy_import|100'] = [
    'mgw_id' => 'mgw_0000000000000001',
    'provider' => 'legacy_import',
    'provider_subject' => '100',
];
$collisionDatabase->identities['telegram|100'] = [
    'mgw_id' => 'mgw_0000000000000002',
    'provider' => 'telegram',
    'provider_subject' => '100',
];
$collisionSubject = new RuntimePrimaryAccountsModuleProjector($collisionDatabase);
$assertThrows(
    static fn() => $collisionSubject->project($snapshot, 1, $stateSha),
    'identity mappings collide'
);
$assertTrue($collisionDatabase->users === [], 'Identity collision must not create a partial user');

$database->ownerships['legacy:999'] = [
    'account_ref' => 'legacy:999',
    'mgw_id' => 'mgw_0000000000000999',
    'legacy_user_id' => '999',
    'ownership_status' => 'active',
    'source_type' => 'runtime_primary_projection',
    'source_ref' => 'telegram:999',
    'source_sha256' => str_repeat('0', 64),
    'created_at_utc' => '2026-07-01T00:00:00+00:00',
    'verified_at_utc' => '2026-07-01T00:00:00+00:00',
];
$extraAudit = $subject->audit($updatedSnapshot, 2, $updatedSha);
$assertTrue(($extraAudit['ok'] ?? true) === false, 'Extra ownership outside snapshot must fail parity');
$assertTrue(($extraAudit['summary']['extra_ownership_count'] ?? 0) === 1, 'Extra ownership count must remain explicit');

fwrite(STDOUT, "RuntimePrimaryAccountsModuleProjectorTest passed: {$assertions} assertions.\n");
