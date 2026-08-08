<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/helpers/validators.php';
require_once dirname(__DIR__) . '/services/GameLaunchFinalizationService.php';

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

$base = [
    'id' => 'game_test',
    'game_type' => 'tictactoe',
    'status' => 'active',
    'board_size' => 3,
    'board' => '---------',
    'player_ids' => ['a', 'b'],
    'symbols' => ['a' => 'X', 'b' => 'O'],
    'turn' => 'a',
    'created_at' => '2026-08-08T10:00:00+00:00',
    'updated_at' => '2026-08-08T10:00:00+00:00',
    'last_move_at' => '2026-08-08T10:00:00+00:00',
    'turn_started_at' => '2026-08-08T10:00:00+00:00',
];

$db = ['games' => ['game_test' => $base]];
$first = GameLaunchFinalizationService::finalizeStoredGame($db, 'game_test');
$assert(is_array($first), 'Stored TTT game must be returned.');
$assert(!empty($first['symbols_randomized']), 'TTT symbols must be marked finalized.');
$assert(in_array((string)($first['turn'] ?? ''), ['a', 'b'], true), 'Turn must belong to a participant.');
$assert(($first['symbols'][$first['turn']] ?? '') === 'X', 'The finalized turn owner must be X.');
$assert(array_values($first['symbols']) === ['X', 'O'] || array_values($first['symbols']) === ['O', 'X'], 'Exactly one X and one O must be assigned.');
$assert((string)($first['turn_started_at'] ?? '') !== '', 'Finalization must own the initial turn start timestamp.');
$assert((string)($first['updated_at'] ?? '') === (string)($first['turn_started_at'] ?? ''), 'Initial turn and update timestamps must share one launch instant.');
$assert((string)($first['last_move_at'] ?? '') === (string)($first['turn_started_at'] ?? ''), 'Legacy last_move_at must stay aligned with the launch instant.');

$snapshot = $db['games']['game_test'];
$second = GameLaunchFinalizationService::finalizeStoredGame($db, 'game_test');
$assert($second === $snapshot, 'Repeated finalization must be a strict no-op.');

$moved = $base;
$moved['board'] = 'X--------';
$db = ['games' => ['game_test' => $moved]];
$legacyMoved = GameLaunchFinalizationService::finalizeStoredGame($db, 'game_test');
$assert(!empty($legacyMoved['symbols_randomized']), 'Moved legacy game must be sealed against later randomization.');
$assert((string)$legacyMoved['board'] === 'X--------', 'Moved legacy board must never be rewritten.');
$assert((string)$legacyMoved['turn'] === 'a', 'Moved legacy turn must never be randomized.');

$nonTtt = $base;
$nonTtt['game_type'] = 'checkers';
$db = ['games' => ['game_test' => $nonTtt]];
$unchanged = GameLaunchFinalizationService::finalizeStoredGame($db, 'game_test');
$assert($unchanged === $nonTtt, 'Non-TTT games must remain untouched.');

$finished = $base;
$finished['status'] = 'finished';
$db = ['games' => ['game_test' => $finished]];
$unchangedFinished = GameLaunchFinalizationService::finalizeStoredGame($db, 'game_test');
$assert($unchangedFinished === $finished, 'Finished TTT games must remain untouched.');

$bot = $base;
$bot['player_ids'] = ['human', 'bot_test'];
$bot['symbols'] = ['human' => 'X', 'bot_test' => 'O'];
$bot['turn'] = 'human';
$bot['is_bot_game'] = true;
$bot['bot_id'] = 'bot_test';
$db = ['games' => ['game_test' => $bot]];
$botFinal = GameLaunchFinalizationService::finalizeStoredGame($db, 'game_test');
$assert(!empty($botFinal['symbols_randomized']), 'Bot TTT game must use the same finalizer.');
if ((string)$botFinal['turn'] === 'bot_test') {
    $assert(!empty($botFinal['bot_move_after_at']), 'Bot-first launch must schedule the existing one-second bot move.');
} else {
    $assert(!isset($botFinal['bot_move_after_at']), 'Human-first launch must not leave a bot move schedule.');
}

$missingDb = ['games' => []];
$assert(GameLaunchFinalizationService::finalizeStoredGame($missingDb, 'missing') === null, 'Missing stored game must return null.');

fwrite(STDOUT, "GameLaunchFinalizationServiceTest: {$assertions} assertions passed\n");
