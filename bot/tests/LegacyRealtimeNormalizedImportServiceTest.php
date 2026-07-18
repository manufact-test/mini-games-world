<?php
declare(strict_types=1);

$root = dirname(__DIR__);
require $root . '/storage/contracts/StorageTransactionInterface.php';
require $root . '/storage/contracts/StorageAdapterInterface.php';
require $root . '/database/DatabaseConnectionInterface.php';
require $root . '/database/PdoDatabaseConnection.php';
require $root . '/database/DatabaseMigrationInterface.php';
require $root . '/database/MigrationRepository.php';
require $root . '/database/MigrationRunner.php';
require $root . '/accounts/MgwIdGenerator.php';
require $root . '/ledger/LedgerIntegrity.php';
require $root . '/ledger/LedgerWriteService.php';
require $root . '/ledger/LedgerIntegrityVerifier.php';
require $root . '/ledger/LegacyEconomyShadowSyncService.php';
require $root . '/ledger/LegacyOpeningBalanceImportService.php';
require $root . '/accounts/LegacyAccountImportService.php';
require $root . '/accounts/LegacyAccountOwnershipLinkService.php';
require $root . '/realtime/LegacyRealtimeShadowSyncService.php';
require $root . '/migration/LegacyRealtimeNormalizedImportService.php';

if (!extension_loaded('pdo_sqlite')) {
    throw new RuntimeException('LegacyRealtimeNormalizedImportServiceTest requires pdo_sqlite.');
}

final class NormalizedRealtimeArrayStorage implements StorageAdapterInterface
{
    public function __construct(private array $data) {}
    public function replace(array $data): void { $this->data = $data; }
    public function transaction(callable $callback): mixed { return $callback($this->data); }
    public function readOnly(callable $callback): mixed { $snapshot = $this->data; return $callback($snapshot); }
    public function driver(): string { return 'json'; }
}

$assertions = 0;
$assertSame = static function (mixed $expected, mixed $actual, string $message) use (&$assertions): void {
    $assertions++;
    if ($expected !== $actual) {
        throw new RuntimeException(
            $message . ': expected ' . var_export($expected, true) . ', got ' . var_export($actual, true)
        );
    }
};
$assertTrue = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

$data = [
    'users' => [
        '1001' => [
            'id' => '1001',
            'telegram_id' => '1001',
            'first_name' => 'Первый',
            'username' => 'first_player',
            'balance_match' => 30,
            'balance_gold' => 0,
            'registered_at' => '2026-07-18T08:00:00+00:00',
            'last_seen_at' => '2026-07-18T09:00:00+00:00',
        ],
        '1002' => [
            'id' => '1002',
            'telegram_id' => '1002',
            'first_name' => 'Второй',
            'username' => 'second_player',
            'balance_match' => 20,
            'balance_gold' => 5,
            'registered_at' => '2026-07-18T08:05:00+00:00',
            'last_seen_at' => '2026-07-18T09:05:00+00:00',
        ],
    ],
    'transactions' => [],
    'games' => [
        'game-human-bot' => [
            'id' => 'game-human-bot',
            'game_type' => 'battleship',
            'room' => 'match',
            'bet' => 10,
            'board_size' => 10,
            'player_ids' => ['1001', 'bot_alpha'],
            'player_names' => ['1001' => 'Первый', 'bot_alpha' => 'Лео'],
            'status' => 'finished',
            'winner_id' => '1001',
            'loser_id' => 'bot_alpha',
            'finish_reason' => 'normal_win',
            'is_bot_game' => true,
            'bot_id' => 'bot_alpha',
            'battleship_fleets' => [
                '1001' => ['ships' => [['id' => 'private-human-ship', 'cells' => [0, 1]]]],
                'bot_alpha' => ['ships' => [['id' => 'private-bot-ship', 'cells' => [10, 11]]]],
            ],
            'created_at' => '2026-07-18T09:10:00+00:00',
            'updated_at' => '2026-07-18T09:20:00+00:00',
            'finished_at' => '2026-07-18T09:20:00+00:00',
        ],
        'game-human-human' => [
            'id' => 'game-human-human',
            'game_type' => 'tictactoe',
            'room' => 'match',
            'bet' => 10,
            'board_size' => 3,
            'board' => 'XOXOXOOXO',
            'player_ids' => ['1001', '1002'],
            'player_names' => ['1001' => 'Первый', '1002' => 'Второй'],
            'symbols' => ['1001' => 'X', '1002' => 'O'],
            'status' => 'finished',
            'winner_id' => null,
            'loser_id' => null,
            'finish_reason' => 'draw',
            'created_at' => '2026-07-18T09:30:00+00:00',
            'updated_at' => '2026-07-18T09:40:00+00:00',
            'finished_at' => '2026-07-18T09:40:00+00:00',
        ],
    ],
    'queue' => [[
        'id' => 'queue-1001',
        'user_id' => '1001',
        'game_type' => 'checkers',
        'room' => 'match',
        'bet' => 10,
        'board_size' => 8,
        'status' => 'waiting',
        'created_at' => '2026-07-18T09:50:00+00:00',
        'updated_at' => '2026-07-18T09:50:05+00:00',
        'expires_at' => '2026-07-18T10:05:00+00:00',
    ]],
    'invites' => [[
        'id' => 'invite-1001-1002',
        'token' => 'invite-token-1001-1002',
        'status' => 'pending',
        'source' => 'direct',
        'inviter_id' => '1001',
        'inviter_name' => 'Первый',
        'invitee_id' => '1002',
        'invitee_name' => 'Второй',
        'game_type' => 'reversi',
        'game_title' => 'Реверси',
        'room' => 'match',
        'bet' => 10,
        'board_size' => 8,
        'board_columns' => 8,
        'board_rows' => 8,
        'created_at' => '2026-07-18T10:00:00+00:00',
        'updated_at' => '2026-07-18T10:00:01+00:00',
        'expires_at' => '2026-07-18T10:15:00+00:00',
        'shared_at' => '2026-07-18T10:00:00+00:00',
    ]],
    'notifications' => [[
        'id' => 'notification-1002',
        'event_key' => 'invite:invite-1001-1002:received:1002',
        'user_id' => '1002',
        'type' => 'invite_received',
        'title' => 'Вас пригласили сыграть',
        'message' => 'Первый приглашает вас в «Реверси».',
        'tone' => 'info',
        'invite_token' => 'invite-token-1001-1002',
        'created_at' => '2026-07-18T10:00:02+00:00',
        'read_at' => null,
    ]],
    'payments' => [],
    'shop_orders' => [],
];

