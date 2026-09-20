<?php
declare(strict_types=1);

$databaseDir = dirname(__DIR__) . '/database';
require $databaseDir . '/DatabaseConnectionInterface.php';
require $databaseDir . '/PdoDatabaseConnection.php';
require $databaseDir . '/DatabaseMigrationInterface.php';
require dirname(__DIR__) . '/ratings/PerGameRatingService.php';
require dirname(__DIR__) . '/accounts/MgwIdGenerator.php';
require dirname(__DIR__) . '/ratings/LeaderboardService.php';
require dirname(__DIR__) . '/ratings/SeasonCalendar.php';
require dirname(__DIR__) . '/ratings/SeasonLifecycleService.php';
require dirname(__DIR__) . '/ratings/SeasonalAwardService.php';

if (!extension_loaded('pdo_sqlite')) {
    throw new RuntimeException('Mvp20_5SeasonalAwardsTest requires pdo_sqlite.');
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
$migration = require $databaseDir . '/migrations/20260920_0046_create_seasonal_awards.php';
$migration->up($database);
$migration->up($database);

$database->execute(
    "UPDATE mgw_rating_control
     SET competition_state = 'active',
         current_season_id = '2026-q4',
         tracking_started_at_utc = '2026-08-15 10:00:00.000000',
         activated_at_utc = '2026-08-15 10:00:00.000000',
         updated_at_utc = '2026-10-01 00:00:00.000000'
     WHERE control_key = 'global'"
);
$database->execute(
    "UPDATE mgw_leaderboard_control
     SET min_rated_matches = 5,
         min_human_wins = 1,
         day_timezone = 'Europe/Moscow',
         updated_at_utc = '2026-10-01 00:00:00.000000'
     WHERE control_key = 'global'"
);

foreach ([
    [
        '2026-q3', 2026, 3,
        '2026-06-30 21:00:00.000000',
        '2026-09-30 21:00:00.000000',
        '2026-08-15 10:00:00.000000',
        'finalizing',
    ],
    [
        '2026-q4', 2026, 4,
        '2026-09-30 21:00:00.000000',
        '2026-12-31 21:00:00.000000',
        '2026-09-30 21:00:00.000000',
        'active',
    ],
] as [$seasonId, $year, $quarter, $start, $end, $officialStart, $state]) {
    $database->execute(
        'INSERT INTO mgw_rating_seasons (
            season_id, calendar_year, quarter, timezone,
            calendar_start_at_utc, calendar_end_at_utc, official_start_at_utc,
            season_state, finalization_reason, standings_frozen_at_utc,
            finalization_started_at_utc, finalized_at_utc,
            created_at_utc, updated_at_utc
         ) VALUES (
            :season_id, :calendar_year, :quarter, :timezone,
            :calendar_start_at_utc, :calendar_end_at_utc, :official_start_at_utc,
            :season_state, NULL, :standings_frozen_at_utc,
            :finalization_started_at_utc, NULL,
            :created_at_utc, :updated_at_utc
         )',
        [
            'season_id' => $seasonId,
            'calendar_year' => $year,
            'quarter' => $quarter,
            'timezone' => 'Europe/Moscow',
            'calendar_start_at_utc' => $start,
            'calendar_end_at_utc' => $end,
            'official_start_at_utc' => $officialStart,
            'season_state' => $state,
            'standings_frozen_at_utc' => $seasonId === '2026-q3' ? $end : null,
            'finalization_started_at_utc' => $seasonId === '2026-q3' ? $end : null,
            'created_at_utc' => '2026-08-15 10:00:00.000000',
            'updated_at_utc' => '2026-10-01 00:00:00.000000',
        ]
    );
}

$lifecycle = new SeasonLifecycleService($database, new SeasonCalendar());
$ready = $lifecycle->updateRewardReadiness('2026-q4', [
    'seasonal_awards_state' => 'ready',
    'top3_frames_state' => 'ready',
    'yearly_medal_state' => 'not_required',
    'localization_state' => 'ready',
    'preview_validation_state' => 'ready',
], new DateTimeImmutable('2026-09-25 12:00:00', new DateTimeZone('UTC')));
$assertSame('ready', (string)$ready['package_state'], 'Q4 package must be READY before seasonal awards can finalize.');

