<?php
declare(strict_types=1);

if (!function_exists('now_iso')) {
    function now_iso(): string { return gmdate('c'); }
}
if (!function_exists('make_id')) {
    function make_id(string $prefix): string {
        static $counter=0;
        return $prefix.'_mvp217_'.(++$counter);
    }
}

$root=dirname(__DIR__);
require $root.'/database/DatabaseConnectionInterface.php';
require $root.'/database/PdoDatabaseConnection.php';
require $root.'/database/DatabaseMigrationInterface.php';
require $root.'/economy/UnifiedBalanceRuntimeState.php';
require $root.'/services/PresenceService.php';
require $root.'/services/GameSettlementService.php';
require $root.'/services/GameNoContestSettlementService.php';
require $root.'/services/ReconnectLifecycleService.php';
require $root.'/tournaments/TournamentRegistrationService.php';
require $root.'/tournaments/TournamentMatchReadinessService.php';
require $root.'/tournaments/TournamentRoundProgressionService.php';

$mysqlHost=trim((string)getenv('MGW_TEST_MYSQL_HOST'));
$isMysql=$mysqlHost!=='';
if($isMysql){
    if(!extension_loaded('pdo_mysql')) throw new RuntimeException('MVP-21.7 test requires pdo_mysql in MySQL mode.');
    $pdo=new PDO(
        'mysql:host='.$mysqlHost.';port='.(trim((string)getenv('MGW_TEST_MYSQL_PORT'))?:'3306')
        .';dbname='.(trim((string)getenv('MGW_TEST_MYSQL_DATABASE'))?:'mgw_test').';charset=utf8mb4',
        trim((string)getenv('MGW_TEST_MYSQL_USER'))?:'root',
        (string)getenv('MGW_TEST_MYSQL_PASSWORD'),
        [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]
    );
}else{
    if(!extension_loaded('pdo_sqlite')) throw new RuntimeException('MVP-21.7 test requires pdo_sqlite.');
    $pdo=new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
    $pdo->exec('PRAGMA foreign_keys = ON');
}
$db=new PdoDatabaseConnection($pdo);

