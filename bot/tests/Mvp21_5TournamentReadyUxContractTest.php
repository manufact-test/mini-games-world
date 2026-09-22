<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$read = static function (string $path) use ($root): string {
    $content = file_get_contents($root . '/' . $path);
    if (!is_string($content)) throw new RuntimeException('Cannot read ' . $path);
    return $content;
};

$source = [
    'migration'=>$read('bot/database/migrations/20260921_0054_create_tournament_match_readiness.php'),
    'readiness'=>$read('bot/tournaments/TournamentMatchReadinessService.php'),
    'hall'=>$read('bot/tournaments/TournamentHallService.php'),
    'game_service'=>$read('bot/services/GameService.php'),
    'runtime'=>$read('bot/services/GameRuntimeService.php'),
    'special_runtime'=>$read('bot/services/ChessRuntimeService.php'),
    'clock'=>$read('bot/services/MatchPreparationClockService.php'),
    'api'=>$read('bot/api.php'),
    'client'=>$read('app/assets/js/api/client.js'),
    'screen'=>$read('app/assets/js/screens/tournaments-screen-v1.js'),
    'launch'=>$read('app/assets/js/production-v110-acceptance-runtime.js'),
    'css'=>$read('app/assets/css/main.css'),
    'manifest'=>$read('app/runtime/client/version-manifest.php'),
];

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

$assert(str_contains($source['migration'], 'mgw_tournament_round_matches')
        && str_contains($source['migration'], 'player_a_ready_at_utc')
        && str_contains($source['migration'], 'player_b_ready_at_utc')
        && str_contains($source['migration'], 'UNIQUE KEY uq_mgw_tournament_round_match_game'),
    'MVP-21.5 must own one durable pair/readiness row and one attached game.');

$assert(str_contains($source['readiness'], 'READY_WINDOW_SECONDS = 120'),
    'Ready window must be exactly two minutes.');
$assert(str_contains($source['readiness'], "STATE_WAITING_READY = 'waiting_ready'")
        && str_contains($source['readiness'], "STATE_READY = 'ready'")
        && str_contains($source['readiness'], "STATE_LAUNCHED = 'launched'")
        && str_contains($source['readiness'], "STATE_READINESS_EXPIRED = 'readiness_expired'"),
    'Readiness lifecycle must be durable and explicit.');
$assert(str_contains($source['readiness'], "technical_loss_at_start")
        && str_contains($source['readiness'], "present_at_start")
        && str_contains($source['readiness'], 'return null;'),
    'Only both-present bracket pairs may enter readiness; technical start outcomes stay owned by MVP-21.4.');
$assert(str_contains($source['readiness'], "hash('sha256'")
        && str_contains($source['readiness'], "'game_tour_'"),
    'Tournament game identity must be deterministic for cross-store retry safety.');
$assert(str_contains($source['readiness'], 'Tournament pair is already attached to another game.'),
    'One tournament pair must never attach to a second runtime game.');

$assert(str_contains($source['hall'], 'technical_loss_at_start')
        && str_contains($source['hall'], 'BRACKET_VERSION = \'mvp21-4-random-v1\'')
        && !str_contains($source['hall'], 'player_a_ready_at_utc'),
    'Accepted MVP-21.4 Hall/bracket owner must remain unchanged by readiness implementation.');

$assert(str_contains($source['game_service'], 'public function createTournamentGame')
        && str_contains($source['game_service'], "'bet' => 0")
        && str_contains($source['game_service'], "'bank' => 0")
        && str_contains($source['game_service'], "'match_source' => 'tournament'"),
    'Tournament runtime game must use the canonical game owner without charging a second entry bet.');
$assert(str_contains($source['game_service'], 'Tournament game id conflicts with an existing game.'),
    'Existing deterministic tournament game must fail closed on identity conflict.');
$assert(str_contains($source['runtime'], 'public function createTournamentGame')
        && str_contains($source['runtime'], 'if ($alreadyExists) return $db[\'games\'][$gameId];'),
    'Base runtime must initialize a tournament engine once and return repeat reads unchanged.');
$assert(str_contains($source['special_runtime'], 'public function createTournamentGame')
        && str_contains($source['special_runtime'], "['chess', 'go', 'domino']")
        && str_contains($source['special_runtime'], 'if ($alreadyExists) return $db[\'games\'][$gameId];'),
    'Chess/Go/Domino must use the same idempotent tournament launch path.');

