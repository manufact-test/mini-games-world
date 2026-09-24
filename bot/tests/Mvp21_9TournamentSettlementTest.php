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

$mysqlHost=trim((string)getenv('MGW_TEST_MYSQL_HOST'));
$isMysql=$mysqlHost!=='';
if($isMysql){
 if(!extension_loaded('pdo_mysql')) throw new RuntimeException('Mvp21_9TournamentSettlementTest requires pdo_mysql for MySQL mode.');
 $port=trim((string)getenv('MGW_TEST_MYSQL_PORT'))?:'3306';
 $name=trim((string)getenv('MGW_TEST_MYSQL_DATABASE'))?:'mgw_test';
 $user=trim((string)getenv('MGW_TEST_MYSQL_USER'))?:'root';
 $pass=(string)getenv('MGW_TEST_MYSQL_PASSWORD');
 $pdo=new PDO('mysql:host='.$mysqlHost.';port='.$port.';dbname='.$name.';charset=utf8mb4',$user,$pass,[
  PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
  PDO::ATTR_EMULATE_PREPARES=>false,
 ]);
}else{
 if(!extension_loaded('pdo_sqlite')) throw new RuntimeException('Mvp21_9TournamentSettlementTest requires pdo_sqlite.');
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
}else{
 $db->execute('CREATE TABLE mgw_users (
  mgw_id TEXT PRIMARY KEY,nickname TEXT NULL,display_name TEXT NULL
 )');
}
if($isMysql){
 $db->execute('CREATE TABLE mgw_account_ownership (
  account_ref VARCHAR(255) COLLATE utf8mb4_bin NOT NULL PRIMARY KEY,
  mgw_id VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  legacy_user_id VARCHAR(191) COLLATE utf8mb4_bin NOT NULL,
  ownership_status VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  source_type VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  source_ref VARCHAR(191) COLLATE utf8mb4_bin NOT NULL
 ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
}else{
 $db->execute('CREATE TABLE mgw_account_ownership (
  account_ref TEXT NOT NULL PRIMARY KEY,mgw_id TEXT NOT NULL,legacy_user_id TEXT NOT NULL,
  ownership_status TEXT NOT NULL,source_type TEXT NOT NULL,source_ref TEXT NOT NULL
 )');
}
(require $root.'/database/migrations/20260717_0005_create_balances_ledger_reservations.php')->up($db);
(require $root.'/database/migrations/20260920_0049_create_official_tournaments.php')->up($db);
if($isMysql){
 $db->execute('CREATE TABLE mgw_tournament_round_matches (
  tournament_id VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  round_no SMALLINT UNSIGNED NOT NULL,pair_no SMALLINT UNSIGNED NOT NULL,
  match_kind VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  winner_mgw_id VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NULL,
  loser_mgw_id VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NULL,
  completed_at_utc DATETIME(6) NULL
 ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
}else{
 $db->execute('CREATE TABLE mgw_tournament_round_matches (
  tournament_id TEXT NOT NULL,round_no INTEGER NOT NULL,pair_no INTEGER NOT NULL,
  match_kind TEXT NOT NULL,winner_mgw_id TEXT NULL,loser_mgw_id TEXT NULL,
  completed_at_utc TEXT NULL
 )');
}
(require $root.'/database/migrations/20260922_0057_create_tournament_results_rewards.php')->up($db);

$assertions=0;
$assertSame=static function(mixed $expected,mixed $actual,string $message)use(&$assertions):void{
 $assertions++;
 if($expected!==$actual) throw new RuntimeException($message.': expected '.var_export($expected,true).', got '.var_export($actual,true));
};
$assertTrue=static function(bool $condition,string $message)use(&$assertions):void{
 $assertions++;
 if(!$condition) throw new RuntimeException($message);
};

$ledger=new LedgerWriteService($db);
$players=[];
for($i=1;$i<=8;$i++){
 $mgw='MGW-'.str_pad((string)$i,16,(string)$i);
 $legacy='settlement-player-'.$i;
 $account='mgw:'.$mgw;
 $players[$i]=['mgw'=>$mgw,'legacy'=>$legacy,'account'=>$account];
 $db->execute('INSERT INTO mgw_users (mgw_id,nickname,display_name) VALUES (:m,:n,:d)',[
  'm'=>$mgw,'n'=>'Игрок '.$i,'d'=>'Игрок '.$i
 ]);
 $db->execute('INSERT INTO mgw_account_ownership (
   account_ref,mgw_id,legacy_user_id,ownership_status,source_type,source_ref
  ) VALUES (:a,:m,:l,:s,:st,:sr)',[
   'a'=>$account,'m'=>$mgw,'l'=>$legacy,'s'=>'active','st'=>'runtime_identity','sr'=>'telegram:'.$legacy
 ]);
 $ledger->postAvailableDelta([
  'operation_key'=>'settlement-seed-'.$i,
  'account_ref'=>$account,'mgw_id'=>$mgw,'legacy_user_id'=>$legacy,
  'asset_code'=>TournamentRegistrationService::ENTRY_ASSET,
  'available_delta'=>100000,'category'=>'test_seed','source_type'=>'test','source_ref'=>'mvp21.9',
  'occurred_at_utc'=>'2026-09-22 18:00:00.000000'
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
    :id,:slot,:title,:game,8,:fee,:asset,:rewards,:state,:created_by,:opened_by,:created,:opened,:updated
   )',
  [
   'id'=>$tournamentId,'slot'=>$activeSlot===''?null:$activeSlot,'title'=>'Settlement test',
   'game'=>'tictactoe','fee'=>TournamentRegistrationService::ENTRY_FEE,
   'asset'=>TournamentRegistrationService::ENTRY_ASSET,
   'rewards'=>json_encode($snapshot,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),
   'state'=>TournamentRegistrationService::STATE_SCHEDULED,'created_by'=>'test','opened_by'=>'test',
   'created'=>'2026-09-22 18:00:00.000000','opened'=>'2026-09-22 18:00:00.000000',
   'updated'=>'2026-09-22 18:00:00.000000'
  ]
 );

 foreach($players as $i=>$player){
  $reservation=$ledger->createReservation([
   'operation_key'=>'register:'.$tournamentId.':'.$i,
   'account_ref'=>$player['account'],'mgw_id'=>$player['mgw'],'legacy_user_id'=>$player['legacy'],
   'asset_code'=>TournamentRegistrationService::ENTRY_ASSET,
   'amount'=>TournamentRegistrationService::ENTRY_FEE,
   'source_type'=>'official_tournament','source_ref'=>$tournamentId,
   'metadata'=>['tournament_id'=>$tournamentId],
   'occurred_at_utc'=>'2026-09-22 18:01:00.000000'
  ]);
  $db->execute(
   'INSERT INTO mgw_tournament_registrations (
     registration_id,tournament_id,mgw_id,account_ref,attempt_no,registration_state,reservation_id,
     registered_at_utc,withdrawn_at_utc,updated_at_utc
    ) VALUES (:r,:t,:m,:a,1,:s,:res,:registered_at,NULL,:updated_at)',
   [
    'r'=>'reg-'.$tournamentId.'-'.$i,'t'=>$tournamentId,'m'=>$player['mgw'],'a'=>$player['account'],
    's'=>TournamentRegistrationService::REGISTRATION_REGISTERED,'res'=>$reservation['reservation_id'],
    'registered_at'=>'2026-09-22 18:01:00.000000',
    'updated_at'=>'2026-09-22 18:01:00.000000'
   ]
  );
 }
 $db->execute(
  'INSERT INTO mgw_tournament_round_matches
   (tournament_id,round_no,pair_no,match_kind,winner_mgw_id,loser_mgw_id,completed_at_utc)
   VALUES (:t,3,1,:k,:w,:l,:c)',
  ['t'=>$tournamentId,'k'=>TournamentRoundProgressionService::MATCH_FINAL,
   'w'=>$players[1]['mgw'],'l'=>$players[2]['mgw'],'c'=>$completedAt]
 );
 $db->execute(
  'INSERT INTO mgw_tournament_round_matches
   (tournament_id,round_no,pair_no,match_kind,winner_mgw_id,loser_mgw_id,completed_at_utc)
   VALUES (:t,3,2,:k,:w,:l,:c)',
  ['t'=>$tournamentId,'k'=>TournamentRoundProgressionService::MATCH_THIRD_PLACE,
   'w'=>$players[3]['mgw'],'l'=>$players[4]['mgw'],'c'=>$completedAt]
 );
};

