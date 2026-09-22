<?php
declare(strict_types=1);

$root=dirname(__DIR__);
require $root.'/database/DatabaseConnectionInterface.php';
require $root.'/database/PdoDatabaseConnection.php';
require $root.'/database/DatabaseMigrationInterface.php';
require $root.'/accounts/MgwIdGenerator.php';
require $root.'/ledger/LedgerIntegrity.php';
require $root.'/ledger/LedgerWriteService.php';
require $root.'/tournaments/TournamentRegistrationService.php';
require $root.'/tournaments/TournamentMatchReadinessService.php';
require $root.'/tournaments/TournamentRoundProgressionService.php';
require $root.'/tournaments/TournamentPrizeReviewService.php';
require $root.'/tournaments/TournamentSettlementService.php';

$mysqlHost=trim((string)getenv('MGW_TEST_MYSQL_HOST'));
$isMysql=$mysqlHost!=='';
if($isMysql){
    if(!extension_loaded('pdo_mysql')) throw new RuntimeException('MVP-21.10 test requires pdo_mysql in MySQL mode.');
    $port=trim((string)getenv('MGW_TEST_MYSQL_PORT'))?:'3306';
    $name=trim((string)getenv('MGW_TEST_MYSQL_DATABASE'))?:'mgw_test';
    $user=trim((string)getenv('MGW_TEST_MYSQL_USER'))?:'root';
    $pass=(string)getenv('MGW_TEST_MYSQL_PASSWORD');
    $pdo=new PDO('mysql:host='.$mysqlHost.';port='.$port.';dbname='.$name.';charset=utf8mb4',$user,$pass,[
        PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_EMULATE_PREPARES=>false,
    ]);
}else{
    if(!extension_loaded('pdo_sqlite')) throw new RuntimeException('MVP-21.10 test requires pdo_sqlite.');
    $pdo=new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
    $pdo->exec('PRAGMA foreign_keys = ON');
}
$db=new PdoDatabaseConnection($pdo);

if($isMysql){
    $db->execute('CREATE TABLE mgw_users (
        mgw_id VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY,
        nickname VARCHAR(160) NULL,display_name VARCHAR(160) NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    $db->execute('CREATE TABLE mgw_account_ownership (
        account_ref VARCHAR(255) COLLATE utf8mb4_bin NOT NULL PRIMARY KEY,
        mgw_id VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        legacy_user_id VARCHAR(191) COLLATE utf8mb4_bin NOT NULL,
        ownership_status VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        source_type VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        source_ref VARCHAR(191) COLLATE utf8mb4_bin NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
}else{
    $db->execute('CREATE TABLE mgw_users (
        mgw_id TEXT PRIMARY KEY,nickname TEXT NULL,display_name TEXT NULL
    )');
    $db->execute('CREATE TABLE mgw_account_ownership (
        account_ref TEXT PRIMARY KEY,mgw_id TEXT NOT NULL,legacy_user_id TEXT NOT NULL,
        ownership_status TEXT NOT NULL,source_type TEXT NOT NULL,source_ref TEXT NOT NULL
    )');
}

(require $root.'/database/migrations/20260717_0005_create_balances_ledger_reservations.php')->up($db);
(require $root.'/database/migrations/20260920_0049_create_official_tournaments.php')->up($db);
(require $root.'/database/migrations/20260921_0054_create_tournament_match_readiness.php')->up($db);
(require $root.'/database/migrations/20260921_0055_add_tournament_round_progression.php')->up($db);
(require $root.'/database/migrations/20260922_0057_create_tournament_results_rewards.php')->up($db);
(require $root.'/database/migrations/20260922_0060_create_tournament_prize_review.php')->up($db);

$assertions=0;
$assertSame=static function(mixed $expected,mixed $actual,string $message)use(&$assertions):void{
    $assertions++;
    if($expected!==$actual){
        throw new RuntimeException($message.': expected '.var_export($expected,true).', got '.var_export($actual,true));
    }
};
$assertTrue=static function(bool $condition,string $message)use(&$assertions):void{
    $assertions++;
    if(!$condition) throw new RuntimeException($message);
};

$ledger=new LedgerWriteService($db);
$players=[];
for($i=1;$i<=8;$i++){
    $mgw='MGW-'.str_repeat((string)$i,16);
    $legacy='mvp2110-player-'.$i;
    $account='mgw:'.$mgw;
    $players[$i]=['mgw'=>$mgw,'legacy'=>$legacy,'account'=>$account];
    $db->execute('INSERT INTO mgw_users (mgw_id,nickname,display_name) VALUES (:m,:n,:d)',[
        'm'=>$mgw,'n'=>'Review P'.$i,'d'=>'Review P'.$i,
    ]);
    $db->execute('INSERT INTO mgw_account_ownership (
        account_ref,mgw_id,legacy_user_id,ownership_status,source_type,source_ref
    ) VALUES (:a,:m,:l,:s,:st,:sr)',[
        'a'=>$account,'m'=>$mgw,'l'=>$legacy,'s'=>'active',
        'st'=>'runtime_identity','sr'=>'telegram:'.$legacy,
    ]);
    $ledger->postAvailableDelta([
        'operation_key'=>'mvp2110-seed-'.$i,
        'account_ref'=>$account,'mgw_id'=>$mgw,'legacy_user_id'=>$legacy,
        'asset_code'=>TournamentRegistrationService::ENTRY_ASSET,
        'available_delta'=>100000,'category'=>'test_seed','source_type'=>'test','source_ref'=>'mvp21.10',
        'occurred_at_utc'=>'2026-09-22 10:00:00.000000',
    ]);
}

