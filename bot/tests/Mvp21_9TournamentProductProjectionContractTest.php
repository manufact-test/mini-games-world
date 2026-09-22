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
 'tournaments_js'=>file_get_contents($root.'/app/assets/js/screens/tournaments-screen-v1.js'),
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

$assert(str_contains($files['profile_api'],"'tournament_rewards'=>$tournamentRewards"),'Profile API must expose durable tournament honors.');
$assert(str_contains($files['profile_api'],'new TournamentRewardProjectionService($database)'),'Profile API must use the read-only projector.');
$assert(str_contains($files['archive'],'->publicArchive()'),'Rating archive overview must project settled official tournaments.');
$assert(str_contains($files['archive'],"['available'=>false,'entries'=>[],'hall_of_fame'=>[]]"),'Legacy archive-only tests must retain a no-tournament fallback.');
$assert(str_contains($files['bootstrap'],'TournamentRewardProjectionService.php'),'Bootstrap must load the projector.');

$assert(str_contains($files['state'],'profileTournamentRewards'),'Client state must retain Profile tournament rewards.');
$assert(str_contains($files['profile_js'],'renderTournamentHonorsSection(tournamentRewards)'),'Profile must render tournament honors.');
$assert(str_contains($files['profile_js'],'Golden Ticket'),'Profile must visibly expose Golden Ticket.');
$assert(str_contains($files['profile_js'],'Действителен до Большого турнира'),'Golden Ticket copy must preserve future Big Tournament semantics.');
$assert(str_contains($files['profile_js'],'не продаётся и не передаётся'),'Golden Ticket must not be presented as inventory commerce.');
$assert(str_contains($files['profile_js'],'champion_crown'),'Champion crown must be a persistent Profile projection.');
$assert(str_contains($files['profile_js'],'silver_frame'),'Silver frame must be a persistent Profile projection.');
$assert(str_contains($files['profile_js'],'bronze_mark'),'Bronze mark must be a persistent Profile projection.');
$assert(str_contains($files['profile_js'],'Активна до'),'Temporary tournament styling must visibly expose expiry.');
$assert(str_contains($files['profile_js'],'winner_badge'),'Permanent winner badge must be visible.');
$assert(str_contains($files['profile_js'],'champion_cosmetics'),'Champion set entitlement must be visible without inventing a sellable SKU.');
$assert(str_contains($files['profile_js'],'cup_gold') && str_contains($files['profile_js'],'cup_silver') && str_contains($files['profile_js'],'cup_bronze'),'All permanent tournament cups must be visible.');
$assert(str_contains($files['profile_js'],'История турниров'),'Profile must expose tournament date/place history.');

$assert(str_contains($files['tournaments_js'],'tournament-archive-v1'),'Arena must publish tournament archive surface identity.');
$assert(str_contains($files['tournaments_js'],'loadTournamentArchiveOverview'),'Tournament archive tab must have a live data owner.');
$assert(str_contains($files['tournaments_js'],'Зал славы турниров'),'Tournament Hall of Fame must have a public Arena surface.');
$assert(str_contains($files['tournaments_js'],'tournaments-v2-tournament-archive-podium'),'Archive must persist podium representation.');
$assert(!str_contains($files['tournaments_js'],'20 участников'),'Golden Ticket UI must not promise a fixed 20-person Big Tournament.');

$assert(str_contains($files['css'],'.profile-v2-tournament-ticket'),'Golden Ticket must have dedicated presentation.');
$assert(str_contains($files['css'],'.profile-v2-tournament-reward'),'Tournament rewards must have isolated Profile styling.');
$assert(str_contains($files['css'],'.tournaments-v2-tournament-hof-card'),'Tournament Hall must have isolated Arena styling.');
$assert(str_contains($files['main_css'],'mgw-tournament-honors.css'),'Main CSS must import the 21.9 product stylesheet.');

$assert(str_contains($files['manifest'],'mvp21_9=product-projections-v1'),'Version manifest must publish the fresh product projection identity.');

$assert($assertions>=34,'MVP-21.9 product integration contract is too shallow: '.$assertions);
fwrite(STDOUT,"Mvp21_9TournamentProductProjectionContractTest: {$assertions} assertions passed\n");