$storage = new NormalizedRealtimeArrayStorage($data);
$pdo = new PDO('sqlite::memory:');
$pdo->exec('PRAGMA foreign_keys = ON');
$database = new PdoDatabaseConnection($pdo);
$runner = new MigrationRunner($database, $root . '/database/migrations');
$assertSame(7, $runner->migrate(false)['executed_count'], 'Normalized realtime test must apply all migrations');

$economy = new LegacyEconomyShadowSyncService($storage, $database);
$assertSame(true, $economy->run()['ok'], 'Economy shadow must be prepared');
$verifier = new LedgerIntegrityVerifier($database);
$opening = new LegacyOpeningBalanceImportService(
    $database,
    new LedgerWriteService($database),
    $verifier
);
$assertSame('completed', $opening->run()['status'], 'Opening balances must be imported first');
$assertSame('completed', (new LegacyAccountImportService($storage, $database))->run()['status'], 'Accounts must be imported first');
$ownership = new LegacyAccountOwnershipLinkService($storage, $database, $verifier);
$assertSame('completed', $ownership->run()['status'], 'Account ownership must be linked first');

$shadow = new LegacyRealtimeShadowSyncService($storage, $database);
$assertSame(true, $shadow->run()['ok'], 'Realtime shadow must be prepared');
$service = new LegacyRealtimeNormalizedImportService($storage, $database, $shadow);
$preview = $service->preview();
$assertSame(true, $preview['ready'], 'Clean normalized realtime preview must be ready');
$assertSame('not_started', $preview['status'], 'Fresh normalized import must not have metadata');
$assertSame(['games' => 2, 'queue' => 1, 'invites' => 1, 'notifications' => 1], $preview['source_counts'], 'Source counts must be exact');
$assertSame($preview['source_counts'], $preview['planned_create_counts'], 'Every source row must be planned on empty target');
$assertSame(['games' => 0, 'queue' => 0, 'invites' => 0, 'notifications' => 0], $preview['conflict_counts'], 'Preview must have no conflicts');
$assertSame(false, array_key_exists('items', $preview), 'Preview output must not expose full source payloads');

$balanceBefore = LedgerIntegrity::canonicalJson($database->fetchAll(
    'SELECT account_ref, asset_code, available_amount, reserved_amount FROM mgw_balances ORDER BY account_ref, asset_code'
));
$ledgerBefore = LedgerIntegrity::canonicalJson($database->fetchAll(
    'SELECT entry_id, entry_sha256 FROM mgw_ledger_entries ORDER BY ledger_sequence'
));

