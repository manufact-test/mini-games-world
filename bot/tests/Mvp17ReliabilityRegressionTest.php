<?php
declare(strict_types=1);

$projectRoot = dirname(__DIR__, 2);

require $projectRoot . '/bot/storage/contracts/StorageTransactionInterface.php';
require $projectRoot . '/bot/storage/contracts/StorageAdapterInterface.php';
require $projectRoot . '/bot/database/DatabaseConnectionInterface.php';
require $projectRoot . '/bot/database/DatabaseExceptionClassifier.php';
require $projectRoot . '/bot/database/PdoDatabaseConnection.php';
require $projectRoot . '/bot/database/DatabaseMigrationInterface.php';
require $projectRoot . '/bot/database/MigrationRepository.php';
require $projectRoot . '/bot/database/MigrationRunner.php';
require $projectRoot . '/bot/realtime/RealtimeDatabaseStore.php';
require $projectRoot . '/bot/runtime/RuntimePrimaryStateSchemaInstaller.php';
require $projectRoot . '/bot/runtime/DatabasePrimaryStateStorageAdapter.php';
require $projectRoot . '/bot/services/PresenceService.php';
require $projectRoot . '/bot/services/ReconnectLifecycleService.php';

if (!function_exists('now_iso')) {
    function now_iso(): string { return gmdate('c'); }
}
if (!function_exists('make_id')) {
    function make_id(string $prefix = 'id'): string {
        static $sequence = 0;
        $sequence++;
        return $prefix . '_mvp17_7_' . $sequence;
    }
}

if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    throw new RuntimeException('Mvp17ReliabilityRegressionTest requires PDO SQLite.');
}

$assertions = 0;
$assertTrue = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};
$assertSame = static function (mixed $expected, mixed $actual, string $message) use (&$assertions): void {
    $assertions++;
    if ($expected !== $actual) {
        throw new RuntimeException($message . ': expected ' . var_export($expected, true)
            . ', got ' . var_export($actual, true));
    }
};
$assertThrows = static function (callable $callback, string $contains, string $message) use (&$assertions): Throwable {
    $assertions++;
    try {
        $callback();
    } catch (Throwable $error) {
        if ($contains !== '' && !str_contains(strtolower($error->getMessage()), strtolower($contains))) {
            throw new RuntimeException($message . ': unexpected error: ' . $error->getMessage(), 0, $error);
        }
        return $error;
    }
    throw new RuntimeException($message . ': expected exception was not thrown.');
};
$deadlock = static function (): PDOException {
    $error = new PDOException('Deadlock found when trying to get lock');
    $error->errorInfo = ['40001', 1213, 'Deadlock found when trying to get lock'];
    return $error;
};

// -------------------------------------------------------------------------
// 1. DB deadlock retry: one exact outer replay, no sleeps/generic retries.
// -------------------------------------------------------------------------
$retryPdo = new PDO('sqlite::memory:');
$retryDb = new PdoDatabaseConnection($retryPdo);
$retryDb->execute('CREATE TABLE retry_probe (id INTEGER PRIMARY KEY, marker TEXT NOT NULL)');
$attempts = 0;
$result = $retryDb->transaction(function (DatabaseConnectionInterface $db) use (&$attempts, $deadlock): string {
    $attempts++;
    $db->execute('INSERT INTO retry_probe (id, marker) VALUES (1, :marker)', ['marker' => 'attempt-' . $attempts]);
    if ($attempts === 1) throw $deadlock();
    return 'committed';
});
$assertSame('committed', $result, 'Deadlocked outer transaction must replay and return second result');
$assertSame(2, $attempts, 'Deadlock must allow exactly one retry');
$rows = $retryDb->fetchAll('SELECT id, marker FROM retry_probe ORDER BY id');
$assertSame(1, count($rows), 'Rolled-back first attempt must not leave duplicate rows');
$assertSame('attempt-2', $rows[0]['marker'] ?? null, 'Only second atomic attempt may commit');

$genericAttempts = 0;
$assertThrows(
    static function () use ($retryDb, &$genericAttempts): void {
        $retryDb->transaction(function (DatabaseConnectionInterface $db) use (&$genericAttempts): void {
            $genericAttempts++;
            $db->execute('INSERT INTO retry_probe (id, marker) VALUES (2, :marker)', ['marker' => 'generic']);
            throw new RuntimeException('generic failure');
        });
    },
    'generic failure',
    'Non-deadlock transaction failure must surface'
);
$assertSame(1, $genericAttempts, 'Generic failure must never be retried');
$assertSame(0, (int)$retryDb->fetchValue('SELECT COUNT(*) FROM retry_probe WHERE id = 2'), 'Generic failure must roll back');

