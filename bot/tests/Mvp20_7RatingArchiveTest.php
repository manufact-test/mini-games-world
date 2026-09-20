<?php
declare(strict_types=1);

$databaseDir = dirname(__DIR__) . '/database';
require $databaseDir . '/DatabaseConnectionInterface.php';
require $databaseDir . '/PdoDatabaseConnection.php';
require $databaseDir . '/DatabaseMigrationInterface.php';
require dirname(__DIR__) . '/accounts/MgwIdGenerator.php';
require dirname(__DIR__) . '/ratings/PerGameRatingService.php';
require dirname(__DIR__) . '/ratings/LeaderboardService.php';
require dirname(__DIR__) . '/ratings/SeasonalAwardService.php';
require dirname(__DIR__) . '/ratings/RatingArchiveService.php';

if (!extension_loaded('pdo_sqlite')) {
    throw new RuntimeException('Mvp20_7RatingArchiveTest requires pdo_sqlite.');
}

$assertions = 0;
$assertSame = static function (mixed $expected, mixed $actual, string $message) use (&$assertions): void {
    $assertions++;
    if ($expected !== $actual) {
        throw new RuntimeException($message . ': expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
};
$assertTrue = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db = new PdoDatabaseConnection($pdo);

$db->execute('CREATE TABLE mgw_users (mgw_id TEXT PRIMARY KEY, status TEXT NOT NULL, nickname TEXT NOT NULL, equipped_avatar_item_id TEXT NULL)');
$db->execute('CREATE TABLE mgw_identities (identity_id INTEGER PRIMARY KEY AUTOINCREMENT, mgw_id TEXT NOT NULL, provider TEXT NOT NULL, provider_subject TEXT NOT NULL)');

(require $databaseDir . '/migrations/20260919_0042_create_per_game_visible_rating.php')->up($db);
(require $databaseDir . '/migrations/20260919_0044_create_leaderboards_and_antifarming.php')->up($db);
(require $databaseDir . '/migrations/20260920_0045_create_quarterly_season_lifecycle.php')->up($db);
(require $databaseDir . '/migrations/20260920_0046_create_seasonal_awards.php')->up($db);

$userA = 'MGW-0000000000000001';
$userB = 'MGW-0000000000000002';
$dev = 'MGW-0000000000000003';
foreach ([[$userA,'Alpha','avatar-a'],[$userB,'Beta','avatar-b'],[$dev,'Dev','avatar-dev']] as [$id,$nickname,$avatar]) {
    $db->execute(
        'INSERT INTO mgw_users (mgw_id,status,nickname,equipped_avatar_item_id) VALUES (:id,:status,:nickname,:avatar)',
        ['id'=>$id,'status'=>'active','nickname'=>$nickname,'avatar'=>$avatar]
    );
}
$db->execute(
    'INSERT INTO mgw_identities (mgw_id,provider,provider_subject) VALUES (:id,:provider,:subject)',
    ['id'=>$dev,'provider'=>'development','subject'=>'mvp20_7_test']
);

$seasons = [
    ['2026-q2',2026,2,'2026-03-31 21:00:00.000000','2026-06-30 21:00:00.000000','closed'],
    ['2026-q3',2026,3,'2026-06-30 21:00:00.000000','2026-09-30 21:00:00.000000','closed'],
    ['2026-q4',2026,4,'2026-09-30 21:00:00.000000','2026-12-31 21:00:00.000000','active'],
];
foreach ($seasons as [$id,$year,$quarter,$start,$end,$state]) {
    $db->execute(
        'INSERT INTO mgw_rating_seasons (
            season_id,calendar_year,quarter,timezone,calendar_start_at_utc,calendar_end_at_utc,
            official_start_at_utc,season_state,finalization_reason,standings_frozen_at_utc,
            finalization_started_at_utc,finalized_at_utc,created_at_utc,updated_at_utc
         ) VALUES (
            :id,:year,:quarter,:timezone,:start,:end,:start,:state,NULL,:frozen,NULL,:finalized,:created,:updated
         )',
        [
            'id'=>$id,'year'=>$year,'quarter'=>$quarter,'timezone'=>'Europe/Moscow',
            'start'=>$start,'end'=>$end,'state'=>$state,
            'frozen'=>$state === 'closed' ? $end : null,
            'finalized'=>$state === 'closed' ? $end : null,
            'created'=>$start,'updated'=>$end,
        ]
    );
}

$db->execute(
    "UPDATE mgw_rating_control
     SET competition_state='active', current_season_id='2026-q4',
         activated_at_utc='2026-10-01 00:00:00.000000',
         updated_at_utc='2026-10-01 00:00:00.000000'
     WHERE control_key='global'"
);

$addParticipation = static function (
    DatabaseConnectionInterface $db,
    string $season,
    string $game,
    string $mgwId,
    string $prefix,
    int $matches,
    int $wins
): void {
    for ($i=1; $i <= $matches; $i++) {
        $result = $i <= $wins ? 'win' : 'loss';
        $db->execute(
            'INSERT INTO mgw_game_rating_participation (
                match_id,mgw_id,season_id,game_type,opponent_mgw_id,result_code,
                points_awarded,rating_day_moscow,match_started_at_utc,match_finished_at_utc,created_at_utc
             ) VALUES (
                :match_id,:mgw_id,:season_id,:game_type,:opponent,:result_code,
                :points,:day,:started,:finished,:created
             )',
            [
                'match_id'=>$prefix . '-' . $i,
                'mgw_id'=>$mgwId,
                'season_id'=>$season,
                'game_type'=>$game,
                'opponent'=>'MGW-9999999999999999',
                'result_code'=>$result,
                'points'=>$result === 'win' ? 1 : 0,
                'day'=>'2026-01-01',
                'started'=>'2026-01-01 10:00:00.000000',
                'finished'=>'2026-01-01 10:01:00.000000',
                'created'=>'2026-01-01 10:01:00.000000',
            ]
        );
    }
};
$addScore = static function (DatabaseConnectionInterface $db, string $season, string $game, string $id, int $points, int $wins): void {
    $db->execute(
        'INSERT INTO mgw_game_rating_scores (season_id,mgw_id,game_type,points,rated_wins,updated_at_utc)
         VALUES (:season,:id,:game,:points,:wins,:updated)',
        ['season'=>$season,'id'=>$id,'game'=>$game,'points'=>$points,'wins'=>$wins,'updated'=>'2026-01-01 12:00:00.000000']
    );
};
$addAward = static function (DatabaseConnectionInterface $db, string $season, string $game, string $id, int $rank, string $tier): void {
    $db->execute(
        'INSERT INTO mgw_season_awards (
            season_id,game_type,mgw_id,rank_position,badge_tier,frame_place,frame_target_season_id,
            frame_valid_from_at_utc,frame_valid_until_at_utc,award_state,revision,granted_at_utc,revoked_at_utc,updated_at_utc
         ) VALUES (
            :season,:game,:id,:rank,:tier,:frame_place,NULL,NULL,NULL,:state,1,:granted,NULL,:updated
         )',
        [
            'season'=>$season,'game'=>$game,'id'=>$id,'rank'=>$rank,'tier'=>$tier,
            'frame_place'=>$rank <= 3 ? $rank : null,'state'=>'active',
            'granted'=>'2026-10-01 00:00:00.000000','updated'=>'2026-10-01 00:00:00.000000',
        ]
    );
};

