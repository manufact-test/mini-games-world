<?php
declare(strict_types=1);

$root=dirname(__DIR__);
require $root.'/database/DatabaseConnectionInterface.php';
require $root.'/database/PdoDatabaseConnection.php';
require $root.'/accounts/MgwIdGenerator.php';
require $root.'/tournaments/TournamentRewardProjectionService.php';

$mysqlHost=trim((string)getenv('MGW_TEST_MYSQL_HOST'));
$isMysql=$mysqlHost!=='';
if($isMysql){
    if(!extension_loaded('pdo_mysql')) throw new RuntimeException('Projection test requires pdo_mysql in MySQL mode.');
    $mysqlPort=trim((string)getenv('MGW_TEST_MYSQL_PORT'))?:'3306';
    $mysqlUser=trim((string)getenv('MGW_TEST_MYSQL_USER'))?:'root';
    $mysqlPassword=(string)getenv('MGW_TEST_MYSQL_PASSWORD');
    $schema='mgw_projection_test';
    $server=new PDO(
        'mysql:host='.$mysqlHost.';port='.$mysqlPort.';charset=utf8mb4',
        $mysqlUser,
        $mysqlPassword,
        [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]
    );
    $server->exec('DROP DATABASE IF EXISTS '.$schema);
    $server->exec('CREATE DATABASE '.$schema.' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    $pdo=new PDO(
        'mysql:host='.$mysqlHost.';port='.$mysqlPort.';dbname='.$schema.';charset=utf8mb4',
        $mysqlUser,
        $mysqlPassword,
        [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]
    );
}else{
    if(!extension_loaded('pdo_sqlite')) throw new RuntimeException('Projection test requires pdo_sqlite.');
    $pdo=new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
}
$db=new PdoDatabaseConnection($pdo);