$outerAttempts = 0;
$nestedAttempts = 0;
$retryDb->transaction(function (DatabaseConnectionInterface $db) use (&$outerAttempts, &$nestedAttempts, $deadlock): void {
    $outerAttempts++;
    $db->execute('INSERT INTO retry_probe (id, marker) VALUES (3, :marker)', ['marker' => 'outer-' . $outerAttempts]);
    $db->transaction(function (DatabaseConnectionInterface $nested) use (&$nestedAttempts, $deadlock): void {
        $nestedAttempts++;
        $nested->execute('INSERT INTO retry_probe (id, marker) VALUES (4, :marker)', ['marker' => 'nested-' . $nestedAttempts]);
        if ($nestedAttempts === 1) throw $deadlock();
    });
});
$assertSame(2, $outerAttempts, 'Nested deadlock must replay the whole outer atomic unit');
$assertSame(2, $nestedAttempts, 'Nested callback must run once per outer attempt');
$assertSame(1, (int)$retryDb->fetchValue('SELECT COUNT(*) FROM retry_probe WHERE id = 3'), 'Outer partial write must not duplicate');
$assertSame(1, (int)$retryDb->fetchValue('SELECT COUNT(*) FROM retry_probe WHERE id = 4'), 'Nested partial write must not duplicate');

// -------------------------------------------------------------------------
// 2. Same-user double request: converge on player_ref, never another player.
// -------------------------------------------------------------------------
$queuePdo = new PDO('sqlite::memory:');
$queuePdo->exec('PRAGMA foreign_keys = ON');
$queueDb = new PdoDatabaseConnection($queuePdo);
$queueRunner = new MigrationRunner($queueDb, $projectRoot . '/bot/database/migrations');
$assertSame(10, $queueRunner->migrate(false)['executed_count'], 'Reliability fixture must apply all ten canonical migrations');

final class Mvp17QueueCollisionDatabase implements DatabaseConnectionInterface
{
    private bool $inject = true;

    public function __construct(private PdoDatabaseConnection $delegate, private string $playerRef) {}
    public function driver(): string { return $this->delegate->driver(); }
    public function fetchAll(string $sql, array $parameters = []): array { return $this->delegate->fetchAll($sql, $parameters); }
    public function fetchValue(string $sql, array $parameters = []): mixed { return $this->delegate->fetchValue($sql, $parameters); }
    public function transaction(callable $callback): mixed {
        return $this->delegate->transaction(fn(DatabaseConnectionInterface $db): mixed => $callback($this));
    }
    public function execute(string $sql, array $parameters = []): int {
        $normalized = strtolower(preg_replace('/\s+/', ' ', trim($sql)) ?? '');
        if ($this->inject && str_starts_with($normalized, 'insert into mgw_match_queue')) {
            $this->inject = false;
            $this->delegate->execute(
                'INSERT INTO mgw_match_queue (
                    queue_id, player_ref, mgw_id, legacy_user_id, game_type, room, bet, board_size,
                    status, reserved_match_id, created_at_utc, updated_at_utc, expires_at_utc
                 ) VALUES (
                    :queue_id, :player_ref, NULL, NULL, :game_type, :room, :bet, :board_size,
                    :status, NULL, :created_at_utc, :updated_at_utc, NULL
                 )',
                [
                    'queue_id' => 'race-winner',
                    'player_ref' => $this->playerRef,
                    'game_type' => 'tictactoe',
                    'room' => 'match',
                    'bet' => 100,
                    'board_size' => 3,
                    'status' => 'waiting',
                    'created_at_utc' => '2026-08-19 12:00:00.000000',
                    'updated_at_utc' => '2026-08-19 12:00:00.000000',
                ]
            );
            $error = new PDOException('UNIQUE constraint failed: mgw_match_queue.player_ref');
            $error->errorInfo = ['23000', 2067, 'UNIQUE constraint failed: mgw_match_queue.player_ref'];
            throw $error;
        }
        return $this->delegate->execute($sql, $parameters);
    }
}

