<?php
declare(strict_types=1);

$databaseDir = dirname(__DIR__) . '/database';
require $databaseDir . '/DatabaseConnectionInterface.php';
require $databaseDir . '/PdoDatabaseConnection.php';
require $databaseDir . '/DatabaseMigrationInterface.php';
require dirname(__DIR__) . '/ratings/PerGameRatingService.php';

if (!extension_loaded('pdo_sqlite')) {
    throw new RuntimeException('Mvp20_1PerGameVisibleRatingTest requires pdo_sqlite.');
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

$migration = require $databaseDir . '/migrations/20260919_0042_create_per_game_visible_rating.php';
$migration->up($database);
$leaderboardMigration = require $databaseDir . '/migrations/20260919_0044_create_leaderboards_and_antifarming.php';
$leaderboardMigration->up($database);
$database->execute(
    'UPDATE mgw_rating_control
     SET tracking_started_at_utc = :tracking, updated_at_utc = :tracking
     WHERE control_key = :control_key',
    [
        'tracking' => '2026-09-19 00:00:00.000000',
        'control_key' => 'global',
    ]
);

$service = new PerGameRatingService($database);
$userA = '01AAAAAAAAAAAAAAAAAAAAAA';
$userB = '01BBBBBBBBBBBBBBBBBBBBBB';

$addMatch = static function (
    DatabaseConnectionInterface $database,
    string $matchId,
    string $gameType,
    ?string $winnerRef,
    string $finishReason,
    string $finishedAt,
    string $matchSource = 'legacy_json',
    array $players = []
): void {
    $database->execute(
        'INSERT INTO mgw_matches (
            match_id, game_type, status, match_source,
            winner_player_ref, finish_reason, started_at_utc, finished_at_utc
         ) VALUES (
            :match_id, :game_type, :status, :match_source,
            :winner_player_ref, :finish_reason, :started_at_utc, :finished_at_utc
         )',
        [
            'match_id' => $matchId,
            'game_type' => $gameType,
            'status' => 'finished',
            'match_source' => $matchSource,
            'winner_player_ref' => $winnerRef,
            'finish_reason' => $finishReason,
            'started_at_utc' => $finishedAt,
            'finished_at_utc' => $finishedAt,
        ]
    );
    foreach ($players as $seat => $player) {
        $database->execute(
            'INSERT INTO mgw_match_players (
                match_id, seat, player_ref, mgw_id, player_type
             ) VALUES (
                :match_id, :seat, :player_ref, :mgw_id, :player_type
             )',
            [
                'match_id' => $matchId,
                'seat' => $seat,
                'player_ref' => $player['player_ref'],
                'mgw_id' => $player['mgw_id'],
                'player_type' => $player['player_type'],
            ]
        );
    }
};

$humanPlayers = static fn(?string $winnerMgwId = null): array => [
    ['player_ref' => 'player:a', 'mgw_id' => $winnerMgwId ?? $userA, 'player_type' => 'human'],
    ['player_ref' => 'player:b', 'mgw_id' => $userB, 'player_type' => 'human'],
];

$initial = $service->snapshot($userA);
$assertSame('preseason', $initial['competition_state'], 'MVP-20.1 must start in PRESEASON');
$assertSame('preseason', $initial['season_id'], 'Preseason must have a separate season id');
$assertSame(8, count($initial['by_game']), 'Snapshot must always contain all eight games');
$assertSame(0, $initial['by_game']['tictactoe']['points'], 'New rating must start from zero');

$addMatch($database, 'm-normal', 'tictactoe', 'player:a', 'normal_win', '2026-09-19 01:00:00.000000', 'legacy_json', $humanPlayers());
$first = $service->processPendingFinishedMatches();
$assertSame(1, $first['rated'], 'Normal human win must be rated');
$assertSame(1, $first['points_awarded'], 'Normal human win must grant +1');
$assertSame(1, $service->snapshot($userA)['by_game']['tictactoe']['points'], 'TTT rating must increment by one');

$repeat = $service->processPendingFinishedMatches();
$assertSame(0, $repeat['recorded'], 'Repeated processing must not create a second outcome');
$assertSame(1, $service->snapshot($userA)['by_game']['tictactoe']['points'], 'Repeated result must never grant twice');

$addMatch($database, 'm-tournament', 'go', 'player:a', 'normal_win', '2026-09-19 02:00:00.000000', 'tournament', $humanPlayers());
$tournament = $service->processPendingFinishedMatches();
$assertSame(2, $tournament['points_awarded'], 'Tournament played win must grant +2');
$assertSame(2, $service->snapshot($userA)['by_game']['go']['points'], 'Tournament points must stay isolated to the game');

$addMatch($database, 'm-bot', 'chess', 'player:a', 'normal_win', '2026-09-19 03:00:00.000000', 'legacy_json', [
    ['player_ref' => 'player:a', 'mgw_id' => $userA, 'player_type' => 'human'],
    ['player_ref' => 'bot:1', 'mgw_id' => null, 'player_type' => 'bot'],
]);
$bot = $service->processPendingFinishedMatches();
$assertSame(0, $bot['points_awarded'], 'Bot match must grant zero rating');
$assertSame(0, $service->snapshot($userA)['by_game']['chess']['points'], 'Bot match must not affect visible rating');

