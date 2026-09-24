<?php
declare(strict_types=1);

$root=dirname(__DIR__,2);
$files=[
 'projector'=>file_get_contents($root.'/bot/tournaments/TournamentRewardProjectionService.php'),
 'settlement'=>file_get_contents($root.'/bot/tournaments/TournamentSettlementService.php'),
 'profile_api'=>file_get_contents($root.'/bot/profile-v2.php'),
 'archive'=>file_get_contents($root.'/bot/ratings/RatingArchiveService.php'),
 'bootstrap'=>file_get_contents($root.'/bot/core/bootstrap.php'),
 'profile_js'=>file_get_contents($root.'/app/assets/js/screens/profile-screen-v110.js'),
 'api_js'=>file_get_contents($root.'/app/assets/js/api/client.js'),
 'shell_js'=>file_get_contents($root.'/app/assets/js/main-v110-handoff-shell.js'),
 'tournaments_js'=>file_get_contents($root.'/app/assets/js/screens/tournaments-screen-v1.js'),
 'ui'=>file_get_contents($root.'/app/assets/js/ui.js'),
 'game_js'=>file_get_contents($root.'/app/assets/js/screens/game-screen-v102.js'),
 'response'=>file_get_contents($root.'/bot/helpers/response.php'),
 'index'=>file_get_contents($root.'/app/index.html'),
 'state'=>file_get_contents($root.'/app/assets/js/state.js'),
 'css'=>file_get_contents($root.'/app/assets/css/components/mgw-tournament-honors.css'),
 'main_css'=>file_get_contents($root.'/app/assets/css/main.css'),
 'manifest'=>file_get_contents($root.'/app/runtime/client/version-manifest.php'),
];
foreach($files as $name=>$value){
 if(!is_string($value)) throw new RuntimeException('Missing MVP-21.9 product source: '.$name);
}

$assertions=0;
$assert=static function(bool $condition,string $message)use(&$assertions):void{
 $assertions++;
 if(!$condition) throw new RuntimeException($message);
};

$assert(str_contains($files['projector'],'final class TournamentRewardProjectionService'),'21.9 must have one explicit read-only projector.');
$assert(!str_contains($files['projector'],'INSERT INTO'),'Projector must never write rewards.');
$assert(!str_contains($files['projector'],'postAvailableDelta'),'Projector must never write ledger money.');
$assert(!str_contains($files['projector'],'consumeReservation'),'Projector must never consume reservations.');
$assert(str_contains($files['settlement'],'persistSettledResult'),'Existing settlement service must remain the reward writer.');
$assert(str_contains($files['settlement'],'mgw_tournament_reward_entitlements'),'Existing settlement owner must remain entitlement writer.');

$assert(str_contains($files['profile_api'],"'tournament_rewards'=>\$tournamentRewards"),'Profile API must expose durable tournament honors.');
$assert(str_contains($files['profile_api'],'new TournamentRewardProjectionService($database)'),'Profile API must use the read-only projector.');
$assert(str_contains($files['projector'],'public function prestigeSnapshot('),'Projector must expose a lightweight prestige-only snapshot.');
$assert(str_contains($files['projector'],"'partial'=>true"),'Lightweight prestige snapshot must be explicitly marked partial.');
$assert(str_contains($files['profile_api'],"tournament_prestige_only"),'Profile endpoint must expose the bounded lightweight prestige read.');
$assert(str_contains($files['profile_api'],'->prestigeSnapshot($mgwId)'),'Lightweight endpoint must avoid the full Profile projection path.');
$assert(str_contains($files['api_js'],'tournamentPrestige: () => requestTournamentPrestige()'),'Client API must expose the lightweight prestige request.');
$assert(str_contains($files['shell_js'],'const prestigePromise = api.tournamentPrestige().catch(() => null);'),'Boot must start prestige hydration immediately alongside its existing reads.');
$assert(str_contains($files['shell_js'],'state.profileTournamentRewards = prestigeResult.tournament_rewards;'),'Boot must publish prestige before shared chrome renders.');
$assert(str_contains($files['archive'],'->publicArchive()'),'Rating archive overview must project settled official tournaments.');
$assert(str_contains($files['archive'],"['available'=>false,'entries'=>[],'hall_of_fame'=>[]]"),'Legacy archive-only tests must retain a no-tournament fallback.');
$assert(str_contains($files['bootstrap'],'TournamentRewardProjectionService.php'),'Bootstrap must load the projector.');

