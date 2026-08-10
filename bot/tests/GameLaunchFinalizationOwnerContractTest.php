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
    'All game types must converge on exactly one shared preparation activation call.');
$assert(!str_contains($api, $activationCall) && !str_contains($inviteStorage, $activationCall),
    'API and invite callers must not become competing preparation clock owners.');

foreach ([
    'tictactoe',
    'four_in_a_row',
    'battleship',
    'checkers',
    'reversi',
    'chess',
    'go',
    'domino',
] as $gameType) {
    $assert(str_contains($service, "'{$gameType}'"), $gameType . ' must be explicitly enrolled in the shared Phase B activation catalog.');
}
$assert(str_contains($service, 'private const PHASE_B_GAME_TYPES'),
    'Supported Phase B games must have one explicit activation allow-list.');
$assert(str_contains($service, 'bool $activatePreparationForNewGame = true'),
    'Post-create finalization must activate preparation by default.');
$assert(str_contains($service, 'if ($activatePreparationForNewGame)'),
    'Compatibility callers must be able to opt out of new-game activation without bypassing TTT finalization.');
$assert(str_contains($service, "array_key_exists('launch_phase', \$game)"),
    'Repeated activation must remain idempotent and must never extend an existing preparation deadline.');
$assert(str_contains($service, 'count(array_unique($playerIds)) < 2'),
    'Shared activation must reject malformed participant shapes.');

$gameStatePos = strpos($api, "case 'game_state':");
$gameActionPos = strpos($api, "case 'game_action':");
$assert($gameStatePos !== false && $gameActionPos !== false && $gameStatePos < $gameActionPos,
    'game_state source boundary must be available for compatibility ownership checks.');
$gameStateSource = substr($api, $gameStatePos, $gameActionPos - $gameStatePos);
$assert(str_contains($gameStateSource, "\$createdFallbackGameId = '';"),
    'game_state must explicitly track whether its search refresh created a new fallback game.');
$assert(str_contains($gameStateSource, '$createdFallbackGameId = (string)($game[\'id\'] ?? \'\');'),
    'Only the actual fallback creation result may grant new-game activation intent.');
$assert(str_contains($gameStateSource, "\$createdFallbackGameId !== '' && \$createdFallbackGameId === \$storedGameId"),
    'game_state compatibility finalization must activate only the exact game created in that request.');

$startSearchPos = strpos($api, "case 'start_search':");
$leaveSearchPos = strpos($api, "case 'leave_search':");
$assert($startSearchPos !== false && $leaveSearchPos !== false && $startSearchPos < $leaveSearchPos,
    'start_search source boundary must be available for post-create ownership checks.');
$startSearchSource = substr($api, $startSearchPos, $leaveSearchPos - $startSearchPos);
$assert(substr_count($startSearchSource, $call) === 1,
    'Human matchmaking creation must pass through the shared post-create finalizer exactly once.');
$assert(str_contains($startSearchSource, '$existingGameIdBeforeSearch ='),
    'start_search must remember any already-active game before matchmaking mutates user state.');
$assert(str_contains($startSearchSource, "\$existingGameIdBeforeSearch === '' || \$existingGameIdBeforeSearch !== \$gameId"),
    'start_search must grant Phase B activation only when the returned game id is new to that request.');

$assert(str_contains($inviteStorage, "['match_source']") && strpos($inviteStorage, "['match_source']") < strpos($inviteStorage, $call),
    'Invite/rematch game-specific metadata must be stored before the shared post-create finalizer runs.');
$assert(!str_contains($service, 'GameSettlementService')
    && !str_contains($service, 'balance_')
    && !str_contains($service, "['transactions']"),
    'Launch finalization must not own economy or settlement.');
$assert(!str_contains($service, 'sleep(') && !str_contains($service, 'usleep('),
    'Final activation must not hide lifecycle races with timing patches.');

fwrite(STDOUT, "GameLaunchFinalizationOwnerContractTest: {$assertions} assertions passed\n");
