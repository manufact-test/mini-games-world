<?php
declare(strict_types=1);

$root=dirname(__DIR__);
require $root.'/database/DatabaseConnectionInterface.php';
require $root.'/database/PdoDatabaseConnection.php';
require $root.'/database/DatabaseMigrationInterface.php';
require $root.'/ledger/LedgerIntegrity.php';
require $root.'/ledger/LedgerWriteService.php';
require $root.'/tournaments/TournamentRegistrationService.php';
require $root.'/tournaments/TournamentMatchReadinessService.php';
require $root.'/tournaments/TournamentRoundProgressionService.php';
require $root.'/tournaments/TournamentSettlementService.php';
require $root.'/tournaments/TournamentCancellationService.php';

$mysqlHost=trim((string)getenv('MGW_TEST_MYSQL_HOST'));
$isMysql=$mysqlHost!=='';
if($isMysql){
    if(!extension_loaded('pdo_mysql')) throw new RuntimeException('MVP-21.8 test requires pdo_mysql in MySQL mode.');
    $pdo=new PDO(
        'mysql:host='.$mysqlHost.';port='.(trim((string)getenv('MGW_TEST_MYSQL_PORT'))?:'3306')
        .';dbname='.(trim((string)getenv('MGW_TEST_MYSQL_DATABASE'))?:'mgw_test').';charset=utf8mb4',
        trim((string)getenv('MGW_TEST_MYSQL_USER'))?:'root',
        (string)getenv('MGW_TEST_MYSQL_PASSWORD'),
        [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]
    );
}else{
    if(!extension_loaded('pdo_sqlite')) throw new RuntimeException('MVP-21.8 test requires pdo_sqlite.');
    $pdo=new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
    $pdo->exec('PRAGMA foreign_keys = ON');
}
$db=new PdoDatabaseConnection($pdo);

