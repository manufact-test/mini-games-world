<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$read = static function (string $path) use ($root): string {
    $content = file_get_contents($root . '/' . $path);
    if (!is_string($content)) throw new RuntimeException('Cannot read ' . $path);
    return $content;
};

$screen = $read('app/assets/js/screens/tournaments-screen-v1.js');
$gameRuntime = $read('bot/services/GameRuntimeService.php');
$specialRuntime = $read('bot/services/ChessRuntimeService.php');
$clock = $read('bot/services/MatchPreparationClockService.php');
$api = $read('bot/api.php');
$progression = $read('bot/tournaments/TournamentRoundProgressionService.php');
$readiness = $read('bot/tournaments/TournamentMatchReadinessService.php');
$acceptance = $read('app/assets/js/production-v110-acceptance-runtime.js');
$gameScreen = $read('app/assets/js/screens/game-screen-v102.js');
$invites = $read('app/assets/js/games/game-invites-v110.js');
$rematch = $read('app/assets/js/games/game-invites-v110-rematch-policy-v175.js');
$fixture = $read('bot/tournaments/StagingTournamentManualAcceptanceService.php');
$adminApi = $read('bot/admin-tournaments.php');
$adminJs = $read('app/assets/js/admin-tournaments.js');
$manifest = $read('app/runtime/client/version-manifest.php');

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

$assert(str_contains($screen, 'if (tournamentBusy) return tournamentSnapshot;'),
    'Visible tournament status must not publish a committed seat while registration verification is pending.');
$assert(str_contains($screen, 'EXTERNAL_TOURNAMENT_COMMIT_CONFIRM_MS = 1200')
        && str_contains($screen, 'shouldStageExternalTournamentCommit')
        && str_contains($screen, 'stageExternalTournamentCommit')
        && str_contains($screen, 'const verified = await api.tournamentStatus()'),
    'Other open clients must independently confirm an external registration count before visible publication.');
$assert(str_contains($screen, 'startTournamentLaunchWatch')
        && str_contains($screen, '}, 350);')
        && str_contains($screen, 'match.self_ready === true')
        && !str_contains($screen, 'match.my_ready === true'),
    'First-ready client must use the actual self_ready field for the bounded shared-game watch.');
$assert(str_contains($screen, 'stopTournamentLaunchWatch();'),
    'Launch watch must have an explicit stop owner.');
$assert(str_contains($screen, 'startTournamentT0SyncBurst')
        && str_contains($screen, 'TOURNAMENT_T0_SYNC_INTERVAL_MS = 250')
        && str_contains($screen, 'TOURNAMENT_T0_SYNC_WINDOW_MS = 6000')
        && str_contains($screen, 'tournamentStartSyncTimer'),
    'Tournament Ready materialization must burst-sync across T0 rather than lag behind two/three-second poll phases.');
$assert(str_contains($screen, 'const hallResult = await api.tournamentHallStatus()')
        && str_contains($screen, "typeof hallResult.snapshot === 'object'")
        && str_contains($screen, 'tournamentHallSnapshot?.bracket && matchReady'),
    'T0 burst must use fresh Hall reads until bracket plus match/progression state is available.');
$assert(str_contains($screen, "document.addEventListener('mgw:tournament-progression-open'"),
    'Tournament screen must accept explicit return-to-progression navigation.');

$assert(str_contains($gameRuntime, "new MatchPreparationClockService())->enrichPublicGame")
        && str_contains($specialRuntime, 'return $this->matchPreparationClock->enrichPublicGame'),
    'All runtime families must project the authoritative Phase-B clock.');
$assert(str_contains($clock, "'turn_deadline_epoch_ms'] = null")
        && str_contains($clock, '$this->assignTurnClock($game, $turn)')
        && str_contains($clock, 'MOVE_TIMEOUT_SEC = 60'),
    'Tournament launch countdown and first playable 60-second turn must remain separate clocks.');
$assert(str_contains($clock, 'TOURNAMENT_INITIAL_ADOPTION_GRACE_SEC = 60')
        && str_contains($clock, 'TOURNAMENT_PEER_ADOPTION_TIMEOUT_SEC = 30')
        && str_contains($clock, 'if (!$hadReadyDevice')
        && str_contains($clock, '$game[\'preparation_deadline_at\'] = gmdate'),
    'Tournament no-start timeout must be re-anchored to the first real runtime adoption.');
$assert(!str_contains($api, 'markTournamentPairReady($tournamentGame)')
        && str_contains($api, 'after BOTH real')
        && str_contains($api, '$attachedReadyGameId'),
    'Tournament Ready must attach one game quickly but defer Phase-B countdown until both clients adopt it.');
$assert(str_contains($acceptance, "if (phase && phase !== 'active') return false;")
        && str_contains($acceptance, "const serverReady = phase === 'active';")
        && str_contains($acceptance, "phase === 'countdown'"),
    'Client must keep input and visible first-turn clock gated until the authoritative active snapshot.');
foreach (['match_source','tournament_id','tournament_round_no','tournament_pair_no','tournament_attempt_no',
          'tournament_match_kind','tournament_wait_kind','tournament_side_swap'] as $field) {
    $assert(str_contains($gameRuntime, "'{$field}'")
            || str_contains($specialRuntime, "'{$field}'"),
        'Public tournament metadata missing: ' . $field);
}
$assert(str_contains($gameRuntime, "'rematch_available'=>false")
        && str_contains($specialRuntime, "'rematch_available'=>false"),
    'Tournament public game must explicitly disable ordinary rematch availability.');

