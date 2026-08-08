<?php
declare(strict_types=1);

if (!function_exists('now_iso')) {
    function now_iso(): string
    {
        return gmdate('c');
    }
}

require_once dirname(__DIR__) . '/services/GameService.php';

$sourcePath = dirname(__DIR__) . '/services/GameService.php';
$source = file_get_contents($sourcePath);
if (!is_string($source)) {
    throw new RuntimeException('GameService source is unavailable.');
}

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

$assert(
    str_contains($source, 'private function legacyTurnLifecycleAllowed(array $game): bool'),
    'GameService must own one explicit legacy turn-lifecycle predicate.'
);
$assert(
    str_contains($source, "if (!array_key_exists('launch_phase', $game))"),
    'Legacy games without launch_phase must remain on the accepted lifecycle.'
);
$assert(
    str_contains($source, "return (string)$game['launch_phase'] === 'active';"),
    'Only explicit Phase B active state may return to the legacy turn lifecycle.'
);
$assert(
    substr_count($source, '$this->legacyTurnLifecycleAllowed($game)') === 2,
    'The shared predicate must guard exactly legacy timeout ownership and bot-turn ownership.'
);

$service = new GameService(['move_timeout_sec' => 60]);
$lifecycle = new ReflectionMethod(GameService::class, 'legacyTurnLifecycleAllowed');
$lifecycle->setAccessible(true);
$isTurnExpired = new ReflectionMethod(GameService::class, 'isTurnExpired');
$isTurnExpired->setAccessible(true);

$assert($lifecycle->invoke($service, ['status' => 'active']) === true, 'Legacy game without launch_phase must remain allowed.');
$assert($lifecycle->invoke($service, ['status' => 'active', 'launch_phase' => 'active']) === true, 'Explicit active Phase B game must be allowed.');
foreach (['preparing', 'countdown', 'preparation_timeout', 'cancelled', 'finished'] as $phase) {
    $assert(
        $lifecycle->invoke($service, ['status' => 'active', 'launch_phase' => $phase]) === false,
        'Pre/non-active launch phase must be isolated from the legacy lifecycle: ' . $phase
    );
}

$expired = [
    'status' => 'active',
    'turn_started_at' => gmdate('c', time() - 61),
    'last_move_at' => gmdate('c', time() - 61),
    'created_at' => gmdate('c', time() - 61),
];
$assert($isTurnExpired->invoke($service, $expired) === true, 'Legacy expired game must still expire exactly as before.');
$expired['launch_phase'] = 'active';
$assert($isTurnExpired->invoke($service, $expired) === true, 'Explicit active Phase B game must still expire under the legacy owner until handoff migration.');
foreach (['preparing', 'countdown', 'preparation_timeout'] as $phase) {
    $expired['launch_phase'] = $phase;
    $assert(
        $isTurnExpired->invoke($service, $expired) === false,
        'Legacy timeout must not finish a pre-active Phase B game: ' . $phase
    );
}

$makeBotGame = static function (?string $launchPhase): array {
    $game = [
        'id' => 'game_test',
        'game_type' => 'tictactoe',
        'room' => 'match',
        'bet' => 10,
        'bank' => 20,
        'board_size' => 3,
        'board' => '---------',
        'player_ids' => ['human_1', 'bot_1'],
        'player_names' => ['human_1' => 'Human', 'bot_1' => 'Bot'],
        'symbols' => ['human_1' => 'X', 'bot_1' => 'O'],
        'turn' => 'bot_1',
        'status' => 'active',
        'winner_id' => null,
        'loser_id' => null,
        'finish_reason' => null,
        'payout_done' => false,
        'is_bot_game' => true,
        'bot_id' => 'bot_1',
        'bot_name' => 'Bot',
        'bot_difficulty' => 'medium',
        'created_at' => gmdate('c', time() - 120),
        'updated_at' => gmdate('c', time() - 120),
        'last_move_at' => gmdate('c', time() - 120),
        'turn_started_at' => gmdate('c', time() - 120),
        'bot_move_after_at' => gmdate('c', time() - 1),
    ];
    if ($launchPhase !== null) $game['launch_phase'] = $launchPhase;
    return $game;
};

$preparingDb = [
    'games' => ['game_test' => $makeBotGame('preparing')],
    'queue' => [],
    'users' => [],
    'transactions' => [],
    'system' => [],
];
$service->cleanup($preparingDb);
$assert($preparingDb['games']['game_test']['board'] === '---------', 'Bot must not move while launch_phase=preparing.');
$assert($preparingDb['games']['game_test']['status'] === 'active', 'Legacy timeout must not finish preparing bot game.');

$countdownDb = [
    'games' => ['game_test' => $makeBotGame('countdown')],
    'queue' => [],
    'users' => [],
    'transactions' => [],
    'system' => [],
];
$service->cleanup($countdownDb);
$assert($countdownDb['games']['game_test']['board'] === '---------', 'Bot must not move while launch_phase=countdown.');
$assert($countdownDb['games']['game_test']['status'] === 'active', 'Legacy timeout must not finish countdown bot game.');

$legacyDb = [
    'games' => ['game_test' => $makeBotGame(null)],
    'queue' => [],
    'users' => [],
    'transactions' => [],
    'system' => [],
];
$service->cleanup($legacyDb);
$assert($legacyDb['games']['game_test']['board'] !== '---------', 'Legacy bot game without launch_phase must keep moving as before.');

$activeDb = [
    'games' => ['game_test' => $makeBotGame('active')],
    'queue' => [],
    'users' => [],
    'transactions' => [],
    'system' => [],
];
$service->cleanup($activeDb);
$assert($activeDb['games']['game_test']['board'] !== '---------', 'Explicit active Phase B bot game must allow the bot lifecycle.');

fwrite(STDOUT, "PhaseBPreActiveLifecycleIsolationTest: {$assertions} assertions passed\n");
