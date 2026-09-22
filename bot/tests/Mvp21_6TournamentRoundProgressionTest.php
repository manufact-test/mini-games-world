<?php
declare(strict_types=1);

$root=dirname(__DIR__);
require $root.'/database/DatabaseConnectionInterface.php';
require $root.'/database/PdoDatabaseConnection.php';
require $root.'/database/DatabaseMigrationInterface.php';
require $root.'/tournaments/TournamentRegistrationService.php';
require $root.'/tournaments/TournamentMatchReadinessService.php';
require $root.'/tournaments/TournamentRoundProgressionService.php';

if(!extension_loaded('pdo_sqlite')) throw new RuntimeException('Mvp21_6TournamentRoundProgressionTest requires pdo_sqlite.');

$pdo=new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$pdo->exec('PRAGMA foreign_keys = ON');
$db=new PdoDatabaseConnection($pdo);

$db->execute('CREATE TABLE mgw_tournaments (
 tournament_id TEXT PRIMARY KEY,active_slot TEXT NOT NULL,tournament_state TEXT NOT NULL,
 game_type TEXT NOT NULL,capacity INTEGER NOT NULL,scheduled_start_at_utc TEXT NOT NULL,
 bracket_generated_at_utc TEXT NULL
)');
$db->execute('CREATE TABLE mgw_users (mgw_id TEXT PRIMARY KEY,nickname TEXT NULL,display_name TEXT NULL)');
$db->execute('CREATE TABLE mgw_tournament_registrations (
 tournament_id TEXT NOT NULL,registration_id TEXT PRIMARY KEY,mgw_id TEXT NOT NULL,
 account_ref TEXT NOT NULL,registration_state TEXT NOT NULL
)');
$db->execute('CREATE TABLE mgw_account_ownership (
 mgw_id TEXT NOT NULL,account_ref TEXT NOT NULL,ownership_status TEXT NOT NULL,legacy_user_id TEXT NOT NULL
)');
$db->execute('CREATE TABLE mgw_tournament_bracket_seeds (
 tournament_id TEXT NOT NULL,seed_no INTEGER NOT NULL,pair_no INTEGER NOT NULL,
 registration_id TEXT NOT NULL,mgw_id TEXT NOT NULL,present_at_start INTEGER NOT NULL,
 technical_loss_at_start INTEGER NOT NULL,PRIMARY KEY(tournament_id,seed_no)
)');
(require $root.'/database/migrations/20260921_0054_create_tournament_match_readiness.php')->up($db);
(require $root.'/database/migrations/20260921_0055_add_tournament_round_progression.php')->up($db);

$assertions=0;
$assertSame=static function(mixed $e,mixed $a,string $m)use(&$assertions):void{
 $assertions++; if($e!==$a) throw new RuntimeException($m.': expected '.var_export($e,true).', got '.var_export($a,true));
};
$assertTrue=static function(bool $c,string $m)use(&$assertions):void{$assertions++;if(!$c)throw new RuntimeException($m);};

$tournament='tour-rounds';
$db->execute('INSERT INTO mgw_tournaments VALUES (:id,:slot,:state,:game,8,:start,:generated)',[
 'id'=>$tournament,'slot'=>TournamentRegistrationService::ACTIVE_SLOT,
 'state'=>TournamentRegistrationService::STATE_SCHEDULED,'game'=>'tictactoe',
 'start'=>'2026-09-21 10:00:00.000000','generated'=>'2026-09-21 10:00:00.000000'
]);

