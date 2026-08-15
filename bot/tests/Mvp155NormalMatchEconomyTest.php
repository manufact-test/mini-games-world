<?php
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/helpers/validators.php';
require_once $root . '/database/DatabaseConnectionInterface.php';
require_once $root . '/database/DatabaseConfig.php';
require_once $root . '/database/PdoDatabaseConnection.php';
require_once $root . '/database/PdoConnectionFactory.php';
require_once $root . '/economy/UnifiedBalanceRuntimeState.php';
require_once $root . '/economy/MatchEconomyRuntimeConfig.php';
require_once $root . '/services/GameService.php';

$assertions = 0;
$assertSame = static function (mixed $expected, mixed $actual, string $message) use (&$assertions): void {
    $assertions++;
    if ($expected !== $actual) {
        throw new RuntimeException($message . ': expected ' . var_export($expected, true)
            . ', got ' . var_export($actual, true));
    }
};

$defaults = EconomyConfigDefinition::defaults();
$config = MatchEconomyRuntimeConfig::apply([
    'environment' => 'local',
    'canonical_economy_snapshot' => [
        'version' => 7,
        'config' => $defaults,
    ],
    'board_sizes' => [3, 5, 9],
    'gold_bets' => [10, 20, 30, 50, 100],
    'move_timeout_sec' => 60,
    'queue_timeout_sec' => 60,
    'match_bot_after_sec' => 9999,
]);

$status = MatchEconomyRuntimeConfig::publicStatus($config);
$assertSame(100, $config['match_bet'], 'Legacy Match entry projection must come from canonical config');
$assertSame(0.1, $config['commission_rate'], 'Legacy settlement rate must project the canonical sink');
$assertSame(100, $status['entry_cost'], 'Public Match entry must be canonical');
$assertSame(180, $status['winner_reward'], 'Public winner reward must be canonical');
$assertSame(20, $status['system_sink'], 'Public system sink must be canonical');
$assertSame(100, $status['draw_refund'], 'Public draw refund must be canonical');
$assertSame(7, $status['config_version'], 'Runtime policy must expose the source config version');

$service = new GameService($config);
$db = [
    'users' => [
        'a' => ['id' => 'a', 'username' => 'A', 'first_name' => 'A', 'balance' => 1000, 'status' => 'idle', 'stats' => []],
        'b' => ['id' => 'b', 'username' => 'B', 'first_name' => 'B', 'balance' => 1000, 'status' => 'idle', 'stats' => []],
    ],
    'queue' => [],
    'games' => [],
    'transactions' => [],
    'system' => [],
];

$a =& $db['users']['a'];
$b =& $db['users']['b'];
$queued = $service->startSearch($db, $a, 'match', 10, 3);
$assertSame(true, $queued['queued'] ?? false, 'First player must enter Match queue');
$assertSame(100, (int)($db['queue'][0]['bet'] ?? 0), 'Client-provided legacy bet must not own Match queue price');
$matched = $service->startSearch($db, $b, 'match', 10, 3);
$gameId = (string)($matched['game']['id'] ?? '');
if ($gameId === '') throw new RuntimeException('Human Match game was not created.');
$assertSame(900, $db['users']['a']['balance'], 'Player A must pay canonical 100 entry');
$assertSame(900, $db['users']['b']['balance'], 'Player B must pay canonical 100 entry');
$assertSame(100, (int)$db['games'][$gameId]['bet'], 'Stored Match entry must be canonical 100');
$assertSame(200, (int)$db['games'][$gameId]['bank'], 'Normal Match bank must be 200');

$service->makeMove($db, $db['users']['a'], $gameId, 0);
$service->makeMove($db, $db['users']['b'], $gameId, 3);
$service->makeMove($db, $db['users']['a'], $gameId, 1);
$service->makeMove($db, $db['users']['b'], $gameId, 4);
$service->makeMove($db, $db['users']['a'], $gameId, 2);

$assertSame('finished', $db['games'][$gameId]['status'], 'Winning game must settle');
$assertSame('a', $db['games'][$gameId]['winner_id'], 'Player A must be winner');
$assertSame(180, (int)$db['games'][$gameId]['payout'], 'Winner reward must be canonical 180');
$assertSame(20, (int)$db['games'][$gameId]['commission'], 'System sink must be canonical 20');
$assertSame(1080, $db['users']['a']['balance'], 'Winner net result must be +80 from 1000');
$assertSame(900, $db['users']['b']['balance'], 'Loser net result must be -100 from 1000');
$assertSame(20, (int)($db['system']['fees_match'] ?? 0), 'System must retain exactly 20');

$entryTransactions = array_values(array_filter(
    $db['transactions'],
    static fn($tx): bool => is_array($tx)
        && (string)($tx['category'] ?? '') === 'game_entry'
        && (string)($tx['game_id'] ?? '') === $gameId
));
$assertSame(2, count($entryTransactions), 'Human match must write two entry ledger events');
$assertSame([-100, -100], array_map(static fn($tx): int => (int)$tx['amount'], $entryTransactions), 'Entry ledger events must be -100 each');

$drawDb = [
    'users' => [
        'c' => ['id' => 'c', 'username' => 'C', 'first_name' => 'C', 'balance' => 1000, 'status' => 'idle', 'stats' => []],
        'd' => ['id' => 'd', 'username' => 'D', 'first_name' => 'D', 'balance' => 1000, 'status' => 'idle', 'stats' => []],
    ],
    'queue' => [],
    'games' => [],
    'transactions' => [],
    'system' => [],
];
$c =& $drawDb['users']['c'];
$d =& $drawDb['users']['d'];
$service->startSearch($drawDb, $c, 'match', 1, 3);
$drawMatched = $service->startSearch($drawDb, $d, 'match', 9999, 3);
$drawId = (string)($drawMatched['game']['id'] ?? '');
if ($drawId === '') throw new RuntimeException('Draw Match game was not created.');

foreach ([['c',0],['d',1],['c',2],['d',4],['c',3],['d',5],['c',7],['d',6],['c',8]] as [$playerId, $cell]) {
    $service->makeMove($drawDb, $drawDb['users'][$playerId], $drawId, $cell);
}

$assertSame('finished', $drawDb['games'][$drawId]['status'], 'Draw game must settle');
$assertSame(null, $drawDb['games'][$drawId]['winner_id'], 'Draw must have no winner');
$assertSame(100, (int)$drawDb['games'][$drawId]['payout'], 'Draw refund per player must be canonical 100');
$assertSame(0, (int)$drawDb['games'][$drawId]['commission'], 'Draw must not create a sink');
$assertSame(1000, $drawDb['users']['c']['balance'], 'Draw must fully refund player C');
$assertSame(1000, $drawDb['users']['d']['balance'], 'Draw must fully refund player D');

$refunds = array_values(array_filter(
    $drawDb['transactions'],
    static fn($tx): bool => is_array($tx)
        && (string)($tx['category'] ?? '') === 'game_refund'
        && (string)($tx['game_id'] ?? '') === $drawId
));
$assertSame(2, count($refunds), 'Draw must write two refund ledger events');
$assertSame([100, 100], array_map(static fn($tx): int => (int)$tx['amount'], $refunds), 'Draw ledger refunds must be +100 each');

fwrite(STDOUT, "Mvp155NormalMatchEconomyTest passed: {$assertions} assertions.\n");
