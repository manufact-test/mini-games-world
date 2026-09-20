<?php
declare(strict_types=1);

$databaseDir = dirname(__DIR__) . '/database';
require $databaseDir . '/DatabaseConnectionInterface.php';
require $databaseDir . '/PdoDatabaseConnection.php';
require $databaseDir . '/DatabaseMigrationInterface.php';
require dirname(__DIR__) . '/ratings/PerGameRatingService.php';
require dirname(__DIR__) . '/ratings/YearlyMedalService.php';

if (!extension_loaded('pdo_sqlite')) {
    throw new RuntimeException('Mvp20_6YearlyMedalTest requires pdo_sqlite.');
}

$assertions = 0;
$assertSame = static function (mixed $expected, mixed $actual, string $message) use (&$assertions): void {
    $assertions++;
    if ($expected !== $actual) {
        throw new RuntimeException(
            $message . ': expected ' . var_export($expected, true)
            . ', got ' . var_export($actual, true)
        );
    }
};
$assertTrue = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};
$assertThrows = static function (callable $callback, string $message) use (&$assertions): void {
    $assertions++;
    try {
        $callback();
    } catch (Throwable) {
        return;
    }
    throw new RuntimeException($message);
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
(require $databaseDir . '/migrations/20260920_0045_create_quarterly_season_lifecycle.php')->up($database);
$migration = require $databaseDir . '/migrations/20260920_0047_create_yearly_medals.php';
$migration->up($database);
$migration->up($database);

$userA = 'U00000000000000000000001';
$userB = 'U00000000000000000000002';
$dev = 'D00000000000000000000001';
foreach ([[$userA,'Alpha'],[$userB,'Beta'],[$dev,'Player9999999']] as [$mgwId,$nickname]) {
    $database->execute(
        'INSERT INTO mgw_users (mgw_id,status,nickname,equipped_avatar_item_id)
         VALUES (:mgw_id,:status,:nickname,:avatar)',
        ['mgw_id'=>$mgwId,'status'=>'active','nickname'=>$nickname,'avatar'=>'starter-default-01']
    );
}
$database->execute(
    'INSERT INTO mgw_identities (mgw_id,provider,provider_subject)
     VALUES (:mgw_id,:provider,:subject)',
    ['mgw_id'=>$dev,'provider'=>'development','subject'=>'stg_medal_test']
);

$seasons = [
    ['2026-q1',2026,1,'2025-12-31 21:00:00.000000','2026-03-31 21:00:00.000000'],
    ['2026-q2',2026,2,'2026-03-31 21:00:00.000000','2026-06-30 21:00:00.000000'],
    ['2026-q3',2026,3,'2026-06-30 21:00:00.000000','2026-09-30 21:00:00.000000'],
    ['2026-q4',2026,4,'2026-09-30 21:00:00.000000','2026-12-31 21:00:00.000000'],
    ['2027-q1',2027,1,'2026-12-31 21:00:00.000000','2027-03-31 21:00:00.000000'],
];
foreach ($seasons as [$seasonId,$year,$quarter,$start,$end]) {
    $database->execute(
        'INSERT INTO mgw_rating_seasons (
            season_id,calendar_year,quarter,timezone,
            calendar_start_at_utc,calendar_end_at_utc,official_start_at_utc,
            season_state,finalization_reason,standings_frozen_at_utc,
            finalization_started_at_utc,finalized_at_utc,
            created_at_utc,updated_at_utc
         ) VALUES (
            :season_id,:year,:quarter,:timezone,
            :start,:end,:official_start,
            :state,NULL,NULL,NULL,NULL,
            :created,:updated
         )',
        [
            'season_id'=>$seasonId,'year'=>$year,'quarter'=>$quarter,'timezone'=>'Europe/Moscow',
            'start'=>$start,'end'=>$end,'official_start'=>$start,
            'state'=>$seasonId === '2026-q4' ? 'active' : 'closed',
            'created'=>'2026-01-01 00:00:00.000000','updated'=>'2026-10-01 00:00:00.000000',
        ]
    );
}
$database->execute(
    "UPDATE mgw_rating_control
     SET competition_state='active',
         current_season_id='2026-q4',
         activated_at_utc='2025-12-31 21:00:00.000000',
         updated_at_utc='2026-10-01 00:00:00.000000'
     WHERE control_key='global'"
);