$players=[];
for($i=1;$i<=8;$i++){
 $mgw='MGW-'.str_pad((string)$i,16,(string)$i);
 $legacy='legacy-'.$i; $reg='reg-'.$i; $account='account-'.$i;
 $players[$i]=['mgw'=>$mgw,'legacy'=>$legacy,'reg'=>$reg,'account'=>$account];
 $db->execute('INSERT INTO mgw_users VALUES (:mgw,:nick,:display)',['mgw'=>$mgw,'nick'=>'P'.$i,'display'=>'P'.$i]);
 $db->execute('INSERT INTO mgw_tournament_registrations VALUES (:t,:r,:m,:a,:s)',[
  't'=>$tournament,'r'=>$reg,'m'=>$mgw,'a'=>$account,'s'=>TournamentRegistrationService::REGISTRATION_REGISTERED
 ]);
 $db->execute('INSERT INTO mgw_account_ownership VALUES (:m,:a,:s,:l)',[
  'm'=>$mgw,'a'=>$account,'s'=>'active','l'=>$legacy
 ]);
 $db->execute('INSERT INTO mgw_tournament_bracket_seeds VALUES (:t,:seed,:pair,:r,:m,1,0)',[
  't'=>$tournament,'seed'=>$i,'pair'=>(int)(($i-1)/2)+1,'r'=>$reg,'m'=>$mgw
 ]);
}

$progress=new TournamentRoundProgressionService($db);
$status=$progress->statusForParticipant(
 $players[1]['mgw'],$players[1]['account'],$players[1]['legacy'],
 new DateTimeImmutable('2026-09-21T10:00:01Z')
);
$assertSame(4,(int)$db->fetchValue('SELECT COUNT(*) FROM mgw_tournament_round_matches WHERE tournament_id=:t AND round_no=1',['t'=>$tournament]),'All first-round pairs must materialize together.');
$assertSame(300,$status['round_break_seconds'],'Round break must be exactly five minutes.');
$assertSame(60,$status['draw_replay_wait_seconds'],'Draw replay wait must be exactly one minute.');

$readyAt='2026-09-21 10:00:02.000000';
$db->execute("UPDATE mgw_tournament_round_matches SET player_a_ready_at_utc=:r,player_b_ready_at_utc=:r,launch_state='ready' WHERE tournament_id=:t",['r'=>$readyAt,'t'=>$tournament]);

$finish=function(int $pair,?int $winnerIndex,string $time)use($progress,$players,$tournament):array{
 $a=$players[(($pair-1)*2)+1]; $b=$players[(($pair-1)*2)+2];
 $gameId=$progress->expectedGameId($tournament,1,$pair,1);
 $progress->attachGame($tournament,1,$pair,1,$gameId,new DateTimeImmutable('2026-09-21T10:00:03Z'));
 return $progress->observeFinishedGame([
  'id'=>$gameId,'match_source'=>'tournament','tournament_id'=>$tournament,
  'tournament_round_no'=>1,'tournament_pair_no'=>$pair,'status'=>'finished',
  'player_ids'=>[$a['legacy'],$b['legacy']],
  'winner_id'=>$winnerIndex===null?null:$players[$winnerIndex]['legacy'],
  'finish_reason'=>$winnerIndex===null?'draw':'normal_win','finished_at'=>$time,
 ],new DateTimeImmutable($time));
};

$finish(1,null,'2026-09-21T10:01:00Z');
$row=$db->fetchAll('SELECT * FROM mgw_tournament_round_matches WHERE tournament_id=:t AND round_no=1 AND pair_no=1',['t'=>$tournament])[0];
$assertSame(2,(int)$row['attempt_no'],'Draw must schedule attempt two.');
$assertSame(TournamentRoundProgressionService::WAIT_DRAW_REPLAY,(string)$row['wait_kind'],'Draw must enter replay wait.');
$assertSame($players[2]['mgw'],(string)$row['player_a_mgw_id'],'Replay must swap durable player order.');
$assertSame($players[1]['mgw'],(string)$row['player_b_mgw_id'],'Replay side order must be reversed.');
$assertSame('2026-09-21 10:02:00.000000',(string)$row['readiness_opened_at_utc'],'Replay must open exactly one minute after draw.');
$assertSame(null,$progress->launchContextForParticipant($players[1]['mgw'],$players[1]['account'],$players[1]['legacy'],new DateTimeImmutable('2026-09-21T10:01:59Z')),'Replay must stay locked for the full minute.');
$replay=$progress->launchContextForParticipant($players[1]['mgw'],$players[1]['account'],$players[1]['legacy'],new DateTimeImmutable('2026-09-21T10:02:00Z'));
$assertTrue(is_array($replay),'Replay must become launchable at exactly +60 seconds.');
$assertSame(true,$replay['side_swap'],'Replay launch context must expose side swap.');
$assertSame($players[2]['legacy'],$replay['players'][0]['legacy_user_id'],'Replay runtime order must be swapped.');
$assertTrue($replay['game_id']!==$progress->expectedGameId($tournament,1,1,1),'Replay must use a new deterministic game id.');