$assert(str_contains($source['clock'], 'public function markReady')
        && str_contains($source['clock'], 'preparation_ready_devices'),
    'Shared Phase-B readiness must wait for each real client to adopt the tournament game.');
$assert(str_contains($source['clock'], 'TOURNAMENT_INITIAL_ADOPTION_GRACE_SEC = 60')
        && str_contains($source['clock'], 'TOURNAMENT_PEER_ADOPTION_TIMEOUT_SEC = 30')
        && str_contains($source['clock'], 'if (!$hadReadyDevice')
        && str_contains($source['clock'], '$adoptionDeadline = $adoptionStarted + self::TOURNAMENT_PEER_ADOPTION_TIMEOUT_SEC'),
    'Tournament preparation timeout must begin from first real runtime adoption, not Hall-side game creation.');
$assert(str_contains($source['clock'], 'countdownSeconds($game)')
        && str_contains($source['clock'], "'launch_countdown_sec' =>"),
    'Shared clock must expose a parameterized authoritative countdown.');
$assert(str_contains($source['clock'], 'self::COUNTDOWN_SEC')
        && str_contains($source['clock'], 'max(1, min(30, $seconds))'),
    'Ordinary three-second launch must remain the default while tournament countdown is bounded.');

foreach (['tournament_match_state','tournament_match_ready'] as $needle) {
    $assert(str_contains($source['api'], $needle), 'Tournament API action missing: ' . $needle);
}
$assert(str_contains($source['api'], '$data[\'games\'][$gameId][\'launch_countdown_sec\'] = 10')
        && !str_contains($source['api'], 'markTournamentPairReady($tournamentGame)')
        && str_contains($source['api'], 'attachGame(')
        && str_contains($source['api'], 'after BOTH real')
        && str_contains($source['api'], '$attachedReadyGameId'),
    'Ready API must create/attach one game but defer the ten-second countdown until both real clients adopt it.');
$assert(str_contains($source['api'], 'GameLaunchFinalizationService::finalizeStoredGame'),
    'Tournament launch must reuse canonical game launch finalization.');
$assert(str_contains($source['api'], 'observeFinishedGame($data[\'games\'][$finishedTournamentGameId])')
        && str_contains($source['api'], '$snapshot = $readiness->status($mgwId, $accountRef, $userId);'),
    'Terminal tournament progression must refresh the Ready projection in the same API response.');

foreach (['tournamentMatchState','tournamentMatchReady'] as $needle) {
    $assert(str_contains($source['client'], $needle), 'Tournament client transport missing: ' . $needle);
}
$assert(str_contains($source['screen'], 'data-tournament-ready')
        && str_contains($source['screen'], "'Я готов'")
        && str_contains($source['screen'], 'readiness_deadline_at_utc')
        && str_contains($source['screen'], 'formatReadyCountdown'),
    'Hall must expose the explicit Ready interaction and authoritative readiness deadline countdown.');
$assert(str_contains($source['screen'], "new CustomEvent('mgw:prime-launch-feedback')")
        && str_contains($source['screen'], 'enterGame(result.game)')
        && str_contains($source['screen'], 'refreshTournamentMatchState'),
    'Ready click must prime feedback, then hand the authoritative game to the existing game screen.');
$assert(str_contains($source['screen'], "tournamentHallSnapshot?.bracket")
        && str_contains($source['screen'], 'await refreshTournamentMatchState()')
        && str_contains($source['screen'], 'match.self_ready === true')
        && str_contains($source['screen'], '}, 350);'),
    'First-ready client must discover the exact shared game through the bounded 350ms self-ready launch watch.');
$assert(str_contains($source['screen'], 'startTournamentVisibleRefresh')
        && str_contains($source['screen'], '2000')
        && str_contains($source['screen'], 'await warmTournamentStatus()')
        && str_contains($source['screen'], 'warmTournamentHallStatus'),
    'Visible Tournament screen must continuously refresh authoritative tournament status and Hall state without navigation away/back.');
$assert(str_contains($source['screen'], 'await api.tournamentRegistrationPublish()')
        && str_contains($source['screen'], "verifiedSnapshot?.registration?.published === false")
        && !str_contains($source['screen'], 'EXTERNAL_TOURNAMENT_COMMIT_CONFIRM_MS')
        && !str_contains($source['screen'], 'stageExternalTournamentCommit'),
    'Cross-client participant count must use the explicit server publication barrier instead of a client delay.');
