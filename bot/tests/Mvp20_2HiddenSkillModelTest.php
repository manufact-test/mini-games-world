<?php
declare(strict_types=1);

$databaseDir = dirname(__DIR__) . '/database';
require $databaseDir . '/DatabaseConnectionInterface.php';
require $databaseDir . '/PdoDatabaseConnection.php';
require $databaseDir . '/DatabaseMigrationInterface.php';
require dirname(__DIR__) . '/services/MatchmakingQueue.php';
require dirname(__DIR__) . '/realtime/RealtimeDatabaseStore.php';
require dirname(__DIR__) . '/ratings/PerGameRatingService.php';
require dirname(__DIR__) . '/ratings/HiddenSkillService.php';

if (!extension_loaded('pdo_sqlite')) {
    throw new RuntimeException('Mvp20_2HiddenSkillModelTest requires pdo_sqlite.');
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
    winner_player_ref TEXT NULL,
    finish_reason TEXT NULL,
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
$database->execute(<<<'SQL'
CREATE TABLE mgw_match_queue (
    queue_id TEXT NOT NULL PRIMARY KEY,
    player_ref TEXT NOT NULL,
    mgw_id TEXT NULL,
    legacy_user_id TEXT NULL,
    game_type TEXT NOT NULL,
    room TEXT NOT NULL,
    bet INTEGER NOT NULL DEFAULT 0,
    board_size INTEGER NOT NULL,
    status TEXT NOT NULL DEFAULT 'waiting',
    reserved_match_id TEXT NULL,
    created_at_utc TEXT NOT NULL,
    updated_at_utc TEXT NOT NULL,
    expires_at_utc TEXT NULL
)
SQL);
$database->execute(<<<'SQL'
CREATE TABLE mgw_rating_control (
    control_key TEXT NOT NULL PRIMARY KEY,
    competition_state TEXT NOT NULL,
    current_season_id TEXT NOT NULL
)
SQL);
$database->execute(
    "INSERT INTO mgw_rating_control (control_key, competition_state, current_season_id)
     VALUES ('global', 'preseason', 'preseason')"
);

$migration = require $databaseDir . '/migrations/20260919_0043_create_hidden_skill_model.php';
$migration->up($database);
$queueColumns = array_map(
    static fn(array $row): string => (string)($row['name'] ?? ''),
    $database->fetchAll('PRAGMA table_info(mgw_match_queue)')
);
$assertTrue(in_array('skill_band', $queueColumns, true), 'MVP-20.2 migration must persist skill_band in the realtime queue schema.');
$migration->up($database);
$queueColumnsAfterRerun = array_map(
    static fn(array $row): string => (string)($row['name'] ?? ''),
    $database->fetchAll('PRAGMA table_info(mgw_match_queue)')
);
$assertSame(1, count(array_filter($queueColumnsAfterRerun, static fn(string $name): bool => $name === 'skill_band')), 'Queue skill_band migration must be idempotent.');

$queueStore = new RealtimeDatabaseStore($database);
$queueRow = $queueStore->upsertQueueEntry([
    'queue_id' => 'skill-queue-a',
    'player_ref' => 'legacy:queue-a',
    'mgw_id' => null,
    'legacy_user_id' => 'queue-a',
    'game_type' => 'tictactoe',
    'room' => 'match',
    'bet' => 10,
    'board_size' => 3,
    'skill_band' => 'band:15',
    'created_at_utc' => '2026-09-19 20:00:00.000000',
    'updated_at_utc' => '2026-09-19 20:00:00.000000',
]);
$assertSame('band:15', (string)$queueRow['skill_band'], 'Realtime queue insert must preserve the server-assigned hidden band.');
$queueRow = $queueStore->upsertQueueEntry([
    'queue_id' => 'ignored-new-queue-id',
    'player_ref' => 'legacy:queue-a',
    'mgw_id' => null,
    'legacy_user_id' => 'queue-a',
    'game_type' => 'tictactoe',
    'room' => 'match',
    'bet' => 10,
    'board_size' => 3,
    'skill_band' => 'band:16',
    'updated_at_utc' => '2026-09-19 20:00:01.000000',
]);
$assertSame('band:16', (string)$queueRow['skill_band'], 'Realtime queue update must keep hidden-band parity across DB projection.');
$assertSame('band:16', (string)$database->fetchValue("SELECT skill_band FROM mgw_match_queue WHERE player_ref = 'legacy:queue-a'"), 'Stored queue band must match the canonical JSON-side queue identity.');
$queueStore->removeQueueEntry('legacy:queue-a');
$database->execute(
    "UPDATE mgw_hidden_skill_control
     SET tracking_started_at_utc = '2026-09-19 20:00:00.000000',
         updated_at_utc = '2026-09-19 20:00:00.000000'
     WHERE control_key = 'global'"
);

$service = new HiddenSkillService($database);
$userA = '01AAAAAAAAAAAAAAAAAAAAAA';
$userB = '01BBBBBBBBBBBBBBBBBBBBBB';

$addMatch = static function (
    DatabaseConnectionInterface $database,
    string $matchId,
    string $gameType,
    ?string $winnerRef,
    string $finishReason,
    string $finishedAt,
    array $players
): void {
    $database->execute(
        'INSERT INTO mgw_matches (
            match_id, game_type, status, winner_player_ref, finish_reason, finished_at_utc
         ) VALUES (
            :match_id, :game_type, :status, :winner_player_ref, :finish_reason, :finished_at_utc
         )',
        [
            'match_id' => $matchId,
            'game_type' => $gameType,
            'status' => 'finished',
            'winner_player_ref' => $winnerRef,
            'finish_reason' => $finishReason,
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

$humans = static fn(?string $aMgwId = null): array => [
    ['player_ref' => 'player:a', 'mgw_id' => $aMgwId ?? $userA, 'player_type' => 'human'],
    ['player_ref' => 'player:b', 'mgw_id' => $userB, 'player_type' => 'human'],
];

$assertSame('band:15', $service->skillBandForUser($userA, 'tictactoe'), 'New player hidden skill must start in neutral band 15');
$assertSame(1800, $service->softAdjustScore(1900, 7500), 'Season soft adjustment must carry 75% of distance from baseline');
$assertSame(1200, $service->softAdjustScore(1100, 7500), 'Season soft adjustment must move low skill toward baseline symmetrically');

$addMatch($database, 'h-normal', 'tictactoe', 'player:a', 'normal_win', '2026-09-19 20:01:00.000000', $humans());
$first = $service->processPendingFinishedMatches();
$assertSame(1, $first['modeled'], 'Normal real-human match must update hidden skill');
$rowA = $database->fetchAll("SELECT * FROM mgw_hidden_skill_scores WHERE mgw_id = '{$userA}' AND game_type = 'tictactoe'")[0];
$rowB = $database->fetchAll("SELECT * FROM mgw_hidden_skill_scores WHERE mgw_id = '{$userB}' AND game_type = 'tictactoe'")[0];
$assertSame(1516, (int)$rowA['skill_score'], 'Equal-skill winner must gain 16 Elo points');
$assertSame(1484, (int)$rowB['skill_score'], 'Equal-skill loser must lose 16 Elo points');
$assertSame(1, (int)$rowA['matches_played'], 'Winner hidden model must count the human match');
$assertSame(1, (int)$rowA['wins'], 'Winner hidden model must count the win');
$assertSame(1, (int)$rowB['losses'], 'Loser hidden model must count the loss');

$repeat = $service->processPendingFinishedMatches();
$assertSame(0, $repeat['modeled'], 'Reprocessing must not update hidden skill twice');
$assertSame(1, (int)$database->fetchValue("SELECT COUNT(*) FROM mgw_hidden_skill_outcomes WHERE match_id = 'h-normal'"), 'One match must have exactly one hidden-skill outcome');
$assertSame(1516, (int)$database->fetchValue("SELECT skill_score FROM mgw_hidden_skill_scores WHERE mgw_id = '{$userA}' AND game_type = 'tictactoe'"), 'Duplicate processing must not change winner score');

$assertSame('band:15', $service->skillBandForUser($userA, 'go'), 'Hidden skill must be isolated per game');
$assertSame(1500, (int)$database->fetchValue("SELECT skill_score FROM mgw_hidden_skill_scores WHERE mgw_id = '{$userA}' AND game_type = 'go'"), 'New game must keep independent baseline');

$addMatch($database, 'h-bot', 'chess', 'player:a', 'normal_win', '2026-09-19 20:02:00.000000', [
    ['player_ref' => 'player:a', 'mgw_id' => $userA, 'player_type' => 'human'],
    ['player_ref' => 'bot:1', 'mgw_id' => null, 'player_type' => 'bot'],
]);
$service->processPendingFinishedMatches();
$assertSame('bot_game', (string)$database->fetchValue("SELECT outcome_code FROM mgw_hidden_skill_outcomes WHERE match_id = 'h-bot'"), 'Bot matches must be permanently excluded from hidden skill');
$assertSame(0, (int)$database->fetchValue("SELECT COUNT(*) FROM mgw_hidden_skill_scores WHERE mgw_id = '{$userA}' AND game_type = 'chess'"), 'Bot-only result must not create a hidden skill update');

$addMatch($database, 'h-tech', 'checkers', 'player:a', 'timeout', '2026-09-19 20:03:00.000000', $humans());
$service->processPendingFinishedMatches();
$assertSame('technical_result', (string)$database->fetchValue("SELECT outcome_code FROM mgw_hidden_skill_outcomes WHERE match_id = 'h-tech'"), 'Technical result must not change hidden skill');

$addMatch($database, 'h-draw', 'tictactoe', null, 'draw', '2026-09-19 20:04:00.000000', $humans());
$service->processPendingFinishedMatches();
$afterDrawA = (int)$database->fetchValue("SELECT skill_score FROM mgw_hidden_skill_scores WHERE mgw_id = '{$userA}' AND game_type = 'tictactoe'");
$afterDrawB = (int)$database->fetchValue("SELECT skill_score FROM mgw_hidden_skill_scores WHERE mgw_id = '{$userB}' AND game_type = 'tictactoe'");
$assertTrue($afterDrawA < 1516 && $afterDrawB > 1484, 'A real-human draw must move unequal hidden skills toward each other');
$assertSame(2, (int)$database->fetchValue("SELECT matches_played FROM mgw_hidden_skill_scores WHERE mgw_id = '{$userA}' AND game_type = 'tictactoe'"), 'Draw must count as a modeled human match');

$addMatch($database, 'h-missing-id', 'domino', 'player:a', 'normal_win', '2026-09-19 20:05:00.000000', [
    ['player_ref' => 'player:a', 'mgw_id' => null, 'player_type' => 'human'],
    ['player_ref' => 'player:b', 'mgw_id' => $userB, 'player_type' => 'human'],
]);
$service->processPendingFinishedMatches();
$assertSame(0, (int)$database->fetchValue("SELECT COUNT(*) FROM mgw_hidden_skill_outcomes WHERE match_id = 'h-missing-id'"), 'Missing canonical identity must defer rather than discard a human result');
$database->execute(
    "UPDATE mgw_match_players SET mgw_id = :mgw_id
     WHERE match_id = 'h-missing-id' AND player_ref = 'player:a'",
    ['mgw_id' => $userA]
);
$service->processPendingFinishedMatches();
$assertSame(1, (int)$database->fetchValue("SELECT COUNT(*) FROM mgw_hidden_skill_outcomes WHERE match_id = 'h-missing-id'"), 'Deferred human result must process after identity catches up');

$addMatch($database, 'h-before-boundary', 'reversi', 'player:a', 'normal_win', '2026-09-19 19:59:59.000000', $humans());
$service->processPendingFinishedMatches();
$assertSame(0, (int)$database->fetchValue("SELECT COUNT(*) FROM mgw_hidden_skill_outcomes WHERE match_id = 'h-before-boundary'"), 'Hidden skill must not backfill matches before its own tracking boundary');

$database->execute(
    "UPDATE mgw_rating_control SET current_season_id = '2026-Q4' WHERE control_key = 'global'"
);
$beforeSoft = (int)$database->fetchValue("SELECT skill_score FROM mgw_hidden_skill_scores WHERE mgw_id = '{$userA}' AND game_type = 'tictactoe'");
$band = $service->skillBandForUser($userA, 'tictactoe');
$afterSoftRow = $database->fetchAll("SELECT skill_score, current_season_id, season_matches FROM mgw_hidden_skill_scores WHERE mgw_id = '{$userA}' AND game_type = 'tictactoe'")[0];
$expectedSoft = $service->softAdjustScore($beforeSoft, 7500);
$assertSame($expectedSoft, (int)$afterSoftRow['skill_score'], 'First access in a new season must apply the soft adjustment exactly once');
$assertSame('2026-Q4', (string)$afterSoftRow['current_season_id'], 'Hidden skill row must advance to the current season');
$assertSame(0, (int)$afterSoftRow['season_matches'], 'Season soft adjustment must reset per-season hidden match count');
$assertSame($service->bandForScore($expectedSoft), $band, 'Matchmaking band must reflect the soft-adjusted hidden score');

$database->execute(
    "UPDATE mgw_rating_control SET competition_state = 'off' WHERE control_key = 'global'"
);
$assertSame('unrated', $service->skillBandForUser($userA, 'tictactoe'), 'OFF state must not expose a hidden-skill band to matchmaking');
$addMatch($database, 'h-off', 'go', 'player:a', 'normal_win', '2026-09-19 20:06:00.000000', $humans());
$service->processPendingFinishedMatches();
$assertSame('competition_off', (string)$database->fetchValue("SELECT outcome_code FROM mgw_hidden_skill_outcomes WHERE match_id = 'h-off'"), 'OFF-state match must not become retroactive hidden skill later');

$assertSame('band:15', $service->bandForScore(1500), 'Baseline score must map to band 15');
$assertSame('band:18', $service->bandForScore(1800), 'Skill score must map deterministically to ordinal matchmaking bands');
$assertTrue($assertions >= 25, 'MVP-20.2 model contract must exercise real-human-only, season adjustment and idempotency');

fwrite(STDOUT, "Mvp20_2HiddenSkillModelTest: {$assertions} assertions passed\n");