$progress->attachGame($tournament,1,1,2,$replay['game_id'],new DateTimeImmutable('2026-09-21T10:02:00Z'));
$progress->observeFinishedGame([
 'id'=>$replay['game_id'],'match_source'=>'tournament','tournament_id'=>$tournament,
 'tournament_round_no'=>1,'tournament_pair_no'=>1,'status'=>'finished',
 'player_ids'=>[$players[2]['legacy'],$players[1]['legacy']],
 'winner_id'=>$players[1]['legacy'],'finish_reason'=>'normal_win','finished_at'=>'2026-09-21T10:03:00Z',
],new DateTimeImmutable('2026-09-21T10:03:00Z'));
$assertSame(2,(int)$db->fetchValue('SELECT COUNT(*) FROM mgw_tournament_match_attempts WHERE tournament_id=:t AND round_no=1 AND pair_no=1',['t'=>$tournament]),'Draw and replay must both remain in durable attempt history.');

$finish(2,3,'2026-09-21T10:03:10Z');
$finish(3,5,'2026-09-21T10:03:20Z');
$finish(4,7,'2026-09-21T10:03:30Z');
$assertSame(2,(int)$db->fetchValue('SELECT COUNT(*) FROM mgw_tournament_round_matches WHERE tournament_id=:t AND round_no=2',['t'=>$tournament]),'Completing all four matches must create exactly two semifinals.');
$semi=$db->fetchAll('SELECT * FROM mgw_tournament_round_matches WHERE tournament_id=:t AND round_no=2 ORDER BY pair_no',['t'=>$tournament]);
$assertSame('2026-09-21 10:08:30.000000',(string)$semi[0]['readiness_opened_at_utc'],'Next round must open five minutes after the last match finishes.');
$assertSame(TournamentRoundProgressionService::WAIT_ROUND_BREAK,(string)$semi[0]['wait_kind'],'Next round must expose round-break wait.');
$assertSame(null,$progress->launchContextForParticipant($players[1]['mgw'],$players[1]['account'],$players[1]['legacy'],new DateTimeImmutable('2026-09-21T10:08:29Z')),'Semifinal must stay locked until the five-minute break ends.');
$semiLaunch=$progress->launchContextForParticipant($players[1]['mgw'],$players[1]['account'],$players[1]['legacy'],new DateTimeImmutable('2026-09-21T10:08:30Z'));
$assertTrue(is_array($semiLaunch),'Semifinal must auto-launch after the round break.');

$completeRound=function(int $round,array $winners,string $baseTime)use($progress,$db,$tournament,$players):void{
 $rows=$db->fetchAll('SELECT * FROM mgw_tournament_round_matches WHERE tournament_id=:t AND round_no=:r ORDER BY pair_no',['t'=>$tournament,'r'=>$round]);
 foreach($rows as $i=>$row){
  $attempt=(int)$row['attempt_no']; $pair=(int)$row['pair_no'];
  $gameId=$progress->expectedGameId($tournament,$round,$pair,$attempt);
  $progress->attachGame($tournament,$round,$pair,$attempt,$gameId,new DateTimeImmutable($baseTime));
  $map=[];
  foreach($players as $p) $map[$p['mgw']]=$p['legacy'];
  $a=(string)$row['player_a_mgw_id'];$b=(string)$row['player_b_mgw_id'];$winner=(string)$row[$winners[$i]];
  $progress->observeFinishedGame([
   'id'=>$gameId,'match_source'=>'tournament','tournament_id'=>$tournament,
   'tournament_round_no'=>$round,'tournament_pair_no'=>$pair,'status'=>'finished',
   'player_ids'=>[$map[$a],$map[$b]],'winner_id'=>$map[$winner],
   'finish_reason'=>'normal_win','finished_at'=>$baseTime,
  ],new DateTimeImmutable($baseTime));
 }
};