$service = new SeasonalAwardService($database, new LeaderboardService($database));

// A finished official-season match without a rating projection must block awards.
$database->execute(
    "INSERT INTO mgw_matches (
        match_id, game_type, status, match_source, winner_player_ref,
        finish_reason, started_at_utc, finished_at_utc
     ) VALUES (
        'pending-projection', 'tictactoe', 'finished', 'legacy_json', 'player:a',
        'normal_win', '2026-09-20 10:00:00.000000', '2026-09-20 10:01:00.000000'
     )"
);
$pending = $service->finalizeSeason(
    '2026-q3',
    '2026-q4',
    'season_close',
    'system:test',
    new DateTimeImmutable('2026-10-01 00:00:00', new DateTimeZone('UTC'))
);
$assertSame('projection_pending', $pending['status'], 'Season awards must wait for visible-rating projection catch-up.');
$assertSame(1, $pending['pending_projection_count'], 'Projection backlog count must be explicit.');
$assertSame(0, (int)$database->fetchValue('SELECT COUNT(*) FROM mgw_season_awards'), 'No award may be written from incomplete standings.');
$database->execute("DELETE FROM mgw_matches WHERE match_id = 'pending-projection'");

// Seed 102 eligible human players. Rank 101/102 prove the rewarded top-100 cap.
// A development identity with a huge score proves technical accounts stay out.
for ($i = 1; $i <= 102; $i++) {
    $mgwId = 'U' . str_pad((string)$i, 22, '0', STR_PAD_LEFT);
    $nickname = 'Player' . $i;
    $database->execute(
        'INSERT INTO mgw_users (mgw_id, status, nickname, equipped_avatar_item_id)
         VALUES (:mgw_id, :status, :nickname, :avatar)',
        ['mgw_id'=>$mgwId,'status'=>'active','nickname'=>$nickname,'avatar'=>'starter-default-01']
    );
    $database->execute(
        'INSERT INTO mgw_game_rating_scores (
            season_id, mgw_id, game_type, points, rated_wins, updated_at_utc
         ) VALUES (
            :season_id, :mgw_id, :game_type, :points, :wins, :updated_at
         )',
        [
            'season_id'=>'2026-q3',
            'mgw_id'=>$mgwId,
            'game_type'=>'tictactoe',
            'points'=>103-$i,
            'wins'=>103-$i,
            'updated_at'=>sprintf('2026-09-01 00:%02d:%02d.000000', intdiv($i-1, 60), ($i-1)%60),
        ]
    );
    $opponent = 'O' . str_pad((string)$i, 22, '0', STR_PAD_LEFT);
    for ($match = 1; $match <= 5; $match++) {
        $database->execute(
            'INSERT INTO mgw_game_rating_participation (
                match_id, mgw_id, season_id, game_type, opponent_mgw_id,
                result_code, points_awarded, rating_day_moscow,
                match_started_at_utc, match_finished_at_utc, created_at_utc
             ) VALUES (
                :match_id, :mgw_id, :season_id, :game_type, :opponent_mgw_id,
                :result_code, :points_awarded, :rating_day_moscow,
                :match_started_at_utc, :match_finished_at_utc, :created_at_utc
             )',
            [
                'match_id'=>'p' . $i . '-m' . $match,
                'mgw_id'=>$mgwId,
                'season_id'=>'2026-q3',
                'game_type'=>'tictactoe',
                'opponent_mgw_id'=>$opponent,
                'result_code'=>$match === 1 ? 'win' : 'loss',
                'points_awarded'=>$match === 1 ? 1 : 0,
                'rating_day_moscow'=>'2026-09-01',
                'match_started_at_utc'=>'2026-09-01 10:00:00.000000',
                'match_finished_at_utc'=>'2026-09-01 10:01:00.000000',
                'created_at_utc'=>'2026-09-01 10:01:00.000000',
            ]
        );
    }
}