$seedTournament('tour-settle-1',TournamentRegistrationService::ACTIVE_SLOT,'2026-09-22 20:00:00.000000',$players,$ledger,$db);
$service=new TournamentSettlementService($db,$ledger);
$result=$service->settleIfComplete('tour-settle-1');

$assertSame('settled',$result['status'],'Completed bracket must settle.');
$assertSame(true,$result['settlement_complete'],'Settlement must close all participant results.');
$assertSame(8,$result['settled_count'],'Every registered participant must receive one durable result.');
$assertSame(8,count($result['runtime_balances']),'Settlement must expose runtime balance convergence for every participant.');

$expectedBalances=[1=>250000,2=>130000,3=>100000,4=>50000,5=>50000,6=>50000,7=>50000,8=>50000];
foreach($expectedBalances as $i=>$expected){
 $balance=$ledger->getBalance($players[$i]['account'],TournamentRegistrationService::ENTRY_ASSET);
 $assertSame($expected,(int)$balance['available_amount'],'Placement '.$i.' available balance must match canonical payout.');
 $assertSame(0,(int)$balance['reserved_amount'],'Placement '.$i.' reservation must be fully settled.');
}
$assertSame(0,(int)$db->fetchValue("SELECT COUNT(*) FROM mgw_reservations WHERE status='active'"),'No tournament reservation may remain active after settlement.');
$assertSame(8,(int)$db->fetchValue('SELECT COUNT(*) FROM mgw_tournament_results WHERE tournament_id=:t',['t'=>'tour-settle-1']),'Results must be durable.');