$first = $service->run();
$assertSame('completed', $first['status'], 'Normalized realtime import must complete');
$assertSame(['games' => 2, 'queue' => 1, 'invites' => 1, 'notifications' => 1], $first['created_counts'], 'First run must create every normalized row');
$assertSame(true, $first['verification']['ok'], 'Final normalized verification must pass');
$assertSame(2, (int)$database->fetchValue('SELECT COUNT(*) FROM mgw_matches'), 'Two matches must be imported');
$assertSame(4, (int)$database->fetchValue('SELECT COUNT(*) FROM mgw_match_players'), 'Four match players must be imported');
$assertSame(2, (int)$database->fetchValue('SELECT COUNT(*) FROM mgw_match_snapshots'), 'One server snapshot per match must be imported');
$assertSame(0, (int)$database->fetchValue('SELECT COUNT(*) FROM mgw_match_player_snapshots'), 'No unsafe private snapshot must be exposed');
$assertSame(1, (int)$database->fetchValue('SELECT COUNT(*) FROM mgw_match_queue'), 'Queue row must be imported');
$assertSame(1, (int)$database->fetchValue('SELECT COUNT(*) FROM mgw_invites'), 'Invite row must be imported');
$assertSame(1, (int)$database->fetchValue('SELECT COUNT(*) FROM mgw_notifications'), 'Notification row must be imported');

$match = $database->fetchAll(
    'SELECT public_state_json, server_state_json, match_source FROM mgw_matches WHERE match_id = :match_id',
    ['match_id' => 'game-human-bot']
)[0] ?? [];
$assertSame(null, $match['public_state_json'] ?? null, 'Legacy import must not expose server state as public JSON');
$assertTrue(str_contains((string)($match['server_state_json'] ?? ''), 'private-human-ship'), 'Exact hidden game state must remain in server-only JSON');
$assertSame('legacy_json', (string)($match['match_source'] ?? ''), 'Legacy match source must be explicit');
$assertSame('bot', (string)$database->fetchValue(
    'SELECT player_type FROM mgw_match_players WHERE match_id = :match_id AND player_ref = :player_ref',
    ['match_id' => 'game-human-bot', 'player_ref' => 'bot:bot_alpha']
), 'Bot player must remain explicit internally');
$assertSame(null, $database->fetchValue(
    'SELECT mgw_id FROM mgw_match_players WHERE match_id = :match_id AND player_ref = :player_ref',
    ['match_id' => 'game-human-bot', 'player_ref' => 'bot:bot_alpha']
), 'Bot must not receive an MGW identity');
$assertTrue(MgwIdGenerator::isValid((string)$database->fetchValue(
    'SELECT mgw_id FROM mgw_match_players WHERE match_id = :match_id AND player_ref = :player_ref',
    ['match_id' => 'game-human-human', 'player_ref' => 'legacy:1002']
)), 'Human player must link to the staged MGW ID');
$assertTrue(MgwIdGenerator::isValid((string)$database->fetchValue(
    'SELECT mgw_id FROM mgw_notifications WHERE notification_id = :notification_id',
    ['notification_id' => 'notification-1002']
)), 'Notification must link to the recipient MGW ID');
$assertSame($balanceBefore, LedgerIntegrity::canonicalJson($database->fetchAll(
    'SELECT account_ref, asset_code, available_amount, reserved_amount FROM mgw_balances ORDER BY account_ref, asset_code'
)), 'Realtime import must not alter balances');
$assertSame($ledgerBefore, LedgerIntegrity::canonicalJson($database->fetchAll(
    'SELECT entry_id, entry_sha256 FROM mgw_ledger_entries ORDER BY ledger_sequence'
)), 'Realtime import must not alter immutable ledger entries');

$repeat = $service->run();
$assertSame(['games' => 0, 'queue' => 0, 'invites' => 0, 'notifications' => 0], $repeat['created_counts'], 'Repeat must not create normalized rows');
$assertSame(['games' => 2, 'queue' => 1, 'invites' => 1, 'notifications' => 1], $repeat['unchanged_counts'], 'Repeat must verify every normalized row');

$database->execute(
    'UPDATE mgw_notifications SET title = :title WHERE notification_id = :notification_id',
    ['title' => 'Tampered', 'notification_id' => 'notification-1002']
);
$tampered = $service->preview();
$assertSame(false, $tampered['ready'], 'Target drift must block normalized import');
$assertSame(1, $tampered['conflict_counts']['notifications'], 'Target drift must be counted');
$database->execute(
    'UPDATE mgw_notifications SET title = :title WHERE notification_id = :notification_id',
    ['title' => 'Вас пригласили сыграть', 'notification_id' => 'notification-1002']
);

$changed = $data;
$changed['notifications'][0]['message'] = 'Источник изменился после shadow sync.';
$storage->replace($changed);
$drift = $service->preview();
$assertSame(false, $drift['ready'], 'Unsynchronized JSON drift must block normalized import');
$assertTrue(
    str_contains(implode(' ', $drift['blocking_reasons']), 'shadow differs'),
    'JSON/shadow drift reason must be explicit'
);

fwrite(STDOUT, "LegacyRealtimeNormalizedImportServiceTest passed: {$assertions} assertions.\n");
