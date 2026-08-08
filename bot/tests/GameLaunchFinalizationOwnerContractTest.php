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
$assert(str_contains($service, 'final class GameLaunchFinalizationService'), 'One explicit launch-finalization service must exist.');
$assert(substr_count($service, 'random_int(') === 1, 'TTT X/O randomness must have exactly one implementation owner.');
$assert(!str_contains($api, 'function mgw_randomize_symbols_for_new_game'), 'Primary API must not keep a second TTT randomizer function.');
$assert(!str_contains($inviteStorage, 'function randomizeTicTacToe'), 'Invite/rematch storage must not keep a second TTT randomizer method.');
$assert(substr_count($api, $call) === 2, 'Primary API must use the finalizer for created search games and game_state compatibility/bot fallback.');
$assert(substr_count($inviteStorage, $call) === 1, 'Invite/rematch creation must use the same finalizer exactly once.');
$assert(!str_contains($api, 'random_int(') && !str_contains($inviteStorage, 'random_int('), 'No caller may own X/O randomness.');
$assert(str_contains($service, "!empty(\$game['symbols_randomized'])"), 'Repeated finalization must remain idempotent.');
$assert(str_contains($service, "(string)(\$game['board'] ?? '') !== \$emptyBoard"), 'Non-empty legacy boards must never be randomized.');
$assert(str_contains($service, "(string)(\$game['status'] ?? '') !== 'active'"), 'Only newly active TTT games may be finalized.');
$assert(!str_contains($service, 'launch_phase') && !str_contains($api, 'launch_phase') && !str_contains($inviteStorage, 'launch_phase'), 'This owner-only PR must not activate Phase B preparation yet.');
$assert(!str_contains($service, 'GameSettlementService') && !str_contains($service, 'balance_') && !str_contains($service, "['transactions']"), 'Launch finalization must not own economy or settlement.');
$assert(str_contains($inviteStorage, "['match_source']") && strpos($inviteStorage, "['match_source']") < strpos($inviteStorage, $call), 'Invite metadata must be stored before the shared post-create finalizer runs.');

fwrite(STDOUT, "GameLaunchFinalizationOwnerContractTest: {$assertions} assertions passed\n");
