<?php
declare(strict_types=1);

$projectRoot = dirname(__DIR__, 2);
require_once $projectRoot . '/bot/database/DatabaseConnectionInterface.php';
require_once $projectRoot . '/bot/accounts/MgwIdGenerator.php';
require_once $projectRoot . '/bot/runtime/RuntimePrimaryStateSchemaInstaller.php';
require_once $projectRoot . '/bot/runtime/ProductionPrimaryRollbackSnapshotMaterializer.php';
require_once $projectRoot . '/bot/runtime/ProductionPrimaryRollbackMaterializedStateConnection.php';

final class ProductionRollbackMaterializerTestDatabase implements DatabaseConnectionInterface
{
    public array $users = [];
    public array $identities = [];
    public array $ownerships = [];
    public array $stateRow = [];
    public int $executeCalls = 0;
    public int $fetchCalls = 0;

    public function driver(): string
    {
        return 'mysql';
    }

    public function execute(string $sql, array $params = []): int
    {
        $this->executeCalls++;
        return 1;
    }

    public function fetchAll(string $sql, array $params = []): array
    {
        $this->fetchCalls++;
        $normalized = strtolower(preg_replace('/\s+/', ' ', trim($sql)) ?? '');
        if (str_contains($normalized, 'from ' . strtolower(RuntimePrimaryStateSchemaInstaller::TABLE))) {
            return [$this->stateRow];
        }
        if (str_contains($normalized, 'from mgw_account_ownership')) {
            $legacyId = (string)($params['legacy_user_id'] ?? '');
            return isset($this->ownerships[$legacyId]) ? [$this->ownerships[$legacyId]] : [];
        }
        if (str_contains($normalized, 'from mgw_users')) {
            $mgwId = (string)($params['mgw_id'] ?? '');
            return isset($this->users[$mgwId]) ? [$this->users[$mgwId]] : [];
        }
        if (str_contains($normalized, 'from mgw_identities')) {
            $key = (string)($params['provider'] ?? '') . '|'
                . (string)($params['provider_subject'] ?? '');
            return isset($this->identities[$key]) ? [$this->identities[$key]] : [];
        }
        throw new RuntimeException('Unexpected materializer test query: ' . $normalized);
    }

    public function fetchValue(string $sql, array $params = []): mixed
    {
        $rows = $this->fetchAll($sql, $params);
        if ($rows === [] || !is_array($rows[0])) return null;
        $value = reset($rows[0]);
        return $value === false ? null : $value;
    }

    public function transaction(callable $callback): mixed
    {
        return $callback($this);
    }
}

$assertions = 0;
$assertSame = static function (mixed $expected, mixed $actual, string $message) use (&$assertions): void {
    $assertions++;
    if ($expected !== $actual) {
        throw new RuntimeException(
            $message . ': expected ' . var_export($expected, true)
                . ', got ' . var_export($actual, true)
        );
    }
};
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
$canonicalJson = static function (array $value): string {
    $canonicalize = static function (mixed $item) use (&$canonicalize): mixed {
        if (!is_array($item)) return $item;
        if (!array_is_list($item)) ksort($item, SORT_STRING);
        foreach ($item as $key => $child) $item[$key] = $canonicalize($child);
        return $item;
    };
    return json_encode(
        $canonicalize($value),
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
    );
};

$snapshot = [
    'users' => [
        '100' => [
            'id' => '100',
            'telegram_id' => '100',
            'first_name' => 'Первый',
            'username' => 'stale_name',
            'registered_at' => '2026-07-01T09:00:00+00:00',
            'last_seen_at' => '2026-07-29T10:00:00+00:00',
        ],
        '200' => [
            'id' => '200',
            'telegram_id' => '200',
            'first_name' => 'Второй',
            'registered_at' => '2026-07-02T09:00:00+00:00',
            'last_seen_at' => '2026-07-29T11:00:00+00:00',
        ],
    ],
    'games' => [],
    'queue' => [],
    'transactions' => [],
    'support' => [],
    'shop_orders' => [],
    'payments' => [],
    'notifications' => [],
    'invites' => [],
    'system' => [],
];
$sourceSnapshot = $snapshot;
$sourceSha = hash('sha256', $canonicalJson($snapshot));
$database = new ProductionRollbackMaterializerTestDatabase();

