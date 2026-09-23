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
require $root.'/tournaments/TournamentSettlementService.php';

$mysqlHost=trim((string)getenv('MGW_TEST_MYSQL_HOST'));
$isMysql=$mysqlHost!=='';
if($isMysql){
    if(!extension_loaded('pdo_mysql')) throw new RuntimeException('MVP-21.11 release proof requires pdo_mysql in MySQL mode.');
    $pdo=new PDO(
        'mysql:host='.$mysqlHost
        .';port='.(trim((string)getenv('MGW_TEST_MYSQL_PORT'))?:'3306')
        .';dbname='.(trim((string)getenv('MGW_TEST_MYSQL_DATABASE'))?:'mgw_test')
        .';charset=utf8mb4',
        trim((string)getenv('MGW_TEST_MYSQL_USER'))?:'root',
        (string)getenv('MGW_TEST_MYSQL_PASSWORD'),
        [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]
    );
}else{
    if(!extension_loaded('pdo_sqlite')) throw new RuntimeException('MVP-21.11 release proof requires pdo_sqlite.');
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

foreach([
    '20260717_0005_create_balances_ledger_reservations.php',
    '20260920_0049_create_official_tournaments.php',
    '20260920_0050_add_tournament_rules_consent.php',
    '20260921_0052_add_tournament_schedule.php',
    '20260921_0053_create_tournament_hall_bracket.php',
    '20260921_0054_create_tournament_match_readiness.php',
    '20260921_0055_add_tournament_round_progression.php',
    '20260922_0057_create_tournament_results_rewards.php',
    '20260922_0058_add_tournament_technical_outcomes.php',
] as $migration){
    (require $root.'/database/migrations/'.$migration)->up($db);
}

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

$assertSame([8,16,32,64,128],TournamentRegistrationService::ALLOWED_CAPACITIES,'Release proof must preserve all five launch capacities.');
$assertSame(50000,TournamentRegistrationService::ENTRY_FEE,'Tournament entry fee must stay canonical.');
$assertSame(300,TournamentRoundProgressionService::ROUND_BREAK_SECONDS,'Round break must stay five minutes.');
$assertSame(60,TournamentRoundProgressionService::DRAW_REPLAY_WAIT_SECONDS,'Draw replay wait must stay one minute.');
$assertSame('ru',TournamentRegistrationService::RULES_LANGUAGE,'Tournament rules localization must stay Russian.');

$ledger=new LedgerWriteService($db);
$progress=new TournamentRoundProgressionService($db);
$settlement=new TournamentSettlementService($db,$ledger);
$playerSerial=0;

$formatUtc=static fn(DateTimeImmutable $value):string=>$value->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
$formatIso=static fn(DateTimeImmutable $value):string=>$value->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');

$seedTournament=static function(
    string $tournamentId,
    string $gameType,
    int $capacity,
    int $caseNo,
    PdoDatabaseConnection $db,
    LedgerWriteService $ledger,
    callable $formatUtc,
    int &$playerSerial
):array{
    $start=(new DateTimeImmutable('2026-09-24T10:00:00Z'))->modify('+'.($caseNo*2).' days');
    $created=$start->modify('-2 hours');
    $reward=TournamentRegistrationService::canonicalRewardSnapshot();
    $rules=TournamentRegistrationService::canonicalRulesSnapshot($gameType,$capacity);
    $rulesJson=json_encode($rules,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
    $rulesSha=TournamentRegistrationService::canonicalRulesSha256($gameType,$capacity);

    $db->execute(
        'INSERT INTO mgw_tournaments (
            tournament_id,active_slot,title,game_type,capacity,entry_fee_amount,entry_asset_code,
            reward_snapshot_json,rules_version,rules_language,rules_snapshot_json,rules_sha256,
            tournament_state,created_by_ref,opened_by_ref,
            created_at_utc,registration_opened_at_utc,registration_closed_at_utc,registration_closed_reason,
            scheduled_start_at_utc,scheduled_by_ref,scheduled_at_utc,
            bracket_effective_at_utc,bracket_generated_at_utc,bracket_version,updated_at_utc
         ) VALUES (
            :id,NULL,:title,:game,:capacity,:fee,:asset,
            :rewards,:rules_version,:rules_language,:rules_json,:rules_sha,
            :state,:created_by,:opened_by,
            :created,:opened,:closed,:closed_reason,
            :scheduled,:scheduled_by,:scheduled_at,
            :effective,:generated,:bracket_version,:updated
         )',
        [
            'id'=>$tournamentId,'title'=>'Release proof '.$gameType.' '.$capacity,'game'=>$gameType,'capacity'=>$capacity,
            'fee'=>TournamentRegistrationService::ENTRY_FEE,'asset'=>TournamentRegistrationService::ENTRY_ASSET,
            'rewards'=>json_encode($reward,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),
            'rules_version'=>TournamentRegistrationService::RULES_VERSION,
            'rules_language'=>TournamentRegistrationService::RULES_LANGUAGE,
            'rules_json'=>$rulesJson,'rules_sha'=>$rulesSha,
            'state'=>TournamentRegistrationService::STATE_SCHEDULED,
            'created_by'=>'mvp21.11','opened_by'=>'mvp21.11',
            'created'=>$formatUtc($created),'opened'=>$formatUtc($created->modify('+10 minutes')),
            'closed'=>$formatUtc($created->modify('+20 minutes')),'closed_reason'=>'full',
            'scheduled'=>$formatUtc($start),'scheduled_by'=>'mvp21.11','scheduled_at'=>$formatUtc($created->modify('+30 minutes')),
            'effective'=>$formatUtc($start),'generated'=>$formatUtc($start),'bracket_version'=>'mvp21.11-release-proof-v1',
            'updated'=>$formatUtc($start),
        ]
    );

    $players=[];
    for($i=1;$i<=$capacity;$i++){
        $playerSerial++;
        $mgw='MGW-'.str_pad(strtoupper(dechex($playerSerial)),16,'0',STR_PAD_LEFT);
        $legacy='mvp2111-'.$caseNo.'-'.$i;
        $account='mgw:'.$mgw;
        $reg='reg-'.$tournamentId.'-'.$i;
        $players[$mgw]=['mgw'=>$mgw,'legacy'=>$legacy,'account'=>$account,'registration'=>$reg];

        $db->execute('INSERT INTO mgw_users (mgw_id,nickname,display_name) VALUES (:m,:n,:d)',[
            'm'=>$mgw,'n'=>'RP '.$caseNo.'-'.$i,'d'=>'Release Player '.$caseNo.'-'.$i,
        ]);
        $db->execute('INSERT INTO mgw_account_ownership (
            account_ref,mgw_id,legacy_user_id,ownership_status,source_type,source_ref
        ) VALUES (:a,:m,:l,:s,:st,:sr)',[
            'a'=>$account,'m'=>$mgw,'l'=>$legacy,'s'=>'active','st'=>'runtime_identity','sr'=>'telegram:'.$legacy,
        ]);
        $ledger->postAvailableDelta([
            'operation_key'=>'mvp2111-seed-'.$caseNo.'-'.$i,
            'account_ref'=>$account,'mgw_id'=>$mgw,'legacy_user_id'=>$legacy,
            'asset_code'=>TournamentRegistrationService::ENTRY_ASSET,
            'available_delta'=>100000,'category'=>'test_seed','source_type'=>'test','source_ref'=>'mvp21.11',
            'occurred_at_utc'=>$formatUtc($created),
        ]);
        $reservation=$ledger->createReservation([
            'operation_key'=>'mvp2111-register-'.$caseNo.'-'.$i,
            'account_ref'=>$account,'mgw_id'=>$mgw,'legacy_user_id'=>$legacy,
            'asset_code'=>TournamentRegistrationService::ENTRY_ASSET,
            'amount'=>TournamentRegistrationService::ENTRY_FEE,
            'source_type'=>'official_tournament','source_ref'=>$tournamentId,
            'metadata'=>['tournament_id'=>$tournamentId,'release_proof'=>true],
            'occurred_at_utc'=>$formatUtc($created->modify('+10 minutes')),
        ]);

        $db->execute(
            'INSERT INTO mgw_tournament_registrations (
                registration_id,tournament_id,mgw_id,account_ref,attempt_no,registration_state,reservation_id,
                rules_version,rules_language,rules_sha256,rules_accepted_at_utc,
                registered_at_utc,withdrawn_at_utc,updated_at_utc
             ) VALUES (
                :r,:t,:m,:a,1,:s,:reservation,
                :rules_version,:rules_language,:rules_sha,:accepted,
                :registered,NULL,:updated
             )',
            [
                'r'=>$reg,'t'=>$tournamentId,'m'=>$mgw,'a'=>$account,
                's'=>TournamentRegistrationService::REGISTRATION_REGISTERED,
                'reservation'=>$reservation['reservation_id'],
                'rules_version'=>TournamentRegistrationService::RULES_VERSION,
                'rules_language'=>TournamentRegistrationService::RULES_LANGUAGE,
                'rules_sha'=>$rulesSha,'accepted'=>$formatUtc($created->modify('+10 minutes')),
                'registered'=>$formatUtc($created->modify('+10 minutes')),'updated'=>$formatUtc($start),
            ]
        );
        $db->execute(
            'INSERT INTO mgw_tournament_bracket_seeds (
                tournament_id,seed_no,pair_no,registration_id,mgw_id,present_at_start,technical_loss_at_start,created_at_utc
             ) VALUES (:t,:seed,:pair,:r,:m,1,0,:created)',
            [
                't'=>$tournamentId,'seed'=>$i,'pair'=>(int)(($i-1)/2)+1,'r'=>$reg,'m'=>$mgw,'created'=>$formatUtc($start),
            ]
        );
    }

    return ['start'=>$start,'players'=>$players,'rules'=>$rules];
};