if($isMysql){
    $db->execute('CREATE TABLE mgw_users (
        mgw_id VARCHAR(24) PRIMARY KEY,nickname VARCHAR(160) NULL,display_name VARCHAR(160) NULL,
        equipped_avatar_item_id VARCHAR(128) NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    $db->execute('CREATE TABLE mgw_tournaments (
        tournament_id VARCHAR(64) PRIMARY KEY,title VARCHAR(160) NOT NULL,game_type VARCHAR(32) NOT NULL,
        capacity INT NOT NULL,scheduled_start_at_utc DATETIME(6) NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    $db->execute('CREATE TABLE mgw_tournament_results (
        tournament_id VARCHAR(64) NOT NULL,mgw_id VARCHAR(24) NOT NULL,placement INT NULL,result_code VARCHAR(32) NOT NULL,
        entry_amount BIGINT NOT NULL,entry_return_amount BIGINT NOT NULL,prize_amount BIGINT NOT NULL,payout_amount BIGINT NOT NULL,
        reward_eligible TINYINT NOT NULL,settled_at_utc DATETIME(6) NOT NULL,
        PRIMARY KEY(tournament_id,mgw_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    $db->execute('CREATE TABLE mgw_tournament_reward_entitlements (
        tournament_id VARCHAR(64) NOT NULL,mgw_id VARCHAR(24) NOT NULL,reward_code VARCHAR(64) NOT NULL,reward_kind VARCHAR(32) NOT NULL,
        valid_from_at_utc DATETIME(6) NULL,valid_until_at_utc DATETIME(6) NULL,granted_at_utc DATETIME(6) NOT NULL,
        PRIMARY KEY(tournament_id,mgw_id,reward_code)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    $db->execute('CREATE TABLE mgw_tournament_golden_tickets (
        mgw_id VARCHAR(24) PRIMARY KEY,ticket_state VARCHAR(16) NOT NULL,championship_count INT NOT NULL,
        first_tournament_id VARCHAR(64) NOT NULL,last_tournament_id VARCHAR(64) NOT NULL,
        first_awarded_at_utc DATETIME(6) NOT NULL,last_awarded_at_utc DATETIME(6) NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
}else{
    $db->execute('CREATE TABLE mgw_users (
        mgw_id TEXT PRIMARY KEY,nickname TEXT NULL,display_name TEXT NULL,equipped_avatar_item_id TEXT NULL
    )');
    $db->execute('CREATE TABLE mgw_tournaments (
        tournament_id TEXT PRIMARY KEY,title TEXT NOT NULL,game_type TEXT NOT NULL,capacity INTEGER NOT NULL,
        scheduled_start_at_utc TEXT NULL
    )');
    $db->execute('CREATE TABLE mgw_tournament_results (
        tournament_id TEXT NOT NULL,mgw_id TEXT NOT NULL,placement INTEGER NULL,result_code TEXT NOT NULL,
        entry_amount INTEGER NOT NULL,entry_return_amount INTEGER NOT NULL,prize_amount INTEGER NOT NULL,payout_amount INTEGER NOT NULL,
        reward_eligible INTEGER NOT NULL,settled_at_utc TEXT NOT NULL,PRIMARY KEY(tournament_id,mgw_id)
    )');
    $db->execute('CREATE TABLE mgw_tournament_reward_entitlements (
        tournament_id TEXT NOT NULL,mgw_id TEXT NOT NULL,reward_code TEXT NOT NULL,reward_kind TEXT NOT NULL,
        valid_from_at_utc TEXT NULL,valid_until_at_utc TEXT NULL,granted_at_utc TEXT NOT NULL,
        PRIMARY KEY(tournament_id,mgw_id,reward_code)
    )');
    $db->execute('CREATE TABLE mgw_tournament_golden_tickets (
        mgw_id TEXT PRIMARY KEY,ticket_state TEXT NOT NULL,championship_count INTEGER NOT NULL,
        first_tournament_id TEXT NOT NULL,last_tournament_id TEXT NOT NULL,
        first_awarded_at_utc TEXT NOT NULL,last_awarded_at_utc TEXT NOT NULL
    )');
}

$assertions=0;
$assertSame=static function(mixed $expected,mixed $actual,string $message)use(&$assertions):void{
    $assertions++;
    if($expected!==$actual) throw new RuntimeException($message.': expected '.var_export($expected,true).', got '.var_export($actual,true));
};
$assertTrue=static function(bool $condition,string $message)use(&$assertions):void{
    $assertions++;
    if(!$condition) throw new RuntimeException($message);
};

$players=[
    1=>['id'=>'MGW-0000000000000001','name'=>'Alpha','avatar'=>'avatar-a'],
    2=>['id'=>'MGW-0000000000000002','name'=>'Beta','avatar'=>'avatar-b'],
    3=>['id'=>'MGW-0000000000000003','name'=>'Gamma','avatar'=>'avatar-c'],
    4=>['id'=>'MGW-0000000000000004','name'=>'Fixture','avatar'=>'avatar-d'],
];
foreach($players as $p){
    $db->execute(
        'INSERT INTO mgw_users (mgw_id,nickname,display_name,equipped_avatar_item_id)
         VALUES (:id,:nickname,:display_name,:avatar)',
        ['id'=>$p['id'],'nickname'=>$p['name'],'display_name'=>$p['name'],'avatar'=>$p['avatar']]
    );
}
foreach([
    ['tour-old','Весенний кубок','chess',8,'2026-07-01 18:00:00.000000'],
    ['tour-new','Осенний кубок','tictactoe',8,'2026-09-20 18:00:00.000000'],
] as [$id,$title,$game,$capacity,$scheduled]){
    $db->execute(
        'INSERT INTO mgw_tournaments (tournament_id,title,game_type,capacity,scheduled_start_at_utc)
         VALUES (:id,:title,:game,:capacity,:scheduled)',
        ['id'=>$id,'title'=>$title,'game'=>$game,'capacity'=>$capacity,'scheduled'=>$scheduled]
    );
}

$insertResult=static function(
    PdoDatabaseConnection $db,string $tour,array $player,int $placement,bool $eligible,string $settled
):void{
    $payout=$placement===1?200000:($placement===2?80000:($placement===3?50000:0));
    $return=$placement<=3?50000:0;
    $prize=max(0,$payout-$return);
    $code=match($placement){1=>'champion',2=>'runner_up',3=>'third_place',4=>'fourth_place',default=>'participant'};
    $db->execute(
        'INSERT INTO mgw_tournament_results (
            tournament_id,mgw_id,placement,result_code,entry_amount,entry_return_amount,
            prize_amount,payout_amount,reward_eligible,settled_at_utc
         ) VALUES (
            :tour,:mgw,:placement,:code,50000,:entry_return,:prize,:payout,:eligible,:settled
         )',
        [
            'tour'=>$tour,'mgw'=>$player['id'],'placement'=>$placement,'code'=>$code,
            'entry_return'=>$eligible?$return:0,'prize'=>$eligible?$prize:0,'payout'=>$eligible?$payout:0,
            'eligible'=>$eligible?1:0,'settled'=>$settled,
        ]
    );
};
$insertResult($db,'tour-old',$players[1],1,true,'2026-07-01 19:00:00.000000');
$insertResult($db,'tour-new',$players[1],1,true,'2026-09-20 19:00:00.000000');
$insertResult($db,'tour-new',$players[2],2,true,'2026-09-20 19:00:00.000000');
$insertResult($db,'tour-new',$players[3],3,true,'2026-09-20 19:00:00.000000');
$insertResult($db,'tour-new',$players[4],4,false,'2026-09-20 19:00:00.000000');

$grant=static function(
    PdoDatabaseConnection $db,string $tour,string $mgw,string $code,string $kind,
    ?string $from,?string $until,string $granted
):void{
    $db->execute(
        'INSERT INTO mgw_tournament_reward_entitlements (
            tournament_id,mgw_id,reward_code,reward_kind,valid_from_at_utc,valid_until_at_utc,granted_at_utc
         ) VALUES (:tour,:mgw,:code,:kind,:from_at,:until_at,:granted)',
        ['tour'=>$tour,'mgw'=>$mgw,'code'=>$code,'kind'=>$kind,'from_at'=>$from,'until_at'=>$until,'granted'=>$granted]
    );
};
$grant($db,'tour-old',$players[1]['id'],'champion_crown','temporary_style','2026-07-01 19:00:00.000000','2026-07-31 19:00:00.000000','2026-07-01 19:00:00.000000');
$grant($db,'tour-old',$players[1]['id'],'cup_gold','permanent_achievement','2026-07-01 19:00:00.000000',null,'2026-07-01 19:00:00.000000');
$grant($db,'tour-old',$players[1]['id'],'hall_of_fame','permanent_achievement','2026-07-01 19:00:00.000000',null,'2026-07-01 19:00:00.000000');
foreach([
    ['golden_ticket','permanent_achievement',null],
    ['champion_crown','temporary_style','2026-10-20 19:00:00.000000'],
    ['winner_badge','permanent_achievement',null],
    ['champion_cosmetics','permanent_achievement',null],
    ['hall_of_fame','permanent_achievement',null],
    ['cup_gold','permanent_achievement',null],
] as [$code,$kind,$until]){
    $grant(
        $db,'tour-new',$players[1]['id'],$code,$kind,'2026-09-20 19:00:00.000000',
        $until,'2026-09-20 19:00:00.000000'
    );
}
foreach([
    [$players[2]['id'],'silver_frame','temporary_style','2026-10-20 19:00:00.000000'],
    [$players[2]['id'],'finalist_result','permanent_achievement',null],
    [$players[2]['id'],'cup_silver','permanent_achievement',null],
    [$players[3]['id'],'bronze_mark','temporary_style','2026-10-20 19:00:00.000000'],
    [$players[3]['id'],'third_place_result','permanent_achievement',null],
    [$players[3]['id'],'cup_bronze','permanent_achievement',null],
] as [$mgw,$code,$kind,$until]){
    $grant($db,'tour-new',$mgw,$code,$kind,'2026-09-20 19:00:00.000000',$until,'2026-09-20 19:00:00.000000');
}

$db->execute(
    'INSERT INTO mgw_tournament_golden_tickets (
        mgw_id,ticket_state,championship_count,first_tournament_id,last_tournament_id,
        first_awarded_at_utc,last_awarded_at_utc
     ) VALUES (:mgw,:state,2,:first,:last,:first_at,:last_at)',
    [
        'mgw'=>$players[1]['id'],'state'=>'active','first'=>'tour-old','last'=>'tour-new',
        'first_at'=>'2026-07-01 19:00:00.000000','last_at'=>'2026-09-20 19:00:00.000000',
    ]
);

$service=new TournamentRewardProjectionService($db);
$profile=$service->profileSnapshot(
    $players[1]['id'],
    new DateTimeImmutable('2026-09-22T20:00:00Z')
);

$assertSame(true,$profile['available'],'Champion profile must expose tournament honors.');
$assertSame(true,$profile['golden_ticket']['valid'],'Golden Ticket must be visible as valid.');
$assertSame(2,$profile['golden_ticket']['championship_count'],'Repeat championship count must be projected.');
$assertSame(false,$profile['golden_ticket']['sellable'],'Golden Ticket must never become sellable.');
$assertSame(false,$profile['golden_ticket']['transferable'],'Golden Ticket must never become transferable.');
$assertSame(2,$profile['summary']['tournaments'],'Profile tournament count must use all durable results.');
$assertSame(2,$profile['summary']['podiums'],'Champion podium count must be durable.');
$assertSame(2,$profile['summary']['championships'],'Champion win count must follow Golden Ticket state.');
$assertSame(1,count($profile['active_temporary']),'Only the unexpired crown must be active.');
$assertSame('champion_crown',$profile['active_temporary'][0]['reward_code'],'Active temporary reward must be the new crown.');
$assertSame(true,$profile['active_temporary'][0]['active'],'Current crown must be active.');
$assertSame('tour-new',$profile['history'][0]['tournament_id'],'Profile history must be newest first.');
$assertSame(1,$profile['history'][0]['placement'],'Profile history must expose permanent placement.');
$assertSame('tictactoe',$profile['history'][0]['game_type'],'Profile history must expose tournament game.');
$assertSame('2026-09-20 18:00:00.000000',$profile['history'][0]['scheduled_start_at_utc'],'Profile history must expose tournament date.');
$assertTrue(count($profile['permanent_achievements'])>=7,'Permanent tournament achievements must persist across tournaments.');

$archive=$service->publicArchive();
$assertSame(true,$archive['available'],'Settled tournaments must activate the public tournament archive.');
$assertSame(2,count($archive['entries']),'Both settled tournaments must appear in archive.');
$assertSame('tour-new',$archive['entries'][0]['tournament_id'],'Tournament archive must be newest first.');
$assertSame(3,count($archive['entries'][0]['top3']),'Current tournament archive must expose reward-eligible top3.');
$assertSame('Alpha',$archive['entries'][0]['top3'][0]['nickname'],'Archive champion must expose canonical nickname.');
$assertSame(1,$archive['entries'][0]['top3'][0]['placement'],'Archive top3 must preserve placement.');
$assertTrue(
    !in_array('Fixture',array_column($archive['entries'][0]['top3'],'nickname'),true),
    'Synthetic/reward-ineligible participant must not enter public podium.'
);
$assertSame(2,count($archive['hall_of_fame']),'Each permanent champion result must appear in tournament Hall of Fame.');
$assertSame('Alpha',$archive['hall_of_fame'][0]['nickname'],'Tournament Hall of Fame must expose champion.');
$assertSame(2,$archive['hall_of_fame'][0]['championship_count'],'Hall of Fame must expose repeat championship count.');

$source=file_get_contents($root.'/tournaments/TournamentRewardProjectionService.php');
$assertTrue(is_string($source),'Projection source must be readable.');
$assertTrue(!str_contains((string)$source,'INSERT INTO'),'Projection owner must remain read-only.');
$assertTrue(!str_contains((string)$source,'UPDATE '),'Projection owner must remain read-only.');
$assertTrue(!str_contains((string)$source,'DELETE FROM'),'Projection owner must remain read-only.');

if($assertions<27) throw new RuntimeException('MVP-21.9 projection test is too shallow: '.$assertions);
fwrite(STDOUT,"Mvp21_9TournamentRewardProjectionTest: {$assertions} assertions passed\n");
