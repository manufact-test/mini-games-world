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
    throw new RuntimeException('Mvp20_3LeaderboardsAntiFarmingTest requires pdo_sqlite.');
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
$database = new PdoDatabaseConnection($pdo);

$database->execute(<<<'SQL'
CREATE TABLE mgw_users (
    mgw_id TEXT NOT NULL PRIMARY KEY,
    status TEXT NOT NULL,
    nickname TEXT NOT NULL,
    equipped_avatar_item_id TEXT NULL
)
SQL);
$database->execute(<<<'SQL'
CREATE TABLE mgw_identities (
    identity_id INTEGER PRIMARY KEY AUTOINCREMENT,
    mgw_id TEXT NOT NULL,
    provider TEXT NOT NULL,
    provider_subject TEXT NOT NULL
)
SQL);
$database->execute(<<<'SQL'
CREATE TABLE mgw_matches (
    match_id TEXT NOT NULL PRIMARY KEY,
    game_type TEXT NOT NULL,
    status TEXT NOT NULL,
    match_source TEXT NULL,
    winner_player_ref TEXT NULL,
    finish_reason TEXT NULL,
    started_at_utc TEXT NULL,
    finished_at_utc TEXT NULL
)
SQL);
$database->execute(<<<'SQL'
CREATE TABLE mgw_match_players (
    match_id TEXT NOT NULL,
    seat INTEGER NOT NULL,
    player_ref TEXT NOT NULL,
    mgw_id TEXT NULL,
    player_type TEXT NOT NULL,
    PRIMARY KEY (match_id, seat)
)
SQL);

(require $databaseDir . '/migrations/20260919_0042_create_per_game_visible_rating.php')->up($database);
(require $databaseDir . '/migrations/20260919_0044_create_leaderboards_and_antifarming.php')->up($database);

$database->execute(
    "UPDATE mgw_rating_control
     SET tracking_started_at_utc = '2026-09-19 00:00:00.000000',
         updated_at_utc = '2026-09-19 00:00:00.000000'
     WHERE control_key = 'global'"
);
$database->execute(
    "UPDATE mgw_leaderboard_control
     SET anti_farming_started_at_utc = '2026-09-19 00:00:00.000000',
         day_timezone = 'Europe/Moscow',
         min_rated_matches = 5,
         min_human_wins = 1,
         max_credited_wins_same_opponent_day = 3,
         updated_at_utc = '2026-09-19 00:00:00.000000'
     WHERE control_key = 'global'"
);

$userA = 'MGW-000000000000000A';
$userB = 'MGW-000000000000000B';
$userC = 'MGW-000000000000000C';
$userD = 'MGW-000000000000000D';
$userE = 'MGW-000000000000000E';
$userF = 'MGW-000000000000000F';

foreach ([
    [$userA, 'Alpha'],
    [$userB, 'Beta'],
    [$userC, 'Gamma'],
    [$userD, 'Delta'],
    [$userE, 'Epsilon'],
    [$userF, 'Player9999999'],
] as [$mgwId, $nickname]) {
    $database->execute(
        'INSERT INTO mgw_users (mgw_id, status, nickname, equipped_avatar_item_id)
         VALUES (:mgw_id, :status, :nickname, :avatar)',
        [
            'mgw_id' => $mgwId,
            'status' => 'active',
            'nickname' => $nickname,
            'avatar' => 'starter-default-01',
        ]
    );
}
$database->execute(
    'INSERT INTO mgw_identities (mgw_id, provider, provider_subject)
     VALUES (:mgw_id, :provider, :provider_subject)',
    [
        'mgw_id' => $userF,
        'provider' => 'development',
        'provider_subject' => 'stg_e2e_player_f',
    ]
);