$racePlayer = 'legacy:double-request-user';
$raceStore = new RealtimeDatabaseStore(new Mvp17QueueCollisionDatabase($queueDb, $racePlayer));
$raceResult = $raceStore->upsertQueueEntry([
    'queue_id' => 'race-loser',
    'player_ref' => $racePlayer,
    'game_type' => 'chess',
    'room' => 'match',
    'bet' => 250,
    'board_size' => 8,
    'status' => 'waiting',
    'created_at_utc' => '2026-08-19T12:00:01Z',
    'updated_at_utc' => '2026-08-19T12:00:01Z',
]);
$assertSame('race-winner', $raceResult['queue_id'] ?? null, 'Same-player race must preserve the canonical winning queue identity');
$assertSame('chess', $raceResult['game_type'] ?? null, 'Same-player race must converge to the latest requested game');
$assertSame(250, (int)($raceResult['bet'] ?? 0), 'Same-player race must converge requested bet');
$assertSame(1, (int)$queueDb->fetchValue('SELECT COUNT(*) FROM mgw_match_queue WHERE player_ref = :player_ref', ['player_ref' => $racePlayer]), 'Same-user double request must leave exactly one queue row');

$regularStore = new RealtimeDatabaseStore($queueDb);
$regularStore->upsertQueueEntry([
    'queue_id' => 'shared-queue-id',
    'player_ref' => 'legacy:owner-user',
    'game_type' => 'go',
    'room' => 'match',
    'bet' => 100,
    'board_size' => 9,
    'status' => 'waiting',
]);
$assertThrows(
    static fn() => $regularStore->upsertQueueEntry([
        'queue_id' => 'shared-queue-id',
        'player_ref' => 'legacy:foreign-user',
        'game_type' => 'domino',
        'room' => 'match',
        'bet' => 100,
        'board_size' => 2,
        'status' => 'waiting',
    ]),
    'unique',
    'Queue-ID collision owned by another player must fail closed'
);
$ownerRow = $regularStore->findQueueEntry('legacy:owner-user');
$assertSame('go', $ownerRow['game_type'] ?? null, 'Cross-user collision must not mutate existing owner row');
$assertSame(null, $regularStore->findQueueEntry('legacy:foreign-user'), 'Cross-user collision must not create foreign row');

// -------------------------------------------------------------------------
// 3. Eight-runtime interleaving: zero cross-game state contamination.
// -------------------------------------------------------------------------
$statePdo = new PDO('sqlite::memory:');
$stateDb = new PdoDatabaseConnection($statePdo);
$installer = new RuntimePrimaryStateSchemaInstaller($stateDb);
$installed = $installer->install();
$assertTrue(($installed['ok'] ?? false) === true, 'DB-primary schema must install for reliability stress');
$adapter = new DatabasePrimaryStateStorageAdapter($stateDb);
$gameTypes = ['tictactoe', 'four_in_a_row', 'battleship', 'checkers', 'reversi', 'chess', 'go', 'domino'];
$source = ['users' => [], 'games' => [], 'queue' => [], 'transactions' => [], 'system' => ['reliability_sequence' => 0]];
foreach ($gameTypes as $index => $gameType) {
    $source['games']['reliability-' . $gameType] = [
        'id' => 'reliability-' . $gameType,
        'game_type' => $gameType,
        'status' => 'active',
        'sentinel' => hash('sha256', $gameType),
        'reliability_counter' => 0,
        'last_scenario' => null,
    ];
}
$adapter->initializeFromSnapshot($source);

$expectedCounters = array_fill_keys($gameTypes, 0);
for ($iteration = 0; $iteration < 160; $iteration++) {
    $targetType = $gameTypes[$iteration % count($gameTypes)];
    $targetId = 'reliability-' . $targetType;
    $before = $adapter->readOnly(static fn(array $data): array => $data);
    $adapter->transaction(static function (array &$data) use ($targetId, $targetType, $iteration): void {
        $data['games'][$targetId]['reliability_counter']++;
        $data['games'][$targetId]['last_scenario'] = 'interleave-' . $iteration;
        $data['system']['reliability_sequence']++;
    });
    $expectedCounters[$targetType]++;
    $after = $adapter->readOnly(static fn(array $data): array => $data);
    foreach ($gameTypes as $otherType) {
        if ($otherType === $targetType) continue;
        $otherId = 'reliability-' . $otherType;
        $assertSame($before['games'][$otherId], $after['games'][$otherId], 'Interleaved ' . $targetType . ' mutation contaminated ' . $otherType);
    }
}
$finalState = $adapter->readOnly(static fn(array $data): array => $data);
foreach ($gameTypes as $gameType) {
    $game = $finalState['games']['reliability-' . $gameType] ?? [];
    $assertSame($expectedCounters[$gameType], (int)($game['reliability_counter'] ?? -1), $gameType . ' must receive only its own interleaved mutations');
    $assertSame(hash('sha256', $gameType), $game['sentinel'] ?? null, $gameType . ' sentinel must survive stress unchanged');
}
$assertSame(160, (int)($finalState['system']['reliability_sequence'] ?? -1), 'All interleaved transactions must commit exactly once');

