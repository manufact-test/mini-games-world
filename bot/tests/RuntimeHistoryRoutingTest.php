<?php
declare(strict_types=1);

$root = dirname(__DIR__);
require $root . '/database/DatabaseConnectionInterface.php';
require $root . '/database/DatabaseConfig.php';
require $root . '/storage/RuntimeStorageRouter.php';
require $root . '/ledger/LedgerIntegrity.php';
require $root . '/services/HistoryService.php';

if (!class_exists('UserService')) {
    final class UserService {}
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
    'database' => ['enabled' => false],
    'feature_flags' => [
        'database_runtime' => [
            'enabled' => false,
            'modules' => [],
        ],
    ],
];
$history = new HistoryService($config, new UserService());
$snapshot = [
    'users' => ['user_1' => ['id' => 'user_1']],
    'transactions' => [
        [
            'id' => 'tx_1',
            'type' => 'balance_change',
            'user_id' => 'user_1',
            'category' => 'weekly_bonus',
            'amount' => 50,
            'room' => 'match',
            'created_at' => '2026-07-19T12:00:00+00:00',
        ],
    ],
    'games' => [
        'game_1' => [
            'id' => 'game_1',
            'player_ids' => ['user_1', 'bot_1'],
            'player_names' => ['bot_1' => 'Бот'],
            'status' => 'finished',
            'winner_id' => 'user_1',
            'room' => 'match',
            'game_type' => 'tictactoe',
            'board_size' => 3,
            'bet' => 10,
            'payout' => 19,
            'commission' => 1,
            'finish_reason' => 'normal_win',
            'created_at' => '2026-07-19T11:59:00+00:00',
            'finished_at' => '2026-07-19T12:00:00+00:00',
        ],
    ],
];

$formatted = $history->formatHistory($snapshot, 'user_1', 24);
$routed = $history->userHistory($snapshot, 'user_1', 24);
$assertSame($formatted, $routed, 'JSON route must keep the existing history response');
$assertSame('Еженедельное начисление', $routed['operations'][0]['title'], 'Operation title must stay unchanged');
$assertSame('Победа', $routed['matches'][0]['result'], 'Match result must stay unchanged');
$assertSame('GAME_1', $routed['matches'][0]['short_id'], 'Pretty match ID must stay unchanged');

$dependencyBlocked = false;
try {
    new RuntimeStorageRouter([
        'environment' => 'staging',
        'storage_driver' => 'json',
        'database' => ['enabled' => true, 'dsn' => 'mysql:host=localhost;dbname=test', 'username' => 'test'],
        'feature_flags' => [
            'database_runtime' => [
                'enabled' => true,
                'modules' => [
                    'accounts' => true,
                    'realtime' => true,
                    'economy' => false,
                    'history' => true,
                ],
            ],
        ],
    ]);
} catch (RuntimeException $error) {
    $dependencyBlocked = str_contains($error->getMessage(), 'realtime and economy');
}
$assertSame(true, $dependencyBlocked, 'History routing must require realtime and economy');

fwrite(STDOUT, "RuntimeHistoryRoutingTest passed: {$assertions} assertions.\n");