$fixtures = [
    '100' => [
        'mgw_id' => 'MGW-0000000000000001',
        'display_name' => 'Первый',
        'created_at_utc' => '2026-07-01 09:00:00',
        'activity_at_utc' => '2026-07-29 10:00:03',
    ],
    '200' => [
        'mgw_id' => 'MGW-0000000000000002',
        'display_name' => 'Второй',
        'created_at_utc' => '2026-07-02 09:00:00',
        'activity_at_utc' => '2026-07-29 11:00:02',
    ],
];
foreach ($fixtures as $legacyId => $fixture) {
    $mgwId = $fixture['mgw_id'];
    $database->ownerships[$legacyId] = [
        'account_ref' => 'legacy:' . $legacyId,
        'mgw_id' => $mgwId,
        'legacy_user_id' => $legacyId,
        'ownership_status' => 'active',
    ];
    $database->users[$mgwId] = [
        'status' => 'active',
        'display_name' => $fixture['display_name'],
        'username' => null,
        'avatar_provider' => null,
        'avatar_external_ref' => null,
        'created_at_utc' => $fixture['created_at_utc'],
        'updated_at_utc' => $fixture['activity_at_utc'],
        'last_seen_at_utc' => $fixture['activity_at_utc'],
    ];
    $database->identities['telegram|' . $legacyId] = [
        'mgw_id' => $mgwId,
        'provider' => 'telegram',
        'provider_subject' => $legacyId,
        'provider_username' => null,
        'linked_at_utc' => $fixture['created_at_utc'],
        'last_authenticated_at_utc' => $fixture['activity_at_utc'],
    ];
    $database->identities['legacy_import|' . $legacyId] = [
        'mgw_id' => $mgwId,
        'provider' => 'legacy_import',
        'provider_subject' => $legacyId,
    ];
}
$database->stateRow = [
    'singleton_id' => 1,
    'revision' => 2190,
    'state_json' => $canonicalJson($snapshot),
    'state_sha256' => $sourceSha,
    'created_at_utc' => '2026-07-01 09:00:00',
    'updated_at_utc' => '2026-07-29 11:00:00',
];

$result = (new ProductionPrimaryRollbackSnapshotMaterializer($database))->materialize(
    $snapshot,
    2190,
    $sourceSha
);
$materialized = $result['snapshot'];

$assertSame(true, $result['ok'], 'Materialization must pass');
$assertSame(true, $result['read_only'], 'Materialization must be read-only');
$assertSame(false, $result['database_write_executed'], 'Materialization must report no DB write');
$assertSame(true, $result['applied'], 'Stale account metadata must trigger materialization');
$assertSame(2, $result['changed_user_count'], 'Two stale account records must be materialized');
$assertSame(3, $result['changed_field_count'], 'Only one username and two activity fields must change');
$assertSame($sourceSha, $result['source_state_sha256'], 'Source state SHA must remain preserved');
$assertTrue(
    !hash_equals($sourceSha, $result['materialized_state_sha256']),
    'Materialized state SHA must be distinct when profile data changed'
);
$assertSame(
    $result['materialized_state_sha256'],
    hash('sha256', $canonicalJson($materialized)),
    'Materialized state SHA must bind the in-memory snapshot'
);
$assertSame(false, array_key_exists('username', $materialized['users']['100']), 'Stale username must be removed');
$assertSame(
    '2026-07-29T10:00:03+00:00',
    $materialized['users']['100']['last_seen_at'],
    'First user activity must come from normalized account data'
);
$assertSame(
    '2026-07-29T11:00:02+00:00',
    $materialized['users']['200']['last_seen_at'],
    'Second user activity must come from normalized account data'
);
$assertSame($sourceSnapshot, $snapshot, 'Materialization must not mutate the source snapshot');
$assertSame(0, $database->executeCalls, 'Materialization must never execute SQL writes');

$sealed = new ProductionPrimaryRollbackMaterializedStateConnection($database, $result);
$lockedRows = $sealed->transaction(static function (DatabaseConnectionInterface $connection): array {
    return $connection->fetchAll(
        'SELECT singleton_id, revision, state_json, state_sha256,
                created_at_utc, updated_at_utc
         FROM ' . RuntimePrimaryStateSchemaInstaller::TABLE . '
         WHERE singleton_id = 1 FOR UPDATE'
    );
});
$assertSame(1, count($lockedRows), 'Materialized state lock must return one row');
$assertSame(
    $result['materialized_state_sha256'],
    $lockedRows[0]['state_sha256'],
    'Locked exporter read must receive the materialized SHA'
);
$assertSame(
    $result['materialized_state_sha256'],
    hash('sha256', (string)$lockedRows[0]['state_json']),
    'Locked exporter read must receive the canonical materialized JSON'
);
$assertSame(true, $sealed->sourceLockVerified(), 'Source state identity must be verified under lock');
$assertSame(1, $sealed->stateSubstitutionCount(), 'State substitution must happen exactly once');
$assertThrows(
    static fn() => $sealed->execute('UPDATE mgw_users SET status = status'),
    'write-sealed'
);
$assertSame(0, $database->executeCalls, 'Write-sealed connection must not delegate SQL writes');

$database->identities['telegram|100']['provider_username'] = 'different';
$assertThrows(
    static fn() => (new ProductionPrimaryRollbackSnapshotMaterializer($database))->materialize(
        $sourceSnapshot,
        2190,
        $sourceSha
    ),
    'username sources disagree'
);
$assertSame(0, $database->executeCalls, 'Blocked materialization must remain read-only');

fwrite(
    STDOUT,
    "ProductionPrimaryRollbackSnapshotMaterializerTest passed: {$assertions} assertions.\n"
);
