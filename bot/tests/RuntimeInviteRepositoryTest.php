<?php
declare(strict_types=1);

$root = dirname(__DIR__);
require $root . '/storage/contracts/StorageTransactionInterface.php';
require $root . '/storage/contracts/StorageAdapterInterface.php';
require $root . '/database/DatabaseConnectionInterface.php';
require $root . '/database/DatabaseConfig.php';
require $root . '/database/PdoDatabaseConnection.php';
require $root . '/database/PdoConnectionFactory.php';
require $root . '/database/DatabaseMigrationInterface.php';
require $root . '/database/MigrationRepository.php';
require $root . '/database/MigrationRunner.php';
require $root . '/storage/RuntimeStorageRouter.php';
require $root . '/realtime/RealtimeDatabaseStore.php';
require $root . '/invites/RuntimeInviteRepository.php';

if (!extension_loaded('pdo_sqlite')) {
    throw new RuntimeException('RuntimeInviteRepositoryTest requires pdo_sqlite.');
}

$assertions = 0;
$assertSame = static function (mixed $expected, mixed $actual, string $message) use (&$assertions): void {
    $assertions++;
    if ($expected !== $actual) {
        throw new RuntimeException($message . ': expected ' . var_export($expected, true)
            . ', got ' . var_export($actual, true));
    }
};
$assertThrows = static function (callable $callback, string $contains, string $message) use (&$assertions): void {
    $assertions++;
    try {
        $callback();
    } catch (Throwable $error) {
        if (str_contains(strtolower($error->getMessage()), strtolower($contains))) return;
        throw new RuntimeException($message . ': unexpected error ' . $error->getMessage());
    }
    throw new RuntimeException($message . ': no error was thrown');
};

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('PRAGMA foreign_keys = ON');
$database = new PdoDatabaseConnection($pdo);
$runner = new MigrationRunner($database, $root . '/database/migrations');
$assertSame(7, $runner->migrate(false)['executed_count'], 'Invite runtime test must apply all migrations');

$now = '2026-07-18 18:30:00.000000';
foreach ([
    ['legacy' => '1001', 'mgw' => 'MGW-INVITER0000001', 'name' => 'Inviter'],
    ['legacy' => '1002', 'mgw' => 'MGW-INVITEE0000001', 'name' => 'Invitee'],
] as $identity) {
    $database->execute(
        'INSERT INTO mgw_users (
            mgw_id, status, display_name, username, created_at_utc, updated_at_utc, last_seen_at_utc
         ) VALUES (
            :mgw_id, :status, :display_name, NULL, :created_at, :updated_at, :last_seen_at
         )',
        [
            'mgw_id' => $identity['mgw'],
            'status' => 'active',
            'display_name' => $identity['name'],
            'created_at' => $now,
            'updated_at' => $now,
            'last_seen_at' => $now,
        ]
    );
    $database->execute(
        'INSERT INTO mgw_account_ownership (
            account_ref, mgw_id, legacy_user_id, ownership_status,
            source_type, source_ref, source_sha256, created_at_utc, verified_at_utc
         ) VALUES (
            :account_ref, :mgw_id, :legacy_user_id, :ownership_status,
            :source_type, :source_ref, :source_sha256, :created_at, :verified_at
         )',
        [
            'account_ref' => 'legacy:' . $identity['legacy'],
            'mgw_id' => $identity['mgw'],
            'legacy_user_id' => $identity['legacy'],
            'ownership_status' => 'active',
            'source_type' => 'legacy_json',
            'source_ref' => 'users.json:' . $identity['legacy'],
            'source_sha256' => hash('sha256', 'invite-owner-' . $identity['legacy']),
            'created_at' => $now,
            'verified_at' => $now,
        ]
    );
}

$config = [
    'environment' => 'staging',
    'storage_driver' => 'json',
    'database' => [
        'enabled' => true,
        'driver' => 'mysql',
        'host' => 'localhost',
        'port' => 3306,
        'name' => 'mgw_test',
        'user' => 'mgw_test',
        'password' => 'test-password',
        'charset' => 'utf8mb4',
    ],
    'feature_flags' => [
        'database_runtime' => [
            'enabled' => true,
            'modules' => [
                'accounts' => true,
                'notifications' => true,
                'invites' => true,
            ],
        ],
    ],
];
$repository = new RuntimeInviteRepository($config, new RuntimeStorageRouter($config), $database);
$data = [
    'invites' => [[
        'id' => 'invite-runtime-1',
        'token' => 'invite-token-1',
        'status' => 'pending',
        'source' => 'direct',
        'inviter_id' => '1001',
        'inviter_name' => 'Inviter',
        'invitee_id' => '1002',
        'invitee_name' => 'Invitee',
        'game_type' => 'tictactoe',
        'game_title' => 'Крестики-нолики',
        'room' => 'match',
        'bet' => 10,
        'board_size' => 3,
        'board_columns' => 3,
        'board_rows' => 3,
        'created_at' => '2026-07-18T18:30:00+00:00',
        'updated_at' => '2026-07-18T18:30:00+00:00',
        'expires_at' => '2026-07-18T18:45:00+00:00',
        'shared_at' => '2026-07-18T18:30:00+00:00',
        'opened_at' => null,
        'accepted_at' => null,
        'ready_deadline_at' => null,
        'started_at' => null,
        'declined_at' => null,
        'cancelled_at' => null,
        'cancelled_by' => null,
        'source_game_id' => null,
        'game_id' => null,
    ]],
];

$first = $repository->synchronize($data);
$assertSame(1, $first['created_count'], 'First invite sync must create one DB row');
$assertSame(1, $first['source_count'], 'First invite sync must count one source row');
$assertSame(1, $first['database_count'], 'First invite sync must count one DB row');
$assertSame(true, $first['parity'], 'First invite sync must prove parity');

$repeat = $repository->synchronize($data);
$assertSame(0, $repeat['created_count'], 'Repeat invite sync must create nothing');
$assertSame(0, $repeat['updated_count'], 'Repeat invite sync must update nothing');
$assertSame(1, $repeat['unchanged_count'], 'Repeat invite sync must verify one unchanged row');

$audit = $repository->auditParity($data);
$assertSame(true, $audit['ok'], 'Read-only invite parity audit must pass');
$assertSame(true, $audit['read_only'], 'Invite audit must report read-only mode');
$assertSame([], $audit['blockers'], 'Clean invite audit must have no blockers');

$data['invites'][0]['status'] = 'cancelled';
$data['invites'][0]['updated_at'] = '2026-07-18T18:31:00+00:00';
$data['invites'][0]['cancelled_at'] = '2026-07-18T18:31:00+00:00';
$data['invites'][0]['cancelled_by'] = '1001';
$updated = $repository->synchronize($data);
$assertSame(1, $updated['updated_count'], 'JSON status transition must update one DB row');
$assertSame(true, $repository->auditParity($data)['ok'], 'Audit must pass after status transition');

$database->execute(
    'UPDATE mgw_invites SET token = :token WHERE invite_id = :invite_id',
    ['token' => 'tampered-token', 'invite_id' => 'invite-runtime-1']
);
$assertThrows(
    static fn() => $repository->synchronize($data),
    'immutable identity',
    'Invite immutable DB drift must fail closed'
);

fwrite(STDOUT, "RuntimeInviteRepositoryTest: {$assertions} assertions passed\n");
