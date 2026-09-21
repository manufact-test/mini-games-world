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
$assert(str_contains($screen, 'startTournamentLaunchWatch')
        && str_contains($screen, '}, 350);')
        && str_contains($screen, 'match.my_ready === true'),
    'First-ready client must use a short bounded watch for the shared launched game.');
$assert(str_contains($screen, 'stopTournamentLaunchWatch();'),
    'Launch watch must have an explicit stop owner.');
$assert(str_contains($screen, "document.addEventListener('mgw:tournament-progression-open'"),
    'Tournament screen must accept explicit return-to-progression navigation.');

$assert(str_contains($gameRuntime, "new MatchPreparationClockService())->enrichPublicGame")
        && str_contains($specialRuntime, 'return $this->matchPreparationClock->enrichPublicGame'),
    'All runtime families must project the authoritative Phase-B clock.');
$assert(str_contains($clock, "'turn_deadline_epoch_ms'] = null")
        && str_contains($clock, '$this->assignTurnClock($game, $turn)')
        && str_contains($clock, 'MOVE_TIMEOUT_SEC = 60'),
    'Tournament launch countdown and first playable 60-second turn must remain separate clocks.');
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
$assert(str_contains($invites, "String(finished.match_source || '') === 'tournament'"),
    'Legacy direct-rematch enhancer must exclude tournament games.');
$assert(str_contains($rematch, "const tournamentMatch = String(game?.match_source || '') === 'tournament'"),
    'Ordinary rematch presentation policy must know tournament source.');

$assert(str_contains($fixture, 'progressionAcceptanceAvailability')
        && str_contains($fixture, 'completeFixtureOnlyPairs')
        && str_contains($fixture, 'fixtureRuntimeIdentityForMgw')
        && str_contains($fixture, 'observeFinishedGame(['),
    'Staging helper must only identify fixture pairs and reuse canonical progression result ownership.');
$assert(str_contains($fixture, "'finish_reason'=>'staging_fixture_acceptance'")
        && !str_contains($fixture, 'createTournamentGame('),
    'Staging fixture completion must not create fake runtime games or a second entry-fee path.');
$assert(str_contains($adminApi, "'complete_fixture_pairs'")
        && str_contains($adminApi, "'manual_progression'"),
    'Tournament Admin must expose the explicit staging progression action and availability.');
$assert(str_contains($adminJs, 'data-tournament-complete-fixtures')
        && str_contains($adminJs, "action:'complete_fixture_pairs'"),
    'Web Admin must expose an explicit human-triggered fixture-only completion control.');

$assert(str_contains($manifest, 'tournaments-screen-v1.js?v=21')
        && str_contains($manifest, 'game-screen-v102.js?v=110')
        && str_contains($manifest, 'game-invites-v110.js?v=1146')
        && str_contains($manifest, 'game-invites-v110-rematch-policy-v175.js?v=2'),
    'Corrective client owners must publish fresh active cache identities.');

if ($assertions < 23) throw new RuntimeException('Corrective v4 contract is too shallow: ' . $assertions);
fwrite(STDOUT, "Mvp21_5_6CorrectiveV4ContractTest: {$assertions} assertions passed\n");
