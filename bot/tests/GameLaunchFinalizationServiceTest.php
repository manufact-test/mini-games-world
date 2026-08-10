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
$beforeFinalize = time();
$first = GameLaunchFinalizationService::finalizeStoredGame($db, 'game_test');
$afterFinalize = time();
$assert(is_array($first), 'Stored TTT game must be returned.');
$assert(!empty($first['symbols_randomized']), 'TTT symbols must be marked finalized.');
$assert(in_array((string)($first['turn'] ?? ''), ['a', 'b'], true), 'Turn must belong to a participant.');
$assert(($first['symbols'][$first['turn']] ?? '') === 'X', 'The finalized turn owner must be X.');
$assert(array_values($first['symbols']) === ['X', 'O'] || array_values($first['symbols']) === ['O', 'X'], 'Exactly one X and one O must be assigned.');
$assert((string)($first['launch_phase'] ?? '') === 'preparing', 'A newly finalized TTT game must enter synchronized preparation.');
$assert(($first['preparation_ready_devices'] ?? null) === [], 'New TTT preparation must start with no client readiness.');
$assert(array_key_exists('starts_at', $first) && $first['starts_at'] === null, 'Shared countdown start must not exist before both players are ready.');
$assert(array_key_exists('turn_starts_at', $first) && $first['turn_starts_at'] === null, 'First turn start must not exist before readiness completes.');
$assert(array_key_exists('turn_deadline_at', $first) && $first['turn_deadline_at'] === null, 'First turn deadline must not run during preparation.');
$assert((string)($first['clock_turn'] ?? 'missing') === '', 'Preparation must not expose an active clock owner yet.');
$assert((int)($first['clock_revision'] ?? -1) === 0, 'Preparation clock revision must start at zero.');
$deadline = strtotime((string)($first['preparation_deadline_at'] ?? '')) ?: 0;
$assert($deadline >= $beforeFinalize + MatchPreparationClockService::PREPARATION_TIMEOUT_SEC
    && $deadline <= $afterFinalize + MatchPreparationClockService::PREPARATION_TIMEOUT_SEC,
    'Preparation deadline must be the bounded ten-second server deadline.');
$assert((string)($first['turn_started_at'] ?? '') === (string)($first['preparation_deadline_at'] ?? ''),
    'Legacy turn_started_at must be parked at the preparation deadline.');
$assert(!isset($first['bot_move_after_at']), 'Human TTT preparation must not retain an early bot schedule.');

$snapshot = $db['games']['game_test'];
$second = GameLaunchFinalizationService::finalizeStoredGame($db, 'game_test');
$assert($second === $snapshot, 'Repeated finalization must be a strict no-op and never extend preparation.');

$legacyFinalized = $base;
$legacyFinalized['symbols_randomized'] = true;
$db = ['games' => ['game_test' => $legacyFinalized]];
$legacySnapshot = $db['games']['game_test'];
$alreadyFinalized = GameLaunchFinalizationService::finalizeStoredGame($db, 'game_test', false);
$assert($alreadyFinalized === $legacySnapshot, 'Compatibility finalization must leave already-finalized legacy TTT games unchanged.');
$assert(!array_key_exists('launch_phase', $alreadyFinalized), 'Compatibility reads must not push legacy TTT games into preparation.');

$moved = $base;
$moved['board'] = 'X--------';
$db = ['games' => ['game_test' => $moved]];
$legacyMoved = GameLaunchFinalizationService::finalizeStoredGame($db, 'game_test', false);
$assert(!empty($legacyMoved['symbols_randomized']), 'Moved legacy game must be sealed against later randomization.');
$assert((string)$legacyMoved['board'] === 'X--------', 'Moved legacy board must never be rewritten.');
$assert((string)$legacyMoved['turn'] === 'a', 'Moved legacy turn must never be randomized.');
$assert(!array_key_exists('launch_phase', $legacyMoved), 'Moved legacy games must never be pushed back into preparation.');

$invalidPlayers = $base;
$invalidPlayers['player_ids'] = ['a'];
$db = ['games' => ['game_test' => $invalidPlayers]];
$invalid = GameLaunchFinalizationService::finalizeStoredGame($db, 'game_test');
$assert(!empty($invalid['symbols_randomized']), 'Invalid participant shape must be sealed against later TTT randomization.');
$assert(!array_key_exists('launch_phase', $invalid), 'Invalid participant shape must not activate preparation.');