if($isMysql){
    $db->execute('CREATE TABLE mgw_tournaments (
        tournament_id VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY,
        active_slot VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,
        tournament_state VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        game_type VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        capacity SMALLINT UNSIGNED NOT NULL,
        scheduled_start_at_utc DATETIME(6) NOT NULL,
        bracket_generated_at_utc DATETIME(6) NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    $db->execute('CREATE TABLE mgw_users (
        mgw_id VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY,
        nickname VARCHAR(160) NULL,display_name VARCHAR(160) NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    $db->execute('CREATE TABLE mgw_tournament_registrations (
        tournament_id VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        registration_id VARCHAR(96) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY,
        mgw_id VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        account_ref VARCHAR(255) COLLATE utf8mb4_bin NOT NULL,
        registration_state VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    $db->execute('CREATE TABLE mgw_account_ownership (
        mgw_id VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        account_ref VARCHAR(255) COLLATE utf8mb4_bin NOT NULL,
        ownership_status VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        legacy_user_id VARCHAR(191) COLLATE utf8mb4_bin NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    $db->execute('CREATE TABLE mgw_tournament_bracket_seeds (
        tournament_id VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        seed_no SMALLINT UNSIGNED NOT NULL,pair_no SMALLINT UNSIGNED NOT NULL,
        registration_id VARCHAR(96) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        mgw_id VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        present_at_start TINYINT(1) NOT NULL,technical_loss_at_start TINYINT(1) NOT NULL,
        PRIMARY KEY(tournament_id,seed_no)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
}else{
    $db->execute('CREATE TABLE mgw_tournaments (
        tournament_id TEXT PRIMARY KEY,active_slot TEXT NULL,tournament_state TEXT NOT NULL,
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
}

(require $root.'/database/migrations/20260921_0054_create_tournament_match_readiness.php')->up($db);
(require $root.'/database/migrations/20260921_0055_add_tournament_round_progression.php')->up($db);
(require $root.'/database/migrations/20260922_0058_add_tournament_technical_outcomes.php')->up($db);

$assertions=0;
$assertSame=static function(mixed $expected,mixed $actual,string $message)use(&$assertions):void{
    $assertions++;
    if($expected!==$actual) throw new RuntimeException($message.': expected '.var_export($expected,true).', got '.var_export($actual,true));
};
$assertTrue=static function(bool $condition,string $message)use(&$assertions):void{
    $assertions++;
    if(!$condition) throw new RuntimeException($message);
};

$players=[];
for($i=1;$i<=8;$i++){
    $mgw='MGW-'.str_pad((string)$i,16,(string)$i);
    $players[$i]=[
        'mgw'=>$mgw,
        'legacy'=>'mvp217-player-'.$i,
        'account'=>'mvp217-account-'.$i,
    ];
    $db->execute('INSERT INTO mgw_users (mgw_id,nickname,display_name) VALUES (:m,:n,:d)',[
        'm'=>$mgw,'n'=>'P'.$i,'d'=>'P'.$i
    ]);
    $db->execute('INSERT INTO mgw_account_ownership (mgw_id,account_ref,ownership_status,legacy_user_id)
                  VALUES (:m,:a,:s,:l)',[
        'm'=>$mgw,'a'=>$players[$i]['account'],'s'=>'active','l'=>$players[$i]['legacy']
    ]);
}

$seedTournament=static function(
    PdoDatabaseConnection $db,
    string $tournamentId,
    array $players,
    bool $present
):void{
    $db->execute('INSERT INTO mgw_tournaments (
        tournament_id,active_slot,tournament_state,game_type,capacity,scheduled_start_at_utc,bracket_generated_at_utc
    ) VALUES (:id,:slot,:state,:game,8,:start,:generated)',[
        'id'=>$tournamentId,'slot'=>$tournamentId,
        'state'=>TournamentRegistrationService::STATE_SCHEDULED,'game'=>'tictactoe',
        'start'=>'2026-09-22 18:00:00.000000','generated'=>'2026-09-22 18:00:00.000000'
    ]);
    foreach($players as $i=>$player){
        $registration='reg-'.$tournamentId.'-'.$i;
        $db->execute('INSERT INTO mgw_tournament_registrations (
            tournament_id,registration_id,mgw_id,account_ref,registration_state
        ) VALUES (:t,:r,:m,:a,:s)',[
            't'=>$tournamentId,'r'=>$registration,'m'=>$player['mgw'],'a'=>$player['account'],
            's'=>TournamentRegistrationService::REGISTRATION_REGISTERED
        ]);
        $db->execute('INSERT INTO mgw_tournament_bracket_seeds (
            tournament_id,seed_no,pair_no,registration_id,mgw_id,present_at_start,technical_loss_at_start
        ) VALUES (:t,:seed,:pair,:r,:m,:present,:technical)',[
            't'=>$tournamentId,'seed'=>$i,'pair'=>(int)(($i-1)/2)+1,'r'=>$registration,'m'=>$player['mgw'],
            'present'=>$present?1:0,'technical'=>$present?0:1
        ]);
    }
};

$progress=new TournamentRoundProgressionService($db);