$assert(str_contains($gameScreen, "String(game?.match_source || '') === 'tournament'")
        && str_contains($gameScreen, 'id="goTournament"')
        && str_contains($gameScreen, 'Вернуться в турнир')
        && str_contains($gameScreen, 'id="goTournament" type="button"'),
    'Tournament result must expose a dedicated return-to-tournament action.');
$assert(str_contains($gameScreen, 'tournamentResultSummaryMarkup')
        && str_contains($gameScreen, 'Турнирный поединок ·')
        && str_contains($gameScreen, 'if (!options.pending && !tournamentMatch)')
        && str_contains($gameScreen, "if (String(game?.match_source || '') === 'tournament') return;"),
    'Tournament result must never hydrate ordinary economy/history summary or expose unavailable balance copy.');
$assert(str_contains($gameScreen, 'tournamentResultDismissed:new Set()')
        && str_contains($gameScreen, 'runtime.tournamentResultDismissed.add')
        && str_contains($gameScreen, 'runtime.tournamentResultDismissed.has(id)'),
    'Dismissed tournament terminal results must not reopen when stale active-game state is observed again.');
$assert(str_contains($screen, "document.addEventListener('mgw:game-finished'")
        && str_contains($screen, 'synchronizeTournamentTerminalProgression')
        && str_contains($screen, 'tournamentMatchSnapshot = null;')
        && str_contains($screen, 'stopTournamentStartSync();'),
    'Tournament terminal navigation must kill stale launch timers/snapshots and pre-sync durable progression.');
$assert(str_contains($api, 'observeFinishedGame($data[\'games\'][$finishedTournamentGameId])')
        && str_contains($api, '$snapshot = $readiness->status($mgwId, $accountRef, $userId);'),
    'Server terminal observation must refresh Ready projection after durable progression changes.');
$assert(str_contains($readiness, 'isInitialManualReadyRow')
        && str_contains($readiness, 'completed_at_utc')
        && str_contains($readiness, 'attempt_no')
        && str_contains($readiness, 'wait_kind'),
    'Initial Ready projection must disappear once the durable pair has progressed or completed.');
$assert(str_contains($invites, "String(finished.match_source || '') === 'tournament'"),
    'Legacy direct-rematch enhancer must exclude tournament games.');
$assert(str_contains($rematch, "const tournamentMatch = String(game?.match_source || '') === 'tournament'"),
    'Ordinary rematch presentation policy must know tournament source.');

$assert(str_contains($fixture, 'progressionAcceptanceAvailability')
        && str_contains($fixture, 'completeFixtureOnlyPairs')
        && str_contains($fixture, 'fixtureRuntimeIdentityForMgw')
        && str_contains($fixture, 'observeFinishedGame(['),
    'Staging helper must only identify fixture pairs and reuse canonical progression result ownership.');
$assert(str_contains($progression, 'both_absent_at_start_pending')
        && str_contains($progression, 'STATE_READINESS_EXPIRED')
        && str_contains($progression, 'ensureFirstRoundStructure'),
    'Every seeded first-round pair, including both-absent competitors, must remain represented in durable progression.');
$assert(str_contains($fixture, 'ensureFirstRoundStructure($tournamentId)'),
    'Staging progression availability must repair missing first-round rows for already-started manual tournaments.');
$assert(str_contains($fixture, "'finish_reason'=>'staging_fixture_acceptance'")
        && !str_contains($fixture, 'createTournamentGame('),
    'Staging fixture completion must not create fake runtime games or a second entry-fee path.');
$assert(str_contains($adminApi, "'complete_fixture_pairs'")
        && str_contains($adminApi, "'manual_progression'"),
    'Tournament Admin must expose the explicit staging progression action and availability.');
$assert(str_contains($adminJs, 'data-tournament-complete-fixtures')
        && str_contains($adminJs, "action:'complete_fixture_pairs'"),
    'Web Admin must expose an explicit human-triggered fixture-only completion control.');
$assert(str_contains($adminJs, "progressionReason !== 'staging_only'")
        && str_contains($adminJs, "progressionPanel.hidden = !progressionVisible"),
    'Staging Admin must keep the fixture progression panel visible even when the action is temporarily disabled.');

$assert(str_contains($manifest, 'tournaments-screen-v1.js?v=25')
        && str_contains($manifest, 'mvp21_5=corrective-v6')
        && str_contains($manifest, 'game-screen-v102.js?v=112')
        && str_contains($manifest, 'mvp21_6=tournament-result-dedupe-v3')
        && str_contains($manifest, 'production-v110-acceptance-runtime.js?v=132')
        && str_contains($manifest, 'game-invites-v110.js?v=1146')
        && str_contains($manifest, 'game-invites-v110-rematch-policy-v175.js?v=2'),
    'Corrective v6 client owners must publish fresh active cache identities without replacing the accepted Phase-B presentation owner.');

if ($assertions < 37) throw new RuntimeException('Corrective v4 contract is too shallow: ' . $assertions);
fwrite(STDOUT, "Mvp21_5_6CorrectiveV4ContractTest: {$assertions} assertions passed\n");