$beforeBotFailure = $adapter->readOnly(static fn(array $data): array => $data);
$assertThrows(
    static function () use ($adapter): void {
        $adapter->transaction(static function (array &$data): void {
            $data['games']['reliability-tictactoe']['reliability_counter'] = 999999;
            $data['games']['reliability-chess']['sentinel'] = 'must-rollback';
            throw new RuntimeException('simulated bot failure');
        });
    },
    'simulated bot failure',
    'Bot/runtime failure must abort the shared state transaction'
);
$afterBotFailure = $adapter->readOnly(static fn(array $data): array => $data);
$assertSame($beforeBotFailure, $afterBotFailure, 'Bot/runtime failure must leave every game byte-equivalent at logical state level');

// -------------------------------------------------------------------------
// 4. Real reconnect owner: disconnect, restore and timeout stay match-scoped.
// -------------------------------------------------------------------------
$presenceDir = sys_get_temp_dir() . '/mgw-mvp17-7-presence-' . bin2hex(random_bytes(6));
$presence = new PresenceService($presenceDir);
$reconnect = new ReconnectLifecycleService(['commission_rate' => 0.10], $presence);

$makeUser = static fn(string $id, string $gameId): array => [
    'id' => $id,
    'username' => $id,
    'balance' => 900,
    'status' => 'playing',
    'current_game_id' => $gameId,
    'stats' => [],
];
$makeGame = static fn(string $id, array $players, string $type = 'tictactoe'): array => [
    'id' => $id,
    'game_type' => $type,
    'status' => 'active',
    'launch_phase' => 'active',
    'room' => 'match',
    'bet' => 100,
    'player_ids' => $players,
    'turn' => $players[0],
    'turn_started_at' => gmdate('c'),
    'updated_at' => gmdate('c'),
];
$reconnectDb = [
    'users' => [
        'p1' => $makeUser('p1', 'disconnect-game'),
        'p2' => $makeUser('p2', 'disconnect-game'),
        'q1' => $makeUser('q1', 'isolation-game'),
        'q2' => $makeUser('q2', 'isolation-game'),
    ],
    'games' => [
        'disconnect-game' => $makeGame('disconnect-game', ['p1', 'p2']),
        'isolation-game' => $makeGame('isolation-game', ['q1', 'q2'], 'chess'),
    ],
    'transactions' => [],
];
foreach ([['p1','s1'], ['p2','s2'], ['q1','sq1'], ['q2','sq2']] as [$player, $session]) {
    $presence->touch($player, $session);
}

$ageForegroundLease = static function (string $directory, string $accountId, int $secondsAgo): void {
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($iterator as $fileInfo) {
        if (!$fileInfo->isFile() || !str_ends_with($fileInfo->getFilename(), '.presence')) continue;
        $accountFile = dirname($fileInfo->getPathname()) . DIRECTORY_SEPARATOR . '.account';
        if (trim((string)@file_get_contents($accountFile)) !== $accountId) continue;
        @file_put_contents($fileInfo->getPathname(), json_encode([
            'touched_at' => time() - $secondsAgo,
            'leave_after' => 0,
            'mode' => 'foreground',
        ], JSON_UNESCAPED_SLASHES), LOCK_EX);
    }
};

$isolationBeforeDisconnect = $reconnectDb['games']['isolation-game'];
$ageForegroundLease($presenceDir, 'p2', 20);
$reconnect->synchronize($reconnectDb, 'p1', 's1', 'status');
$assertTrue(!empty($reconnectDb['games']['disconnect-game']['reconnect_v2']['paused']), 'Stale foreground lease must enter reconnect pause');
$assertTrue(isset($reconnectDb['games']['disconnect-game']['reconnect_v2']['players']['p2']), 'Disconnected player must own reconnect deadline');
$assertSame($isolationBeforeDisconnect, $reconnectDb['games']['isolation-game'], 'Disconnect in one match must not mutate another active match');

$presence->touch('p2', 's2');
$previousDisconnected = ['state' => 'disconnected', 'last_foreground_at' => time() - 20];
$reconnect->synchronize($reconnectDb, 'p2', 's2', 'ping', $previousDisconnected);
$assertTrue(empty($reconnectDb['games']['disconnect-game']['reconnect_v2']), 'Fresh ping before deadline must restore paused match');
$assertSame($isolationBeforeDisconnect, $reconnectDb['games']['isolation-game'], 'Reconnect restore must remain match-scoped');