$addMatch = static function (
    DatabaseConnectionInterface $database,
    string $matchId,
    string $winnerMgwId,
    string $loserMgwId,
    string $startedAt,
    string $gameType = 'tictactoe',
    string $source = 'legacy_json'
): void {
    $winnerRef = 'player:' . substr($winnerMgwId, -1);
    $loserRef = 'player:' . substr($loserMgwId, -1);
    $database->execute(
        'INSERT INTO mgw_matches (
            match_id, game_type, status, match_source, winner_player_ref,
            finish_reason, started_at_utc, finished_at_utc
         ) VALUES (
            :match_id, :game_type, :status, :match_source, :winner_player_ref,
            :finish_reason, :started_at_utc, :finished_at_utc
         )',
        [
            'match_id' => $matchId,
            'game_type' => $gameType,
            'status' => 'finished',
            'match_source' => $source,
            'winner_player_ref' => $winnerRef,
            'finish_reason' => 'normal_win',
            'started_at_utc' => $startedAt,
            'finished_at_utc' => $startedAt,
        ]
    );
    foreach ([
        ['seat'=>0, 'ref'=>$winnerRef, 'id'=>$winnerMgwId],
        ['seat'=>1, 'ref'=>$loserRef, 'id'=>$loserMgwId],
    ] as $player) {
        $database->execute(
            'INSERT INTO mgw_match_players (
                match_id, seat, player_ref, mgw_id, player_type
             ) VALUES (
                :match_id, :seat, :player_ref, :mgw_id, :player_type
             )',
            [
                'match_id' => $matchId,
                'seat' => $player['seat'],
                'player_ref' => $player['ref'],
                'mgw_id' => $player['id'],
                'player_type' => 'human',
            ]
        );
    }
};

$rating = new PerGameRatingService($database);
$leaderboard = new LeaderboardService($database);

// Four wins vs the same opponent on one Moscow day: only the first three credit.
for ($i = 1; $i <= 4; $i++) {
    $addMatch(
        $database,
        'farm-' . $i,
        $userA,
        $userB,
        sprintf('2026-09-19 10:0%d:00.000000', $i)
    );
}
$summary = $rating->processPendingFinishedMatches();
$assertSame(4, $summary['recorded'], 'All four human matches must remain durable match results.');
$assertSame(3, $summary['rated'], 'Only the first three wins may credit visible rating.');
$assertSame(3, $summary['points_awarded'], 'Fourth same-opponent/day win must add zero rating points.');
$assertSame(1, $summary['anti_farming_limited'], 'Exactly the fourth win must hit the anti-farming cap.');
$assertSame(3, $rating->snapshot($userA)['by_game']['tictactoe']['points'], 'Visible rating must stop at three same-opponent wins for that day.');

$fourth = $database->fetchAll(
    "SELECT points_requested, points_delta, anti_farming_limited, outcome_code
     FROM mgw_game_rating_outcomes WHERE match_id = 'farm-4'"
)[0];
$assertSame(1, (int)$fourth['points_requested'], 'Fourth win must preserve the rating amount it requested.');
$assertSame(0, (int)$fourth['points_delta'], 'Fourth win must credit zero visible rating.');
$assertSame(1, (int)$fourth['anti_farming_limited'], 'Fourth win must be auditable as anti-farming limited.');
$assertSame('anti_farming_cap', (string)$fourth['outcome_code'], 'Fourth win must have an explicit anti-farming audit outcome.');
$assertSame(
    3,
    (int)$database->fetchValue(
        "SELECT credited_wins FROM mgw_rating_daily_pair_wins
         WHERE rating_day_moscow = '2026-09-19'
           AND game_type = 'tictactoe'
           AND winner_mgw_id = '{$userA}'
           AND opponent_mgw_id = '{$userB}'"
    ),
    'Daily pair counter must never exceed the canonical cap of three.'
);

