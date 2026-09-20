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
require dirname(__DIR__) . '/ratings/YearlyMedalService.php';
require dirname(__DIR__) . '/ratings/SeasonCalendar.php';
require dirname(__DIR__) . '/ratings/SeasonLifecycleService.php';
require dirname(__DIR__) . '/ratings/RatingAdminService.php';

if (!extension_loaded('pdo_sqlite')) {
    throw new RuntimeException('Mvp20_8RatingAdminTest requires pdo_sqlite.');
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

foreach ([
    '20260919_0042_create_per_game_visible_rating.php',
    '20260919_0044_create_leaderboards_and_antifarming.php',
    '20260920_0045_create_quarterly_season_lifecycle.php',
    '20260920_0046_create_seasonal_awards.php',
    '20260920_0047_create_yearly_medals.php',
    '20260920_0048_create_rating_admin_review.php',
] as $migration) {
    (require $databaseDir . '/migrations/' . $migration)->up($db);
}

$userA = 'MGW-0000000000000001';
$userB = 'MGW-0000000000000002';
foreach ([[$userA,'Alpha'],[$userB,'Beta']] as [$id,$nickname]) {
    $db->execute(
        'INSERT INTO mgw_users (mgw_id,status,nickname,equipped_avatar_item_id)
         VALUES (:id,:status,:nickname,:avatar)',
        ['id'=>$id,'status'=>'active','nickname'=>$nickname,'avatar'=>'starter-default-01']
    );
}

$seasonRows = [
    ['2026-q3',2026,3,'2026-06-30 21:00:00.000000','2026-09-30 21:00:00.000000','closed'],
    ['2026-q4',2026,4,'2026-09-30 21:00:00.000000','2026-12-31 21:00:00.000000','active'],
];
foreach ($seasonRows as [$id,$year,$quarter,$start,$end,$state]) {
    $db->execute(
        'INSERT INTO mgw_rating_seasons (
            season_id,calendar_year,quarter,timezone,
            calendar_start_at_utc,calendar_end_at_utc,official_start_at_utc,
            season_state,finalization_reason,standings_frozen_at_utc,
            finalization_started_at_utc,finalized_at_utc,created_at_utc,updated_at_utc
         ) VALUES (
            :id,:year,:quarter,:timezone,
            :start,:end,:start,
            :state,NULL,:frozen,:finalizing,:finalized,:created,:updated
         )',
        [
            'id'=>$id,'year'=>$year,'quarter'=>$quarter,'timezone'=>'Europe/Moscow',
            'start'=>$start,'end'=>$end,'state'=>$state,
            'frozen'=>$state === 'closed' ? $end : null,
            'finalizing'=>$state === 'closed' ? $end : null,
            'finalized'=>$state === 'closed' ? $end : null,
            'created'=>$start,'updated'=>$end,
        ]
    );
}

$db->execute(
    "UPDATE mgw_rating_control
     SET competition_state='active',
         current_season_id='2026-q4',
         tracking_started_at_utc='2026-07-01 00:00:00.000000',
         activated_at_utc='2026-07-01 00:00:00.000000',
         updated_at_utc='2026-10-01 00:00:00.000000'
     WHERE control_key='global'"
);

$db->execute(
    'INSERT INTO mgw_season_reward_packages (
        target_season_id,package_state,seasonal_awards_state,top3_frames_state,
        yearly_medal_state,localization_state,preview_validation_state,
        ready_at_utc,created_at_utc,updated_at_utc
     ) VALUES (
        :target,:package,:ready,:ready,:ready,:ready,:ready,:at,:at,:at
     )',
    [
        'target'=>'2026-q4',
        'package'=>'ready',
        'ready'=>'ready',
        'at'=>'2026-09-20 00:00:00.000000',
    ]
);
$db->execute(
    'INSERT INTO mgw_season_boundary_operations (
        ending_season_id,target_season_id,boundary_at_utc,
        operation_state,block_reason,started_at_utc,completed_at_utc,
        created_at_utc,updated_at_utc
     ) VALUES (
        :ending,:target,:boundary,:state,NULL,:boundary,:completed,:created,:updated
     )',
    [
        'ending'=>'2026-q3','target'=>'2026-q4',
        'boundary'=>'2026-09-30 21:00:00.000000',
        'state'=>'completed',
        'completed'=>'2026-09-30 21:00:01.000000',
        'created'=>'2026-09-30 21:00:00.000000',
        'updated'=>'2026-09-30 21:00:01.000000',
    ]
);