$timeoutDb = [
    'users' => [
        't1' => $makeUser('t1', 'timeout-game'),
        't2' => $makeUser('t2', 'timeout-game'),
        'u1' => $makeUser('u1', 'timeout-isolation'),
        'u2' => $makeUser('u2', 'timeout-isolation'),
    ],
    'games' => [
        'timeout-game' => $makeGame('timeout-game', ['t1', 't2']),
        'timeout-isolation' => $makeGame('timeout-isolation', ['u1', 'u2'], 'go'),
    ],
    'transactions' => [],
];
$timeoutPresenceDir = sys_get_temp_dir() . '/mgw-mvp17-7-timeout-' . bin2hex(random_bytes(6));
$timeoutPresence = new PresenceService($timeoutPresenceDir);
$timeoutLifecycle = new ReconnectLifecycleService(['commission_rate' => 0.10], $timeoutPresence);
foreach ([['t1','st1'], ['t2','st2'], ['u1','su1'], ['u2','su2']] as [$player, $session]) {
    $timeoutPresence->touch($player, $session);
}
$timeoutIsolationBefore = $timeoutDb['games']['timeout-isolation'];
$ageForegroundLease($timeoutPresenceDir, 't2', 80);
$timeoutLifecycle->synchronize($timeoutDb, 't1', 'st1', 'status');
$assertTrue(!empty($timeoutDb['games']['timeout-game']['reconnect_v2']['paused']), 'Expired stale lease must first create reconnect state');
$timeoutLifecycle->synchronize($timeoutDb, 't1', 'st1', 'status');
$assertSame('finished', $timeoutDb['games']['timeout-game']['status'] ?? null, 'Expired reconnect deadline must finish match');
$assertSame('disconnect_timeout', $timeoutDb['games']['timeout-game']['finish_reason'] ?? null, 'Expired reconnect must settle as disconnect_timeout');
$assertSame('t1', $timeoutDb['games']['timeout-game']['winner_id'] ?? null, 'Connected opponent must win disconnect timeout');
$assertSame($timeoutIsolationBefore, $timeoutDb['games']['timeout-isolation'], 'Timeout settlement must not contaminate another active match');

// -------------------------------------------------------------------------
// 5. Server/bot failure recovery: no-contest only, full refund, no stats game.
// -------------------------------------------------------------------------
$failureDb = [
    'users' => [
        'human' => $makeUser('human', 'bot-failure-game'),
    ],
    'games' => [
        'bot-failure-game' => array_merge(
            $makeGame('bot-failure-game', ['human', 'bot_easy_1']),
            ['is_bot_game' => true, 'bot_id' => 'bot_easy_1', 'bot_difficulty' => 'easy']
        ),
        'already-finished' => [
            'id' => 'already-finished',
            'game_type' => 'reversi',
            'status' => 'finished',
            'sentinel' => 'do-not-touch',
        ],
    ],
    'transactions' => [],
];
$finishedBefore = $failureDb['games']['already-finished'];
$failurePresence = new PresenceService(sys_get_temp_dir() . '/mgw-mvp17-7-failure-' . bin2hex(random_bytes(6)));
$failureLifecycle = new ReconnectLifecycleService(['commission_rate' => 0.10], $failurePresence);
$cancelled = $failureLifecycle->cancelActiveGamesForServerFailure($failureDb, 'incident-mvp17-7');
$assertSame(1, $cancelled, 'Server/bot failure recovery must cancel exactly the active failed match');
$failedGame = $failureDb['games']['bot-failure-game'];
$assertSame('finished', $failedGame['status'] ?? null, 'Failed bot match must become terminal');
$assertSame('server_failure', $failedGame['finish_reason'] ?? null, 'Failed bot match must carry server_failure reason');
$assertSame(true, $failedGame['no_contest'] ?? false, 'Failed bot match must be a no-contest');
$assertSame(1000, (int)($failureDb['users']['human']['balance'] ?? 0), 'Human entry cost must be fully refunded after bot/server failure');
$assertSame(0, (int)($failureDb['users']['human']['stats']['games_played'] ?? 0), 'No-contest failure must not count a played game');
$assertSame($finishedBefore, $failureDb['games']['already-finished'], 'Server failure recovery must not mutate already-terminal match');

fwrite(STDOUT, "Mvp17ReliabilityRegressionTest passed: {$assertions} assertions.\n");