$seedTournament=static function(
    string $tournamentId,
    string $activeSlot,
    string $completedAt,
    array $players,
    LedgerWriteService $ledger,
    PdoDatabaseConnection $db
):void{
    $snapshot=TournamentRegistrationService::canonicalRewardSnapshot();
    $db->execute(
        'INSERT INTO mgw_tournaments (
            tournament_id,active_slot,title,game_type,capacity,entry_fee_amount,entry_asset_code,
            reward_snapshot_json,tournament_state,created_by_ref,opened_by_ref,
            created_at_utc,registration_opened_at_utc,updated_at_utc
         ) VALUES (
            :id,:slot,:title,:game,8,:fee,:asset,:rewards,:state,:created_by,:opened_by,
            :created,:opened,:updated
         )',
        [
            'id'=>$tournamentId,'slot'=>$activeSlot===''?null:$activeSlot,
            'title'=>'Prize review '.$tournamentId,'game'=>'tictactoe',
            'fee'=>TournamentRegistrationService::ENTRY_FEE,'asset'=>TournamentRegistrationService::ENTRY_ASSET,
            'rewards'=>json_encode($snapshot,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),
            'state'=>TournamentRegistrationService::STATE_SCHEDULED,'created_by'=>'test','opened_by'=>'test',
            'created'=>'2026-09-22 10:00:00.000000','opened'=>'2026-09-22 10:00:00.000000',
            'updated'=>'2026-09-22 10:00:00.000000',
        ]
    );

    foreach($players as $i=>$player){
        $reservation=$ledger->createReservation([
            'operation_key'=>'mvp2110-register:'.$tournamentId.':'.$i,
            'account_ref'=>$player['account'],'mgw_id'=>$player['mgw'],'legacy_user_id'=>$player['legacy'],
            'asset_code'=>TournamentRegistrationService::ENTRY_ASSET,
            'amount'=>TournamentRegistrationService::ENTRY_FEE,
            'source_type'=>'official_tournament','source_ref'=>$tournamentId,
            'metadata'=>['tournament_id'=>$tournamentId],
            'occurred_at_utc'=>'2026-09-22 10:01:00.000000',
        ]);
        $db->execute(
            'INSERT INTO mgw_tournament_registrations (
                registration_id,tournament_id,mgw_id,account_ref,attempt_no,registration_state,reservation_id,
                registered_at_utc,withdrawn_at_utc,updated_at_utc
             ) VALUES (:r,:t,:m,:a,1,:s,:res,:registered_at,NULL,:updated_at)',
            [
                'r'=>'reg-'.$tournamentId.'-'.$i,'t'=>$tournamentId,'m'=>$player['mgw'],'a'=>$player['account'],
                's'=>TournamentRegistrationService::REGISTRATION_REGISTERED,'res'=>$reservation['reservation_id'],
                'registered_at'=>'2026-09-22 10:01:00.000000','updated_at'=>'2026-09-22 10:01:00.000000',
            ]
        );
    }

    $base=[
        'opened'=>'2026-09-22 11:00:00.000000',
        'deadline'=>'2026-09-22 11:02:00.000000',
        'ready'=>'2026-09-22 11:00:10.000000',
        'created'=>'2026-09-22 11:00:00.000000',
        'updated'=>$completedAt,
    ];
    $db->execute(
        'INSERT INTO mgw_tournament_round_matches (
            tournament_id,round_no,pair_no,player_a_mgw_id,player_b_mgw_id,
            readiness_opened_at_utc,readiness_deadline_at_utc,
            player_a_ready_at_utc,player_b_ready_at_utc,launch_state,game_id,
            created_at_utc,updated_at_utc,attempt_no,wait_kind,match_kind,
            winner_mgw_id,loser_mgw_id,result_reason,completed_at_utc
         ) VALUES (
            :t,3,1,:a,:b,:opened,:deadline,:ready_a,:ready_b,:launch,:game,
            :created,:updated,1,:wait,:kind,:winner,:loser,:reason,:completed
         )',
        [
            't'=>$tournamentId,'a'=>$players[1]['mgw'],'b'=>$players[2]['mgw'],
            'opened'=>$base['opened'],'deadline'=>$base['deadline'],'ready_a'=>$base['ready'],'ready_b'=>$base['ready'],
            'launch'=>TournamentRoundProgressionService::STATE_COMPLETED,'game'=>'game-'.$tournamentId.'-final',
            'created'=>$base['created'],'updated'=>$base['updated'],'wait'=>TournamentRoundProgressionService::WAIT_ROUND_BREAK,
            'kind'=>TournamentRoundProgressionService::MATCH_FINAL,'winner'=>$players[1]['mgw'],'loser'=>$players[2]['mgw'],
            'reason'=>'normal_win','completed'=>$completedAt,
        ]
    );
    $db->execute(
        'INSERT INTO mgw_tournament_round_matches (
            tournament_id,round_no,pair_no,player_a_mgw_id,player_b_mgw_id,
            readiness_opened_at_utc,readiness_deadline_at_utc,
            player_a_ready_at_utc,player_b_ready_at_utc,launch_state,game_id,
            created_at_utc,updated_at_utc,attempt_no,wait_kind,match_kind,
            winner_mgw_id,loser_mgw_id,result_reason,completed_at_utc
         ) VALUES (
            :t,3,2,:a,:b,:opened,:deadline,:ready_a,:ready_b,:launch,:game,
            :created,:updated,1,:wait,:kind,:winner,:loser,:reason,:completed
         )',
        [
            't'=>$tournamentId,'a'=>$players[3]['mgw'],'b'=>$players[4]['mgw'],
            'opened'=>$base['opened'],'deadline'=>$base['deadline'],'ready_a'=>$base['ready'],'ready_b'=>$base['ready'],
            'launch'=>TournamentRoundProgressionService::STATE_COMPLETED,'game'=>'game-'.$tournamentId.'-third',
            'created'=>$base['created'],'updated'=>$base['updated'],'wait'=>TournamentRoundProgressionService::WAIT_ROUND_BREAK,
            'kind'=>TournamentRoundProgressionService::MATCH_THIRD_PLACE,'winner'=>$players[3]['mgw'],'loser'=>$players[4]['mgw'],
            'reason'=>'normal_win','completed'=>$completedAt,
        ]
    );
};