if($isMysql){
    $db->execute('CREATE TABLE mgw_users (
        mgw_id VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY,
        status VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT \'active\',
        nickname VARCHAR(160) NULL,display_name VARCHAR(160) NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
}else{
    $db->execute("CREATE TABLE mgw_users (
        mgw_id TEXT PRIMARY KEY,status TEXT NOT NULL DEFAULT 'active',
        nickname TEXT NULL,display_name TEXT NULL
    )");
}

(require $root.'/database/migrations/20260717_0005_create_balances_ledger_reservations.php')->up($db);
(require $root.'/database/migrations/20260920_0049_create_official_tournaments.php')->up($db);
(require $root.'/database/migrations/20260920_0050_add_tournament_rules_consent.php')->up($db);
(require $root.'/database/migrations/20260921_0052_add_tournament_schedule.php')->up($db);
(require $root.'/database/migrations/20260921_0054_create_tournament_match_readiness.php')->up($db);
(require $root.'/database/migrations/20260921_0055_add_tournament_round_progression.php')->up($db);
(require $root.'/database/migrations/20260922_0057_create_tournament_results_rewards.php')->up($db);
(require $root.'/database/migrations/20260922_0058_add_tournament_technical_outcomes.php')->up($db);
(require $root.'/database/migrations/20260922_0059_add_tournament_cancellation_emergency.php')->up($db);

$assertions=0;
$assertSame=static function(mixed $expected,mixed $actual,string $message)use(&$assertions):void{
    $assertions++;
    if($expected!==$actual) throw new RuntimeException($message.': expected '.var_export($expected,true).', got '.var_export($actual,true));
};
$assertTrue=static function(bool $condition,string $message)use(&$assertions):void{
    $assertions++;
    if(!$condition) throw new RuntimeException($message);
};
$assertThrows=static function(callable $fn,string $contains,string $message)use(&$assertions):void{
    $assertions++;
    try{$fn();}catch(Throwable $e){
        if($contains===''||str_contains($e->getMessage(),$contains)) return;
        throw new RuntimeException($message.': wrong exception '.$e->getMessage());
    }
    throw new RuntimeException($message.': expected exception');
};

$ledger=new LedgerWriteService($db);
$players=[];
for($i=1;$i<=8;$i++){
    $mgw='MGW-'.str_pad((string)$i,16,(string)$i);
    $legacy='cancel-player-'.$i;
    $account='mgw:'.$mgw;
    $players[$i]=['mgw'=>$mgw,'legacy'=>$legacy,'account'=>$account];
    $db->execute(
        'INSERT INTO mgw_users (mgw_id,status,nickname,display_name)
         VALUES (:mgw_id,:status,:nickname,:display_name)',
        ['mgw_id'=>$mgw,'status'=>'active','nickname'=>'Игрок '.$i,'display_name'=>'Игрок '.$i]
    );
    $ledger->postAvailableDelta([
        'operation_key'=>'mvp21-8-seed-'.$i,
        'account_ref'=>$account,'mgw_id'=>$mgw,'legacy_user_id'=>$legacy,
        'asset_code'=>TournamentRegistrationService::ENTRY_ASSET,
        'available_delta'=>100000,'category'=>'test_seed','source_type'=>'test','source_ref'=>'mvp21.8',
        'occurred_at_utc'=>'2026-09-22 20:00:00.000000',
    ]);
}

$seedTournament=static function(
    PdoDatabaseConnection $db,
    LedgerWriteService $ledger,
    array $players,
    string $tournamentId,
    bool $technicalCancelRequired = false,
    int $registeredCount = 8
):void{
    $snapshot=TournamentRegistrationService::canonicalRewardSnapshot();
    $db->execute(
        'INSERT INTO mgw_tournaments (
            tournament_id,active_slot,title,game_type,capacity,entry_fee_amount,entry_asset_code,
            reward_snapshot_json,tournament_state,created_by_ref,opened_by_ref,
            created_at_utc,registration_opened_at_utc,registration_closed_at_utc,
            registration_closed_reason,scheduled_start_at_utc,scheduled_by_ref,scheduled_at_utc,updated_at_utc
         ) VALUES (
            :id,:slot,:title,:game,8,:fee,:asset,:reward,:state,:created_by,:opened_by,
            :created,:opened,:closed,:closed_reason,:scheduled,:scheduled_by,:scheduled_at,:updated
         )',
        [
            'id'=>$tournamentId,'slot'=>TournamentRegistrationService::ACTIVE_SLOT,
            'title'=>'Cancel test '.$tournamentId,'game'=>'tictactoe',
            'fee'=>TournamentRegistrationService::ENTRY_FEE,'asset'=>TournamentRegistrationService::ENTRY_ASSET,
            'reward'=>json_encode($snapshot,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),
            'state'=>TournamentRegistrationService::STATE_SCHEDULED,
            'created_by'=>'test','opened_by'=>'test',
            'created'=>'2026-09-22 20:00:00.000000','opened'=>'2026-09-22 20:00:00.000000',
            'closed'=>'2026-09-22 20:02:00.000000','closed_reason'=>'full',
            'scheduled'=>'2026-09-22 21:00:00.000000','scheduled_by'=>'test',
            'scheduled_at'=>'2026-09-22 20:03:00.000000','updated'=>'2026-09-22 20:03:00.000000',
        ]
    );

    for($i=1;$i<=$registeredCount;$i++){
        $player=$players[$i];
        $reservation=$ledger->createReservation([
            'operation_key'=>'register-'.$tournamentId.'-'.$i,
            'account_ref'=>$player['account'],'mgw_id'=>$player['mgw'],'legacy_user_id'=>$player['legacy'],
            'asset_code'=>TournamentRegistrationService::ENTRY_ASSET,
            'amount'=>TournamentRegistrationService::ENTRY_FEE,
            'source_type'=>'official_tournament','source_ref'=>$tournamentId,
            'metadata'=>['tournament_id'=>$tournamentId],
            'occurred_at_utc'=>'2026-09-22 20:01:00.000000',
        ]);
        $db->execute(
            'INSERT INTO mgw_tournament_registrations (
                registration_id,tournament_id,mgw_id,account_ref,attempt_no,registration_state,reservation_id,
                registered_at_utc,withdrawn_at_utc,updated_at_utc
             ) VALUES (
                :registration_id,:tournament_id,:mgw_id,:account_ref,1,:registration_state,:reservation_id,
                :registered_at_utc,NULL,:updated_at_utc
             )',
            [
                'registration_id'=>'reg-'.$tournamentId.'-'.$i,
                'tournament_id'=>$tournamentId,'mgw_id'=>$player['mgw'],'account_ref'=>$player['account'],
                'registration_state'=>TournamentRegistrationService::REGISTRATION_REGISTERED,
                'reservation_id'=>$reservation['reservation_id'],
                'registered_at_utc'=>'2026-09-22 20:01:00.000000',
                'updated_at_utc'=>'2026-09-22 20:01:00.000000',
            ]
        );
    }

    if($registeredCount>=2){
        $launchState=$technicalCancelRequired
            ? TournamentRoundProgressionService::STATE_TECHNICAL_CANCEL_REQUIRED
            : TournamentRoundProgressionService::STATE_COMPLETED;
        $resultReason=$technicalCancelRequired ? 'technical_restart_exhausted' : 'normal_win';
        $completedAt=$technicalCancelRequired ? null : '2026-09-22 20:20:00.000000';
        $db->execute(
            'INSERT INTO mgw_tournament_round_matches (
                tournament_id,round_no,pair_no,player_a_mgw_id,player_b_mgw_id,
                readiness_opened_at_utc,readiness_deadline_at_utc,
                player_a_ready_at_utc,player_b_ready_at_utc,launch_state,game_id,
                created_at_utc,updated_at_utc,attempt_no,wait_kind,match_kind,
                winner_mgw_id,loser_mgw_id,result_reason,completed_at_utc
             ) VALUES (
                :tournament_id,1,1,:player_a,:player_b,:opened,:deadline,:a_ready,:b_ready,
                :launch_state,:game_id,:created,:updated,1,:wait_kind,:match_kind,
                :winner,:loser,:result_reason,:completed_at
             )',
            [
                'tournament_id'=>$tournamentId,'player_a'=>$players[1]['mgw'],'player_b'=>$players[2]['mgw'],
                'opened'=>'2026-09-22 20:10:00.000000','deadline'=>'2026-09-22 20:12:00.000000',
                'a_ready'=>'2026-09-22 20:10:10.000000','b_ready'=>'2026-09-22 20:10:11.000000',
                'launch_state'=>$launchState,'game_id'=>$technicalCancelRequired?null:'game-'.$tournamentId,
                'created'=>'2026-09-22 20:10:00.000000','updated'=>'2026-09-22 20:20:00.000000',
                'wait_kind'=>TournamentRoundProgressionService::WAIT_INITIAL_READY,
                'match_kind'=>TournamentRoundProgressionService::MATCH_ELIMINATION,
                'winner'=>$technicalCancelRequired?null:$players[1]['mgw'],
                'loser'=>$technicalCancelRequired?null:$players[2]['mgw'],
                'result_reason'=>$resultReason,'completed_at'=>$completedAt,
            ]
        );

        if(!$technicalCancelRequired){
            $db->execute(
                'INSERT INTO mgw_tournament_match_attempts (
                    tournament_id,round_no,pair_no,attempt_no,game_id,player_a_mgw_id,player_b_mgw_id,
                    result_type,winner_mgw_id,loser_mgw_id,finish_reason,finished_at_utc,created_at_utc
                 ) VALUES (
                    :tournament_id,1,1,1,:game_id,:player_a,:player_b,:result_type,:winner,:loser,
                    :finish_reason,:finished_at,:created_at
                 )',
                [
                    'tournament_id'=>$tournamentId,'game_id'=>'game-'.$tournamentId,
                    'player_a'=>$players[1]['mgw'],'player_b'=>$players[2]['mgw'],
                    'result_type'=>'win','winner'=>$players[1]['mgw'],'loser'=>$players[2]['mgw'],
                    'finish_reason'=>'player_left','finished_at'=>'2026-09-22 20:20:00.000000',
                    'created_at'=>'2026-09-22 20:20:00.000000',
                ]
            );
            $eventKey=hash('sha256',$tournamentId.'|technical');
            $db->execute(
                'INSERT INTO mgw_tournament_technical_outcomes (
                    event_key,tournament_id,round_no,pair_no,attempt_no,game_id,outcome_code,
                    player_a_mgw_id,player_b_mgw_id,winner_mgw_id,loser_mgw_id,
                    metadata_json,occurred_at_utc,created_at_utc
                 ) VALUES (
                    :event_key,:tournament_id,1,1,1,:game_id,:outcome_code,
                    :player_a,:player_b,:winner,:loser,:metadata,:occurred,:created
                 )',
                [
                    'event_key'=>$eventKey,'tournament_id'=>$tournamentId,'game_id'=>'game-'.$tournamentId,
                    'outcome_code'=>'manual_leave','player_a'=>$players[1]['mgw'],'player_b'=>$players[2]['mgw'],
                    'winner'=>$players[1]['mgw'],'loser'=>$players[2]['mgw'],
                    'metadata'=>'{}','occurred'=>'2026-09-22 20:20:00.000000','created'=>'2026-09-22 20:20:00.000000',
                ]
            );
        }
    }
};