$devId = 'D' . str_repeat('0', 22);
$database->execute(
    'INSERT INTO mgw_users (mgw_id, status, nickname, equipped_avatar_item_id)
     VALUES (:mgw_id, :status, :nickname, :avatar)',
    ['mgw_id'=>$devId,'status'=>'active','nickname'=>'Player9999999','avatar'=>'starter-default-01']
);
$database->execute(
    'INSERT INTO mgw_identities (mgw_id, provider, provider_subject)
     VALUES (:mgw_id, :provider, :subject)',
    ['mgw_id'=>$devId,'provider'=>'development','subject'=>'stg_award_test']
);
$database->execute(
    'INSERT INTO mgw_game_rating_scores (
        season_id, mgw_id, game_type, points, rated_wins, updated_at_utc
     ) VALUES (
        :season_id, :mgw_id, :game_type, 9999, 9999, :updated_at
     )',
    ['season_id'=>'2026-q3','mgw_id'=>$devId,'game_type'=>'tictactoe','updated_at'=>'2026-08-31 00:00:00.000000']
);
for ($match = 1; $match <= 5; $match++) {
    $database->execute(
        'INSERT INTO mgw_game_rating_participation (
            match_id, mgw_id, season_id, game_type, opponent_mgw_id,
            result_code, points_awarded, rating_day_moscow,
            match_started_at_utc, match_finished_at_utc, created_at_utc
         ) VALUES (
            :match_id, :mgw_id, :season_id, :game_type, :opponent_mgw_id,
            :result_code, 1, :rating_day_moscow,
            :started, :finished, :created
         )',
        [
            'match_id'=>'dev-m' . $match,
            'mgw_id'=>$devId,
            'season_id'=>'2026-q3',
            'game_type'=>'tictactoe',
            'opponent_mgw_id'=>'O' . str_repeat('9', 22),
            'result_code'=>'win',
            'rating_day_moscow'=>'2026-09-01',
            'started'=>'2026-09-01 09:00:00.000000',
            'finished'=>'2026-09-01 09:01:00.000000',
            'created'=>'2026-09-01 09:01:00.000000',
        ]
    );
}

$finalized = $service->finalizeSeason(
    '2026-q3',
    '2026-q4',
    'season_close',
    'system:test',
    new DateTimeImmutable('2026-10-01 00:00:00', new DateTimeZone('UTC'))
);
$assertSame('completed', $finalized['status'], 'Complete rating projection must allow awards.');
$assertSame(100, $finalized['granted'], 'Only top 100 across the seeded game may receive awards.');
$assertSame(8, (int)$database->fetchValue("SELECT COUNT(*) FROM mgw_season_award_runs WHERE season_id = '2026-q3'"), 'All eight game scopes must have one idempotency run row.');
$assertSame(100, (int)$database->fetchValue("SELECT COUNT(*) FROM mgw_season_awards WHERE season_id = '2026-q3' AND game_type = 'tictactoe' AND award_state = 'active'"), 'Exactly top 100 must remain active.');
$assertSame(0, (int)$database->fetchValue("SELECT COUNT(*) FROM mgw_season_awards WHERE mgw_id = '{$devId}'"), 'Development technical identity must never receive a seasonal award.');

$rank1 = $database->fetchAll("SELECT * FROM mgw_season_awards WHERE season_id='2026-q3' AND game_type='tictactoe' AND rank_position=1")[0];
$rank2 = $database->fetchAll("SELECT * FROM mgw_season_awards WHERE season_id='2026-q3' AND game_type='tictactoe' AND rank_position=2")[0];
$rank10 = $database->fetchAll("SELECT * FROM mgw_season_awards WHERE season_id='2026-q3' AND game_type='tictactoe' AND rank_position=10")[0];
$rank11 = $database->fetchAll("SELECT * FROM mgw_season_awards WHERE season_id='2026-q3' AND game_type='tictactoe' AND rank_position=11")[0];
$rank100 = $database->fetchAll("SELECT * FROM mgw_season_awards WHERE season_id='2026-q3' AND game_type='tictactoe' AND rank_position=100")[0];

