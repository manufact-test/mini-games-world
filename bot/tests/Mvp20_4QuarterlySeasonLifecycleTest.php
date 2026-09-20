<?php
declare(strict_types=1);

$databaseDir = dirname(__DIR__) . '/database';
require $databaseDir . '/DatabaseConnectionInterface.php';
require $databaseDir . '/PdoDatabaseConnection.php';
require $databaseDir . '/DatabaseMigrationInterface.php';
require dirname(__DIR__) . '/ratings/PerGameRatingService.php';
require dirname(__DIR__) . '/ratings/SeasonCalendar.php';
require dirname(__DIR__) . '/ratings/SeasonAssignmentResolver.php';
require dirname(__DIR__) . '/ratings/SeasonLifecycleService.php';

if (!extension_loaded('pdo_sqlite')) {
    throw new RuntimeException('Mvp20_4QuarterlySeasonLifecycleTest requires pdo_sqlite.');
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

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$database = new PdoDatabaseConnection($pdo);

(require $databaseDir . '/migrations/20260919_0042_create_per_game_visible_rating.php')->up($database);
$migration = require $databaseDir . '/migrations/20260920_0045_create_quarterly_season_lifecycle.php';
$migration->up($database);
$migration->up($database);

$calendar = new SeasonCalendar();
$q3 = $calendar->definition(2026, 3);
$assertSame('2026-q3', $q3['season_id'], 'Q3 season id must be deterministic.');
$assertSame('Europe/Moscow', $q3['timezone'], 'Quarterly calendar must use Moscow timezone.');
$assertSame('2026-06-30 21:00:00.000000', $q3['calendar_start_at_utc'], 'Q3 must begin at Moscow midnight.');
$assertSame('2026-09-30 21:00:00.000000', $q3['calendar_end_at_utc'], 'Q3 must end at the next Moscow quarter boundary.');
$assertSame('2026-q4', $calendar->nextDefinition($q3)['season_id'], 'Quarter transition must advance Q3 to Q4.');
$assertSame('2027-q1', $calendar->nextDefinition('2026-q4')['season_id'], 'Q4 must roll into next-year Q1.');

$service = new SeasonLifecycleService($database, $calendar);

// PRESEASON is intentionally inert: no official season history or reminders.
$preseason = $service->reconcile(new DateTimeImmutable('2026-08-20 12:00:00', new DateTimeZone('UTC')));
$assertSame('inactive', $preseason['status'], 'PRESEASON must not create an official season.');
$assertSame('preseason', $preseason['competition_state'], 'Launch state must remain PRESEASON.');
$assertSame(0, (int)$database->fetchValue('SELECT COUNT(*) FROM mgw_rating_seasons'), 'PRESEASON must not create official season rows.');
$assertSame(0, (int)$database->fetchValue('SELECT COUNT(*) FROM mgw_season_preparation_reminders'), 'PRESEASON must not create official reminder cycles.');

// Simulate the future MVP-22.5 admin activation boundary without implementing
// that admin control here. MVP-20.4 must consume ACTIVE safely and idempotently.
$database->execute(
    "UPDATE mgw_rating_control
     SET competition_state = 'active',
         current_season_id = 'preseason',
         activated_at_utc = '2026-08-15 10:00:00.000000',
         updated_at_utc = '2026-08-15 10:00:00.000000'
     WHERE control_key = 'global'"
);

$activated = $service->reconcile(new DateTimeImmutable('2026-08-15 10:01:00', new DateTimeZone('UTC')));
$assertSame('active', $activated['status'], 'ACTIVE competition must initialize the official quarter.');
$assertSame('2026-q3', $activated['current_season_id'], 'Mid-quarter activation must start official history in the containing quarter.');
$seasonQ3 = $database->fetchAll("SELECT * FROM mgw_rating_seasons WHERE season_id = '2026-q3'")[0];
$assertSame('2026-08-15 10:00:00.000000', (string)$seasonQ3['official_start_at_utc'], 'First official season must start exactly at activation, not retroactively at July 1.');
$assertSame('active', (string)$seasonQ3['season_state'], 'Activated quarter must be active.');
$assertSame('2026-q3', (string)$database->fetchValue("SELECT current_season_id FROM mgw_rating_control WHERE control_key = 'global'"), 'Rating control must point at the official quarter.');

$assertSame(1, (int)$database->fetchValue("SELECT COUNT(*) FROM mgw_season_reward_packages WHERE target_season_id = '2026-q4'"), 'Active season must create one durable next-season readiness package.');
$assertSame(3, (int)$database->fetchValue("SELECT COUNT(*) FROM mgw_season_preparation_reminders WHERE ending_season_id = '2026-q3'"), 'Active season must own T-21/T-14/T-7 preparation checkpoints.');
$reminders = $database->fetchAll(
    "SELECT checkpoint_days, due_at_utc
     FROM mgw_season_preparation_reminders
     WHERE ending_season_id = '2026-q3'
     ORDER BY checkpoint_days DESC"
);
$assertSame(21, (int)$reminders[0]['checkpoint_days'], 'First reminder must be T-21.');
$assertSame('2026-09-09 21:00:00.000000', (string)$reminders[0]['due_at_utc'], 'T-21 must respect the Moscow quarter boundary.');
$assertSame(14, (int)$reminders[1]['checkpoint_days'], 'Second reminder must be T-14.');
$assertSame('2026-09-16 21:00:00.000000', (string)$reminders[1]['due_at_utc'], 'T-14 must respect the Moscow quarter boundary.');
$assertSame(7, (int)$reminders[2]['checkpoint_days'], 'Third reminder must be T-7.');
$assertSame('2026-09-23 21:00:00.000000', (string)$reminders[2]['due_at_utc'], 'T-7 must respect the Moscow quarter boundary.');

$t21 = $service->reconcile(new DateTimeImmutable('2026-09-10 00:00:00', new DateTimeZone('UTC')));
$assertSame(1, count($t21['reminders_due']), 'Exactly T-21 must be due before T-14.');
$assertSame(21, (int)$t21['reminders_due'][0]['checkpoint_days'], 'Due reminder must identify the 21-day checkpoint.');

// Mark the Q4 package READY using the canonical checklist contract.
$readyQ4 = $service->updateRewardReadiness('2026-q4', [
    'seasonal_awards_state' => 'ready',
    'top3_frames_state' => 'not_required',
    'yearly_medal_state' => 'not_required',
    'localization_state' => 'ready',
    'preview_validation_state' => 'ready',
], new DateTimeImmutable('2026-09-15 12:00:00', new DateTimeZone('UTC')));
$assertSame('ready', (string)$readyQ4['package_state'], 'All required checklist items must make the package READY.');

$afterReady = $service->reconcile(new DateTimeImmutable('2026-09-17 12:00:00', new DateTimeZone('UTC')));
$assertSame(0, count($afterReady['reminders_due']), 'READY package must stop reminder escalation.');
$assertSame(
    3,
    (int)$database->fetchValue(
        "SELECT COUNT(*) FROM mgw_season_preparation_reminders
         WHERE ending_season_id = '2026-q3' AND reminder_state = 'satisfied'"
    ),
    'READY package must durably satisfy every preparation reminder.'
);

// READY boundary: close Q3 idempotently and start Q4 exactly at Moscow boundary.
$boundaryQ3 = $service->reconcile(new DateTimeImmutable('2026-09-30 21:00:01', new DateTimeZone('UTC')));
$assertSame('2026-q4', $boundaryQ3['current_season_id'], 'Boundary must advance current rating season to Q4.');
$assertSame('closed', (string)$database->fetchValue("SELECT season_state FROM mgw_rating_seasons WHERE season_id = '2026-q3'"), 'READY ending season must close.');
$assertSame('completed', (string)$database->fetchValue("SELECT operation_state FROM mgw_season_boundary_operations WHERE ending_season_id = '2026-q3'"), 'READY boundary operation must complete.');
$assertSame('2026-09-30 21:00:00.000000', (string)$database->fetchValue("SELECT standings_frozen_at_utc FROM mgw_rating_seasons WHERE season_id = '2026-q3'"), 'Standings freeze must use the exact quarter boundary.');
$assertSame('active', (string)$database->fetchValue("SELECT season_state FROM mgw_rating_seasons WHERE season_id = '2026-q4'"), 'Next quarter must start even though awards are owned by later MVP slices.');

// Missing-assets boundary: new season starts, previous standings stay durable in FINALIZING.
$boundaryQ4 = $service->reconcile(new DateTimeImmutable('2026-12-31 21:00:01', new DateTimeZone('UTC')));
$assertSame('2027-q1', $boundaryQ4['current_season_id'], 'Missing reward assets must not stop the next calendar season from starting.');
$assertSame('finalizing', (string)$database->fetchValue("SELECT season_state FROM mgw_rating_seasons WHERE season_id = '2026-q4'"), 'Missing assets must keep ending season in FINALIZING.');
$assertSame('assets_required', (string)$database->fetchValue("SELECT finalization_reason FROM mgw_rating_seasons WHERE season_id = '2026-q4'"), 'FINALIZING reason must be ASSETS_REQUIRED.');
$assertSame('assets_required', (string)$database->fetchValue("SELECT operation_state FROM mgw_season_boundary_operations WHERE ending_season_id = '2026-q4'"), 'Boundary operation must durably expose ASSETS_REQUIRED.');
$assertSame('active', (string)$database->fetchValue("SELECT season_state FROM mgw_rating_seasons WHERE season_id = '2027-q1'"), 'Current quarter must continue while prior awards wait.');
$assertSame(1, count($service->snapshot(new DateTimeImmutable('2027-01-01 00:00:00', new DateTimeZone('UTC')))['pending_finalizations']), 'Blocked season must remain visible to future Admin/status owners.');

// Once the approved package becomes READY, retrying reconcile completes exactly once.
$service->updateRewardReadiness('2027-q1', [
    'seasonal_awards_state' => 'ready',
    'top3_frames_state' => 'ready',
    'yearly_medal_state' => 'ready',
    'localization_state' => 'ready',
    'preview_validation_state' => 'ready',
], new DateTimeImmutable('2027-01-02 12:00:00', new DateTimeZone('UTC')));
$recovered = $service->reconcile(new DateTimeImmutable('2027-01-02 12:00:01', new DateTimeZone('UTC')));
$assertSame(['2026-q4'], $recovered['recovered'], 'READY retry must resume the blocked finalization.');
$assertSame('closed', (string)$database->fetchValue("SELECT season_state FROM mgw_rating_seasons WHERE season_id = '2026-q4'"), 'Recovered season must close.');
$assertSame('completed', (string)$database->fetchValue("SELECT operation_state FROM mgw_season_boundary_operations WHERE ending_season_id = '2026-q4'"), 'Recovered operation must complete.');

$repeat = $service->reconcile(new DateTimeImmutable('2027-01-02 12:00:02', new DateTimeZone('UTC')));
$assertSame([], $repeat['recovered'], 'Repeated reconcile must not finalize the same season twice.');
$assertSame(2, (int)$database->fetchValue('SELECT COUNT(*) FROM mgw_season_boundary_operations'), 'Each quarter boundary must have exactly one durable operation.');
$assertSame(3, (int)$database->fetchValue('SELECT COUNT(*) FROM mgw_rating_seasons'), 'Q3, Q4 and Q1 must each exist exactly once.');

// Match assignment is based on finish time, not on when the delayed projection runs.
$control = [
    'competition_state' => 'active',
    'current_season_id' => '2027-q1',
    'activated_at' => '2026-08-15 10:00:00.000000',
];
$resolver = new SeasonAssignmentResolver($database);
$assertSame('preseason', $resolver->resolve('2026-08-15 09:59:59.000000', $control)['season_id'], 'Pre-activation result must remain PRESEASON forever.');
$assertSame('2026-q3', $resolver->resolve('2026-09-01 12:00:00.000000', $control)['season_id'], 'Delayed Q3 result must remain in Q3.');
$assertSame('2026-q4', $resolver->resolve('2026-10-01 12:00:00.000000', $control)['season_id'], 'Delayed Q4 result must remain in Q4.');
$assertSame('2027-q1', $resolver->resolve('2027-01-01 12:00:00.000000', $control)['season_id'], 'Current-quarter result must resolve to Q1.');

$assertTrue($assertions >= 35, 'MVP-20.4 focused contract must cover calendar, PRESEASON, reminders, boundaries, recovery and assignment.');
fwrite(STDOUT, "Mvp20_4QuarterlySeasonLifecycleTest: {$assertions} assertions passed\n");