$assertSame(
    8,
    (int)$database->fetchValue("SELECT COUNT(*) FROM mgw_game_rating_participation WHERE match_id LIKE 'farm-%'"),
    'Leaderboard participation must count both players for all four rated human matches.'
);
$assertSame(
    4,
    (int)$database->fetchValue(
        "SELECT COUNT(*) FROM mgw_game_rating_participation
         WHERE mgw_id = '{$userA}' AND result_code = 'win'"
    ),
    'Fourth win still counts as a played human win for participation.'
);
$assertSame(
    3,
    (int)$database->fetchValue(
        "SELECT SUM(points_awarded) FROM mgw_game_rating_participation
         WHERE mgw_id = '{$userA}'"
    ),
    'Participation audit must distinguish played wins from credited rating points.'
);

// The next Moscow day resets only the anti-farming daily pair gate.
$addMatch($database, 'next-day', $userA, $userB, '2026-09-20 10:00:00.000000');
$nextDay = $rating->processPendingFinishedMatches();
$assertSame(1, $nextDay['points_awarded'], 'A new Moscow day must allow rating credit again.');
$assertSame(4, $rating->snapshot($userA)['by_game']['tictactoe']['points'], 'Fifth match on the next day must raise visible rating to four.');

$boardA = $leaderboard->snapshot('tictactoe', $userA);
$assertTrue($boardA['viewer']['eligible'] === true, 'Five rated matches plus one win must make the viewer leaderboard-eligible.');
$assertSame(5, $boardA['viewer']['rated_matches'], 'Eligibility must count all five rated human matches.');
$assertSame(5, $boardA['viewer']['human_wins'], 'Eligibility win count is human wins, including anti-farming-limited wins.');
$assertSame(1, count($boardA['entries']), 'Player with five losses and zero wins must remain ineligible.');
$assertSame('Alpha', $boardA['entries'][0]['nickname'], 'Eligible leader must expose canonical nickname.');
$assertSame(4, $boardA['entries'][0]['points'], 'Leaderboard must show credited visible points only.');
$assertSame(1, $boardA['entries'][0]['rank'], 'First eligible player must have rank one.');

$boardB = $leaderboard->snapshot('tictactoe', $userB);
$assertTrue($boardB['viewer']['eligible'] === false, 'Five rated matches without a win must not qualify.');
$assertSame(5, $boardB['viewer']['rated_matches'], 'Losses still count toward the minimum match threshold.');
$assertSame(0, $boardB['viewer']['human_wins'], 'No-win player must fail the one-win threshold.');

// One real win makes B eligible without rewriting previous participation.
$addMatch($database, 'b-win', $userB, $userC, '2026-09-20 11:00:00.000000');
$rating->processPendingFinishedMatches();
$boardBEligible = $leaderboard->snapshot('tictactoe', $userB);
$assertTrue($boardBEligible['viewer']['eligible'] === true, 'One human win after five matches must make B eligible.');
$assertSame(2, count($boardBEligible['entries']), 'Both eligible players must appear after B earns a win.');
$assertSame('Alpha', $boardBEligible['entries'][0]['nickname'], 'Higher visible points must rank first.');
$assertSame('Beta', $boardBEligible['entries'][1]['nickname'], 'Lower visible points must rank second.');