$assertSame('gold', (string)$rank1['badge_tier'], 'Rank 1 must receive gold badge.');
$assertSame('silver', (string)$rank2['badge_tier'], 'Rank 2 must receive silver badge.');
$assertSame('silver', (string)$rank10['badge_tier'], 'Rank 10 must still receive silver badge.');
$assertSame('bronze', (string)$rank11['badge_tier'], 'Rank 11 must receive bronze badge.');
$assertSame('bronze', (string)$rank100['badge_tier'], 'Rank 100 must receive bronze badge.');
$assertSame(3, (int)$database->fetchValue("SELECT COUNT(*) FROM mgw_season_awards WHERE season_id='2026-q3' AND game_type='tictactoe' AND frame_place IS NOT NULL"), 'Only top 3 receive temporary next-season frames.');
$assertSame(1, (int)$rank1['frame_place'], 'Rank 1 frame entitlement must preserve first place.');
$assertSame('2026-q4', (string)$rank1['frame_target_season_id'], 'Temporary frame must target the next season.');
$assertSame('2026-09-30 21:00:00.000000', (string)$rank1['frame_valid_from_at_utc'], 'Temporary frame starts with next official season.');
$assertSame('2026-12-31 21:00:00.000000', (string)$rank1['frame_valid_until_at_utc'], 'Temporary frame expires at next season boundary.');

$user1 = 'U' . str_pad('1', 22, '0', STR_PAD_LEFT);
$user101 = 'U' . str_pad('101', 22, '0', STR_PAD_LEFT);
$view = $service->userAwards($user1, new DateTimeImmutable('2026-10-15 12:00:00', new DateTimeZone('UTC')));
$assertSame(1, count($view['active_frame_entitlements']), 'Top-3 frame must be active during the next season.');
$assertSame(1, (int)$view['best_active_frame']['frame_place'], 'Best active frame must expose its original place.');
$expired = $service->userAwards($user1, new DateTimeImmutable('2027-01-01 00:00:00', new DateTimeZone('UTC')));
$assertSame(0, count($expired['active_frame_entitlements']), 'Temporary top-3 frame must expire after the next season.');

$auditBeforeRepeat = (int)$database->fetchValue('SELECT COUNT(*) FROM mgw_season_award_audit');
$runRevisionBeforeRepeat = (int)$database->fetchValue("SELECT revision FROM mgw_season_award_runs WHERE season_id='2026-q3' AND game_type='tictactoe'");
$repeat = $service->finalizeSeason(
    '2026-q3',
    '2026-q4',
    'season_close',
    'system:test',
    new DateTimeImmutable('2026-10-01 00:00:01', new DateTimeZone('UTC'))
);
$assertSame('completed', $repeat['status'], 'Repeated season finalization must be safe.');
$assertSame(0, $repeat['granted'], 'Repeated season finalization must not grant duplicate awards.');
$assertSame(0, $repeat['reissued'], 'Repeated season finalization must not reissue unchanged awards.');
$assertSame(0, $repeat['revoked'], 'Repeated season finalization must not revoke unchanged awards.');
$assertSame($auditBeforeRepeat, (int)$database->fetchValue('SELECT COUNT(*) FROM mgw_season_award_audit'), 'Idempotent retry must not append duplicate audit events.');
$assertSame($runRevisionBeforeRepeat, (int)$database->fetchValue("SELECT revision FROM mgw_season_award_runs WHERE season_id='2026-q3' AND game_type='tictactoe'"), 'Idempotent retry must not bump revision.');

