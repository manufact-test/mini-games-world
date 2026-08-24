<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$databaseDir = $root . '/database';
require $databaseDir . '/DatabaseConnectionInterface.php';
require $databaseDir . '/PdoDatabaseConnection.php';
require $databaseDir . '/DatabaseMigrationInterface.php';
require $databaseDir . '/MigrationRepository.php';
require $databaseDir . '/MigrationRunner.php';
require $root . '/social/FriendGraphService.php';
require $root . '/social/SocialPlayerProfileReader.php';

if (!extension_loaded('pdo_sqlite')) throw new RuntimeException('SocialPlayerProfileReaderTest requires pdo_sqlite.');

$assertions = 0;
$assertSame = static function (mixed $expected, mixed $actual, string $message) use (&$assertions): void {
    $assertions++;
    if ($expected !== $actual) throw new RuntimeException($message . ': expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
};
$assertTrue = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('PRAGMA foreign_keys = ON');
$database = new PdoDatabaseConnection($pdo);
$runner = new MigrationRunner($database, $databaseDir . '/migrations');
$expectedMigrations = count(glob($databaseDir . '/migrations/*.php') ?: []);
$assertSame($expectedMigrations, $runner->migrate(false)['executed_count'], 'Reader fixture must apply current schema');

$mgwId = 'MGW-00000000000000AA';
$database->execute(
    'INSERT INTO mgw_users (mgw_id,status,nickname,display_name,username,equipped_avatar_item_id,created_at_utc,updated_at_utc,last_seen_at_utc)
     VALUES (:id,:status,:nickname,:display_name,:username,:avatar,:created,:updated,:seen)',
    [
        'id'=>$mgwId, 'status'=>'active', 'nickname'=>'PublicHero',
        'display_name'=>'Private Provider Name', 'username'=>'provider_private',
        'avatar'=>'starter-default-02', 'created'=>'2026-08-01 10:00:00.000000',
        'updated'=>'2026-08-19 10:00:00.000000', 'seen'=>'2026-08-19 10:00:00.000000',
    ]
);

$insertMatch = static function (DatabaseConnectionInterface $db, string $id, string $game, string $result, int $index) use ($mgwId): void {
    $time = sprintf('2026-08-19 11:%02d:00.000000', $index);
    $db->execute(
        'INSERT INTO mgw_matches (match_id,game_type,room,status,board_size,bet,state_version,created_at_utc,started_at_utc,updated_at_utc,finished_at_utc)
         VALUES (:id,:game,:room,:status,3,0,1,:created,:started,:updated,:finished)',
        ['id'=>$id,'game'=>$game,'room'=>'normal','status'=>'finished','created'=>$time,'started'=>$time,'updated'=>$time,'finished'=>$time]
    );
    $db->execute(
        'INSERT INTO mgw_match_players (match_id,seat,player_ref,mgw_id,player_type,display_name,result,joined_at_utc,updated_at_utc)
         VALUES (:match,1,:ref,:mgw,:type,:name,:result,:joined,:updated)',
        ['match'=>$id,'ref'=>'mgw:'.$mgwId,'mgw'=>$mgwId,'type'=>'human','name'=>'stale-name','result'=>$result,'joined'=>$time,'updated'=>$time]
    );
};

$insertMatch($database, 'social_profile_win', 'tictactoe', 'win', 1);
$insertMatch($database, 'social_profile_loss', 'tictactoe', 'loss', 2);
$insertMatch($database, 'social_profile_draw', 'chess', 'draw', 3);
$insertMatch($database, 'social_profile_unknown', 'go', '', 4);

$profile = (new SocialPlayerProfileReader($database))->read($mgwId);
$assertSame('PublicHero', $profile['nickname'] ?? null, 'Reader must use canonical MGW nickname');
$assertSame('PublicHero', $profile['display_name'] ?? null, 'Public display must equal canonical nickname');
$assertSame('starter-default-02', $profile['avatar']['item_id'] ?? null, 'Reader must use MGW-owned avatar');
$assertTrue(!array_key_exists('username', $profile), 'Reader must not expose provider username');
$assertTrue(!str_contains(json_encode($profile, JSON_THROW_ON_ERROR), 'Private Provider Name'), 'Reader must not expose provider display name');
$assertSame(4, $profile['stats']['games_played'] ?? null, 'All finished human matches count as played');
$assertSame(1, $profile['stats']['wins'] ?? null, 'Win aggregation must be deterministic');
$assertSame(1, $profile['stats']['losses'] ?? null, 'Loss aggregation must be deterministic');
$assertSame(1, $profile['stats']['draws'] ?? null, 'Draw aggregation must be deterministic');
$assertSame(2, $profile['stats']['by_game']['tictactoe']['games_played'] ?? null, 'Per-game totals must use existing match owner');
$assertSame(1, $profile['stats']['by_game']['chess']['draws'] ?? null, 'Per-game draw must be exposed');
$assertSame(1, $profile['stats']['by_game']['go']['games_played'] ?? null, 'Unknown result still counts as played without inventing outcome');

fwrite(STDOUT, 'PASS: ' . $assertions . " assertions\n");
