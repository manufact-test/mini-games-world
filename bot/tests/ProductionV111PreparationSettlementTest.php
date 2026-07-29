<?php
declare(strict_types=1);

if (!function_exists('now_iso')) {
    function now_iso(): string { return gmdate('c'); }
}
if (!function_exists('make_id')) {
    function make_id(string $prefix = 'id'): string {
        static $sequence = 0;
        $sequence++;
        return $prefix . '_test_' . $sequence;
    }
}

$root = dirname(__DIR__);
require_once $root . '/services/FeatureFlagService.php';
require_once $root . '/services/GameCatalogService.php';
require_once $root . '/services/GameService.php';
require_once $root . '/services/GameSettlementService.php';
require_once $root . '/services/FourInARowBotService.php';
require_once $root . '/services/FourInARowService.php';
require_once $root . '/services/GameRuntimeService.php';
require_once $root . '/services/GameActionService.php';

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

$catalog = (new ReflectionClass(GameCatalogService::class))->newInstanceWithoutConstructor();
$runtime = (new ReflectionClass(GameRuntimeService::class))->newInstanceWithoutConstructor();
$service = new GameActionService($catalog, $runtime);

$baseGame = static function (string $id, string $phase, string $deadline): array {
    return [
        'id' => $id,
        'game_type' => 'tictactoe',
        'room' => 'match',
        'bet' => 10,
        'bank' => 20,
        'board_size' => 3,
        'board' => '---------',
        'player_ids' => ['100', '200'],
        'player_names' => ['100' => 'One', '200' => 'Two'],
        'symbols' => ['100' => 'X', '200' => 'O'],
        'turn' => '100',
        'status' => 'active',
        'launch_phase' => $phase,
        'preparation_deadline_at' => $deadline,
        'winner_id' => null,
        'loser_id' => null,
        'payout_done' => false,
        'created_at' => gmdate('c', time() - 4),
        'updated_at' => now_iso(),
        'turn_started_at' => gmdate('c', time() + 20),
    ];
};

$earlyDb = [
    'users' => [
        '100' => ['id' => '100', 'username' => 'one', 'balance_match' => 90, 'status' => 'playing', 'current_game_id' => 'early'],
        '200' => ['id' => '200', 'username' => 'two', 'balance_match' => 90, 'status' => 'playing', 'current_game_id' => 'early'],
    ],
    'games' => ['early' => $baseGame('early', 'preparing', gmdate('c', time() + 8))],
    'transactions' => [],
];
$earlyThrown = false;
try {
    $user =& $earlyDb['users']['100'];
    $service->apply($earlyDb, $user, 'early', ['type' => 'cancel_preparation']);
} catch (RuntimeException $error) {
    $earlyThrown = str_contains($error->getMessage(), 'ещё продолжается');
}
$assert($earlyThrown, 'Preparation cannot be cancelled before the readiness deadline.');
$assert($earlyDb['users']['100']['balance_match'] === 90 && $earlyDb['users']['200']['balance_match'] === 90, 'Early cancellation must not alter balances.');

$db = [
    'users' => [
        '100' => ['id' => '100', 'username' => 'one', 'balance_match' => 90, 'status' => 'playing', 'current_game_id' => 'expired'],
        '200' => ['id' => '200', 'username' => 'two', 'balance_match' => 90, 'status' => 'playing', 'current_game_id' => 'expired'],
    ],
    'games' => ['expired' => $baseGame('expired', 'preparation_timeout', gmdate('c', time() - 1))],
    'transactions' => [],
];
$user =& $db['users']['100'];
$settled = $service->apply($db, $user, 'expired', ['type' => 'cancel_preparation']);
$assert($settled['status'] === 'finished' && $settled['launch_phase'] === 'cancelled', 'Expired preparation must finish as a cancelled match.');
$assert($settled['finish_reason'] === 'preparation_timeout' && !empty($settled['v111_preparation_refund_done']), 'Expired preparation must record an idempotent timeout reason.');
$assert($db['users']['100']['balance_match'] === 100 && $db['users']['200']['balance_match'] === 100, 'Both human entry stakes must be restored exactly once.');
$assert($db['users']['100']['status'] === 'idle' && $db['users']['200']['status'] === 'idle', 'Both players must be released after preparation timeout.');
$assert($db['users']['100']['current_game_id'] === null && $db['users']['200']['current_game_id'] === null, 'Preparation timeout must clear both current game links.');
$refundRows = array_values(array_filter($db['transactions'], static fn(array $tx): bool => ($tx['category'] ?? '') === 'game_preparation_refund'));
$finishRows = array_values(array_filter($db['transactions'], static fn(array $tx): bool => ($tx['type'] ?? '') === 'game_finish'));
$assert(count($refundRows) === 2 && count($finishRows) === 1, 'Settlement must create two balance refunds and one finish record.');
$transactionCount = count($db['transactions']);
$service->apply($db, $user, 'expired', ['type' => 'cancel_preparation']);
$assert($db['users']['100']['balance_match'] === 100 && $db['users']['200']['balance_match'] === 100, 'Repeated settlement must not duplicate balance refunds.');
$assert(count($db['transactions']) === $transactionCount, 'Repeated settlement must not append duplicate transactions.');

$handoffDb = [
    'games' => [
        'handoff' => [
            'id' => 'handoff',
            'status' => 'active',
            'launch_phase' => 'active',
            'turn' => '200',
            'turn_started_at' => now_iso(),
            'v111_clock_turn' => '100',
            'v111_clock_revision' => 3,
            'player_ids' => ['100', '200'],
        ],
    ],
];
$method = new ReflectionMethod(GameActionService::class, 'synchronizeTurnHandoff');
$before = time();
$handoffFallback = $handoffDb['games']['handoff'];
$handoffArgs = [&$handoffDb, 'handoff', '100', $handoffFallback];
$handoff = $method->invokeArgs($service, $handoffArgs);
$startsAt = strtotime((string)$handoff['turn_starts_at']);
$deadlineAt = strtotime((string)$handoff['turn_deadline_at']);
$assert($startsAt !== false && $startsAt >= $before + 1 && $startsAt <= time() + 2, 'Turn handoff must start slightly in the future for both devices.');
$assert($deadlineAt !== false && $deadlineAt - $startsAt === 60, 'Turn deadline must be exactly 60 seconds after synchronized start.');
$assert($handoff['v111_clock_turn'] === '200' && $handoff['v111_clock_revision'] === 4, 'Turn handoff must advance the authoritative clock revision once.');

$sameTurnDb = ['games' => ['same' => $handoff + ['id' => 'same']]];
$sameRevision = (int)$sameTurnDb['games']['same']['v111_clock_revision'];
$sameFallback = $sameTurnDb['games']['same'];
$sameArgs = [&$sameTurnDb, 'same', '200', $sameFallback];
$same = $method->invokeArgs($service, $sameArgs);
$assert((int)$same['v111_clock_revision'] === $sameRevision, 'A repeated same-turn state must not restart the timer.');

fwrite(STDOUT, "ProductionV111PreparationSettlementTest: {$assertions} assertions passed\n");