// Both absent at T0: close the pair without inventing a winner, then propagate
// vacancies through the bracket until final + third-place are terminal.
$seedTournament($db,'tour-217-absent',$players,false);
$absent=$progress->ensureFirstRoundStructure(
    'tour-217-absent',
    new DateTimeImmutable('2026-09-22T18:00:01Z')
);
$assertSame(8,count($absent['matches']),'All-absent eight-player bracket must still have a complete structural path.');
$round1=$db->fetchAll('SELECT * FROM mgw_tournament_round_matches
                       WHERE tournament_id=:t AND round_no=1 ORDER BY pair_no',['t'=>'tour-217-absent']);
$assertSame(4,count($round1),'Four first-round rows must exist.');
foreach($round1 as $row){
    $assertSame(TournamentRoundProgressionService::STATE_COMPLETED,(string)$row['launch_state'],'Both-absent T0 pair must be terminal.');
    $assertSame(null,$row['winner_mgw_id'],'Both-absent T0 pair must not invent a winner.');
    $assertSame('both_absent_at_start',(string)$row['result_reason'],'Both-absent outcome must be explicit.');
}
$finalRows=$db->fetchAll('SELECT * FROM mgw_tournament_round_matches
                          WHERE tournament_id=:t AND round_no=3 ORDER BY pair_no',['t'=>'tour-217-absent']);
$assertSame(2,count($finalRows),'Vacancy propagation must still create final and third-place structural rows.');
foreach($finalRows as $row){
    $assertSame(null,$row['winner_mgw_id'],'Vacant terminal row must not invent a placement winner.');
    $assertSame('vacant_bracket_slot',(string)$row['result_reason'],'Vacant terminal row must be auditable.');
}
$absentAudit=(int)$db->fetchValue(
    'SELECT COUNT(*) FROM mgw_tournament_technical_outcomes WHERE tournament_id=:t',
    ['t'=>'tour-217-absent']
);
$assertSame(8,$absentAudit,'Every automatic both-absent/vacancy outcome must have one durable audit event.');

// Infrastructure failure: one clean technical restart, then explicit escalation
// to the cancellation boundary. It must never masquerade as a draw or swap sides.
$seedTournament($db,'tour-217-restart',$players,true);
$progress->ensureFirstRoundStructure('tour-217-restart',new DateTimeImmutable('2026-09-22T18:00:01Z'));
$game1=$progress->expectedGameId('tour-217-restart',1,1,1);
$progress->attachGame('tour-217-restart',1,1,1,$game1,new DateTimeImmutable('2026-09-22T18:00:02Z'));
$progress->observeFinishedGame([
    'id'=>$game1,'match_source'=>'tournament','tournament_id'=>'tour-217-restart',
    'tournament_round_no'=>1,'tournament_pair_no'=>1,'tournament_attempt_no'=>1,
    'status'=>'finished','player_ids'=>[$players[1]['legacy'],$players[2]['legacy']],
    'winner_id'=>null,'finish_reason'=>'server_failure','finished_at'=>'2026-09-22T18:01:00Z',
],new DateTimeImmutable('2026-09-22T18:01:00Z'));
$restartRow=$db->fetchAll('SELECT * FROM mgw_tournament_round_matches
                           WHERE tournament_id=:t AND round_no=1 AND pair_no=1',['t'=>'tour-217-restart'])[0];
$assertSame(2,(int)$restartRow['attempt_no'],'First infrastructure failure must schedule exactly one restart attempt.');
$assertSame(TournamentRoundProgressionService::WAIT_TECHNICAL_RESTART,(string)$restartRow['wait_kind'],'Infrastructure retry must have its own wait kind.');
$assertSame($players[1]['mgw'],(string)$restartRow['player_a_mgw_id'],'Technical restart must preserve side A.');
$assertSame($players[2]['mgw'],(string)$restartRow['player_b_mgw_id'],'Technical restart must preserve side B.');
$assertSame('2026-09-22 18:02:00.000000',(string)$restartRow['readiness_opened_at_utc'],'Technical restart must wait one minute.');
$assertSame(null,$restartRow['completed_at_utc'],'Restarting technical failure must not complete the pair.');

$game2=$progress->expectedGameId('tour-217-restart',1,1,2);
$progress->attachGame('tour-217-restart',1,1,2,$game2,new DateTimeImmutable('2026-09-22T18:02:00Z'));
$progress->observeFinishedGame([
    'id'=>$game2,'match_source'=>'tournament','tournament_id'=>'tour-217-restart',
    'tournament_round_no'=>1,'tournament_pair_no'=>1,'tournament_attempt_no'=>2,
    'status'=>'finished','player_ids'=>[$players[1]['legacy'],$players[2]['legacy']],
    'winner_id'=>null,'finish_reason'=>'server_failure','finished_at'=>'2026-09-22T18:03:00Z',
],new DateTimeImmutable('2026-09-22T18:03:00Z'));
$restartRow=$db->fetchAll('SELECT * FROM mgw_tournament_round_matches
                           WHERE tournament_id=:t AND round_no=1 AND pair_no=1',['t'=>'tour-217-restart'])[0];
$assertSame(TournamentRoundProgressionService::STATE_TECHNICAL_CANCEL_REQUIRED,(string)$restartRow['launch_state'],'Repeated infrastructure failure must stop progression at cancellation boundary.');
$assertSame('technical_restart_exhausted',(string)$restartRow['result_reason'],'Cancellation boundary must retain exact technical reason.');
$assertSame(null,$restartRow['completed_at_utc'],'A tournament requiring cancellation must not silently progress.');
$assertSame(0,(int)$db->fetchValue('SELECT COUNT(*) FROM mgw_tournament_round_matches
                                    WHERE tournament_id=:t AND round_no=2',['t'=>'tour-217-restart']),'No next round may exist after fatal technical escalation.');
$assertSame(3,(int)$db->fetchValue('SELECT COUNT(*) FROM mgw_tournament_technical_outcomes
                                    WHERE tournament_id=:t AND round_no=1 AND pair_no=1',['t'=>'tour-217-restart']),'Restart, repeated failure and escalation must all remain auditable.');

// Manual leave and single disconnect are technical wins, not rated tournament wins.
$seedTournament($db,'tour-217-results',$players,true);
$progress->ensureFirstRoundStructure('tour-217-results',new DateTimeImmutable('2026-09-22T18:00:01Z'));
$manualGame=$progress->expectedGameId('tour-217-results',1,1,1);
$progress->attachGame('tour-217-results',1,1,1,$manualGame,new DateTimeImmutable('2026-09-22T18:00:02Z'));
$progress->observeFinishedGame([
    'id'=>$manualGame,'match_source'=>'tournament','tournament_id'=>'tour-217-results',
    'tournament_round_no'=>1,'tournament_pair_no'=>1,'status'=>'finished',
    'player_ids'=>[$players[1]['legacy'],$players[2]['legacy']],
    'winner_id'=>$players[1]['legacy'],'finish_reason'=>'player_left','finished_at'=>'2026-09-22T18:01:00Z',
],new DateTimeImmutable('2026-09-22T18:01:00Z'));
$manualRow=$db->fetchAll('SELECT * FROM mgw_tournament_round_matches
                          WHERE tournament_id=:t AND round_no=1 AND pair_no=1',['t'=>'tour-217-results'])[0];
$assertSame($players[1]['mgw'],(string)$manualRow['winner_mgw_id'],'Opponent of manual leaver must receive technical bracket win.');
$assertSame('player_left',(string)$manualRow['result_reason'],'Manual leave reason must remain durable.');
$assertSame(1,(int)$db->fetchValue("SELECT COUNT(*) FROM mgw_tournament_technical_outcomes
                                    WHERE tournament_id=:t AND outcome_code='manual_leave'",['t'=>'tour-217-results']),'Manual leave must have one audit row.');

$disconnectGame=$progress->expectedGameId('tour-217-results',1,2,1);
$progress->attachGame('tour-217-results',1,2,1,$disconnectGame,new DateTimeImmutable('2026-09-22T18:00:02Z'));
$disconnectPayload=[
    'id'=>$disconnectGame,'match_source'=>'tournament','tournament_id'=>'tour-217-results',
    'tournament_round_no'=>1,'tournament_pair_no'=>2,'status'=>'finished',
    'player_ids'=>[$players[3]['legacy'],$players[4]['legacy']],
    'winner_id'=>$players[3]['legacy'],'finish_reason'=>'disconnect_timeout','finished_at'=>'2026-09-22T18:01:10Z',
];
$progress->observeFinishedGame($disconnectPayload,new DateTimeImmutable('2026-09-22T18:01:10Z'));
$progress->observeFinishedGame($disconnectPayload,new DateTimeImmutable('2026-09-22T18:01:11Z'));
$assertSame(1,(int)$db->fetchValue('SELECT COUNT(*) FROM mgw_tournament_match_attempts
                                    WHERE tournament_id=:t AND round_no=1 AND pair_no=2',['t'=>'tour-217-results']),'Duplicate observer must not duplicate a technical attempt.');
$assertSame(1,(int)$db->fetchValue("SELECT COUNT(*) FROM mgw_tournament_technical_outcomes
                                    WHERE tournament_id=:t AND round_no=1 AND pair_no=2 AND outcome_code='disconnect_timeout'",['t'=>'tour-217-results']),'Duplicate observer must not duplicate technical audit.');

$ratingSource=file_get_contents($root.'/ratings/PerGameRatingService.php');
$assertTrue(
    is_string($ratingSource)
    && str_contains($ratingSource,"if (\$finishReason !== 'normal_win' && \$finishReason !== 'draw')"),
    'Technical tournament finishes must remain excluded from visible rating points.'
);

// Runtime reconnect contract: one disconnected = 60 seconds; both tournament
// players disconnected = shared 180 seconds. Generic games remain covered by
// the existing MVP-17.4 regression.
$temp=sys_get_temp_dir().'/mgw-mvp21-7-'.bin2hex(random_bytes(6));
if(!mkdir($temp,0700,true)&&!is_dir($temp)) throw new RuntimeException('Unable to create presence temp directory.');
$removeTree=static function(string $path)use(&$removeTree):void{
    if(!is_dir($path)){@unlink($path);return;}
    foreach(scandir($path)?:[] as $item){
        if($item==='.'||$item==='..')continue;
        $removeTree($path.DIRECTORY_SEPARATOR.$item);
    }
    @rmdir($path);
};
$newRuntime=static function(string $gameId,string $a,string $b):array{
    $now=time();
    $user=static fn(string $id,string $game):array=>[
        'id'=>$id,'username'=>$id,UnifiedBalanceRuntimeState::FIELD=>1000,
        'status'=>'playing','current_game_id'=>$game,'active_session_id'=>'session-'.$id,
        'active_session_at'=>now_iso(),'stats'=>['games_played'=>0,'match_games_this_week'=>0,'wins'=>0,'losses'=>0,'draws'=>0],
    ];
    return [
        'users'=>[$a=>$user($a,$gameId),$b=>$user($b,$gameId)],
        'games'=>[$gameId=>[
            'id'=>$gameId,'status'=>'active','launch_phase'=>'active','room'=>'match','game_type'=>'tictactoe',
            'bet'=>0,'player_ids'=>[$a,$b],'turn'=>$a,'turn_started_at'=>gmdate('c',$now-5),
            'turn_deadline_at'=>gmdate('c',$now+55),'turn_deadline_epoch_ms'=>($now+55)*1000,
            'match_source'=>'tournament','tournament_id'=>'runtime-tour',
        ]],
        'transactions'=>[],'system'=>[],
    ];
};

$presence=new PresenceService($temp);
$lifecycle=new ReconnectLifecycleService(['commission_rate'=>0.10,'active_session_timeout_sec'=>180],$presence);
try{
    $runtime=$newRuntime('g-217-both','r1','r2');
    $presence->touch('r1','session-r1','lease-r1');
    $presence->touch('r2','session-r2','lease-r2');
    $prev=$presence->gameplaySnapshot('r1');
    $presence->leave('r1','session-r1','lease-r1');
    $lifecycle->synchronize($runtime,'r1','session-r1','leave',$prev);
    $one=$runtime['games']['g-217-both']['reconnect_v2']['players']['r1'];
    $assertSame(60000,(int)$one['deadline_ms']-(int)$one['disconnected_at_ms'],'Single tournament disconnect must keep 60-second reconnect window.');

    $prev=$presence->gameplaySnapshot('r2');
    $presence->leave('r2','session-r2','lease-r2');
    $lifecycle->synchronize($runtime,'r2','session-r2','leave',$prev);
    $reconnect=$runtime['games']['g-217-both']['reconnect_v2'];
    $assertTrue(!empty($reconnect['tournament_both_disconnect']),'Dual tournament disconnect must enter shared branch.');
    $assertSame(
        180000,
        (int)$reconnect['both_deadline_ms']-(int)$reconnect['both_disconnected_at_ms'],
        'Both tournament players must receive a three-minute shared reconnect window.'
    );
    $assertSame('active',(string)$runtime['games']['g-217-both']['status'],'Both-disconnect branch must not settle immediately.');

    $runtime['games']['g-217-both']['reconnect_v2']['both_deadline_ms']=1;
    foreach($runtime['games']['g-217-both']['reconnect_v2']['players'] as &$playerState){
        $playerState['deadline_ms']=1;
    }
    unset($playerState);
    $lifecycle->synchronize($runtime,'nobody','session-none','status',[]);
    $assertSame('finished',(string)$runtime['games']['g-217-both']['status'],'Expired three-minute branch must finish the runtime game.');
    $assertSame('tournament_both_absent_timeout',(string)$runtime['games']['g-217-both']['finish_reason'],'Both absent after three minutes must have explicit no-winner reason.');
    $assertSame(null,$runtime['games']['g-217-both']['winner_id'],'Both absent after three minutes must not invent a runtime winner.');

    $runtime=$newRuntime('g-217-return','r3','r4');
    $presence->touch('r3','session-r3','lease-r3');
    $presence->touch('r4','session-r4','lease-r4');
    $prev=$presence->gameplaySnapshot('r3');
    $presence->leave('r3','session-r3','lease-r3');
    $lifecycle->synchronize($runtime,'r3','session-r3','leave',$prev);
    $prev=$presence->gameplaySnapshot('r4');
    $presence->leave('r4','session-r4','lease-r4');
    $lifecycle->synchronize($runtime,'r4','session-r4','leave',$prev);

    $prev=$presence->gameplaySnapshot('r3');
    $presence->touch('r3','session-r3-new','lease-r3-new');
    $lifecycle->synchronize($runtime,'r3','session-r3-new','ping',$prev);
    $assertSame('active',(string)$runtime['games']['g-217-return']['status'],'First returning player must keep tournament match active.');
    $remaining=$runtime['games']['g-217-return']['reconnect_v2']['players']??[];
    $assertTrue(!isset($remaining['r3'])&&isset($remaining['r4']),'Returned player must be removed from shared reconnect wait.');

    $runtime['games']['g-217-return']['reconnect_v2']['players']['r4']['deadline_ms']=1;
    $runtime['games']['g-217-return']['reconnect_v2']['both_deadline_ms']=1;
    $lifecycle->synchronize($runtime,'r3','session-r3-new','status',[]);
    $assertSame('finished',(string)$runtime['games']['g-217-return']['status'],'Remaining absent player must lose when shared deadline expires.');
    $assertSame('r3',(string)$runtime['games']['g-217-return']['winner_id'],'Returned participant must receive the technical win.');
    $assertSame('tournament_disconnect_timeout',(string)$runtime['games']['g-217-return']['finish_reason'],'Shared branch one-sided expiry must have schema-safe tournament reason.');

    // Regression from manual Telegram acceptance: both documents can disappear
    // while the JSON reconnect mutation is missed. The first returning ping then
    // owns recovery. It must promote the still-disconnected opponent into the
    // shared 180-second tournament branch before restoring the current player.
    $runtime=$newRuntime('g-217-missed-leaves','r5','r6');
    $presence->touch('r5','session-r5','lease-r5');
    $presence->touch('r6','session-r6','lease-r6');
    $presence->leave('r5','session-r5','lease-r5');
    $presence->leave('r6','session-r6','lease-r6');

    // Real Telegram repro waits about two minutes. The WebView can finish the
    // presence leave write while the reconnect JSON mutation is missed during
    // shutdown. Age both explicit-leave leases beyond the 12-second online
    // handoff grace, but keep them well inside gameplay retention.
    $leftAt=time()-120;
    foreach([
        ['r5','session-r5','lease-r5'],
        ['r6','session-r6','lease-r6'],
    ] as [$playerId,$sessionId,$leaseId]){
        $accountDirectory=$temp.DIRECTORY_SEPARATOR.'account-'.hash('sha256',$playerId);
        $leasePath=$accountDirectory.DIRECTORY_SEPARATOR.'session-'
            .hash('sha256',$sessionId."\0presence:".$leaseId).'.presence';
        file_put_contents($leasePath,json_encode([
            'touched_at'=>$leftAt-4,
            'leave_after'=>$leftAt+12,
            'mode'=>'left',
        ],JSON_UNESCAPED_SLASHES),LOCK_EX);
    }

    $onlineAfterAgedLeaves=$presence->onlineAccountIds();
    $assertTrue(
        !in_array('r5',$onlineAfterAgedLeaves,true)&&!in_array('r6',$onlineAfterAgedLeaves,true),
        'Expired leave grace must not keep departed tournament players publicly online.'
    );
    $staleR5=$presence->gameplaySnapshot('r5');
    $assertSame('disconnected',(string)($staleR5['state']??''),'Two-minute-old explicit leave must remain a gameplay disconnect tombstone.');
    $assertSame($leftAt*1000,(int)($staleR5['disconnected_at_ms']??0),'Gameplay tombstone must preserve the original leave instant for the shared deadline.');

    // Match the real presence.php ordering: capture previous snapshot, touch the
    // new document lease, decide mutation, then synchronize.
    $prev=$presence->gameplaySnapshot('r5');
    $presence->touch('r5','session-r5-new','lease-r5-new');
    $assertTrue(
        $lifecycle->needsMutation($runtime,'r5','session-r5-new','ping',$prev),
        'First returning presence.php ping after two minutes must request reconnect mutation.'
    );
    $lifecycle->synchronize($runtime,'r5','session-r5-new','ping',$prev);
    $reconnect=$runtime['games']['g-217-missed-leaves']['reconnect_v2']??[];
    $assertTrue(!empty($reconnect['tournament_both_disconnect']),'First returning ping must recover a missed dual-disconnect into the shared tournament branch.');
    $assertSame('active',(string)$runtime['games']['g-217-missed-leaves']['status'],'Recovered dual-disconnect must keep the same tournament game active inside the shared window.');
    $remaining=$reconnect['players']??[];
    $assertTrue(!isset($remaining['r5'])&&isset($remaining['r6']),'Returning player must be restored while the still-away opponent remains under the shared deadline.');
    $assertSame(
        180000,
        (int)($reconnect['both_deadline_ms']??0)-(int)($reconnect['both_disconnected_at_ms']??0),
        'Recovered dual-disconnect must own the full three-minute shared window.'
    );
    $remainingSharedMs=(int)($reconnect['both_deadline_ms']??0)-(int)floor(microtime(true)*1000);
    $assertTrue(
        $remainingSharedMs>=55000&&$remainingSharedMs<=65000,
        'Returning after about two minutes must keep only the remaining shared minute instead of resetting a fresh three-minute window.'
    );
    $assertSame('g-217-missed-leaves',(string)$runtime['users']['r5']['current_game_id'],'First returning participant must retain the same tournament game.');

    $prev=$presence->gameplaySnapshot('r6');
    $presence->touch('r6','session-r6-new','lease-r6-new');
    $assertTrue(
        $lifecycle->needsMutation($runtime,'r6','session-r6-new','ping',$prev),
        'Second returning presence.php ping must still enter the existing shared reconnect mutation.'
    );
    $lifecycle->synchronize($runtime,'r6','session-r6-new','ping',$prev);
    $assertTrue(!isset($runtime['games']['g-217-missed-leaves']['reconnect_v2']),'Second return inside the shared window must fully resume the tournament game.');
    $assertSame('active',(string)$runtime['games']['g-217-missed-leaves']['status'],'Both returning players must resume the original active game instead of producing a false terminal result.');
    $assertSame('g-217-missed-leaves',(string)$runtime['users']['r6']['current_game_id'],'Second returning participant must retain the same tournament game.');
    $assertSame('session-r5-new',(string)$runtime['users']['r5']['active_session_id'],'First returning client must keep transferred session ownership after full resume.');
    $assertSame('session-r6-new',(string)$runtime['users']['r6']['active_session_id'],'Second returning client must receive transferred session ownership after full resume.');

    // Real Telegram Desktop can close/minimize the WebView with only the
    // visibility/background signal reaching the server. The final pagehide
    // leave beacon is not guaranteed. A concurrent bootstrap request can also
    // arrive before the dedicated presence ping and used to mask that evidence.
    $runtime=$newRuntime('g-217-background-bootstrap-race','r7','r8');
    $presence->touch('r7','session-r7','lease-r7-old');
    $presence->touch('r8','session-r8','lease-r8-old');
    $presence->background('r7','session-r7','lease-r7-old');
    $presence->background('r8','session-r8','lease-r8-old');

    $backgroundAt=time()-120;
    foreach([
        ['r7','session-r7','lease-r7-old'],
        ['r8','session-r8','lease-r8-old'],
    ] as [$playerId,$sessionId,$leaseId]){
        $accountDirectory=$temp.DIRECTORY_SEPARATOR.'account-'.hash('sha256',$playerId);
        $leasePath=$accountDirectory.DIRECTORY_SEPARATOR.'session-'
            .hash('sha256',$sessionId."\0presence:".$leaseId).'.presence';
        file_put_contents($leasePath,json_encode([
            'touched_at'=>$backgroundAt,
            'leave_after'=>0,
            'mode'=>'background',
        ],JSON_UNESCAPED_SLASHES),LOCK_EX);
    }

    // bootstrap() still owns a legacy empty-lease online touch. It must remain
    // neutral for gameplay and must not erase the stale document lease.
    $presence->touch('r7','session-r7');
    $staleBackgroundR7=$presence->gameplaySnapshot('r7');
    $assertSame('background',(string)($staleBackgroundR7['state']??''),'A lost-pagehide Telegram exit must remain represented as background before reconnect recovery.');
    $assertTrue(!empty($staleBackgroundR7['tournament_disconnect_fallback']),'Two-minute-old background must expose tournament-only disconnect fallback.');
    $assertTrue(
        (int)($staleBackgroundR7['disconnected_at_ms']??0) > 0
        && (int)($staleBackgroundR7['disconnected_at_ms']??0) < (int)floor(microtime(true)*1000),
        'Stale background fallback must retain a past disconnect instant rather than minting a fresh three-minute window.'
    );

    $prev=$presence->gameplaySnapshot('r7');
    $presence->touch('r7','session-r7','lease-r7-new');
    $assertTrue(
        $lifecycle->needsMutation($runtime,'r7','session-r7','ping',$prev),
        'First real presence ping must request mutation even when bootstrap won the network race.'
    );
    $lifecycle->synchronize($runtime,'r7','session-r7','ping',$prev);
    $reconnect=$runtime['games']['g-217-background-bootstrap-race']['reconnect_v2']??[];
    $assertTrue(!empty($reconnect['tournament_both_disconnect']),'Lost pagehide on both Telegram documents must recover into the shared tournament branch.');
    $assertSame('active',(string)$runtime['games']['g-217-background-bootstrap-race']['status'],'Recovered background/bootstrap race must keep the tournament game active.');
    $remaining=$reconnect['players']??[];
    $assertTrue(!isset($remaining['r7'])&&isset($remaining['r8']),'First returning player must resume while the second stale-background player keeps the shared window open.');

    $presence->touch('r8','session-r8');
    $prev=$presence->gameplaySnapshot('r8');
    $presence->touch('r8','session-r8','lease-r8-new');
    $assertTrue(
        $lifecycle->needsMutation($runtime,'r8','session-r8','ping',$prev),
        'Second real presence ping must still enter the recovered shared branch after its own bootstrap touch.'
    );
    $lifecycle->synchronize($runtime,'r8','session-r8','ping',$prev);
    $assertTrue(!isset($runtime['games']['g-217-background-bootstrap-race']['reconnect_v2']),'Both returns after a lost Telegram pagehide must resume the same game.');
    $assertSame('active',(string)$runtime['games']['g-217-background-bootstrap-race']['status'],'Real Telegram background/bootstrap race must not produce a false terminal tournament result.');
    $assertSame('g-217-background-bootstrap-race',(string)$runtime['users']['r7']['current_game_id'],'First player must keep the original tournament game after recovery.');
    $assertSame('g-217-background-bootstrap-race',(string)$runtime['users']['r8']['current_game_id'],'Second player must keep the original tournament game after recovery.');
}finally{
    $removeTree($temp);
}

if($assertions<73) throw new RuntimeException('MVP-21.7 technical-outcome test is too shallow: '.$assertions);
fwrite(STDOUT,"Mvp21_7TournamentTechnicalOutcomesTest: {$assertions} assertions passed\n");
