<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$service = file_get_contents($root . '/bot/services/GameLaunchFinalizationService.php');
$api = file_get_contents($root . '/bot/api.php');
$inviteStorage = file_get_contents($root . '/bot/services/invites/GameInviteStorageTrait.php');
if (!is_string($service) || !is_string($api) || !is_string($inviteStorage)) {
    throw new RuntimeException('Launch-finalization sources are unavailable.');
}

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

$call = 'GameLaunchFinalizationService::finalizeStoredGame';
$activationCall = 'initializeNewGame($game)';
$assert(str_contains($service, 'final class GameLaunchFinalizationService'), 'One explicit launch-finalization service must exist.');
$assert(substr_count($service, 'random_int(') === 1, 'TTT X/O randomness must have exactly one implementation owner.');
$assert(!str_contains($api, 'function mgw_randomize_symbols_for_new_game'), 'Primary API must not keep a second TTT randomizer function.');
$assert(!str_contains($inviteStorage, 'function randomizeTicTacToe'), 'Invite/rematch storage must not keep a second TTT randomizer method.');
$assert(substr_count($api, $call) === 2, 'Primary API must use the finalizer for created search games and game_state compatibility/bot fallback.');
$assert(substr_count($inviteStorage, $call) === 1, 'Invite/rematch creation must use the same finalizer exactly once.');
$assert(!str_contains($api, 'random_int(') && !str_contains($inviteStorage, 'random_int('), 'No caller may own X/O randomness.');

$assert(str_contains($service, "require_once __DIR__ . '/MatchPreparationClockService.php';"),
    'The finalization owner must explicitly load the shared Phase B clock service.');
$assert(substr_count($service, $activationCall) === 1,
    'New TTT preparation must have exactly one activation call in the finalization owner.');
$assert(!str_contains($api, $activationCall) && !str_contains($inviteStorage, $activationCall),
    'API and invite callers must not become competing preparation activation owners.');
$assert(str_contains($service, "!empty(\$game['symbols_randomized'])"),
    'Already-finalized legacy games must return before preparation activation.');
$assert(str_contains($service, "(string)(\$game['board'] ?? '') !== \$emptyBoard"),
    'Non-empty legacy boards must return before preparation activation.');
$assert(str_contains($service, "(string)(\$game['status'] ?? '') !== 'active'"),
    'Only newly active TTT games may be finalized and activated.');
$assert(str_contains($service, "\$game['game_type'] !== 'tictactoe'"),
    'Preparation activation must remain TTT-only at this switch.');

$normalFinalizedPos = strrpos($service, "\$game['symbols_randomized'] = true;");
$activationPos = strpos($service, $activationCall);
$assert($normalFinalizedPos !== false && $activationPos !== false && $normalFinalizedPos < $activationPos,
    'Preparation may activate only after one-time symbol/turn finalization has been sealed.');
$botSchedulePos = strpos($service, "\$game['bot_move_after_at'] = gmdate('c', time() + 1);");
$assert($botSchedulePos !== false && $botSchedulePos < $activationPos,
    'Shared preparation must run after legacy bot launch scheduling so initializeNewGame can clear any early bot move.');

$assert(!str_contains($service, 'GameSettlementService')
    && !str_contains($service, 'balance_')
    && !str_contains($service, "['transactions']"),
    'Launch finalization must not own economy or settlement.');
$assert(str_contains($inviteStorage, "['match_source']") && strpos($inviteStorage, "['match_source']") < strpos($inviteStorage, $call),
    'Invite metadata must be stored before the shared post-create finalizer runs.');
$assert(!str_contains($service, 'sleep(') && !str_contains($service, 'usleep('),
    'Final activation must not hide lifecycle races with timing patches.');

fwrite(STDOUT, "GameLaunchFinalizationOwnerContractTest: {$assertions} assertions passed\n");