$addRated = static function (
    DatabaseConnectionInterface $db,
    string $mgwId,
    string $prefix,
    int $points,
    int $wins
): void {
    for ($i=1; $i<=5; $i++) {
        $db->execute(
            'INSERT INTO mgw_game_rating_participation (
                match_id,mgw_id,season_id,game_type,opponent_mgw_id,result_code,
                points_awarded,rating_day_moscow,match_started_at_utc,
                match_finished_at_utc,created_at_utc
             ) VALUES (
                :match_id,:mgw_id,:season,:game,:opponent,:result,
                :points,:day,:started,:finished,:created
             )',
            [
                'match_id'=>$prefix . '-' . $i,
                'mgw_id'=>$mgwId,
                'season'=>'2026-q3',
                'game'=>'tictactoe',
                'opponent'=>$mgwId === 'MGW-0000000000000001'
                    ? 'MGW-0000000000000002'
                    : 'MGW-0000000000000001',
                'result'=>$i <= $wins ? 'win' : 'loss',
                'points'=>$i <= $wins ? 1 : 0,
                'day'=>'2026-09-15',
                'started'=>'2026-09-15 10:00:00.000000',
                'finished'=>'2026-09-15 10:01:00.000000',
                'created'=>'2026-09-15 10:01:00.000000',
            ]
        );
    }
    $db->execute(
        'INSERT INTO mgw_game_rating_scores (
            season_id,mgw_id,game_type,points,rated_wins,updated_at_utc
         ) VALUES (:season,:mgw_id,:game,:points,:wins,:updated)',
        [
            'season'=>'2026-q3','mgw_id'=>$mgwId,'game'=>'tictactoe',
            'points'=>$points,'wins'=>$wins,'updated'=>'2026-09-15 11:00:00.000000',
        ]
    );
};
$addRated($db,$userA,'a-rated',10,1);
$addRated($db,$userB,'b-rated',8,2);

// Explicit bot-game evidence: outcome is retained for diagnostics, but it must
// never create a rating participation row.
$db->execute(
    'INSERT INTO mgw_matches (
        match_id,game_type,status,match_source,winner_player_ref,
        finish_reason,started_at_utc,finished_at_utc
     ) VALUES (
        :id,:game,:status,:source,:winner,:reason,:started,:finished
     )',
    [
        'id'=>'bot-evidence-1','game'=>'tictactoe','status'=>'finished',
        'source'=>'matchmaking','winner'=>'human-ref','reason'=>'normal_win',
        'started'=>'2026-09-20 10:00:00.000000',
        'finished'=>'2026-09-20 10:01:00.000000',
    ]
);
$db->execute(
    'INSERT INTO mgw_match_players (match_id,seat,player_ref,mgw_id,player_type)
     VALUES (:match,1,:human_ref,:human_id,:human_type),
            (:match,2,:bot_ref,NULL,:bot_type)',
    [
        'match'=>'bot-evidence-1',
        'human_ref'=>'human-ref','human_id'=>$userA,'human_type'=>'human',
        'bot_ref'=>'bot-ref','bot_type'=>'bot',
    ]
);
$db->execute(
    'INSERT INTO mgw_game_rating_outcomes (
        match_id,season_id,game_type,competition_state,winner_mgw_id,
        points_delta,outcome_code,match_source,finish_reason,
        finished_at_utc,processed_at_utc
     ) VALUES (
        :match,:season,:game,:state,NULL,
        0,:code,:source,:reason,:finished,:processed
     )',
    [
        'match'=>'bot-evidence-1','season'=>'2026-q3','game'=>'tictactoe',
        'state'=>'active','code'=>'bot_game','source'=>'matchmaking',
        'reason'=>'normal_win',
        'finished'=>'2026-09-20 10:01:00.000000',
        'processed'=>'2026-09-20 10:01:01.000000',
    ]
);

$awardService = new SeasonalAwardService($db);
$initialAwards = $awardService->reconcileGameAwards(
    '2026-q3','tictactoe','2026-q4',[],'season_close','test:initial',
    new DateTimeImmutable('2026-10-01T00:00:00Z')
);
$assertSame(2, $initialAwards['active_awards'], 'Initial awarded board must contain both eligible humans.');
$medalService = new YearlyMedalService($db);
$initialMedal = $medalService->reconcileSeasonFragment(
    '2026-q3',[],'season_close','test:initial',
    new DateTimeImmutable('2026-10-01T00:00:00Z')
);
$assertSame(2, $initialMedal['eligible_count'], 'Initial yearly fragment must include both meaningful participants.');

$admin = new RatingAdminService($db);
$snapshot = $admin->snapshot();
$assertSame('2026-q4', $snapshot['competition']['current_season_id'], 'Snapshot must expose current official season.');
$assertSame(0, $snapshot['metrics']['bot_participation_violations'], 'Bot game must never leak into rating participation.');
$assertTrue($snapshot['metrics']['bot_game_outcomes'] >= 0, 'Bot diagnostic metric must remain numeric.');

$excluded = $admin->setExclusion(
    '2026-q3',
    MgwIdGenerator::toPublic($userA),
    'match_manipulation',
    'Replay review confirmed coordinated manipulation.',
    'telegram:42',
    new DateTimeImmutable('2026-10-02T00:00:00Z')
);
$assertSame('active', $excluded['exclusion_state'], 'Reviewed exclusion must become active.');
$assertSame(MgwIdGenerator::toPublic($userA), $excluded['public_mgw_id'], 'Admin review must accept public MGW-ID.');
$assertSame(1, (int)$db->fetchValue('SELECT COUNT(*) FROM mgw_rating_review_audit WHERE action_code=\'exclude\''), 'Exclusion must append review audit.');