$runLifecycle=static function(
    string $tournamentId,
    string $gameType,
    int $capacity,
    array $seed,
    PdoDatabaseConnection $db,
    TournamentRoundProgressionService $progress,
    TournamentSettlementService $settlement,
    callable $formatUtc,
    callable $formatIso,
    callable $assertSame,
    callable $assertTrue
):void{
    $players=$seed['players'];
    $start=$seed['start'];
    $snapshot=$progress->ensureFirstRoundStructure($tournamentId,$start->modify('+1 second'));

    $assertSame($capacity/2,count(array_filter(
        $snapshot['matches'],
        static fn(array $m):bool=>(int)$m['round_no']===1
    )),$tournamentId.' first-round pair count must match capacity.');
    $assertSame($gameType,(string)($seed['rules']['tournament']['game_type'] ?? ''),$tournamentId.' rules snapshot must preserve game type.');
    $assertTrue(trim((string)($seed['rules']['tournament']['game_title'] ?? ''))!=='',$tournamentId.' localized game title must be present.');

    $guard=0;
    while(true){
        $guard++;
        if($guard>16) throw new RuntimeException($tournamentId.' lifecycle did not terminate.');

        $rows=$db->fetchAll(
            'SELECT * FROM mgw_tournament_round_matches
             WHERE tournament_id=:t AND completed_at_utc IS NULL
             ORDER BY round_no ASC,pair_no ASC',
            ['t'=>$tournamentId]
        );
        if($rows===[]) break;

        $round=(int)$rows[0]['round_no'];
        foreach($rows as $row){
            if((int)$row['round_no']!==$round) continue;
            $a=(string)$row['player_a_mgw_id'];
            $b=(string)$row['player_b_mgw_id'];
            if($a==='' || $b==='') throw new RuntimeException($tournamentId.' normal release proof unexpectedly created a vacancy.');

            $attempt=max(1,(int)$row['attempt_no']);
            $gameId=$progress->expectedGameId($tournamentId,$round,(int)$row['pair_no'],$attempt);
            $open=new DateTimeImmutable((string)$row['readiness_opened_at_utc'],new DateTimeZone('UTC'));
            $finish=$open->modify('+1 second');
            $progress->attachGame($tournamentId,$round,(int)$row['pair_no'],$attempt,$gameId,$open);
            $progress->observeFinishedGame([
                'id'=>$gameId,
                'match_source'=>'tournament',
                'tournament_id'=>$tournamentId,
                'tournament_round_no'=>$round,
                'tournament_pair_no'=>(int)$row['pair_no'],
                'tournament_attempt_no'=>$attempt,
                'status'=>'finished',
                'player_ids'=>[$players[$a]['legacy'],$players[$b]['legacy']],
                'winner_id'=>$players[$a]['legacy'],
                'finish_reason'=>'normal_win',
                'finished_at'=>$formatIso($finish),
            ],$finish);
        }
    }

    $allRows=$db->fetchAll(
        'SELECT * FROM mgw_tournament_round_matches WHERE tournament_id=:t ORDER BY round_no,pair_no',
        ['t'=>$tournamentId]
    );
    $expectedRounds=(int)round(log($capacity,2));
    $maxRound=0;
    $finalCount=0;
    $thirdCount=0;
    foreach($allRows as $row){
        $maxRound=max($maxRound,(int)$row['round_no']);
        if((string)$row['match_kind']===TournamentRoundProgressionService::MATCH_FINAL) $finalCount++;
        if((string)$row['match_kind']===TournamentRoundProgressionService::MATCH_THIRD_PLACE) $thirdCount++;
        $assertTrue(trim((string)($row['completed_at_utc'] ?? ''))!=='',$tournamentId.' must not leave an incomplete bracket row.');
    }
    $assertSame($expectedRounds,$maxRound,$tournamentId.' must automatically reach the expected terminal round.');
    $assertSame($capacity,count($allRows),$tournamentId.' must materialize the complete elimination bracket plus third-place match.');
    $assertSame(1,$finalCount,$tournamentId.' must create exactly one final.');
    $assertSame(1,$thirdCount,$tournamentId.' must create exactly one third-place match.');

    $settled=$settlement->settleIfComplete($tournamentId);
    $assertSame('settled',$settled['status'],$tournamentId.' must settle automatically after terminal bracket completion.');
    $assertSame(true,$settled['settlement_complete'],$tournamentId.' settlement must be terminal.');
    $assertSame($capacity,(int)$db->fetchValue(
        'SELECT COUNT(*) FROM mgw_tournament_results WHERE tournament_id=:t',
        ['t'=>$tournamentId]
    ),$tournamentId.' must create exactly one durable result per registered participant.');
    foreach([1,2,3,4] as $place){
        $assertSame(1,(int)$db->fetchValue(
            'SELECT COUNT(*) FROM mgw_tournament_results WHERE tournament_id=:t AND placement=:p',
            ['t'=>$tournamentId,'p'=>$place]
        ),$tournamentId.' must have exactly one terminal placement '.$place.'.');
    }
    $assertSame(0,(int)$db->fetchValue(
        "SELECT COUNT(*) FROM mgw_reservations res
         INNER JOIN mgw_tournament_registrations r ON r.reservation_id=res.reservation_id
         WHERE r.tournament_id=:t AND res.status='active'",
        ['t'=>$tournamentId]
    ),$tournamentId.' must leave no active tournament entry reservation.');

    $ledgerBefore=(int)$db->fetchValue('SELECT COUNT(*) FROM mgw_ledger_entries');
    $entitlementsBefore=(int)$db->fetchValue(
        'SELECT COUNT(*) FROM mgw_tournament_reward_entitlements WHERE tournament_id=:t',
        ['t'=>$tournamentId]
    );
    $ticketsBefore=(int)$db->fetchValue('SELECT COUNT(*) FROM mgw_tournament_golden_tickets');
    $settlement->settleIfComplete($tournamentId);
    $assertSame($ledgerBefore,(int)$db->fetchValue('SELECT COUNT(*) FROM mgw_ledger_entries'),$tournamentId.' settlement retry must not duplicate ledger entries.');
    $assertSame($entitlementsBefore,(int)$db->fetchValue(
        'SELECT COUNT(*) FROM mgw_tournament_reward_entitlements WHERE tournament_id=:t',
        ['t'=>$tournamentId]
    ),$tournamentId.' settlement retry must not duplicate entitlements.');
    $assertSame($ticketsBefore,(int)$db->fetchValue('SELECT COUNT(*) FROM mgw_tournament_golden_tickets'),$tournamentId.' settlement retry must not duplicate Golden Tickets.');
};

$cases=[
    [8,'tictactoe'],
    [16,'four_in_a_row'],
    [32,'battleship'],
    [64,'checkers'],
    [128,'reversi'],
    [8,'chess'],
    [8,'go'],
    [8,'domino'],
];

foreach($cases as $caseNo=>$case){
    [$capacity,$gameType]=$case;
    $tournamentId='tour-release-'.($caseNo+1).'-'.$capacity.'-'.$gameType;
    $seed=$seedTournament($tournamentId,$gameType,$capacity,$caseNo+1,$db,$ledger,$formatUtc,$playerSerial);
    $runLifecycle(
        $tournamentId,$gameType,$capacity,$seed,$db,$progress,$settlement,
        $formatUtc,$formatIso,$assertSame,$assertTrue
    );
}

if($assertions<100) throw new RuntimeException('MVP-21.11 release proof is unexpectedly shallow: '.$assertions);
fwrite(STDOUT,'MVP-21.11 tournament release proof OK ('.$assertions.' assertions, driver='.$db->driver().")\n");