$seedTournament($db,$ledger,$players,'tour-cancel-normal');
$service=new TournamentCancellationService($db,$ledger);

$availability=$service->availability();
$assertSame(true,$availability['normal_cancel_available'],'Normal cancellation must be available before settlement.');
$assertSame(true,$availability['emergency_stop_available'],'Emergency stop must be available before settlement.');
$assertSame(false,$availability['technical_cancel_required'],'Ordinary tournament must not report technical escalation.');

$assertThrows(
    fn()=> $service->cancel(
        'tour-cancel-normal',TournamentCancellationService::KIND_CANCEL,'Плановая отмена','telegram:admin',[]
    ),
    'второго подтверждения',
    'Backend must reject a single-confirm cancellation.'
);

$confirmation=[
    'confirmed'=>true,
    'mode'=>TournamentCancellationService::CONFIRMATION_MODE,
    'tournament_id'=>'tour-cancel-normal',
    'kind'=>TournamentCancellationService::KIND_CANCEL,
];
$ledgerBefore=(int)$db->fetchValue('SELECT COUNT(*) FROM mgw_ledger_entries');
$result=$service->cancel(
    'tour-cancel-normal',
    TournamentCancellationService::KIND_CANCEL,
    'Плановая отмена',
    'telegram:admin',
    $confirmation,
    new DateTimeImmutable('2026-09-22T20:30:00Z')
);