$addMatch($database, 'm-timeout', 'checkers', 'player:a', 'timeout', '2026-09-19 04:00:00.000000', 'legacy_json', $humanPlayers());
$service->processPendingFinishedMatches();
$assertSame(0, $service->snapshot($userA)['by_game']['checkers']['points'], 'Technical timeout win must grant zero rating');

$addMatch($database, 'm-draw', 'reversi', null, 'draw', '2026-09-19 05:00:00.000000', 'legacy_json', $humanPlayers());
$service->processPendingFinishedMatches();
$assertSame(0, $service->snapshot($userA)['by_game']['reversi']['points'], 'Draw must grant zero rating');

$addMatch($database, 'm-loss', 'battleship', 'player:b', 'normal_win', '2026-09-19 06:00:00.000000', 'legacy_json', $humanPlayers());
$service->processPendingFinishedMatches();
$assertSame(0, $service->snapshot($userA)['by_game']['battleship']['points'], 'Loss must grant zero rating to the loser');
$assertSame(1, $service->snapshot($userB)['by_game']['battleship']['points'], 'Human winner must receive the rating point');

$addMatch($database, 'm-missing-id', 'domino', 'player:a', 'normal_win', '2026-09-19 07:00:00.000000', 'legacy_json', [
    ['player_ref' => 'player:a', 'mgw_id' => null, 'player_type' => 'human'],
    ['player_ref' => 'player:b', 'mgw_id' => $userB, 'player_type' => 'human'],
]);
$missing = $service->processPendingFinishedMatches();
$assertSame(1, $missing['deferred'], 'Missing canonical winner identity must defer instead of discarding a point');
$assertSame(0, (int)$database->fetchValue("SELECT COUNT(*) FROM mgw_game_rating_outcomes WHERE match_id = 'm-missing-id'"), 'Deferred identity must not finalize the outcome');
$database->execute(
    'UPDATE mgw_match_players SET mgw_id = :mgw_id WHERE match_id = :match_id AND player_ref = :player_ref',
    ['mgw_id' => $userA, 'match_id' => 'm-missing-id', 'player_ref' => 'player:a']
);
$service->processPendingFinishedMatches();
$assertSame(1, $service->snapshot($userA)['by_game']['domino']['points'], 'Deferred result must award after canonical identity catches up');

$addMatch($database, 'm-before-tracking', 'four_in_a_row', 'player:a', 'normal_win', '2026-09-18 23:59:59.000000', 'legacy_json', $humanPlayers());
$service->processPendingFinishedMatches();
$assertSame(0, (int)$database->fetchValue("SELECT COUNT(*) FROM mgw_game_rating_outcomes WHERE match_id = 'm-before-tracking'"), 'Pre-MVP tracking history must not be retroactively rated');

$database->execute(
    "UPDATE mgw_rating_control
     SET competition_state = 'off', updated_at_utc = '2026-09-19 08:00:00.000000'
     WHERE control_key = 'global'"
);
$addMatch($database, 'm-off', 'four_in_a_row', 'player:a', 'normal_win', '2026-09-19 08:30:00.000000', 'legacy_json', $humanPlayers());
$service->processPendingFinishedMatches();
$offOutcome = $database->fetchAll("SELECT points_delta, outcome_code FROM mgw_game_rating_outcomes WHERE match_id = 'm-off'");
$assertSame(0, (int)$offOutcome[0]['points_delta'], 'OFF state must grant zero points');
$assertSame('competition_off', $offOutcome[0]['outcome_code'], 'OFF state must be auditable');

$database->execute(
    "UPDATE mgw_rating_control
     SET competition_state = 'active',
         current_season_id = '2026-Q4',
         activated_at_utc = '2026-09-19 10:00:00.000000',
         updated_at_utc = '2026-09-19 10:00:00.000000'
     WHERE control_key = 'global'"
);
$addMatch($database, 'm-late-preseason', 'four_in_a_row', 'player:a', 'normal_win', '2026-09-19 09:59:00.000000', 'legacy_json', $humanPlayers());
$addMatch($database, 'm-active', 'four_in_a_row', 'player:a', 'normal_win', '2026-09-19 10:01:00.000000', 'legacy_json', $humanPlayers());
$service->processPendingFinishedMatches();

$active = $service->snapshot($userA);
$assertSame('active', $active['competition_state'], 'ACTIVE snapshot must expose official state');
$assertSame('2026-Q4', $active['season_id'], 'ACTIVE snapshot must use current official season id');
$assertSame(1, $active['by_game']['four_in_a_row']['points'], 'Only post-activation match may count in official season');
$lateOutcome = $database->fetchAll("SELECT season_id, competition_state, points_delta FROM mgw_game_rating_outcomes WHERE match_id = 'm-late-preseason'");
$assertSame('preseason', $lateOutcome[0]['season_id'], 'Late projected pre-activation match must remain preseason');
$assertSame('preseason', $lateOutcome[0]['competition_state'], 'Late pre-activation result must never become official retroactively');
$assertSame(1, (int)$lateOutcome[0]['points_delta'], 'Preseason visible point may still be preserved in preseason bucket');

$totalNormalOutcomes = (int)$database->fetchValue("SELECT COUNT(*) FROM mgw_game_rating_outcomes WHERE match_id = 'm-normal'");
$assertSame(1, $totalNormalOutcomes, 'One match must have exactly one durable rating outcome');

$assertTrue($assertions >= 20, 'Focused MVP-20.1 contract must exercise the full result matrix');
fwrite(STDOUT, "Mvp20_1PerGameVisibleRatingTest: {$assertions} assertions passed\n");