$completeRound(2,['player_a_mgw_id','player_a_mgw_id'],'2026-09-21T10:10:00Z');
$finals=$db->fetchAll('SELECT * FROM mgw_tournament_round_matches WHERE tournament_id=:t AND round_no=3 ORDER BY pair_no',['t'=>$tournament]);
$assertSame(2,count($finals),'Semifinals must create both final and third-place match.');
$assertSame(TournamentRoundProgressionService::MATCH_FINAL,(string)$finals[0]['match_kind'],'Pair 1 of last round must be final.');
$assertSame(TournamentRoundProgressionService::MATCH_THIRD_PLACE,(string)$finals[1]['match_kind'],'Pair 2 of last round must be third-place.');
$assertSame('2026-09-21 10:15:00.000000',(string)$finals[0]['readiness_opened_at_utc'],'Final and third-place match must share the same five-minute break.');

$completeRound(3,['player_a_mgw_id','player_a_mgw_id'],'2026-09-21T10:16:00Z');
$finalStatus=$progress->statusForParticipant($players[1]['mgw'],$players[1]['account'],$players[1]['legacy'],new DateTimeImmutable('2026-09-21T10:16:01Z'));
$assertSame(true,$finalStatus['tournament_complete'],'Tournament becomes complete only after both final and third-place match finish.');
$assertSame(9,(int)$db->fetchValue('SELECT COUNT(*) FROM mgw_tournament_match_attempts WHERE tournament_id=:t',['t'=>$tournament]),'Eight-player tournament with one draw replay must keep all nine played attempts.');

// Corrective v5: every seeded pair must materialize even when both competitors
// were absent at T0. Omitting these rows made an 8-player round structurally odd
// after the live pair finished and prevented the staging fixture helper from
// seeing its work.
$absentTournament='tour-rounds-both-absent';
$db->execute('INSERT INTO mgw_tournaments VALUES (:id,:slot,:state,:game,8,:start,:generated)',[
 'id'=>$absentTournament,'slot'=>'fixture-backfill',
 'state'=>TournamentRegistrationService::STATE_SCHEDULED,'game'=>'tictactoe',
 'start'=>'2026-09-21 11:00:00.000000','generated'=>'2026-09-21 11:00:00.000000'
]);
for($i=1;$i<=8;$i++){
 $db->execute('INSERT INTO mgw_tournament_bracket_seeds VALUES (:t,:seed,:pair,:r,:m,:present,:tech)',[
  't'=>$absentTournament,
  'seed'=>$i,
  'pair'=>(int)(($i-1)/2)+1,
  'r'=>'absent-reg-'.$i,
  'm'=>$players[$i]['mgw'],
  'present'=>$i<=2?1:0,
  'tech'=>$i<=2?0:1,
 ]);
}
$absentSnapshot=$progress->ensureFirstRoundStructure(
 $absentTournament,
 new DateTimeImmutable('2026-09-21T11:00:01Z')
);
$assertSame(4,count($absentSnapshot['matches']),'Both-absent seeds must not disappear from an eight-player first round.');
$bothAbsentRows=$db->fetchAll(
 'SELECT * FROM mgw_tournament_round_matches WHERE tournament_id=:t AND pair_no>=2 ORDER BY pair_no',
 ['t'=>$absentTournament]
);
$assertSame(3,count($bothAbsentRows),'All three both-absent fixture pairs must have durable unresolved rows.');
foreach($bothAbsentRows as $row){
 $assertSame(TournamentMatchReadinessService::STATE_READINESS_EXPIRED,(string)$row['launch_state'],'Both-absent pair must be non-launchable.');
 $assertSame('both_absent_at_start_pending',(string)$row['result_reason'],'Both-absent pair must retain an explicit pending-resolution reason.');
 $assertSame(null,$row['completed_at_utc'],'Both-absent pair must remain unresolved until canonical progression chooses a winner.');
}

if($assertions<31) throw new RuntimeException('MVP-21.6 progression test is too shallow: '.$assertions);
fwrite(STDOUT,"Mvp21_6TournamentRoundProgressionTest: {$assertions} assertions passed\n");