// Future MVP-20.8 fraud review can exclude a confirmed offender. Rankings must
// shift before the top-100 cap, with audited revocation/reissue/grant.
$correction = $service->reconcileGameAwards(
    '2026-q3',
    'tictactoe',
    '2026-q4',
    [$user1],
    'fraud_correction',
    'admin:test',
    new DateTimeImmutable('2026-10-05 12:00:00', new DateTimeZone('UTC'))
);
$assertSame('reconciled', $correction['status'], 'Fraud correction must reconcile changed standings.');
$assertSame(1, $correction['revoked'], 'Excluded former leader must be revoked.');
$assertTrue($correction['reissued'] >= 99, 'Shifted top-100 members must be reissued at corrected ranks.');
$assertSame(1, $correction['granted'], 'Former rank 101 must move into rewarded rank 100.');

$formerLeader = $database->fetchAll("SELECT * FROM mgw_season_awards WHERE season_id='2026-q3' AND game_type='tictactoe' AND mgw_id='{$user1}'")[0];
$assertSame('revoked', (string)$formerLeader['award_state'], 'Confirmed excluded leader must no longer own an active seasonal award.');

$newRank1Id = 'U' . str_pad('2', 22, '0', STR_PAD_LEFT);
$newRank1 = $database->fetchAll("SELECT * FROM mgw_season_awards WHERE season_id='2026-q3' AND game_type='tictactoe' AND mgw_id='{$newRank1Id}'")[0];
$assertSame(1, (int)$newRank1['rank_position'], 'Old rank 2 must shift to rank 1.');
$assertSame('gold', (string)$newRank1['badge_tier'], 'Corrected rank 1 must receive gold.');
$assertSame(1, (int)$newRank1['frame_place'], 'Corrected rank 1 must receive first-place next-season frame entitlement.');

$promoted101 = $database->fetchAll("SELECT * FROM mgw_season_awards WHERE season_id='2026-q3' AND game_type='tictactoe' AND mgw_id='{$user101}'")[0];
$assertSame(100, (int)$promoted101['rank_position'], 'Former rank 101 must shift into rank 100.');
$assertSame('bronze', (string)$promoted101['badge_tier'], 'Corrected rank 100 must receive bronze.');
$assertSame('active', (string)$promoted101['award_state'], 'Promoted rank 101 must become an active award holder.');

$fraudAudit = $database->fetchAll(
    "SELECT action_code, reason_code
     FROM mgw_season_award_audit
     WHERE season_id='2026-q3' AND game_type='tictactoe' AND reason_code='fraud_correction'"
);
$actions = array_count_values(array_map(static fn(array $row): string => (string)$row['action_code'], $fraudAudit));
$assertSame(1, (int)($actions['revoke'] ?? 0), 'Fraud correction audit must record the revocation.');
$assertSame(1, (int)($actions['grant'] ?? 0), 'Fraud correction audit must record the promoted grant.');
$assertTrue((int)($actions['reissue'] ?? 0) >= 99, 'Fraud correction audit must record shifted reissues.');

$auditAfterCorrection = (int)$database->fetchValue('SELECT COUNT(*) FROM mgw_season_award_audit');
$repeatCorrection = $service->reconcileGameAwards(
    '2026-q3',
    'tictactoe',
    '2026-q4',
    [$user1],
    'fraud_correction',
    'admin:test',
    new DateTimeImmutable('2026-10-05 12:00:01', new DateTimeZone('UTC'))
);
$assertSame('unchanged', $repeatCorrection['status'], 'Repeated identical fraud correction must be idempotent.');
$assertSame($auditAfterCorrection, (int)$database->fetchValue('SELECT COUNT(*) FROM mgw_season_award_audit'), 'Repeated correction must not duplicate audit rows.');

$assertThrows(
    static fn() => $service->reconcileGameAwards('preseason', 'tictactoe', '2026-q4'),
    'PRESEASON must never issue an official seasonal award.'
);

$assertTrue($assertions >= 40, 'MVP-20.5 focused test must cover tiers, frames, idempotency, projection gating and fraud correction.');
fwrite(STDOUT, "Mvp20_5SeasonalAwardsTest: {$assertions} assertions passed\n");