$assertSame('cancelled',$result['status'],'Normal cancellation must close the tournament.');
$assertSame(8,$result['participant_count'],'All registered participants must be in cancellation audit.');
$assertSame(8,$result['refunded_count'],'All registered participants must receive a refund.');
$assertSame(400000,$result['refund_amount'],'Full refund audit must equal 8 x 50000.');
$assertSame(8,$result['released_reservation_count'],'Active reservations must be canonically released.');
$assertSame(0,$result['consumed_refund_count'],'No consumed entry existed in normal scenario.');
$assertSame(1,$result['annulled_match_count'],'Existing bracket result must be marked annulled.');
$assertSame(1,$result['annulled_attempt_count'],'Existing match attempt must be marked annulled.');
$assertSame(1,$result['annulled_technical_count'],'Existing technical outcome must be marked annulled.');
$assertSame(false,$result['replayed'],'First cancellation call must not be a replay.');

$tournament=$db->fetchAll(
    'SELECT * FROM mgw_tournaments WHERE tournament_id=:t',
    ['t'=>'tour-cancel-normal']
)[0];
$assertSame(null,$tournament['active_slot'],'Cancellation must release the official active slot.');
$assertSame(TournamentRegistrationService::STATE_CANCELLED,(string)$tournament['tournament_state'],'Normal cancel state must be durable.');
$assertSame('cancel',(string)$tournament['cancellation_kind'],'Cancellation kind must be durable.');
$assertSame('Плановая отмена',(string)$tournament['cancellation_reason'],'Cancellation reason must be durable.');
$assertSame('telegram:admin',(string)$tournament['cancelled_by_ref'],'Cancellation actor must be durable.');

