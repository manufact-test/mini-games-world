<?php
declare(strict_types=1);

require dirname(__DIR__) . '/database/DatabaseConnectionInterface.php';
require dirname(__DIR__) . '/database/DatabaseExceptionClassifier.php';
require dirname(__DIR__) . '/database/PdoDatabaseConnection.php';
require dirname(__DIR__) . '/database/DatabaseMigrationInterface.php';

if (!class_exists('PerGameRatingService')) {
    final class PerGameRatingService
    {
        public const STATE_OFF = 'off';
        public const STATE_PRESEASON = 'preseason';
        public const STATE_ACTIVE = 'active';
        public const PRESEASON_ID = 'preseason';
    }
}

require dirname(__DIR__) . '/ratings/SeasonCalendar.php';
require dirname(__DIR__) . '/ratings/SeasonLifecycleService.php';
require dirname(__DIR__) . '/operations/AdminOperationsService.php';

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};
$assertSame = static function (mixed $expected, mixed $actual, string $message) use (&$assertions): void {
    $assertions++;
    if ($expected !== $actual) {
        throw new RuntimeException($message . ': expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
};

$dsn = trim((string)getenv('MGW_OPERATIONS_MYSQL_DSN'));
if ($dsn !== '') {
    $pdo = new PDO(
        $dsn,
        (string)getenv('MGW_OPERATIONS_MYSQL_USER'),
        (string)getenv('MGW_OPERATIONS_MYSQL_PASS'),
        [
            PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES=>false,
        ]
    );
} else {
    $pdo = new PDO('sqlite::memory:');
}
$db = new PdoDatabaseConnection($pdo);

$tables = [
    'mgw_admin_operations_audit',
    'mgw_admin_release_log',
    'mgw_admin_future_plans',
    'mgw_admin_tasks',
    'mgw_season_boundary_operations',
    'mgw_season_preparation_reminders',
    'mgw_season_reward_packages',
    'mgw_rating_seasons',
    'mgw_rating_control',
];
if ($db->driver() === 'mysql') $db->execute('SET FOREIGN_KEY_CHECKS=0');
foreach ($tables as $table) $db->execute('DROP TABLE IF EXISTS ' . $table);
if ($db->driver() === 'mysql') $db->execute('SET FOREIGN_KEY_CHECKS=1');

(require dirname(__DIR__) . '/database/migrations/20260920_0045_create_quarterly_season_lifecycle.php')->up($db);
(require dirname(__DIR__) . '/database/migrations/20260926_0066_create_admin_operations.php')->up($db);

if ($db->driver() === 'mysql') {
    $db->execute(<<<'SQL'
CREATE TABLE mgw_rating_control (
    control_key VARCHAR(32) NOT NULL PRIMARY KEY,
    competition_state VARCHAR(24) NOT NULL,
    current_season_id VARCHAR(64) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
} else {
    $db->execute('CREATE TABLE mgw_rating_control (control_key TEXT PRIMARY KEY, competition_state TEXT NOT NULL, current_season_id TEXT NOT NULL)');
}
$db->execute(
    'INSERT INTO mgw_rating_control (control_key,competition_state,current_season_id)
     VALUES (:key,:state,:season)',
    ['key'=>'global','state'=>'active','season'=>'2026-q3']
);

$calendar = new SeasonCalendar();
$current = $calendar->definition(2026, 3);
$next = $calendar->definition(2026, 4);
$nowText = '2026-09-26 12:00:00.000000';

$db->execute(
    'INSERT INTO mgw_rating_seasons (
        season_id,calendar_year,quarter,timezone,calendar_start_at_utc,calendar_end_at_utc,
        official_start_at_utc,season_state,finalization_reason,standings_frozen_at_utc,
        finalization_started_at_utc,finalized_at_utc,created_at_utc,updated_at_utc
     ) VALUES (
        :season_id,:year,:quarter,:timezone,:calendar_start,:calendar_end,
        :official_start,:state,NULL,NULL,NULL,NULL,:created,:updated
     )',
    [
        'season_id'=>$current['season_id'],
        'year'=>$current['calendar_year'],
        'quarter'=>$current['quarter'],
        'timezone'=>$current['timezone'],
        'calendar_start'=>$current['calendar_start_at_utc'],
        'calendar_end'=>$current['calendar_end_at_utc'],
        'official_start'=>$current['calendar_start_at_utc'],
        'state'=>'active',
        'created'=>$nowText,
        'updated'=>$nowText,
    ]
);
$db->execute(
    'INSERT INTO mgw_season_reward_packages (
        target_season_id,package_state,seasonal_awards_state,top3_frames_state,
        yearly_medal_state,localization_state,preview_validation_state,
        ready_at_utc,created_at_utc,updated_at_utc
     ) VALUES (
        :target,:package,:awards,:frames,:medal,:localization,:preview,NULL,:created,:updated
     )',
    [
        'target'=>$next['season_id'],
        'package'=>'pending',
        'awards'=>'pending',
        'frames'=>'pending',
        'medal'=>'pending',
        'localization'=>'pending',
        'preview'=>'pending',
        'created'=>$nowText,
        'updated'=>$nowText,
    ]
);
foreach ([21,14,7] as $days) {
    $db->execute(
        'INSERT INTO mgw_season_preparation_reminders (
            ending_season_id,target_season_id,checkpoint_days,due_at_utc,reminder_state,
            became_due_at_utc,resolved_at_utc,created_at_utc,updated_at_utc
         ) VALUES (
            :ending,:target,:days,:due,:state,NULL,NULL,:created,:updated
         )',
        [
            'ending'=>$current['season_id'],
            'target'=>$next['season_id'],
            'days'=>$days,
            'due'=>$calendar->reminderDueAt($current, $days),
            'state'=>'pending',
            'created'=>$nowText,
            'updated'=>$nowText,
        ]
    );
}

$service = new AdminOperationsService($db, $calendar);
$now = new DateTimeImmutable('2026-09-26T12:00:00+00:00');
$snapshot = $service->snapshot($now);

$assertSame(true, $snapshot['season_preparation']['enabled'], 'ACTIVE competition must expose recurring season preparation');
$assertSame('2026-q4', $snapshot['season_preparation']['target_season_id'], 'Next quarterly season must come from SeasonCalendar');
$assertSame(3, count($snapshot['tasks']['active']), 'All three due canonical T-21/T-14/T-7 reminders must materialize as Admin tasks');
$titles = array_column($snapshot['tasks']['active'], 'title');
$assert(in_array('Сезон заканчивается через 3 недели — подготовьте награды следующего сезона', $titles, true), 'T-21 copy must remain canonical');

$again = $service->snapshot($now);
$assertSame(3, count($again['tasks']['active']), 'Season reminder projection must be idempotent');

$weekly = $service->createTask([
    'title'=>'Еженедельная операционная проверка',
    'category'=>'operations',
    'recurrence_code'=>'weekly',
    'due_at_utc'=>'2026-09-26T13:00:00+00:00',
    'owner_ref'=>'ops',
], 'telegram:1', $now);
$assertSame('open', $weekly['task_status'], 'Manual recurring task must start open');
$service->updateTask(
    (string)$weekly['task_id'],
    ['task_status'=>'done','result_text'=>'Проверено','owner_ref'=>'ops'],
    'telegram:1',
    new DateTimeImmutable('2026-09-26T14:00:00+00:00')
);
$afterWeekly = $service->snapshot(new DateTimeImmutable('2026-09-26T14:00:00+00:00'));
$series = array_values(array_filter(
    array_merge($afterWeekly['tasks']['active'], $afterWeekly['tasks']['recent_closed']),
    static fn(array $row): bool => (string)$row['series_id'] === (string)$weekly['series_id']
));
$assertSame(2, count($series), 'Completing a recurring task must create exactly one next occurrence');
$nextOccurrence = array_values(array_filter($series, static fn(array $row): bool => $row['task_status'] === 'open'));
$assertSame(1, count($nextOccurrence), 'Recurring series must have one open next occurrence');
$assert(str_starts_with((string)$nextOccurrence[0]['due_at_utc'], '2026-10-03 13:00:00'), 'Weekly recurrence must advance by seven days');

$seasonReady = $service->updateSeasonReadiness('2026-q4', [
    'seasonal_awards_state'=>'ready',
    'top3_frames_state'=>'ready',
    'yearly_medal_state'=>'ready',
    'localization_state'=>'ready',
    'preview_validation_state'=>'ready',
], 'telegram:1', new DateTimeImmutable('2026-09-26T15:00:00+00:00'));
$assertSame(true, $seasonReady['ready'], 'Season package READY must be computed by SeasonLifecycleService');
$afterReady = $service->snapshot(new DateTimeImmutable('2026-09-26T15:00:00+00:00'));
$seasonOpen = array_values(array_filter(
    $afterReady['tasks']['active'],
    static fn(array $row): bool => (string)$row['source_type'] === 'season_preparation'
));
$assertSame(0, count($seasonOpen), 'Season reminder Admin tasks must stop when canonical package becomes READY');

$plan = $service->createPlan([
    'title'=>'Новый режим после стабилизации',
    'category'=>'product',
    'plan_status'=>'idea',
    'target_period'=>'после MVP-22',
    'owner_ref'=>'product',
    'notes'=>'Проверить после закрытия админки.',
], 'telegram:1', $now);
$updatedPlan = $service->updatePlan(
    (string)$plan['plan_id'],
    ['plan_status'=>'planned','target_period'=>'Q1 2027'],
    'telegram:1',
    $now
);
$assertSame('planned', $updatedPlan['plan_status'], 'Future Plan status must be editable');
$assertSame('Q1 2027', $updatedPlan['target_period'], 'Future Plan target period must be durable');

$release = $service->createRelease([
    'version_label'=>'mvp22.7-staging',
    'environment'=>'staging',
    'release_sha'=>str_repeat('a', 40),
    'released_at_utc'=>'2026-09-26T16:00:00+00:00',
    'summary_text'=>'Задачи, планы и журнал релизов.',
    'known_issues_text'=>'Нет известных.',
    'rollback_link'=>'https://github.com/manufact-test/mini-games-world/tree/' . str_repeat('a', 40),
], 'telegram:1', $now);
$assertSame(str_repeat('a', 40), $release['release_sha'], 'Release log must preserve exact 40-char SHA');
$release = $service->updateRelease(
    (string)$release['release_id'],
    ['known_issues_text'=>'Открытых известных проблем нет.'],
    'telegram:1',
    $now
);
$assertSame('Открытых известных проблем нет.', $release['known_issues_text'], 'Known issues must be updateable without rewriting release identity');

$badRollback = false;
try {
    $service->createRelease([
        'version_label'=>'bad',
        'environment'=>'staging',
        'release_sha'=>str_repeat('b', 40),
        'released_at_utc'=>'2026-09-26T16:00:00+00:00',
        'summary_text'=>'bad',
        'rollback_link'=>'https://example.com/rollback',
    ], 'telegram:1', $now);
} catch (InvalidArgumentException) {
    $badRollback = true;
}
$assert($badRollback, 'Rollback link must stay inside the MGW GitHub repository');

$final = $service->snapshot($now);
$assertSame(1, count($final['release_log']), 'MVP-22.7 must not invent retroactive release history');
$assert(count($final['recent_audit']) >= 8, 'Task/plan/release/readiness changes must be auditable');
$assert(str_contains($final['coverage']['release_history'], 'Старые релизы не восстанавливаются'), 'Release history limitation must be explicit');
$assert(str_contains($final['coverage']['season_schedule'], 'не дублируются'), 'Season reminder ownership boundary must be explicit');

fwrite(STDOUT, "Mvp22_7AdminOperationsTest: {$assertions} assertions passed\n");