$recalc = $admin->recalculateSeason(
    '2026-q3',
    'Apply reviewed match-manipulation correction.',
    'telegram:42',
    new DateTimeImmutable('2026-10-02T00:01:00Z')
);
$assertSame('2026-q4', $recalc['target_season_id'], 'Recalculation must reuse the recorded next-season owner.');
$assertSame([$userA], $recalc['excluded_mgw_ids'], 'Recalculation must persist the exact reviewed exclusion set.');

$activeAwardA = (int)$db->fetchValue(
    'SELECT COUNT(*) FROM mgw_season_awards
     WHERE season_id=\'2026-q3\' AND game_type=\'tictactoe\'
       AND mgw_id=:id AND award_state=\'active\'',
    ['id'=>$userA]
);
$rankB = (int)$db->fetchValue(
    'SELECT rank_position FROM mgw_season_awards
     WHERE season_id=\'2026-q3\' AND game_type=\'tictactoe\'
       AND mgw_id=:id AND award_state=\'active\'',
    ['id'=>$userB]
);
$assertSame(0, $activeAwardA, 'Excluded player award must be revoked by canonical award reconciliation.');
$assertSame(1, $rankB, 'Next eligible player must shift into first place after correction.');

$fragmentA = (string)$db->fetchValue(
    'SELECT fragment_state FROM mgw_yearly_medal_fragments
     WHERE calendar_year=2026 AND quarter=3 AND mgw_id=:id',
    ['id'=>$userA]
);
$fragmentB = (string)$db->fetchValue(
    'SELECT fragment_state FROM mgw_yearly_medal_fragments
     WHERE calendar_year=2026 AND quarter=3 AND mgw_id=:id',
    ['id'=>$userB]
);
$assertSame('revoked', $fragmentA, 'Reviewed exclusion must revoke the fraudulent yearly fragment.');
$assertSame('active', $fragmentB, 'Unexcluded meaningful participant must retain yearly fragment.');

$job = $admin->recentJobs(1)[0] ?? null;
$assertTrue(is_array($job), 'Recalculation must create a durable job record.');
$assertSame('completed', $job['job_state'], 'Successful recalculation job must close as completed.');
$assertSame([$userA], $job['excluded_mgw_ids'], 'Job audit must preserve the exact exclusion set.');

$rehearsal = $admin->seasonCloseRehearsal('2026-q3');
$assertSame(true, $rehearsal['dry_run'], 'Season-close rehearsal must declare dry-run semantics.');
$assertSame(0, $rehearsal['bot_exclusion']['participation_violations'], 'Rehearsal must fail closed on bot participation violations.');
$assertSame(1, $rehearsal['games']['tictactoe']['eligible_count'], 'Rehearsal must preview standings with reviewed exclusion applied.');

$restored = $admin->revokeExclusion(
    '2026-q3',
    MgwIdGenerator::toPublic($userA),
    'Appeal review cleared the player.',
    'telegram:42',
    new DateTimeImmutable('2026-10-03T00:00:00Z')
);
$assertSame('revoked', $restored['exclusion_state'], 'Review restore must revoke the exclusion instead of deleting history.');
$assertSame(1, (int)$db->fetchValue('SELECT COUNT(*) FROM mgw_rating_review_audit WHERE action_code=\'restore\''), 'Restore must append review audit.');

$recalc2 = $admin->recalculateSeason(
    '2026-q3',
    'Apply approved appeal restore.',
    'telegram:42',
    new DateTimeImmutable('2026-10-03T00:01:00Z')
);
$assertSame([], $recalc2['excluded_mgw_ids'], 'Second recalculation must use the now-empty active exclusion set.');
$restoredRankA = (int)$db->fetchValue(
    'SELECT rank_position FROM mgw_season_awards
     WHERE season_id=\'2026-q3\' AND game_type=\'tictactoe\'
       AND mgw_id=:id AND award_state=\'active\'',
    ['id'=>$userA]
);
$assertSame(1, $restoredRankA, 'Appeal restore must deterministically restore the original first place.');

$scoreBefore = (int)$db->fetchValue(
    'SELECT points FROM mgw_game_rating_scores
     WHERE season_id=\'2026-q3\' AND game_type=\'tictactoe\' AND mgw_id=:id',
    ['id'=>$userA]
);
$assertSame(10, $scoreBefore, 'MVP-20.8 corrections must not rewrite visible rating score history.');

$assertTrue($assertions >= 20, 'MVP-20.8 focused model must cover review, audit, recalculation, bot exclusion and rehearsal.');
fwrite(STDOUT, "Mvp20_8RatingAdminTest: {$assertions} assertions passed\n");
