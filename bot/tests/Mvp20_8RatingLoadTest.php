<?php
declare(strict_types=1);

$databaseDir = dirname(__DIR__) . '/database';
require $databaseDir . '/DatabaseConnectionInterface.php';
require $databaseDir . '/PdoDatabaseConnection.php';
require $databaseDir . '/DatabaseMigrationInterface.php';
require dirname(__DIR__) . '/accounts/MgwIdGenerator.php';
require dirname(__DIR__) . '/ratings/PerGameRatingService.php';
require dirname(__DIR__) . '/ratings/LeaderboardService.php';

if (!extension_loaded('pdo_sqlite')) {
    throw new RuntimeException('Mvp20_8RatingLoadTest requires pdo_sqlite.');
}

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db = new PdoDatabaseConnection($pdo);

$db->execute('CREATE TABLE mgw_users (
    mgw_id TEXT PRIMARY KEY,
    status TEXT NOT NULL,
    nickname TEXT NOT NULL,
    equipped_avatar_item_id TEXT NULL
)');
$db->execute('CREATE TABLE mgw_identities (
    identity_id INTEGER PRIMARY KEY AUTOINCREMENT,
    mgw_id TEXT NOT NULL,
    provider TEXT NOT NULL,
    provider_subject TEXT NOT NULL
)');
$db->execute('CREATE TABLE mgw_matches (
    match_id TEXT NOT NULL PRIMARY KEY,
    game_type TEXT NOT NULL,
    status TEXT NOT NULL,
    match_source TEXT NULL,
    winner_player_ref TEXT NULL,
    finish_reason TEXT NULL,
    started_at_utc TEXT NULL,
    finished_at_utc TEXT NULL
)');
$db->execute('CREATE TABLE mgw_match_players (
    match_id TEXT NOT NULL,
    seat INTEGER NOT NULL,
    player_ref TEXT NOT NULL,
    mgw_id TEXT NULL,
    player_type TEXT NOT NULL,
    PRIMARY KEY (match_id, seat)
)');

(require $databaseDir . '/migrations/20260919_0042_create_per_game_visible_rating.php')->up($db);
(require $databaseDir . '/migrations/20260919_0044_create_leaderboards_and_antifarming.php')->up($db);

$players = 500;
$games = LeaderboardService::gameTypes();
$started = microtime(true);

$db->transaction(function (DatabaseConnectionInterface $database) use ($players, $games): void {
    for ($i=1; $i<=$players; $i++) {
        $mgwId = sprintf('MGW-%016X', $i);
        $database->execute(
            'INSERT INTO mgw_users (mgw_id,status,nickname,equipped_avatar_item_id)
             VALUES (:id,:status,:nickname,:avatar)',
            [
                'id'=>$mgwId,
                'status'=>'active',
                'nickname'=>'LoadPlayer' . $i,
                'avatar'=>'starter-default-01',
            ]
        );
        foreach ($games as $gameIndex=>$gameType) {
            $points = 100000 - ($i * 10) - $gameIndex;
            $database->execute(
                'INSERT INTO mgw_game_rating_scores (
                    season_id,mgw_id,game_type,points,rated_wins,updated_at_utc
                 ) VALUES (
                    :season,:id,:game,:points,:wins,:updated
                 )',
                [
                    'season'=>'load-q1','id'=>$mgwId,'game'=>$gameType,
                    'points'=>$points,'wins'=>1,'updated'=>'2026-01-20 00:00:00.000000',
                ]
            );
            for ($m=1; $m<=5; $m++) {
                $database->execute(
                    'INSERT INTO mgw_game_rating_participation (
                        match_id,mgw_id,season_id,game_type,opponent_mgw_id,result_code,
                        points_awarded,rating_day_moscow,match_started_at_utc,
                        match_finished_at_utc,created_at_utc
                     ) VALUES (
                        :match,:id,:season,:game,:opponent,:result,
                        :points,:day,:started,:finished,:created
                     )',
                    [
                        'match'=>"load-{$gameIndex}-{$i}-{$m}",
                        'id'=>$mgwId,
                        'season'=>'load-q1',
                        'game'=>$gameType,
                        'opponent'=>sprintf('MGW-%016X', (($i % $players) + 1)),
                        'result'=>$m === 1 ? 'win' : 'loss',
                        'points'=>$m === 1 ? 1 : 0,
                        'day'=>'2026-01-20',
                        'started'=>'2026-01-20 10:00:00.000000',
                        'finished'=>'2026-01-20 10:01:00.000000',
                        'created'=>'2026-01-20 10:01:00.000000',
                    ]
                );
            }
        }
    }
});

$seedSeconds = microtime(true) - $started;
$service = new LeaderboardService($db);
$queryStarted = microtime(true);
$totalRows = 0;
foreach ($games as $gameType) {
    $board = $service->standingsForSeason('load-q1', $gameType, 100);
    if (count($board) !== 100) {
        throw new RuntimeException('Load test top100 size mismatch for ' . $gameType . '.');
    }
    if (($board[0]['rank'] ?? null) !== 1) {
        throw new RuntimeException('Load test rank owner mismatch for ' . $gameType . '.');
    }
    $totalRows += count($board);
}
$excluded = [];
for ($i=1; $i<=50; $i++) $excluded[] = sprintf('MGW-%016X', $i);
$excludedBoard = $service->standingsForSeason('load-q1', 'tictactoe', 100, $excluded);
if (($excludedBoard[0]['mgw_id'] ?? '') !== sprintf('MGW-%016X', 51)) {
    throw new RuntimeException('Load test exclusion shift did not preserve deterministic ordering.');
}
$querySeconds = microtime(true) - $queryStarted;
$totalSeconds = microtime(true) - $started;

if ($querySeconds > 5.0) {
    throw new RuntimeException(sprintf('MVP-20.8 leaderboard load query budget exceeded: %.3fs', $querySeconds));
}
if ($totalSeconds > 15.0) {
    throw new RuntimeException(sprintf('MVP-20.8 focused load budget exceeded: %.3fs', $totalSeconds));
}

fwrite(
    STDOUT,
    sprintf(
        "Mvp20_8RatingLoadTest: %d players, %d games, %d returned rows; seed %.3fs; queries %.3fs; total %.3fs\n",
        $players,
        count($games),
        $totalRows,
        $seedSeconds,
        $querySeconds,
        $totalSeconds
    )
);
