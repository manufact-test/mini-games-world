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

$assert(str_contains($source['clock'], 'markTournamentPairReady')
        && str_contains($source['clock'], "match_source")
        && str_contains($source['clock'], 'server-tournament-ready'),
    'Durable Hall readiness must hand off into the existing shared Phase-B clock without client auto-ready.');
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
        && str_contains($source['api'], 'markTournamentPairReady($tournamentGame)')
        && str_contains($source['api'], 'attachGame('),
    'Both-ready API path must create one ten-second locked game and durably attach it.');
$assert(str_contains($source['api'], 'GameLaunchFinalizationService::finalizeStoredGame'),
    'Tournament launch must reuse canonical game launch finalization.');

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
        && str_contains($source['screen'], 'await refreshTournamentMatchState()'),
    'Hall heartbeat must let the first-ready player discover the exact game launched by the second.');

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

$assert(str_contains($source['manifest'], 'client.js?v=1143')
        && str_contains($source['manifest'], 'mvp21_5=ready-v1'),
    'API client must publish a fresh MVP-21.5 cache identity.');
$assert(str_contains($source['manifest'], 'tournaments-screen-v1.js?v=18')
        && str_contains($source['manifest'], 'mvp21_5=ready-first-match-v1'),
    'Tournament screen must publish a fresh MVP-21.5 cache identity.');
$assert(str_contains($source['manifest'], 'production-v110-acceptance-runtime.js?v=131')
        && str_contains($source['manifest'], 'mvp21_5=countdown-10-av-v1'),
    'Shared Phase-B presentation must publish a fresh countdown cache identity.');
$assert(str_contains($source['manifest'], 'main.css?v=199'),
    'Readiness presentation CSS must publish a fresh cache identity.');

if ($assertions < 32) throw new RuntimeException('MVP-21.5 UX contract is too shallow.');
fwrite(STDOUT, "Mvp21_5TournamentReadyUxContractTest: {$assertions} assertions passed\n");