$review=new TournamentPrizeReviewService($db);
$settlement=new TournamentSettlementService($db,$ledger,$review);

// No serious signal: top-3 is a review candidate surface, not an automatic hold.
$seedTournament('tour-review-release',TournamentRegistrationService::ACTIVE_SLOT,'2026-09-22 12:00:00.000000',$players,$ledger,$db);
$baselineDecision=$review->settlementDecision('tour-review-release',[
    $players[1]['mgw']=>1,$players[2]['mgw']=>2,$players[3]['mgw']=>3,$players[4]['mgw']=>4,
]);
$assertSame(false,$baselineDecision['hold'],'Top-3 alone must never create a provisional hold without a serious signal.');

// A serious signal tied to the finalist's flagged tournament match holds only
// effective positions 2-4. Champion and non-prize participants settle normally.
$signal=$review->flagSeriousSignal(
    'tour-review-release',$players[2]['mgw'],'match_manipulation','Suspicious final sequence.',
    'admin:test','game-tour-review-release-final',
    new DateTimeImmutable('2026-09-22T11:59:00Z')
);
$assertSame(TournamentPrizeReviewService::STATE_PENDING,$signal['review_state'],'Serious signal must open a pending review.');
$signalReplay=$review->flagSeriousSignal(
    'tour-review-release',$players[2]['mgw'],'match_manipulation','Suspicious final sequence.',
    'admin:test','game-tour-review-release-final',
    new DateTimeImmutable('2026-09-22T11:59:01Z')
);
$assertSame(TournamentPrizeReviewService::STATE_PENDING,$signalReplay['review_state'],'Exact signal retry must stay pending.');
$assertSame(1,(int)$db->fetchValue(
    "SELECT COUNT(*) FROM mgw_tournament_prize_review_audit
     WHERE tournament_id='tour-review-release' AND action_code='serious_signal'"
),'Exact serious-signal retry must not duplicate durable audit.');