$assert(str_contains($source['screen'], 'tournamentStartBoundaryTimer')
        && str_contains($source['screen'], 'startTournamentT0SyncBurst')
        && str_contains($source['screen'], 'TOURNAMENT_T0_SYNC_INTERVAL_MS = 250')
        && str_contains($source['screen'], 'TOURNAMENT_T0_SYNC_WINDOW_MS = 6000')
        && str_contains($source['screen'], 'const hallResult = await api.tournamentHallStatus()')
        && str_contains($source['screen'], 'await refreshTournamentMatchState()'),
    'Registered clients must burst-sync fresh Hall/readiness state across T0 instead of waiting for the arbitrary two/three-second poll phase.');
$assert(str_contains($source['screen'], 'Загружаем готовность вашей пары…')
        && str_contains($source['screen'], 'tournamentMatchError')
        && str_contains($source['screen'], 'const matchMarkup = tournamentMatchMarkup();'),
    'Ready UI must be prominent above the bracket and must not silently disappear when match-state loading fails.');
$assert(str_contains($source['screen'], 'if (tournamentStarted && registered)')
        && str_contains($source['screen'], 'tournaments-v2-hall--started'),
    'Started participant view must collapse obsolete pre-start metadata into the bracket-first Hall view.');
$assert(
    strpos($source['api'], "\$snapshot = \$action === 'tournament_match_ready'") !== false
    && strpos($source['api'], "\$progressionSnapshot = \$progression->statusForParticipant") !== false
    && strpos($source['api'], "\$snapshot = \$action === 'tournament_match_ready'")
        < strpos($source['api'], "\$progressionSnapshot = \$progression->statusForParticipant"),
    'MVP-21.5 Ready owner must materialize the first-round pair before later-round progression observes it.'
);
$assert(str_contains($source['api'], '$initialReadyOwnsWindow')
        && str_contains($source['api'], 'if (!$initialReadyOwnsWindow)')
        && str_contains($source['readiness'], 'SELECT tournament_id FROM mgw_tournaments')
        && str_contains($source['readiness'], 'Serialize first-round pair materialization'),
    'Initial Ready window must be isolated from progression and serialized against simultaneous two-client T0 requests.');


$assert(str_contains($source['launch'], 'launchCountdownSeconds(game)')
        && str_contains($source['launch'], 'String(total - index)'),
    'Phase-B presentation must render authoritative N..1 countdown instead of hardcoded 3-2-1.');
$assert(str_contains($source['launch'], "import { haptic }")
        && str_contains($source['launch'], 'navigator.vibrate')
        && str_contains($source['launch'], 'createOscillator')
        && str_contains($source['launch'], 'AudioContext'),
    'Countdown must provide bounded sound and vibration/haptic feedback.');
$assert(str_contains($source['launch'], "presentation.lastCue === value"),
    'Sound/vibration cue must be deduped per visible countdown number.');

foreach ([
    '.tournaments-v2-ready',
    '.tournaments-v2-ready-players',
    '.tournaments-v2-ready-player.is-ready',
    '.tournaments-v2-ready-action',
] as $needle) {
    $assert(str_contains($source['css'], $needle), 'Ready UI CSS missing: ' . $needle);
}

$assert(str_contains($source['manifest'], 'client.js?v=1145')
        && str_contains($source['manifest'], 'mvp21_5=ready-v1'),
    'API client must publish a fresh MVP-21.5 cache identity.');
$assert(str_contains($source['manifest'], 'tournaments-screen-v1.js?v=29')
        && str_contains($source['manifest'], 'mvp21_8=corrective-v8')
        && str_contains($source['manifest'], 'registration=server-publish-barrier-v1')
        && str_contains($source['manifest'], 'ready=t0-burst-250ms-peer-adoption-v4'),
    'Tournament screen must publish the fresh corrective-v8 registration/T0/peer-adoption cache identity.');
$assert(str_contains($source['manifest'], 'production-v110-acceptance-runtime.js?v=132')
        && str_contains($source['manifest'], 'mvp21_5=countdown-10-fresh60-v2'),
    'Shared Phase-B presentation must publish the fresh server-active/fresh-60 cache identity.');
$assert(str_contains($source['manifest'], 'main.css?v=200'),
    'Readiness presentation CSS must publish a fresh cache identity.');

if ($assertions < 40) throw new RuntimeException('MVP-21.5 UX contract is too shallow.');
fwrite(STDOUT, "Mvp21_5TournamentReadyUxContractTest: {$assertions} assertions passed\n");
