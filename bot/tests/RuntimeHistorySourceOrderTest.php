<?php
declare(strict_types=1);

$root = dirname(__DIR__);
require $root . '/database/DatabaseConnectionInterface.php';
require $root . '/database/DatabaseConfig.php';
require $root . '/storage/RuntimeStorageRouter.php';
require $root . '/ledger/LedgerIntegrity.php';
require $root . '/services/HistoryService.php';
require $root . '/history/RuntimeHistoryRepository.php';

if (!class_exists('UserService')) {
    final class UserService {}
}

final class RuntimeHistorySourceOrderDatabase implements DatabaseConnectionInterface
{
    public function __construct(private array $rows) {}
    public function driver(): string { return 'mysql'; }
    public function execute(string $sql, array $parameters = []): int { throw new RuntimeException('Unexpected execute.'); }
    public function fetchAll(string $sql, array $parameters = []): array { return $this->rows; }
    public function fetchValue(string $sql, array $parameters = []): mixed { throw new RuntimeException('Unexpected fetchValue.'); }
    public function transaction(callable $callback): mixed { return $callback($this); }
}

$assertions = 0;
$assertSame = static function (mixed $expected, mixed $actual, string $message) use (&$assertions): void {
    $assertions++;
    if ($expected !== $actual) {
        throw new RuntimeException($message . ': expected ' . var_export($expected, true)
            . ', got ' . var_export($actual, true));
    }
};

$config = [
    'environment' => 'staging',
    'storage_driver' => 'json',
    'database' => [
        'enabled' => true,
        'driver' => 'mysql',
        'host' => 'localhost',
        'port' => 3306,
        'name' => 'test',
        'user' => 'test',
        'password' => 'test-password',
        'charset' => 'utf8mb4',
    ],
    'feature_flags' => [
        'database_runtime' => [
            'enabled' => true,
            'modules' => [
                'accounts' => true,
                'realtime' => true,
                'economy' => true,
                'history' => true,
            ],
        ],
    ],
];

$transactionNew = [
    'id' => 'tx_new',
    'type' => 'balance_change',
    'user_id' => 'user_1',
    'category' => 'weekly_bonus',
    'amount' => 50,
    'room' => 'match',
    'created_at' => '2026-07-19T12:00:00+00:00',
];
$transactionOld = [
    'id' => 'tx_old',
    'type' => 'balance_change',
    'user_id' => 'user_1',
    'category' => 'welcome_bonus',
    'amount' => 100,
    'room' => 'match',
    'created_at' => '2026-07-18T12:00:00+00:00',
];
$gameNew = [
    'id' => 'game_new',
    'player_ids' => ['user_1', 'bot_2'],
    'player_names' => ['bot_2' => 'Бот 2'],
    'status' => 'finished',
    'winner_id' => 'user_1',
    'room' => 'match',
    'game_type' => 'tictactoe',
    'board_size' => 3,
    'bet' => 10,
    'payout' => 19,
    'commission' => 1,
    'finish_reason' => 'normal_win',
    'created_at' => '2026-07-19T11:00:00+00:00',
    'finished_at' => '2026-07-19T11:05:00+00:00',
];
$gameOld = [
    'id' => 'game_old',
    'player_ids' => ['user_1', 'bot_1'],
    'player_names' => ['bot_1' => 'Бот 1'],
    'status' => 'finished',
    'winner_id' => null,
    'room' => 'match',
    'game_type' => 'tictactoe',
    'board_size' => 3,
    'bet' => 10,
    'payout' => 0,
    'commission' => 0,
    'finish_reason' => 'draw',
    'created_at' => '2026-07-18T11:00:00+00:00',
    'finished_at' => '2026-07-18T11:05:00+00:00',
];

$snapshot = [
    'users' => ['user_1' => ['id' => 'user_1']],
    'transactions' => [$transactionNew, $transactionOld],
    'games' => [
        'game_new' => $gameNew,
        'game_old' => $gameOld,
    ],
];

$shadowRow = static function (string $type, string $key, array $payload): array {
    $json = LedgerIntegrity::canonicalJson($payload);
    return [
        'entity_type' => $type,
        'entity_key' => $key,
        'payload_json' => $json,
        'payload_sha256' => hash('sha256', $json),
        'source_updated_at_utc' => null,
    ];
};

$rows = [
    $shadowRow('games', 'game_old', $gameOld),
    $shadowRow('economy_transaction', 'tx_old', $transactionOld),
    $shadowRow('games', 'game_new', $gameNew),
    $shadowRow('economy_transaction', 'tx_new', $transactionNew),
];

$router = new RuntimeStorageRouter($config);
$formatter = new HistoryService($config, new UserService());
$repository = new RuntimeHistoryRepository(
    $config,
    $router,
    new RuntimeHistorySourceOrderDatabase($rows),
    $formatter
);
$report = $repository->auditParity($snapshot);

$assertSame(true, $report['ok'], 'History parity must ignore database row order');
$assertSame(0, $report['mismatch_count'], 'No user history may differ');
$assertSame(0, $report['operation_mismatch_count'], 'Operation history must match');
$assertSame(0, $report['match_mismatch_count'], 'Match history must match');
$assertSame([], $report['blockers'], 'Parity report must be blocker-free');
$assertSame(false, $report['production_changed'], 'Audit must never change production');
$assertSame(false, $report['sensitive_identifiers_exposed'], 'Audit must not expose identifiers');

fwrite(STDOUT, "RuntimeHistorySourceOrderTest passed: {$assertions} assertions.\n");