$addParticipation = static function (
    DatabaseConnectionInterface $database,
    string $matchId,
    string $mgwId,
    string $seasonId,
    string $gameType,
    string $result
): void {
    $database->execute(
        'INSERT INTO mgw_game_rating_participation (
            match_id,mgw_id,season_id,game_type,opponent_mgw_id,
            result_code,points_awarded,rating_day_moscow,
            match_started_at_utc,match_finished_at_utc,created_at_utc
         ) VALUES (
            :match_id,:mgw_id,:season_id,:game_type,:opponent,
            :result_code,:points_awarded,:day,
            :started,:finished,:created
         )',
        [
            'match_id'=>$matchId,'mgw_id'=>$mgwId,'season_id'=>$seasonId,'game_type'=>$gameType,
            'opponent'=>'O00000000000000000000001','result_code'=>$result,
            'points_awarded'=>$result === 'win' ? 1 : 0,'day'=>'2026-01-10',
            'started'=>'2026-01-10 10:00:00.000000',
            'finished'=>'2026-01-10 10:01:00.000000',
            'created'=>'2026-01-10 10:01:00.000000',
        ]
    );
};

// One rated human match is sufficient; a win is deliberately NOT required.
$addParticipation($database,'a-q1-loss',$userA,'2026-q1','tictactoe','loss');
$addParticipation($database,'b-q2-loss',$userB,'2026-q2','chess','loss');
$addParticipation($database,'a-q3-draw',$userA,'2026-q3','go','draw');
$addParticipation($database,'a-q4-win',$userA,'2026-q4','domino','win');
$addParticipation($database,'dev-q1',$dev,'2026-q1','tictactoe','win');

$service = new YearlyMedalService($database);

$design2026 = $database->fetchAll("SELECT * FROM mgw_yearly_medal_designs WHERE calendar_year=2026");
$assertSame(1, count($design2026), 'Migration must seed exactly one 2026 annual medal design.');
$assertSame('annual-2026-neon-orbit', (string)$design2026[0]['medal_id'], '2026 annual medal id must be stable.');
$assertSame('ready', (string)$design2026[0]['design_state'], 'Seeded 2026 design must be ready.');

$q1 = $service->reconcileSeasonFragment(
    '2026-q1', [], 'season_close', 'system:test',
    new DateTimeImmutable('2026-04-01 00:00:00', new DateTimeZone('UTC'))
);
$assertSame('reconciled', $q1['status'], 'Q1 fragment reconciliation must succeed.');
$assertSame(1, $q1['eligible_count'], 'Exactly one real player must qualify in Q1.');
$assertSame(1, $q1['granted'], 'One rated loss must still unlock the quarter fragment.');
$assertSame(0, (int)$database->fetchValue("SELECT COUNT(*) FROM mgw_yearly_medal_fragments WHERE mgw_id='{$dev}'"), 'Development identity must never receive an official medal fragment.');

$q1Repeat = $service->reconcileSeasonFragment(
    '2026-q1', [], 'season_close', 'system:test',
    new DateTimeImmutable('2026-04-01 00:00:01', new DateTimeZone('UTC'))
);
$assertSame('unchanged', $q1Repeat['status'], 'Identical quarter reconciliation must be idempotent.');
$assertSame(0, $q1Repeat['granted'], 'Idempotent retry must not grant a duplicate fragment.');
$assertSame(1, (int)$database->fetchValue("SELECT COUNT(*) FROM mgw_yearly_medal_audit WHERE season_id='2026-q1'"), 'Idempotent retry must not duplicate audit.');

$q2 = $service->reconcileSeasonFragment(
    '2026-q2', [], 'season_close', 'system:test',
    new DateTimeImmutable('2026-07-01 00:00:00', new DateTimeZone('UTC'))
);
$assertSame(1, $q2['eligible_count'], 'Q2 must award only the player who actually participated in Q2.');