$held=$settlement->settleIfComplete('tour-review-release');
$assertSame('review_hold',$held['status'],'Serious signal on place 2 must hold the affected prize path.');
$assertSame(false,$held['settlement_complete'],'Held prize path must keep terminal settlement open.');
$assertSame(5,$held['settled_count'],'Champion plus four non-prize participants must settle while places 2-4 remain held.');
$assertSame(
    [$players[2]['mgw'],$players[3]['mgw'],$players[4]['mgw']],
    $held['review']['held_mgw_ids'],
    'Only effective positions 2-4 must be held.'
);
$assertSame(0,(int)$db->fetchValue("SELECT COUNT(*) FROM mgw_reservations WHERE status='active'"),'Entry reservations must still settle while prize payouts are held.');
$assertSame(250000,(int)$ledger->getBalance($players[1]['account'],TournamentRegistrationService::ENTRY_ASSET)['available_amount'],'Unaffected champion must receive the canonical payout.');
$assertSame(50000,(int)$ledger->getBalance($players[2]['account'],TournamentRegistrationService::ENTRY_ASSET)['available_amount'],'Held runner-up must not receive payout before review.');
$assertSame(0,(int)$db->fetchValue(
    'SELECT COUNT(*) FROM mgw_tournament_results WHERE tournament_id=:t AND mgw_id=:m',
    ['t'=>'tour-review-release','m'=>$players[2]['mgw']]
),'Held runner-up must not get a premature immutable result.');

$terminalHeld=$settlement->terminalSnapshotForParticipant('tour-review-release',$players[2]['mgw']);
$assertSame('review_hold',$terminalHeld['settlement_state'],'Participant terminal snapshot must expose review hold.');
$assertSame(true,$terminalHeld['prize_review']['self_held'],'Affected finalist must see that own prize path is held.');

$released=$review->resolve(
    'tour-review-release',$players[2]['mgw'],TournamentPrizeReviewService::DECISION_RELEASE,
    'Manual replay review cleared the result.','admin:test',
    new DateTimeImmutable('2026-09-22T12:05:00Z')
);
$assertSame(TournamentPrizeReviewService::STATE_RELEASED,$released['review_state'],'Admin release must close the pending review.');
$settled=$settlement->settleIfComplete('tour-review-release');
$assertSame('settled',$settled['status'],'Released prize path must settle through the existing settlement owner.');
$assertSame(true,$settled['settlement_complete'],'Released tournament must fully settle.');
$assertSame(130000,(int)$ledger->getBalance($players[2]['account'],TournamentRegistrationService::ENTRY_ASSET)['available_amount'],'Released runner-up must receive 80k total payout.');
$assertSame(100000,(int)$ledger->getBalance($players[3]['account'],TournamentRegistrationService::ENTRY_ASSET)['available_amount'],'Released third place must receive 50k return.');
$ledgerCountAfterRelease=(int)$db->fetchValue('SELECT COUNT(*) FROM mgw_ledger_entries');
$settlement->settleIfComplete('tour-review-release');
$assertSame($ledgerCountAfterRelease,(int)$db->fetchValue('SELECT COUNT(*) FROM mgw_ledger_entries'),'Settlement replay after review release must not duplicate ledger entries.');

// Second tournament: same place-2 serious signal, but Admin disqualifies.
// Place 1 remains; canonical places 3 and 4 shift to effective 2 and 3.
$seedTournament('tour-review-dq','', '2026-09-23 12:00:00.000000',$players,$ledger,$db);
$review->flagSeriousSignal(
    'tour-review-dq',$players[2]['mgw'],'fraud','Confirmed account manipulation signal.',
    'admin:test','game-tour-review-dq-final',
    new DateTimeImmutable('2026-09-23T11:59:00Z')
);
$holdDq=$settlement->settleIfComplete('tour-review-dq');
$assertSame('review_hold',$holdDq['status'],'Second serious signal must hold before disqualification decision.');

$dq=$review->resolve(
    'tour-review-dq',$players[2]['mgw'],TournamentPrizeReviewService::DECISION_DISQUALIFY,
    'Evidence confirmed manipulation.','admin:test',
    new DateTimeImmutable('2026-09-23T12:05:00Z')
);
$assertSame(TournamentPrizeReviewService::STATE_DISQUALIFIED,$dq['review_state'],'Admin must be able to persist a disqualification.');
$settledDq=$settlement->settleIfComplete('tour-review-dq');
$assertSame('settled',$settledDq['status'],'Resolved disqualification must unblock canonical settlement.');

