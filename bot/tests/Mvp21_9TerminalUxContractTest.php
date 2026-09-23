<?php
declare(strict_types=1);
// Manual acceptance UI corrective CI checkpoint.

$root=dirname(__DIR__);
$js=file_get_contents(dirname($root).'/app/assets/js/screens/tournaments-screen-v1.js');
$gameJs=file_get_contents(dirname($root).'/app/assets/js/screens/game-screen-v102.js');
$css=file_get_contents(dirname($root).'/app/assets/css/main.css');
$manifest=file_get_contents(dirname($root).'/app/runtime/client/version-manifest.php');
$api=file_get_contents($root.'/api.php');
$stagingReset=file_get_contents($root.'/tournaments/StagingTournamentManualAcceptanceService.php');

if(!is_string($js)||!is_string($gameJs)||!is_string($css)||!is_string($manifest)||!is_string($api)||!is_string($stagingReset)){
    throw new RuntimeException('MVP-21.9 terminal UX sources are unavailable.');
}

$assertions=0;
$assertContains=static function(string $needle,string $haystack,string $message)use(&$assertions):void{
    $assertions++;
    if(strpos($haystack,$needle)===false){
        throw new RuntimeException($message.' Missing: '.$needle);
    }
};
$assertNotContains=static function(string $needle,string $haystack,string $message)use(&$assertions):void{
    $assertions++;
    if(strpos($haystack,$needle)!==false){
        throw new RuntimeException($message.' Forbidden: '.$needle);
    }
};

$assertContains('function tournamentTerminalMarkup(progression, activeRoundMarkup)', $js, 'Terminal presentation must have one explicit owner.');
$assertContains('Все матчи турнира завершены. Награды начислены', $js, 'Completed tournament must expose settled terminal state.');
$assertContains('data-tournament-terminal-rating', $js, 'Terminal result must provide a clear next action.');
$assertContains('Финальная сетка · архив', $js, 'Final bracket must remain available as archive instead of dominating terminal UX.');
$assertContains("if (matchKind === 'final') status = winner ? 'чемпион' : '2 место';", $js, 'Final winner/loser semantics must be terminal.');
$assertContains("else if (matchKind === 'third_place') status = winner ? '3 место' : '4 место';", $js, 'Third-place semantics must be terminal.');
$assertContains("matches.some(match => ['final','third_place'].includes", $js, 'Final-round heading must derive from match kind rather than hard-coded round number.');
$assertNotContains("roundNo === 3 ? 'Финальный раунд'", $js, 'Eight-player round number must not become a permanent final-round assumption.');

$assertContains('.tournaments-v2-terminal{', $css, 'Terminal block must have isolated styling.');
$assertContains('.tournaments-v2-terminal-podium{', $css, 'Podium must have dedicated responsive styling.');
$assertContains('tournaments-v2-tournament-rules tournaments-v2-final-bracket-archive', $js, 'Final archive must reuse the accepted starting-bracket disclosure shell.');
$assertContains('.tournaments-v2-tournament-rules', $css, 'Unified bracket archive disclosure must reuse the tournament rules styling owner.');
$assertContains('Награда:', $js, 'Player-facing terminal copy must show only the final tournament reward.');
$assertNotContains('moneyCopy = `Выплата', $js, 'Player-facing terminal copy must not expose internal prize/entry-return accounting.');
$assertContains("document.addEventListener('mgw:sheet-closed'", $gameJs, 'Closing a tournament result sheet must have an explicit route owner.');
$assertContains("String(game?.match_source || '') !== 'tournament'", $gameJs, 'Result-sheet close routing must remain tournament-scoped.');
$assertContains("showScreen('tournaments')", $gameJs, 'Dismissed tournament result must return to the Tournament surface instead of the dead game board.');

$assertContains('mvp21_9=terminal-results-settlement-v1', $manifest, 'Actual tournament module mapping must publish MVP-21.9 runtime.');
$assertContains('mvp21_manual=acceptance-corrective-v1', $manifest, 'Manual acceptance corrective must publish a fresh Tournament screen identity.');
$assertContains('mvp21_manual=tournament-result-return-v1', $manifest, 'Tournament result return corrective must publish a fresh active game-screen identity.');
$assertContains('mvp21_9=terminal-results-v1', $manifest, 'Actual main CSS mapping must publish MVP-21.9 styling.');

$assertContains('new TournamentSettlementService(', $api, 'Terminal API must use the canonical settlement owner.');
$assertContains('mgw_apply_tournament_settlement_balances(', $api, 'Settlement must converge canonical DB payout into current runtime balances.');
$assertContains("'terminal_result'", $api, 'Terminal settlement projection must reach tournament UI.');

$assertContains("in_array(\$reservationStatus, ['active','consumed'], true)", $stagingReset, 'Staging reset must accept both active and terminal-consumed reservations.');
$assertContains("'settled_reservations_preserved'", $stagingReset, 'Staging reset must expose consumed-reservation evidence.');
$assertContains('Settled synthetic staging fixture must not retain competitive rewards.', $stagingReset, 'Staging reset must fail closed if a synthetic fixture somehow kept a competitive payout.');

echo "MVP-21.9 terminal UX contract OK ({$assertions} assertions)\n";
