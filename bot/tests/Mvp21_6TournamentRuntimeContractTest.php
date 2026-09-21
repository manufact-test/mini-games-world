<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$read = static function (string $path) use ($root): string {
    $content = file_get_contents($root . '/' . $path);
    if (!is_string($content)) throw new RuntimeException('Cannot read ' . $path);
    return $content;
};

$api = $read('bot/api.php');
$gameService = $read('bot/services/GameService.php');
$readiness = $read('bot/tournaments/TournamentMatchReadinessService.php');
$progression = $read('bot/tournaments/TournamentRoundProgressionService.php');
$bootstrap = $read('bot/core/bootstrap.php');

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

$assert(str_contains($bootstrap, 'TournamentRoundProgressionService.php'),
    'Bootstrap must load the tournament progression owner.');
$assert(str_contains($api, 'new TournamentRoundProgressionService($database)'),
    'Tournament match API must instantiate the progression owner.');
$assert(str_contains($api, '$progression->statusForParticipant('),
    'Tournament heartbeat must publish progression state.');
$assert(str_contains($api, '$progression->observeFinishedGame($data[\'games\'][$finishedTournamentGameId])'),
    'Finished canonical tournament games must be observed by the durable progression owner.');
$assert(str_contains($api, '$progression->launchContextForParticipant('),
    'Tournament API must fall back from first-match readiness to later-round progression launch.');
$assert(str_contains($api, '$progressionOwnsLaunch = is_array($launch);'),
    'API must explicitly distinguish later-round progression ownership.');
$assert(str_contains($api, "'tournament_attempt_no'=>(int)(\$launch['attempt_no'] ?? 1)"),
    'Tournament game creation must receive attempt identity.');
$assert(str_contains($api, "'tournament_match_kind'=>(string)(\$launch['match_kind'] ?? 'elimination')"),
    'Tournament game creation must receive final/third-place stage identity.');
$assert(str_contains($api, "'tournament_wait_kind'=>(string)(\$launch['wait_kind'] ?? 'initial_ready')"),
    'Tournament game creation must receive wait-kind identity.');
$assert(str_contains($api, "'tournament_side_swap'=>!empty(\$launch['side_swap'])"),
    'Tournament replay launch must expose the durable side-swap marker.');
$assert(str_contains($api, '$progression->attachGame('),
    'Later-round/replay game attachment must be DB-owned by progression.');
$assert(str_contains($api, "'progression'=>\$progressionSnapshot"),
    'Tournament match response must return progression state to the client.');

$assert(str_contains($gameService, "\$attemptNo = max(1, (int)(\$metadata['tournament_attempt_no'] ?? 1));"),
    'Game runtime must normalize tournament attempt metadata.');
$assert(str_contains($gameService, "'tournament_attempt_no' => \$attemptNo"),
    'Stored game must persist tournament attempt number.');
$assert(str_contains($gameService, "'tournament_match_kind' => \$matchKind"),
    'Stored game must persist tournament match kind.');
$assert(str_contains($gameService, "'tournament_wait_kind' => \$waitKind"),
    'Stored game must persist tournament wait kind.');
$assert(str_contains($gameService, "'tournament_side_swap' => \$sideSwap"),
    'Stored game must persist replay side-swap marker.');
$assert(str_contains($gameService, "\$attemptNo > 1 ? ':a' . \$attemptNo : ''"),
    'Replay source_match_id must be attempt-aware without changing first-attempt identity.');
$assert(str_contains($gameService, "'bet' => 0") && str_contains($gameService, "'bank' => 0"),
    'Tournament replay/round games must remain zero-bet and never charge the entry fee again.');

$assert(str_contains($readiness, "(int)(\$row['attempt_no'] ?? 1) !== 1"),
    'First-match readiness must reject replay attempts.');
$assert(str_contains($readiness, "(string)(\$row['wait_kind'] ?? 'initial_ready') !== 'initial_ready'"),
    'First-match readiness must reject round/replay wait owners.');
$assert(str_contains($readiness, "trim((string)(\$row['completed_at_utc'] ?? '')) !== ''"),
    'Manual Ready must remain frozen after the first logical match completes.');
$assert(str_contains($progression, 'public const ROUND_BREAK_SECONDS = 300;'),
    'Progression must keep the canonical five-minute round break.');
$assert(str_contains($progression, 'public const DRAW_REPLAY_WAIT_SECONDS = 60;'),
    'Progression must keep the canonical one-minute draw replay wait.');
$assert(str_contains($progression, "MATCH_THIRD_PLACE = 'third_place'"),
    'Progression must explicitly own the third-place match.');
$assert(str_contains($progression, "'player_a_mgw_id'=>\$bMgw")
        && str_contains($progression, "'player_b_mgw_id'=>\$aMgw"),
    'Draw replay must durably reverse pair order.');

if ($assertions < 26) throw new RuntimeException('MVP-21.6 runtime contract is too shallow: ' . $assertions);
fwrite(STDOUT, "Mvp21_6TournamentRuntimeContractTest: {$assertions} assertions passed\n");
