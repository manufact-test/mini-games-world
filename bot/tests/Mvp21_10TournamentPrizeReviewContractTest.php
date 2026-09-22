<?php
declare(strict_types=1);

$root=dirname(__DIR__,2);
$files=[
    'migration'=>file_get_contents($root.'/bot/database/migrations/20260922_0060_create_tournament_prize_review.php'),
    'review'=>file_get_contents($root.'/bot/tournaments/TournamentPrizeReviewService.php'),
    'settlement'=>file_get_contents($root.'/bot/tournaments/TournamentSettlementService.php'),
    'admin_api'=>file_get_contents($root.'/bot/admin-tournaments.php'),
    'admin_html'=>file_get_contents($root.'/app/admin.php'),
    'admin_js'=>file_get_contents($root.'/app/assets/js/admin-tournaments.js'),
    'terminal_js'=>file_get_contents($root.'/app/assets/js/screens/tournaments-screen-v1.js'),
    'bootstrap'=>file_get_contents($root.'/bot/core/bootstrap.php'),
];
foreach($files as $name=>$source){
    if(!is_string($source)) throw new RuntimeException('Missing MVP-21.10 source: '.$name);
}

$assertions=0;
$assert=static function(bool $condition,string $message)use(&$assertions):void{
    $assertions++;
    if(!$condition) throw new RuntimeException($message);
};

$assert(str_contains($files['migration'],'mgw_tournament_prize_reviews'),'Prize review state must be durable.');
$assert(str_contains($files['migration'],'mgw_tournament_prize_review_audit'),'Every signal/decision must have durable audit storage.');
$assert(str_contains($files['review'],"STATE_PENDING = 'pending'"),'Serious signals must enter provisional pending state.');
$assert(str_contains($files['review'],"STATE_RELEASED = 'released'"),'Admin review must support release.');
$assert(str_contains($files['review'],"STATE_DISQUALIFIED = 'disqualified'"),'Admin review must support disqualification.');
$assert(str_contains($files['review'],'Heavy tournament review is limited to top-3 or a participant from a flagged tournament match.'),'Heavy review scope must be top3 or explicitly flagged matches.');
$assert(str_contains($files['review'],'Serious prize-path signal must be registered before tournament settlement starts.'),'Late signals must fail closed instead of creating clawback logic.');
$assert(str_contains($files['review'],'hold_threshold_placement'),'Only the affected prize path must be held.');
$assert(str_contains($files['review'],'effective_placements'),'Disqualification must produce deterministic effective placement projection.');
$assert(!str_contains($files['review'],'postAvailableDelta'),'Review owner must never pay rewards.');
$assert(!str_contains($files['review'],'consumeReservation'),'Review owner must never settle entry reservations.');
$assert(!str_contains($files['review'],'INSERT INTO mgw_tournament_results'),'Review owner must never write final tournament results.');
$assert(!str_contains($files['review'],'mgw_tournament_reward_entitlements'),'Review owner must never grant entitlements.');
$assert(!str_contains($files['review'],'mgw_tournament_golden_tickets'),'Review owner must never grant Golden Tickets.');

$assert(str_contains($files['settlement'],'TournamentPrizeReviewService $prizeReview'),'Existing settlement owner must consume review state.');
$assert(str_contains($files['settlement'],"'status'=>$reviewHold ? 'review_hold' : 'settled'"),'Settlement must expose explicit review hold state.');
$assert(str_contains($files['settlement'],'if ($heldForReview)'),'Held path must stop before payout/result persistence.');
$assert(str_contains($files['settlement'],'RESULT_DISQUALIFIED'),'Settlement owner must persist reviewed disqualification.');
$assert(str_contains($files['settlement'],"operationKey($tournamentId, $mgwId, 'payout')"),'Reward release must preserve the existing exactly-once operation key.');
$assert(str_contains($files['settlement'],'prize_review_disqualified'),'Settlement ledger metadata must retain review decision context.');

$assert(str_contains($files['admin_api'],"$action === 'prize_review_flag'"),'Tournament Admin must be able to register a serious signal.');
$assert(str_contains($files['admin_api'],"$action === 'prize_review_release'"),'Tournament Admin must be able to release a held prize path.');
$assert(str_contains($files['admin_api'],"$action === 'prize_review_disqualify'"),'Tournament Admin must be able to disqualify after review.');
$assert(str_contains($files['admin_api'],'$settlement->settleIfComplete($tournamentId);'),'Admin decision must return to the canonical settlement owner.');
$assert(str_contains($files['admin_api'],'mgw_apply_tournament_settlement_runtime_balances'),'Admin release must converge durable payout into runtime balance.');
$assert(str_contains($files['admin_html'],'data-tournament-review-panel'),'Web Admin must expose prize review controls.');
$assert(str_contains($files['admin_html'],'Поставить призовой путь на проверку'),'Admin serious-signal action must be explicit.');
$assert(str_contains($files['admin_js'],'Разрешить выплату'),'Admin review queue must expose release action.');
$assert(str_contains($files['admin_js'],'Дисквалифицировать'),'Admin review queue must expose disqualification action.');
$assert(str_contains($files['admin_js'],'сдвинуты каноническим settlement owner'),'Admin copy must preserve single-writer placement semantics.');

$assert(str_contains($files['terminal_js'],"settlement_state || '') === 'review_hold'"),'Participant terminal UI must expose provisional review hold.');
$assert(str_contains($files['terminal_js'],'Ваша призовая ветка временно удержана'),'Affected player must see the hold state.');
$assert(str_contains($files['terminal_js'],"result_code || '') === 'disqualified'"),'Disqualification must be visible in terminal result.');
$assert(str_contains($files['bootstrap'],'TournamentPrizeReviewService.php'),'Runtime bootstrap must load prize review before settlement.');

$assert($assertions>=34,'MVP-21.10 integration contract is too shallow: '.$assertions);
fwrite(STDOUT,"Mvp21_10TournamentPrizeReviewContractTest: {$assertions} assertions passed\n");