$dqResult=$db->fetchAll(
    'SELECT placement,result_code,payout_amount,reward_eligible
     FROM mgw_tournament_results WHERE tournament_id=:t AND mgw_id=:m',
    ['t'=>'tour-review-dq','m'=>$players[2]['mgw']]
)[0];
$assertSame(null,$dqResult['placement'],'Disqualified runner-up must lose competitive placement.');
$assertSame(TournamentSettlementService::RESULT_DISQUALIFIED,(string)$dqResult['result_code'],'Disqualification must remain durable in final result.');
$assertSame(0,(int)$dqResult['payout_amount'],'Disqualified player must receive no prize payout.');
$assertSame(0,(int)$dqResult['reward_eligible'],'Disqualified player must receive no competitive entitlement.');

$shiftedSecond=$db->fetchAll(
    'SELECT placement,payout_amount FROM mgw_tournament_results WHERE tournament_id=:t AND mgw_id=:m',
    ['t'=>'tour-review-dq','m'=>$players[3]['mgw']]
)[0];
$shiftedThird=$db->fetchAll(
    'SELECT placement,payout_amount FROM mgw_tournament_results WHERE tournament_id=:t AND mgw_id=:m',
    ['t'=>'tour-review-dq','m'=>$players[4]['mgw']]
)[0];
$assertSame(2,(int)$shiftedSecond['placement'],'Original third place must shift to second.');
$assertSame(80000,(int)$shiftedSecond['payout_amount'],'Shifted second place must receive canonical 80k total.');
$assertSame(3,(int)$shiftedThird['placement'],'Original fourth place must shift to third.');
$assertSame(50000,(int)$shiftedThird['payout_amount'],'Shifted third place must receive canonical 50k return.');
$assertSame(0,(int)$db->fetchValue(
    'SELECT COUNT(*) FROM mgw_tournament_reward_entitlements WHERE tournament_id=:t AND mgw_id=:m',
    ['t'=>'tour-review-dq','m'=>$players[2]['mgw']]
),'Disqualified player must receive no reward entitlements.');
$assertSame(3,(int)$db->fetchValue(
    'SELECT COUNT(*) FROM mgw_tournament_reward_entitlements WHERE tournament_id=:t AND mgw_id=:m',
    ['t'=>'tour-review-dq','m'=>$players[3]['mgw']]
),'Shifted runner-up must receive silver-frame/finalist/silver-cup entitlements.');
$assertSame(3,(int)$db->fetchValue(
    'SELECT COUNT(*) FROM mgw_tournament_reward_entitlements WHERE tournament_id=:t AND mgw_id=:m',
    ['t'=>'tour-review-dq','m'=>$players[4]['mgw']]
),'Shifted third place must receive bronze-mark/result/bronze-cup entitlements.');

$assertSame(2,(int)$db->fetchValue(
    "SELECT COUNT(*) FROM mgw_tournament_prize_review_audit WHERE tournament_id='tour-review-dq'"
),'Serious signal + disqualification must leave two durable audit events.');

$lateSignalRejected=false;
try{
    $review->flagSeriousSignal(
        'tour-review-dq',$players[1]['mgw'],'manual_review','Too late.',
        'admin:test',null,new DateTimeImmutable('2026-09-23T12:10:00Z')
    );
}catch(RuntimeException){
    $lateSignalRejected=true;
}
$assertTrue($lateSignalRejected,'New serious signal after settlement starts must fail closed instead of requiring prize clawback.');

$source=file_get_contents($root.'/tournaments/TournamentPrizeReviewService.php');
$assertTrue(is_string($source),'Prize review source must be readable.');
$assertTrue(!str_contains((string)$source,'postAvailableDelta'),'Prize review service must never become a payout owner.');
$assertTrue(!str_contains((string)$source,'consumeReservation'),'Prize review service must never consume entry reservations.');
$assertTrue(!str_contains((string)$source,'mgw_tournament_reward_entitlements'),'Prize review service must never grant entitlements.');
$assertTrue(!str_contains((string)$source,'mgw_tournament_golden_tickets'),'Prize review service must never grant Golden Tickets.');

if($assertions<40) throw new RuntimeException('MVP-21.10 prize review test is too shallow: '.$assertions);
fwrite(STDOUT,"Mvp21_10TournamentPrizeReviewTest: {$assertions} assertions passed\n");