$addParticipation($db,'2026-q2','tictactoe',$userA,'q2-a',5,1);
$addScore($db,'2026-q2','tictactoe',$userA,4,1);
$addAward($db,'2026-q2','tictactoe',$userA,1,'gold');

$addParticipation($db,'2026-q3','tictactoe',$userA,'q3-a',5,1);
$addParticipation($db,'2026-q3','tictactoe',$userB,'q3-b',6,2);
$addParticipation($db,'2026-q3','tictactoe',$dev,'q3-dev',8,4);
$addScore($db,'2026-q3','tictactoe',$userA,10,1);
$addScore($db,'2026-q3','tictactoe',$userB,8,2);
$addScore($db,'2026-q3','tictactoe',$dev,50,4);
$addAward($db,'2026-q3','tictactoe',$userA,1,'gold');
$addAward($db,'2026-q3','tictactoe',$userB,2,'silver');
$addAward($db,'2026-q3','tictactoe',$dev,3,'silver');

$addParticipation($db,'2026-q4','tictactoe',$userA,'q4-a',2,1);
$addScore($db,'2026-q4','tictactoe',$userA,2,1);

$service = new RatingArchiveService($db);

$profile = $service->profileSnapshot($userA);
$assertSame('2026-q4', $profile['current_season_id'], 'Profile archive must expose current season id.');
$assertSame(2, $profile['current_cards']['tictactoe']['rated_matches'], 'Current season card must use canonical human participation rows.');
$assertSame(1, $profile['current_cards']['tictactoe']['human_wins'], 'Current season card must count human wins.');
$assertSame(false, $profile['current_cards']['tictactoe']['eligible'], 'Two matches must remain below leaderboard eligibility.');
$assertSame(3, $profile['official_human_wins_all_seasons'], 'Official all-season wins must aggregate official season participation only.');
$assertSame(2, count($profile['previous_seasons']), 'Profile history must include the two closed seasons with player activity.');
$assertSame('2026-q3', $profile['previous_seasons'][0]['season_id'], 'Previous seasons must be newest first.');
$assertSame(1, $profile['previous_seasons'][0]['games']['tictactoe']['rank'], 'Historical personal season card must expose durable awarded rank.');

$overview = $service->publicOverview();
$assertSame(2, count($overview['seasons']), 'Public archive catalog must contain closed official seasons only.');
$assertSame(false, $overview['tournaments']['available'], 'Tournament archive must stay reserved until official tournaments exist.');
$assertSame(2, count($overview['hall_of_fame']), 'Hall of Fame must exclude technical development identities.');
$assertSame('Alpha', $overview['hall_of_fame'][0]['nickname'], 'Hall of Fame must expose canonical MGW nickname.');

$archive = $service->seasonArchive('2026-q3','tictactoe');
$assertSame(2, count($archive['entries']), 'Top-100 archive must exclude development identities and keep eligible real players.');
$assertSame('Alpha', $archive['entries'][0]['nickname'], 'Archived ranking must preserve canonical tie/ranking owner ordering.');
$assertSame(10, $archive['entries'][0]['points'], 'Archived top-100 must expose frozen season points.');
$assertSame(2, count($archive['top3']), 'Top3 archive is the leading slice of the same canonical top-100 board.');

$thrown = false;
try { $service->seasonArchive('2026-q4','tictactoe'); } catch (InvalidArgumentException) { $thrown = true; }
$assertTrue($thrown, 'Active season must never masquerade as a frozen archive.');

$source = file_get_contents(dirname(__DIR__) . '/ratings/RatingArchiveService.php');
$assertTrue(is_string($source) && str_contains($source, 'standingsForSeason'), 'Archive top-100 must delegate ranking to LeaderboardService.');
$assertTrue(is_string($source) && !str_contains($source, 'INSERT INTO mgw_game_rating_scores'), 'Rating archive owner must remain read-only.');

fwrite(STDOUT, "Mvp20_7RatingArchiveTest: {$assertions} assertions passed\n");