$assertSame(0,(int)$db->fetchValue(
    "SELECT COUNT(*) FROM mgw_tournament_registrations
     WHERE tournament_id='tour-cancel-normal' AND registration_state='registered'"
),'Cancellation must leave no registered participant rows.');
$assertSame(8,(int)$db->fetchValue(
    "SELECT COUNT(*) FROM mgw_tournament_registrations
     WHERE tournament_id='tour-cancel-normal' AND registration_state='cancelled'"
),'Cancellation must retain all participant rows as cancelled audit history.');
$assertSame(0,(int)$db->fetchValue(
    "SELECT COUNT(*) FROM mgw_reservations
     WHERE source_ref='tour-cancel-normal' AND status='active'"
),'Cancellation must leave no active entry reservation.');

for($i=1;$i<=8;$i++){
    $balance=$ledger->getBalance($players[$i]['account'],TournamentRegistrationService::ENTRY_ASSET);
    $assertSame(100000,(int)$balance['available_amount'],'Participant '.$i.' must receive the entire 50000 entry back.');
    $assertSame(0,(int)$balance['reserved_amount'],'Participant '.$i.' must have no reserved entry after cancellation.');
}
$assertSame('tournament_cancelled',(string)$db->fetchValue(
    "SELECT annulment_reason FROM mgw_tournament_round_matches
     WHERE tournament_id='tour-cancel-normal' AND round_no=1 AND pair_no=1"
),'Bracket result must retain explicit annulment reason.');
$assertTrue((string)$db->fetchValue(
    "SELECT annulled_at_utc FROM mgw_tournament_match_attempts
     WHERE tournament_id='tour-cancel-normal' AND round_no=1 AND pair_no=1"
)!=='','Match attempt must remain durable but annulled.');

$event=$db->fetchAll(
    'SELECT * FROM mgw_tournament_cancellation_events WHERE tournament_id=:t',
    ['t'=>'tour-cancel-normal']
);
$assertSame(1,count($event),'Cancellation must produce exactly one durable audit event.');
$assertSame(TournamentCancellationService::CONFIRMATION_MODE,(string)$event[0]['confirmation_mode'],'Audit must prove double-confirm mode.');

$last=$service->lastCancellationForParticipant($players[1]['mgw']);
$assertSame('tour-cancel-normal',(string)$last['tournament_id'],'Participant must receive latest cancellation projection.');
$assertSame(50000,(int)$last['refund_amount'],'Participant projection must show full entry refund.');
$assertSame(true,$last['results_annulled'],'Participant projection must state result annulment.');

$ledgerAfter=(int)$db->fetchValue('SELECT COUNT(*) FROM mgw_ledger_entries');
$replay=$service->cancel(
    'tour-cancel-normal',
    TournamentCancellationService::KIND_CANCEL,
    'Плановая отмена',
    'telegram:admin',
    $confirmation,
    new DateTimeImmutable('2026-09-22T20:31:00Z')
);
$assertSame(true,$replay['replayed'],'Exact cancellation retry must be idempotent.');
$assertSame($ledgerAfter,(int)$db->fetchValue('SELECT COUNT(*) FROM mgw_ledger_entries'),'Cancellation retry must not add ledger rows.');
$assertSame(1,(int)$db->fetchValue(
    "SELECT COUNT(*) FROM mgw_tournament_cancellation_events WHERE tournament_id='tour-cancel-normal'"
),'Cancellation retry must not duplicate audit.');

$settlement=new TournamentSettlementService($db,$ledger);
$settleAfterCancel=$settlement->settleIfComplete('tour-cancel-normal');
$assertSame('cancelled',$settleAfterCancel['status'],'Settlement owner must refuse a cancelled tournament.');
$assertSame(0,(int)$db->fetchValue(
    "SELECT COUNT(*) FROM mgw_tournament_results WHERE tournament_id='tour-cancel-normal'"
),'Cancelled tournament must never produce placement rewards.');

$db->execute(
    "UPDATE mgw_tournaments SET tournament_state='staging_reset'
     WHERE tournament_id='tour-cancel-normal'"
);
$assertSame(
    null,
    $service->lastCancellationForParticipant($players[1]['mgw']),
    'A staging-reset tournament must preserve audit rows without remaining visible as the participant current cancellation.'
);