$assert(str_contains($files['state'],'profileTournamentRewards'),'Client state must retain Profile tournament rewards.');
$assert(str_contains($files['profile_js'],'renderTournamentPrestigeSummary(tournamentRewards)'),'Profile must place a compact tournament status directly in the main profile flow.');
$assert(str_contains($files['profile_js'],'data-open-tournament-showcase'),'Compact tournament status must open the dedicated prestige showcase.');
$assert(str_contains($files['profile_js'],'tournamentShowcaseMarkup(snapshot)'),'Profile must own one dedicated tournament showcase renderer.');
$assert(str_contains($files['profile_js'],'data-tournament-showcase-scroll'),'Tournament Showcase must have a dedicated bounded scroll owner.');
$assert(str_contains($files['profile_js'],'tournamentPrestigeIconSvg'),'Tournament rewards must use the shared SVG icon language instead of platform emoji.');
$assert(str_contains($files['profile_js'],"TOURNAMENT_HIDDEN_REWARD_CODES = new Set(['champion_cosmetics'])"),'Undefined champion cosmetics entitlement must stay durable but hidden until real inventory items exist.');
$assert(str_contains($files['profile_js'],'Golden Ticket'),'Profile must visibly expose Golden Ticket.');
$assert(str_contains($files['profile_js'],'Допуск к Большому турниру'),'Golden Ticket must communicate Big Tournament access.');
$assert(str_contains($files['profile_js'],'не продаётся и не передаётся'),'Golden Ticket must not be presented as inventory commerce.');
$assert(str_contains($files['profile_js'],'champion_crown'),'Champion crown must remain a persistent Profile projection.');
$assert(str_contains($files['profile_js'],'silver_frame'),'Silver frame must remain a persistent Profile projection.');
$assert(str_contains($files['profile_js'],'bronze_mark'),'Bronze mark must remain a persistent Profile projection.');
$assert(str_contains($files['profile_js'],'Активна до'),'Temporary tournament styling must visibly expose expiry.');
$assert(str_contains($files['profile_js'],'has-tournament-crown'),'Active crown must project onto the Profile identity.');
$assert(str_contains($files['profile_js'],'has-tournament-silver-frame'),'Active silver frame must project onto the Profile identity.');
$assert(str_contains($files['profile_js'],'has-tournament-bronze-mark'),'Active bronze mark must project onto the Profile identity.');
$assert(str_contains($files['css'],'.profile-v2-tournament-status'),'Tournament prestige must be visible near the top of Profile without scrolling through the collection.');
$assert(str_contains($files['css'],'.profile-v2-tournament-showcase'),'Full prestige detail must live in a dedicated showcase.');
$assert(str_contains($files['css'],'.profile-v2-tournament-showcase-scroll') && str_contains($files['css'],'overflow-y:auto'),'Tournament Showcase scroll owner must actually scroll vertically.');
$assert(str_contains($files['css'],'grid-auto-columns:40px'),'Compact prestige metrics must share one normalized desktop geometry.');
$assert(str_contains($files['css'],'width:11px') && str_contains($files['css'],'.mgw-game-prestige-crown'),'Live champion crown must use the final compact geometry.');
$assert(str_contains($files['css'],'.profile-v2-tournament-crown'),'Active champion crown must have a visual Profile owner.');
$assert(str_contains($files['css'],'.mgw-tournament-icon'),'Tournament honors must share one SVG geometry owner.');
$assert(str_contains($files['profile_js'],'winner_badge'),'Permanent winner badge must be visible.');
$assert(str_contains($files['profile_js'],'cup_gold') && str_contains($files['profile_js'],'cup_silver') && str_contains($files['profile_js'],'cup_bronze'),'All permanent tournament cups must remain supported.');
$assert(str_contains($files['profile_js'],'profile-v2-tournament-history-disclosure'),'Tournament history must collapse instead of lengthening every prestige profile.');
$assert(str_contains($files['profile_js'],"document.dispatchEvent(new CustomEvent('mgw:tournament-hall-of-fame-open'))"),'Profile Hall of Fame action must navigate to the real Arena Hall of Fame.');
$assert(str_contains($files['ui'],'has-tournament-prestige-crown'),'Shared app chrome must visibly project an active champion crown.');
$assert(str_contains($files['game_js'],'gameTournamentCrownSvg'),'Live match participant cards must render champion prestige.');
$assert(str_contains($files['game_js'],'mgw-game-player-mark-stack'),'Live crown must share a dedicated X/O alignment stack instead of consuming nickname width.');
$assert(str_contains($files['css'],'bottom:calc(100% + 1px)') && str_contains($files['css'],'transform:translateX(-50%)'),'Live crown must be centered directly above the player mark axis.');
$assert(str_contains($files['game_js'],"player?.tournament_prestige?.champion_crown === true"),'Live match crown must derive from authoritative public player prestige.');
$assert(str_contains($files['response'],'mgw_tournament_reward_entitlements'),'Public game identity must read the durable champion crown entitlement.');
$assert(str_contains($files['response'],"\$player['tournament_prestige'] = \$tournamentPrestige"),'Public game identity must expose prestige separately from game mechanics.');
$assert(str_contains($files['index'],'mvp21_prestige=showcase-v1') && str_contains($files['index'],'mvp21_prestige_corrective=visual-v2'),'Index must bust the prestige corrective stylesheet cache.');

