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
$locale=json_decode((string)file_get_contents(dirname($root).'/app/locales/ru.json'),true,512,JSON_THROW_ON_ERROR);

if(!is_string($js)||!is_string($gameJs)||!is_string($css)||!is_string($manifest)||!is_string($api)||!is_string($stagingReset)||!is_array($locale)){
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

$assertContains('function tournamentTerminalMarkup(progression)', $js, 'Terminal presentation must have one explicit owner.');
$assertContains("t('arena.terminal.all_done_rewards')", $js, 'Completed tournament must expose settled terminal state through canonical localization.');
if (($locale['arena']['terminal']['all_done_rewards'] ?? null) !== 'Все матчи турнира завершены. Награды начислены') throw new RuntimeException('Accepted RU terminal completion copy changed.');
$assertContains('data-tournament-terminal-rating', $js, 'Terminal result must provide a clear next action.');
$assertContains("return t('arena.bracket.final_round');", $js, 'Final placement matches must remain available in their own localized final-round accordion.');
if (($locale['arena']['bracket']['final_round'] ?? null) !== 'Финальный раунд') throw new RuntimeException('Accepted RU final-round copy changed.');
$assertContains("status = winner ? t('arena.bracket.champion') : (hasWinner ? t('arena.bracket.second_place') : t('arena.bracket.no_result'));", $js, 'Final winner/loser semantics must remain terminal through canonical localization.');
$assertContains("status = winner ? t('arena.bracket.third_place_status') : (hasWinner ? t('arena.bracket.fourth_place') : t('arena.bracket.no_result'));", $js, 'Third-place semantics must remain terminal through canonical localization.');
$assertContains("matches.some(match => ['final','third_place'].includes", $js, 'Final-round heading must derive from match kind rather than hard-coded round number.');
$assertNotContains("roundNo === 3 ? 'Финальный раунд'", $js, 'Eight-player round number must not become a permanent final-round assumption.');

$assertContains('.tournaments-v2-terminal{', $css, 'Terminal block must have isolated styling.');
$assertContains('.tournaments-v2-terminal-podium{', $css, 'Podium must have dedicated responsive styling.');
$assertContains('tournaments-v2-tournament-rules tournaments-v2-round-archive', $js, 'Every round archive, including the final stage, must reuse the accepted disclosure shell.');
$assertContains('.tournaments-v2-tournament-rules', $css, 'Unified bracket archive disclosure must reuse the tournament rules styling owner.');
$assertContains("t('arena.terminal.reward_amount'", $js, 'Player-facing terminal copy must show only the final tournament reward through canonical localization.');
if (($locale['arena']['terminal']['reward_amount'] ?? null) !== 'Награда: {amount} коинов') throw new RuntimeException('Accepted RU terminal reward copy changed.');
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