$assertSame(6,(int)$db->fetchValue('SELECT COUNT(*) FROM mgw_tournament_reward_entitlements WHERE tournament_id=:t AND mgw_id=:m',[
 't'=>'tour-settle-1','m'=>$players[1]['mgw']
]),'Champion must receive ticket, crown, badge, champion set, Hall of Fame and gold cup entitlements.');
$assertSame(3,(int)$db->fetchValue('SELECT COUNT(*) FROM mgw_tournament_reward_entitlements WHERE tournament_id=:t AND mgw_id=:m',[
 't'=>'tour-settle-1','m'=>$players[2]['mgw']
]),'Runner-up must receive frame, finalist result and silver cup entitlements.');
$assertSame(3,(int)$db->fetchValue('SELECT COUNT(*) FROM mgw_tournament_reward_entitlements WHERE tournament_id=:t AND mgw_id=:m',[
 't'=>'tour-settle-1','m'=>$players[3]['mgw']
]),'Third place must receive bronze mark, permanent result and bronze cup entitlements.');
$assertSame('2026-10-22 20:00:00.000000',(string)$db->fetchValue(
 'SELECT valid_until_at_utc FROM mgw_tournament_reward_entitlements
  WHERE tournament_id=:t AND mgw_id=:m AND reward_code=:c',
 ['t'=>'tour-settle-1','m'=>$players[1]['mgw'],'c'=>'champion_crown']
),'Champion crown must expire exactly 30 days after terminal completion.');

$ticket=$db->fetchAll('SELECT * FROM mgw_tournament_golden_tickets WHERE mgw_id=:m',['m'=>$players[1]['mgw']])[0];
$assertSame('active',(string)$ticket['ticket_state'],'Champion Golden Ticket must be active.');
$assertSame(1,(int)$ticket['championship_count'],'First championship must produce championship_count=1.');

$terminal=$service->terminalSnapshotForParticipant('tour-settle-1',$players[1]['mgw']);
$assertSame(true,$terminal['settlement_complete'],'Terminal snapshot must expose settled state.');
$assertSame('Игрок 1',(string)$terminal['podium'][0]['nickname'],'Podium must expose canonical champion nickname.');
$assertSame(1,(int)$terminal['self_result']['placement'],'Champion must see own first place.');
$assertSame(200000,(int)$terminal['self_result']['payout_amount'],'Champion terminal payout must be total 200000.');
$assertSame(150000,(int)$terminal['self_result']['prize_amount'],'Champion terminal prize must be 150000.');
$assertSame(250000,(int)$terminal['self_result']['balance']['available_amount'],'Terminal balance must reflect settlement.');

$ledgerBeforeReplay=(int)$db->fetchValue('SELECT COUNT(*) FROM mgw_ledger_entries');
$service->settleIfComplete('tour-settle-1');
$assertSame($ledgerBeforeReplay,(int)$db->fetchValue('SELECT COUNT(*) FROM mgw_ledger_entries'),'Repeated settlement must not add ledger entries.');
$assertSame(8,(int)$db->fetchValue('SELECT COUNT(*) FROM mgw_tournament_results WHERE tournament_id=:t',['t'=>'tour-settle-1']),'Repeated settlement must not duplicate results.');

$seedTournament('tour-settle-2','', '2026-09-23 20:00:00.000000',$players,$ledger,$db);
$service->settleIfComplete('tour-settle-2');
$ticket=$db->fetchAll('SELECT * FROM mgw_tournament_golden_tickets WHERE mgw_id=:m',['m'=>$players[1]['mgw']])[0];
$assertSame(2,(int)$ticket['championship_count'],'Repeat championship must increment championship_count exactly once.');
$service->settleIfComplete('tour-settle-2');
$ticket=$db->fetchAll('SELECT * FROM mgw_tournament_golden_tickets WHERE mgw_id=:m',['m'=>$players[1]['mgw']])[0];
$assertSame(2,(int)$ticket['championship_count'],'Repeated second settlement must not double-increment championship_count.');