$assert(str_contains($files['tournaments_js'],'tournament-archive-v1'),'Arena must publish tournament archive surface identity.');
$assert(str_contains($files['tournaments_js'],'loadTournamentArchiveOverview'),'Tournament archive tab must have a live data owner.');
$assert(str_contains($files['tournaments_js'],'Зал славы турниров'),'Tournament Hall of Fame must have a public Arena surface.');
$assert(str_contains($files['tournaments_js'],"document.addEventListener('mgw:tournament-hall-of-fame-open'"),'Arena must accept direct Hall of Fame navigation from Profile prestige.');
$assert(str_contains($files['tournaments_js'],'tournamentHallTrophySvg'),'Hall of Fame trophy count must use the shared non-emoji visual language.');
$assert(str_contains($files['tournaments_js'],'tournaments-v2-tournament-archive-podium'),'Archive must persist podium representation.');
$assert(!str_contains($files['tournaments_js'],'20 участников'),'Golden Ticket UI must not promise a fixed 20-person Big Tournament.');

$assert(str_contains($files['css'],'.profile-v2-tournament-ticket'),'Golden Ticket must have dedicated presentation.');
$assert(str_contains($files['css'],'.profile-v2-tournament-reward'),'Tournament rewards must have isolated Profile styling.');
$assert(str_contains($files['css'],'.tournaments-v2-tournament-hof-card'),'Tournament Hall must have isolated Arena styling.');
$assert(str_contains($files['main_css'],'mgw-tournament-honors.css'),'Main CSS must import the 21.9 product stylesheet.');

$assert(str_contains($files['manifest'],'mvp21_9=product-projections-v1'),'Version manifest must preserve the product projection identity.');
$assert(str_contains($files['manifest'],'mvp21_prestige=showcase-v1'),'Version manifest must publish the prestige showcase identity.');
$assert(str_contains($files['manifest'],'mvp21_prestige=champion-crown-v1'),'Version manifest must publish the shared champion crown identity.');
$assert(str_contains($files['manifest'],'mvp21_prestige=hall-of-fame-link-v1'),'Version manifest must publish the Hall of Fame navigation identity.');
$assert(str_contains($files['manifest'],'mvp21_prestige=early-read-v2'),'Version manifest must publish the early prestige API identity.');
$assert(str_contains($files['manifest'],'mvp21_prestige=early-hydration-v2'),'Version manifest must publish the early boot hydration identity.');
$assert(str_contains($files['manifest'],'mvp21_prestige_corrective=visual-v2'),'Version manifest must publish the visual corrective identity.');
$assert(str_contains($files['manifest'],'mvp21_prestige_corrective=crown-size-v2'),'Version manifest must publish the compact live crown identity.');
$assert(str_contains($files['manifest'],'mvp21_prestige_final=profile-nav-v3'),'Version manifest must preserve the accepted final Profile navigation polish.');
$assert(str_contains($files['manifest'],'mvp21_profile_first_open=idle-convergence-v4'),'Version manifest must publish the first-open idle convergence corrective.');
$assert(str_contains($files['manifest'],'mvp21_prestige_final=mark-axis-v3'),'Version manifest must publish the final crown/mark alignment.');

$assert($assertions>=72,'MVP-21.9 product integration contract is too shallow: '.$assertions);
fwrite(STDOUT,"Mvp21_9TournamentProductProjectionContractTest: {$assertions} assertions passed\n");