// Consumed-but-unrewarded entry: refund it exactly once with available delta.
$seedTournament($db,$ledger,$players,'tour-cancel-consumed',false,2);
$consumedReservation=(string)$db->fetchValue(
    "SELECT reservation_id FROM mgw_tournament_registrations
     WHERE tournament_id='tour-cancel-consumed' AND mgw_id=:mgw_id",
    ['mgw_id'=>$players[1]['mgw']]
);
$ledger->consumeReservation([
    'operation_key'=>'mvp21-8-partial-consume',
    'reservation_id'=>$consumedReservation,
    'metadata'=>['purpose'=>'simulate_pre_reward_consumption'],
    'occurred_at_utc'=>'2026-09-22 20:25:00.000000',
]);
$consumeConfirmation=[
    'confirmed'=>true,'mode'=>TournamentCancellationService::CONFIRMATION_MODE,
    'tournament_id'=>'tour-cancel-consumed','kind'=>TournamentCancellationService::KIND_CANCEL,
];
$consumedResult=$service->cancel(
    'tour-cancel-consumed',TournamentCancellationService::KIND_CANCEL,'Отмена после consume',
    'telegram:admin',$consumeConfirmation,new DateTimeImmutable('2026-09-22T20:35:00Z')
);
$assertSame(1,$consumedResult['consumed_refund_count'],'Consumed but unrewarded entry must receive one canonical available refund.');
$assertSame(1,$consumedResult['released_reservation_count'],'Other active entry must use reservation release.');
for($i=1;$i<=2;$i++){
    $balance=$ledger->getBalance($players[$i]['account'],TournamentRegistrationService::ENTRY_ASSET);
    $assertSame(100000,(int)$balance['available_amount'],'Consumed-path participant '.$i.' must end fully refunded.');
    $assertSame(0,(int)$balance['reserved_amount'],'Consumed-path participant '.$i.' must have no reservation.');
}

// Repeated infrastructure failure must force the emergency branch.
$seedTournament($db,$ledger,$players,'tour-cancel-emergency',true,2);
$availability=$service->availability();
$assertSame(true,$availability['technical_cancel_required'],'21.7 escalation must be visible to 21.8.');
$assertSame(false,$availability['normal_cancel_available'],'Technical escalation must not offer normal cancel.');
$assertSame(true,$availability['emergency_stop_available'],'Technical escalation must offer emergency stop.');

$emergencyConfirmation=[
    'confirmed'=>true,'mode'=>TournamentCancellationService::CONFIRMATION_MODE,
    'tournament_id'=>'tour-cancel-emergency','kind'=>TournamentCancellationService::KIND_EMERGENCY,
];
$assertThrows(
    fn()=> $service->cancel(
        'tour-cancel-emergency',TournamentCancellationService::KIND_EMERGENCY,'',
        'telegram:admin',$emergencyConfirmation
    ),
    'обязательно укажите причину',
    'Emergency stop must require a reason.'
);
$emergency=$service->cancel(
    'tour-cancel-emergency',
    TournamentCancellationService::KIND_EMERGENCY,
    'Повторный серверный сбой после технического перезапуска',
    'telegram:admin',
    $emergencyConfirmation,
    new DateTimeImmutable('2026-09-22T20:40:00Z')
);
$assertSame(TournamentCancellationService::KIND_EMERGENCY,$emergency['kind'],'Emergency audit must retain emergency kind.');
$assertSame(2,$emergency['refunded_count'],'Emergency must fully refund every registered participant.');
$assertSame(100000,$emergency['refund_amount'],'Emergency refund total must equal all entries.');
$assertSame('tournament_emergency_stop',(string)$db->fetchValue(
    "SELECT annulment_reason FROM mgw_tournament_round_matches
     WHERE tournament_id='tour-cancel-emergency' AND round_no=1 AND pair_no=1"
),'Emergency must explicitly annul the technical-cancel-required pair.');
$assertSame(TournamentRegistrationService::STATE_EMERGENCY_STOPPED,(string)$db->fetchValue(
    "SELECT tournament_state FROM mgw_tournaments WHERE tournament_id='tour-cancel-emergency'"
),'Emergency state must be durable.');

if($assertions<65) throw new RuntimeException('MVP-21.8 cancellation test is too shallow: '.$assertions);
fwrite(STDOUT,"Mvp21_8TournamentCancellationTest: {$assertions} assertions passed\n");
