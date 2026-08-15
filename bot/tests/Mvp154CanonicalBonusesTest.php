<?php
declare(strict_types=1);

$root = dirname(__DIR__);
require $root . '/helpers/validators.php';
require $root . '/economy/UnifiedBalanceRuntimeState.php';
require $root . '/services/NotificationService.php';
require $root . '/services/WeeklyMatchEconomyService.php';

$assertions = 0;
$assertSame = static function (mixed $expected, mixed $actual, string $message) use (&$assertions): void {
    $assertions++;
    if ($expected !== $actual) {
        throw new RuntimeException($message . ': expected ' . var_export($expected, true)
            . ', got ' . var_export($actual, true));
    }
};

$bonuses = [
    'starter' => 1000,
    'weekly' => 500,
    'weekly_match_threshold' => 3,
    'first_game' => 50,
];
$config = [
    'environment' => 'staging',
    'weekly_match_start_at' => '2026-07-13 12:00:00',
];
$service = new WeeklyMatchEconomyService($config, new NotificationService(), $bonuses);

$user = [
    'id' => '15401',
    'mgw_id' => 'MGW-MVP15401',
    'mgw_account_ref' => 'mgw:MGW-MVP15401',
    'username' => 'mvp154',
    'balance' => 0,
];
$db = [
    'users' => ['15401' => &$user],
    'games' => [
        'ttt-1' => [
            'id' => 'ttt-1', 'status' => 'finished', 'room' => 'match', 'game_type' => 'tictactoe',
            'player_ids' => ['15401', 'opponent'], 'finished_at' => '2026-08-11T10:00:00Z', 'match_started' => true,
        ],
        'ttt-2' => [
            'id' => 'ttt-2', 'status' => 'finished', 'room' => 'match', 'game_type' => 'tictactoe',
            'player_ids' => ['15401', 'opponent2'], 'finished_at' => '2026-08-12T10:00:00Z', 'match_started' => true,
        ],
        'chess-1' => [
            'id' => 'chess-1', 'status' => 'finished', 'room' => 'match', 'game_type' => 'chess',
            'player_ids' => ['15401', 'opponent3'], 'finished_at' => '2026-08-13T10:00:00Z', 'match_started' => true,
        ],
        'cancelled-battleship' => [
            'id' => 'cancelled-battleship', 'status' => 'finished', 'room' => 'match', 'game_type' => 'battleship',
            'player_ids' => ['15401', 'opponent4'], 'finished_at' => '2026-08-14T10:00:00Z',
            'launch_phase' => 'cancelled', 'finish_reason' => 'preparation_timeout',
            'preparation_cancelled_at' => '2026-08-14T10:00:00Z', 'match_started' => false,
        ],
        'gold-game' => [
            'id' => 'gold-game', 'status' => 'finished', 'room' => 'gold', 'game_type' => 'reversi',
            'player_ids' => ['15401', 'opponent5'], 'finished_at' => '2026-08-14T11:00:00Z', 'match_started' => true,
        ],
    ],
    'transactions' => [],
    'notifications' => [],
];

$now = new DateTimeImmutable('2026-08-17 12:05:00', new DateTimeZone('Europe/Moscow'));
$first = $service->applyDueForUser($db, $user, $now);
$assertSame(1600, $user['balance'], 'Starter + two first-game rewards + weekly reward must use canonical amounts');
$assertSame(1000, $user['weekly_match_welcome_grant_amount'], 'Starter amount must come from canonical config');
$assertSame(500, $user['weekly_match_bonus_last_amount'], 'Weekly amount must come from canonical config');
$assertSame(3, $user['weekly_match_bonus_checked_games'], 'Canceled preparation and Gold game must not qualify');
$assertSame(['tictactoe', 'chess'], $first['first_games']['awarded_games'], 'Only actually completed normal game types must receive first-game rewards');
$assertSame(2, $user['weekly_match_first_game_total'], 'Two unique first-game rewards must be recorded');

$transactionCount = count($db['transactions']);
$notificationCount = count($db['notifications']);
$second = $service->applyDueForUser($db, $user, $now);
$assertSame(1600, $user['balance'], 'Second application must be idempotent');
$assertSame($transactionCount, count($db['transactions']), 'Second application must not create duplicate financial transactions');
$assertSame($notificationCount, count($db['notifications']), 'Second application must not create duplicate notifications');
$assertSame(false, $second['first_games']['awarded'], 'Second application must not repeat first-game grants');

$db['games']['battleship-1'] = [
    'id' => 'battleship-1', 'status' => 'finished', 'room' => 'match', 'game_type' => 'battleship',
    'player_ids' => ['15401', 'opponent6'], 'finished_at' => '2026-08-17T09:10:00Z', 'match_started' => true,
];
$third = $service->applyDueForUser($db, $user, $now->modify('+10 minutes'));
$assertSame(1650, $user['balance'], 'A newly completed game type must still grant after weekly cycle was already checked');
$assertSame(['battleship'], $third['first_games']['awarded_games'], 'Valid Battleship completion must grant exactly once');

$remaining = ['four_in_a_row', 'checkers', 'reversi', 'go', 'domino'];
foreach ($remaining as $index => $gameType) {
    $id = 'extra-' . $gameType;
    $db['games'][$id] = [
        'id' => $id,
        'status' => 'finished',
        'room' => 'match',
        'game_type' => $gameType,
        'player_ids' => ['15401', 'opponent-' . $index],
        'finished_at' => sprintf('2026-08-17T09:%02d:00Z', 20 + $index),
        'match_started' => true,
    ];
}
$allEight = $service->applyDueForUser($db, $user, $now->modify('+20 minutes'));
$assertSame(1900, $user['balance'], 'All eight first-game rewards must cap at +400 total');
$assertSame(8, $user['weekly_match_first_game_total'], 'Exactly eight unique first-game rewards must exist');
$assertSame(true, $allEight['first_games']['all_games_reward_pending'], 'All-eight completion must raise the future frame/badge trigger');

$status = $service->status($db, $user, $now);
$assertSame('Europe/Moscow', $status['timezone'], 'Weekly cycle must be owned by Moscow timezone');
$assertSame(500, $status['bonus_amount'], 'Status must expose canonical weekly amount');
$assertSame(1000, $status['starter_amount'], 'Status must expose canonical starter amount');
$assertSame(50, $status['first_game_amount'], 'Status must expose canonical first-game amount');

$overrideService = new WeeklyMatchEconomyService(
    $config,
    null,
    ['starter' => 1200, 'weekly' => 600, 'weekly_match_threshold' => 2, 'first_game' => 75]
);
$overrideUser = ['id' => '15402', 'balance' => 0];
$overrideDb = [
    'games' => [
        ['id' => 'o1', 'status' => 'finished', 'room' => 'match', 'game_type' => 'go', 'player_ids' => ['15402', 'x'], 'finished_at' => '2026-08-11T10:00:00Z', 'match_started' => true],
        ['id' => 'o2', 'status' => 'finished', 'room' => 'match', 'game_type' => 'domino', 'player_ids' => ['15402', 'y'], 'finished_at' => '2026-08-12T10:00:00Z', 'match_started' => true],
    ],
    'transactions' => [],
    'notifications' => [],
];
$overrideService->applyDueForUser($overrideDb, $overrideUser, $now);
$assertSame(1950, $overrideUser['balance'], 'Injected authoritative config must drive every bonus value and threshold');

fwrite(STDOUT, "Mvp154CanonicalBonusesTest passed: {$assertions} assertions.\n");