// Directly seed two fully eligible equal-score rows to prove deterministic tie-breaks.
foreach ([
    [$userD, '2026-09-19 08:00:00.000000'],
    [$userE, '2026-09-19 07:00:00.000000'],
] as [$mgwId, $updatedAt]) {
    $database->execute(
        'INSERT INTO mgw_game_rating_scores (
            season_id, mgw_id, game_type, points, rated_wins, updated_at_utc
         ) VALUES (
            :season_id, :mgw_id, :game_type, 4, 4, :updated_at
         )',
        [
            'season_id' => 'preseason',
            'mgw_id' => $mgwId,
            'game_type' => 'go',
            'updated_at' => $updatedAt,
        ]
    );
    for ($n = 1; $n <= 5; $n++) {
        $database->execute(
            'INSERT INTO mgw_game_rating_participation (
                match_id, mgw_id, season_id, game_type, opponent_mgw_id,
                result_code, points_awarded, rating_day_moscow,
                match_started_at_utc, match_finished_at_utc, created_at_utc
             ) VALUES (
                :match_id, :mgw_id, :season_id, :game_type, :opponent,
                :result_code, :points_awarded, :day,
                :started, :finished, :created
             )',
            [
                'match_id' => strtolower(substr($mgwId, -1)) . '-tie-' . $n,
                'mgw_id' => $mgwId,
                'season_id' => 'preseason',
                'game_type' => 'go',
                'opponent' => $userC,
                'result_code' => $n === 1 ? 'win' : 'loss',
                'points_awarded' => $n === 1 ? 1 : 0,
                'day' => '2026-09-19',
                'started' => '2026-09-19 06:00:00.000000',
                'finished' => '2026-09-19 06:00:00.000000',
                'created' => '2026-09-19 06:00:00.000000',
            ]
        );
    }
}
// A staging E2E account can have perfectly valid durable rating rows but must
// never appear in the public board.
$database->execute(
    'INSERT INTO mgw_game_rating_scores (
        season_id, mgw_id, game_type, points, rated_wins, updated_at_utc
     ) VALUES (
        :season_id, :mgw_id, :game_type, 99, 5, :updated_at
     )',
    [
        'season_id' => 'preseason',
        'mgw_id' => $userF,
        'game_type' => 'go',
        'updated_at' => '2026-09-19 05:00:00.000000',
    ]
);
for ($n = 1; $n <= 5; $n++) {
    $database->execute(
        'INSERT INTO mgw_game_rating_participation (
            match_id, mgw_id, season_id, game_type, opponent_mgw_id,
            result_code, points_awarded, rating_day_moscow,
            match_started_at_utc, match_finished_at_utc, created_at_utc
         ) VALUES (
            :match_id, :mgw_id, :season_id, :game_type, :opponent,
            :result_code, :points_awarded, :day,
            :started, :finished, :created
         )',
        [
            'match_id' => 'dev-tie-' . $n,
            'mgw_id' => $userF,
            'season_id' => 'preseason',
            'game_type' => 'go',
            'opponent' => $userC,
            'result_code' => $n === 1 ? 'win' : 'loss',
            'points_awarded' => $n === 1 ? 1 : 0,
            'day' => '2026-09-19',
            'started' => '2026-09-19 05:00:00.000000',
            'finished' => '2026-09-19 05:00:00.000000',
            'created' => '2026-09-19 05:00:00.000000',
        ]
    );
}

$tied = $leaderboard->snapshot('go', $userD);
$assertSame(2, count($tied['entries']), 'Development-provider accounts must be excluded from the public leaderboard even when otherwise eligible.');
$assertSame('Epsilon', $tied['entries'][0]['nickname'], 'Equal points/wins must prefer the player who reached the score earlier.');
$assertSame('Delta', $tied['entries'][1]['nickname'], 'Later equal-score player must follow the earlier one.');
$assertTrue(
    !in_array('Player9999999', array_column($tied['entries'], 'nickname'), true),
    'Generated staging E2E nickname must never leak into the public leaderboard.'
);
$assertSame(
    ['points_desc','credited_wins_desc','score_reached_at_asc','mgw_id_asc'],
    $tied['tie_break'],
    'Leaderboard must publish its deterministic tie-break contract.'
);

$repeat = $rating->processPendingFinishedMatches();
$assertSame(0, $repeat['recorded'], 'Reprocessing must never duplicate rating or participation.');
$assertSame(
    1,
    (int)$database->fetchValue("SELECT COUNT(*) FROM mgw_game_rating_outcomes WHERE match_id = 'farm-4'"),
    'Fourth win must still have exactly one durable rating outcome.'
);

$assertTrue($assertions >= 30, 'MVP-20.3 focused contract must cover eligibility, cap, audit and tie-breaks.');
fwrite(STDOUT, "Mvp20_3LeaderboardsAntiFarmingTest: {$assertions} assertions passed\n");