$phaseBTypes = [
    'tictactoe',
    'four_in_a_row',
    'battleship',
    'checkers',
    'reversi',
    'chess',
    'go',
    'domino',
];
foreach ($phaseBTypes as $gameType) {
    $candidate = $base;
    $candidate['game_type'] = $gameType;
    if ($gameType !== 'tictactoe') {
        $candidate['symbols_randomized'] = true;
    }
    $db = ['games' => ['game_test' => $candidate]];
    $activated = GameLaunchFinalizationService::finalizeStoredGame($db, 'game_test');
    $assert((string)($activated['launch_phase'] ?? '') === 'preparing', $gameType . ' new game must enter shared preparation.');
    $assert(($activated['preparation_ready_devices'] ?? null) === [], $gameType . ' must start with no readiness.');
    $assert(array_key_exists('starts_at', $activated) && $activated['starts_at'] === null, $gameType . ' countdown must wait for readiness.');
    $assert((int)($activated['clock_revision'] ?? -1) === 0, $gameType . ' preparation clock revision must start at zero.');
}

$legacyNonTtt = $base;
$legacyNonTtt['game_type'] = 'checkers';
$legacyNonTtt['symbols_randomized'] = true;
$db = ['games' => ['game_test' => $legacyNonTtt]];
$legacyNonTttSnapshot = $db['games']['game_test'];
$unchanged = GameLaunchFinalizationService::finalizeStoredGame($db, 'game_test', false);
$assert($unchanged === $legacyNonTttSnapshot, 'Compatibility reads must leave legacy non-TTT games untouched.');
$assert(!array_key_exists('launch_phase', $unchanged), 'Legacy non-TTT games must remain outside Phase B unless explicitly new.');

$unsupported = $base;
$unsupported['game_type'] = 'future_unknown_game';
$unsupported['symbols_randomized'] = true;
$db = ['games' => ['game_test' => $unsupported]];
$unsupportedResult = GameLaunchFinalizationService::finalizeStoredGame($db, 'game_test');
$assert(!array_key_exists('launch_phase', $unsupportedResult), 'Unknown games must not be silently enrolled in Phase B.');

$finished = $base;
$finished['status'] = 'finished';
$db = ['games' => ['game_test' => $finished]];
$unchangedFinished = GameLaunchFinalizationService::finalizeStoredGame($db, 'game_test');
$assert($unchangedFinished === $finished, 'Finished games must remain untouched.');
$assert(!array_key_exists('launch_phase', $unchangedFinished), 'Finished games must not re-enter preparation.');

$bot = $base;
$bot['player_ids'] = ['human', 'bot_test'];
$bot['symbols'] = ['human' => 'X', 'bot_test' => 'O'];
$bot['turn'] = 'human';
$bot['is_bot_game'] = true;
$bot['bot_id'] = 'bot_test';
$db = ['games' => ['game_test' => $bot]];
$botFinal = GameLaunchFinalizationService::finalizeStoredGame($db, 'game_test');
$assert(!empty($botFinal['symbols_randomized']), 'Bot TTT game must use the same finalizer.');
$assert((string)($botFinal['launch_phase'] ?? '') === 'preparing', 'New bot TTT games must use the same preparation lifecycle.');
$assert(!isset($botFinal['bot_move_after_at']), 'Bot may not move before server-owned readiness and countdown complete.');
$assert(($botFinal['preparation_ready_devices'] ?? null) === [], 'Bot readiness must not be fabricated by the finalizer.');

$botCheckers = $legacyNonTtt;
$botCheckers['player_ids'] = ['human', 'bot_checkers'];
$botCheckers['is_bot_game'] = true;
$botCheckers['bot_id'] = 'bot_checkers';
$botCheckers['turn'] = 'bot_checkers';
$botCheckers['bot_move_after_at'] = gmdate('c', time() + 1);
$db = ['games' => ['game_test' => $botCheckers]];
$botCheckersFinal = GameLaunchFinalizationService::finalizeStoredGame($db, 'game_test');
$assert((string)($botCheckersFinal['launch_phase'] ?? '') === 'preparing', 'New non-TTT bot games must enter shared preparation.');
$assert(!isset($botCheckersFinal['bot_move_after_at']), 'Non-TTT bots must not move before shared readiness and countdown.');

$missingDb = ['games' => []];
$assert(GameLaunchFinalizationService::finalizeStoredGame($missingDb, 'missing') === null, 'Missing stored game must return null.');

fwrite(STDOUT, "GameLaunchFinalizationServiceTest: {$assertions} assertions passed\n");