$q3 = $service->reconcileSeasonFragment(
    '2026-q3', [], 'season_close', 'system:test',
    new DateTimeImmutable('2026-10-01 00:00:00', new DateTimeZone('UTC'))
);
$q4 = $service->reconcileSeasonFragment(
    '2026-q4', [], 'season_close', 'system:test',
    new DateTimeImmutable('2027-01-01 00:00:00', new DateTimeZone('UTC'))
);
$assertSame(1, $q3['eligible_count'], 'A Q3 rated draw must unlock Q3 for the participating player.');
$assertSame(1, $q4['eligible_count'], 'A Q4 rated win must unlock Q4 for the participating player.');

$userAView = $service->userSnapshot($userA, new DateTimeImmutable('2026-12-15 00:00:00', new DateTimeZone('UTC')));
$assertSame(true, $userAView['visible'], 'ACTIVE player with fragments must have a Profile medal surface.');
$assertSame([1,3,4], $userAView['featured']['unlocked_quarters'], 'Missing Q2 must remain a visible hole; later quarters cannot fill it.');
$assertSame(3, $userAView['featured']['fragment_count'], 'Missing season must leave a three-piece annual medal.');
$assertSame(false, $userAView['featured']['complete'], 'Annual medal is incomplete while any quarter is missing.');

$userBView = $service->userSnapshot($userB, new DateTimeImmutable('2026-12-15 00:00:00', new DateTimeZone('UTC')));
$assertSame([2], $userBView['featured']['unlocked_quarters'], 'A player with only Q2 participation must own only Q2 fragment.');

// The service has no Store/purchase path. A genuine late projection/correction can
// reconcile historical participation, but a purchase can never fabricate a part.
$assertSame(0, (int)$database->fetchValue("SELECT COUNT(*) FROM mgw_yearly_medal_fragments WHERE mgw_id='{$userA}' AND quarter=2"), 'Missing Q2 cannot appear without Q2 rated participation.');

// Cross-year finalization requires the annual design for the new year as part of
// readiness; one design is annual, not one new four-part design per quarter.
$yearBoundary = $service->boundaryReadiness('2026-q4','2027-q1');
$assertSame(false, $yearBoundary['ready'], 'Q4 -> Q1 must block while the 2027 annual design is missing.');
$assertSame([2027], $yearBoundary['missing_years'], 'Only the missing next-year design should block the year boundary.');

$design2027 = $service->upsertDesign(
    2027,
    'annual-2027-aurora-core',
    'aurora-core-2027',
    'ready',
    new DateTimeImmutable('2026-12-15 12:00:00', new DateTimeZone('UTC'))
);
$assertSame('ready', (string)$design2027['design_state'], 'Prepared 2027 annual design must become READY.');
$yearBoundaryReady = $service->boundaryReadiness('2026-q4','2027-q1');
$assertSame(true, $yearBoundaryReady['ready'], 'Prepared annual design must clear the year-boundary readiness gate.');

$database->execute(
    "UPDATE mgw_rating_control
     SET competition_state='preseason',
         current_season_id='preseason',
         updated_at_utc='2026-12-20 00:00:00.000000'
     WHERE control_key='global'"
);
$preseasonView = $service->userSnapshot($userA);
$assertSame(false, $preseasonView['visible'], 'PRESEASON must hide official yearly medal surfaces.');
$assertSame([], $preseasonView['years'], 'PRESEASON must not expose official medal history.');
$assertThrows(
    static fn() => $service->reconcileSeasonFragment('preseason'),
    'PRESEASON must never grant official yearly medal parts.'
);

$serviceSource = file_get_contents(dirname(__DIR__) . '/ratings/YearlyMedalService.php');
$assertTrue(is_string($serviceSource) && !str_contains($serviceSource, 'ProductInventoryService'), 'Yearly medal parts must not use permanent Store inventory.');
$assertTrue(
    !str_contains((string)$serviceSource, 'function purchase')
        && !str_contains((string)$serviceSource, 'buyFragment')
        && !str_contains((string)$serviceSource, 'mgw_inventory_items'),
    'There must be no retroactive medal-part purchase path.'
);
$assertTrue($assertions >= 25, 'MVP-20.6 focused test must cover one-match unlock, holes, annual readiness, PRESEASON and idempotency.');

fwrite(STDOUT, "Mvp20_6YearlyMedalTest: {$assertions} assertions passed\n");