$db->execute(
 'UPDATE mgw_tournament_results
  SET reward_eligible=0
  WHERE tournament_id=:t AND mgw_id=:m',
 ['t'=>'tour-settle-1','m'=>$players[1]['mgw']]
);
foreach($players as $i=>$player){
 $ledger->postAvailableDelta([
  'operation_key'=>'settlement-third-topup-'.$i,
  'account_ref'=>$player['account'],
  'mgw_id'=>$player['mgw'],
  'legacy_user_id'=>$player['legacy'],
  'asset_code'=>TournamentRegistrationService::ENTRY_ASSET,
  'available_delta'=>50000,
  'category'=>'test_seed',
  'source_type'=>'test',
  'source_ref'=>'mvp21.9-third-settlement',
  'occurred_at_utc'=>'2026-09-24 18:00:00.000000',
 ]);
}
$seedTournament('tour-settle-3','', '2026-09-24 20:00:00.000000',$players,$ledger,$db);
$service->settleIfComplete('tour-settle-3');
$ticket=$db->fetchAll('SELECT * FROM mgw_tournament_golden_tickets WHERE mgw_id=:m',['m'=>$players[1]['mgw']])[0];
$assertSame(
 2,
 (int)$ticket['championship_count'],
 'Reward-ineligible historical test win must not inflate the next real championship count.'
);

$fixtureLegacy='stg_tour_v2_abcdef123456';
$fixture=[
 'mgw'=>'MGW-ABCDEFGHJKMNPQRS',
 'legacy'=>$fixtureLegacy,
 'account'=>'legacy:'.$fixtureLegacy,
];
$db->execute('INSERT INTO mgw_users (mgw_id,nickname,display_name) VALUES (:m,:n,:d)',[
 'm'=>$fixture['mgw'],'n'=>'Тестовый участник 2','d'=>'Тестовый участник 2'
]);
$db->execute('INSERT INTO mgw_account_ownership (
 account_ref,mgw_id,legacy_user_id,ownership_status,source_type,source_ref
) VALUES (:a,:m,:l,:s,:st,:sr)',[
 'a'=>$fixture['account'],'m'=>$fixture['mgw'],'l'=>$fixture['legacy'],
 's'=>'active','st'=>'runtime_identity','sr'=>'development:'.$fixture['legacy']
]);
$ledger->postAvailableDelta([
 'operation_key'=>'fixture-settlement-seed',
 'account_ref'=>$fixture['account'],'mgw_id'=>$fixture['mgw'],'legacy_user_id'=>$fixture['legacy'],
 'asset_code'=>TournamentRegistrationService::ENTRY_ASSET,
 'available_delta'=>50000,'category'=>'test_grant','source_type'=>'test','source_ref'=>'mvp21.9-fixture',
 'occurred_at_utc'=>'2026-09-24 18:00:00.000000'
]);

$fixturePlayers=$players;
$fixturePlayers[2]=$fixture;
foreach($fixturePlayers as $i=>$player){
 if($i===2) continue;
 $ledger->postAvailableDelta([
  'operation_key'=>'fixture-round-topup-'.$i,
  'account_ref'=>$player['account'],'mgw_id'=>$player['mgw'],'legacy_user_id'=>$player['legacy'],
  'asset_code'=>TournamentRegistrationService::ENTRY_ASSET,
  'available_delta'=>50000,'category'=>'test_seed','source_type'=>'test','source_ref'=>'mvp21.9-fixture',
  'occurred_at_utc'=>'2026-09-24 18:00:00.000000'
 ]);
}
$seedTournament('tour-settle-fixture','', '2026-09-24 20:00:00.000000',$fixturePlayers,$ledger,$db);
$service->settleIfComplete('tour-settle-fixture');

$fixtureBalance=$ledger->getBalance($fixture['account'],TournamentRegistrationService::ENTRY_ASSET);
$assertSame(0,(int)$fixtureBalance['available_amount'],'Synthetic fixture placement must not receive competitive coin payout.');
$assertSame(0,(int)$fixtureBalance['reserved_amount'],'Synthetic fixture reservation must still settle fully.');
$fixtureResult=$db->fetchAll(
 'SELECT placement,payout_amount,reward_eligible FROM mgw_tournament_results WHERE tournament_id=:t AND mgw_id=:m',
 ['t'=>'tour-settle-fixture','m'=>$fixture['mgw']]
)[0];
$assertSame(2,(int)$fixtureResult['placement'],'Synthetic fixture bracket placement must remain auditable.');
$assertSame(0,(int)$fixtureResult['payout_amount'],'Synthetic fixture result must persist zero payout.');
$assertSame(0,(int)$fixtureResult['reward_eligible'],'Synthetic fixture result must be marked reward-ineligible.');
$assertSame(0,(int)$db->fetchValue(
 'SELECT COUNT(*) FROM mgw_tournament_reward_entitlements WHERE tournament_id=:t AND mgw_id=:m',
 ['t'=>'tour-settle-fixture','m'=>$fixture['mgw']]
),'Synthetic fixture must receive no competitive entitlement.');

echo "MVP-21.9 tournament settlement OK ({$assertions} assertions)\n";
