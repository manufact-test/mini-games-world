<?php
declare(strict_types=1);

require_once __DIR__ . '/../database/DatabaseConnectionInterface.php';
require_once __DIR__ . '/../database/DatabaseExceptionClassifier.php';
require_once __DIR__ . '/../database/PdoDatabaseConnection.php';
require_once __DIR__ . '/../database/DatabaseMigrationInterface.php';
require_once __DIR__ . '/../accounts/MgwIdGenerator.php';
require_once __DIR__ . '/../moderation/ModerationService.php';

if (!extension_loaded('pdo_sqlite')) {
    fwrite(STDOUT, "SKIP: pdo_sqlite is unavailable\n");
    exit(0);
}

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('PRAGMA foreign_keys = ON');
$db = new PdoDatabaseConnection($pdo);

$db->execute(<<<'SQL'
CREATE TABLE mgw_users (
    mgw_id TEXT NOT NULL PRIMARY KEY,
    status TEXT NOT NULL DEFAULT 'active',
    nickname TEXT NOT NULL,
    updated_at_utc TEXT NOT NULL
)
SQL);
$db->execute('CREATE TABLE mgw_matches (match_id TEXT NOT NULL PRIMARY KEY)');

(require __DIR__ . '/../database/migrations/20260819_0012_create_player_reports.php')->up($db);
(require __DIR__ . '/../database/migrations/20260925_0063_create_moderation_restrictions_appeals.php')->up($db);

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
$assertModerationReason = static function (callable $callback, string $expectedReason, string $message) use (&$assertions): void {
    $assertions++;
    try {
        $callback();
    } catch (ModerationException $error) {
        if ($error->reason === $expectedReason) return;
        throw new RuntimeException($message . ': expected reason ' . $expectedReason . ', got ' . $error->reason);
    }
    throw new RuntimeException($message . ': exception was not thrown');
};

$reporter = 'MGW-0123456789ABCDEF';
$target = 'MGW-0123456789ABCDEG';
$now = '2026-09-25 12:00:00.000000';
foreach ([[$reporter,'Reporter'],[$target,'Target']] as [$id,$nickname]) {
    $db->execute(
        'INSERT INTO mgw_users (mgw_id,status,nickname,updated_at_utc)
         VALUES (:mgw_id,:status,:nickname,:updated_at_utc)',
        ['mgw_id'=>$id,'status'=>'active','nickname'=>$nickname,'updated_at_utc'=>$now]
    );
}
$db->execute(
    'INSERT INTO mgw_player_reports (
        report_id,reporter_mgw_id,target_mgw_id,reason,details,related_match_id,status,
        created_at_utc,updated_at_utc,reviewed_at_utc,resolved_at_utc,last_admin_ref
     ) VALUES (
        :report_id,:reporter,:target,:reason,:details,NULL,:status,
        :created,:updated,NULL,NULL,NULL
     )',
    [
        'report_id'=>'RPT-MVP22300000000001',
        'reporter'=>$reporter,
        'target'=>$target,
        'reason'=>'cheating',
        'details'=>'Проверочная жалоба MVP-22.3',
        'status'=>'open',
        'created'=>$now,
        'updated'=>$now,
    ]
);

$service = new ModerationService($db);
$options = $service->adminOptions();
$assertSame(
    ['nickname','avatar','spam','cheating','stalling','other'],
    array_keys($options['report_reasons']),
    'Moderation report reasons must match the authoritative MVP-22.3 categories.'
);
$assertSame(4, count($options['restriction_scopes']), 'Moderation must expose four bounded restriction scopes.');
$assertSame(4, count($options['restriction_durations']), 'Moderation must expose bounded duration choices.');

$warning = $service->warning(
    'RPT-MVP22300000000001',
    'Формальное предупреждение после ручной проверки',
    'telegram:admin-one'
);
$assertSame('warning', $warning['action_type'], 'Warning must be a durable moderation action.');
$assertSame('active', $warning['status'], 'Warning must remain visible as an active moderation decision.');

$restriction = $service->restrict(
    'RPT-MVP22300000000001',
    'social',
    3600,
    'Временно ограничить социальные действия',
    'telegram:admin-one'
);
$assertSame('restriction', $restriction['action_type'], 'Restriction must be stored as its own action.');
$assertSame('social', $restriction['scope_code'], 'Restriction scope must be explicit.');
$assertTrue((string)$restriction['expires_at_utc'] !== '', 'Timed restriction must keep its expiry.');

$service->assertAllowed($target, 'profile');
$assertions++;
$assertModerationReason(
    fn() => $service->assertAllowed($target, 'social'),
    'restricted',
    'Matching restriction scope must block the guarded action.'
);

$appeal = $service->submitAppeal(
    $target,
    (string)$restriction['action_id'],
    'Прошу пересмотреть временное ограничение.'
);
$assertSame('open', $appeal['status'], 'Player appeal must enter the queue as open.');
$assertModerationReason(
    fn() => $service->submitAppeal(
        $target,
        (string)$restriction['action_id'],
        'Дубликат апелляции.'
    ),
    'appeal_exists',
    'One moderation action must not receive duplicate open appeals.'
);

$appealAccepted = $service->reviewAppeal(
    (string)$appeal['appeal_id'],
    'accept',
    'Ограничение снимается после повторной проверки.',
    'telegram:admin-two'
);
$assertSame('accepted', $appealAccepted['status'], 'Accepted appeal must have durable accepted state.');
$service->assertAllowed($target, 'social');
$assertions++;

$ban = $service->recommendPermanentBan(
    'RPT-MVP22300000000001',
    'Повторные серьёзные нарушения — нужна вторая проверка.',
    'telegram:admin-one'
);
$assertSame('permanent_ban', $ban['action_type'], 'Permanent ban must begin as a recommendation action.');
$assertSame('pending_second_review', $ban['status'], 'Permanent ban recommendation must wait for a second admin.');
$assertSame('active', (string)$db->fetchValue('SELECT status FROM mgw_users WHERE mgw_id=:id', ['id'=>$target]), 'Recommendation alone must never ban the account.');

$assertModerationReason(
    fn() => $service->reviewPermanentBan(
        (string)$ban['action_id'],
        'approve',
        'Попытка самоподтверждения.',
        'telegram:admin-one'
    ),
    'second_admin_required',
    'The recommending admin must not confirm their own permanent ban.'
);
$assertSame('active', (string)$db->fetchValue('SELECT status FROM mgw_users WHERE mgw_id=:id', ['id'=>$target]), 'Rejected same-admin confirmation must not change account status.');

$confirmedBan = $service->reviewPermanentBan(
    (string)$ban['action_id'],
    'approve',
    'Независимая вторая проверка подтверждает блокировку.',
    'telegram:admin-two'
);
$assertSame('confirmed', $confirmedBan['status'], 'Second admin approval must confirm permanent ban.');
$assertSame('telegram:admin-two', $confirmedBan['second_review_by_admin_ref'], 'Second reviewer must remain auditable.');
$assertSame('banned', (string)$db->fetchValue('SELECT status FROM mgw_users WHERE mgw_id=:id', ['id'=>$target]), 'Confirmed permanent ban must set the canonical account status.');
$assertModerationReason(
    fn() => $service->assertAllowed($target, 'gameplay'),
    'account_banned',
    'Confirmed permanent ban must block guarded gameplay entry.'
);

$banAppeal = $service->submitAppeal(
    $target,
    (string)$ban['action_id'],
    'Прошу пересмотреть постоянную блокировку.'
);
$banAppealAccepted = $service->reviewAppeal(
    (string)$banAppeal['appeal_id'],
    'accept',
    'Блокировка отменена после апелляции.',
    'telegram:admin-three'
);
$assertSame('accepted', $banAppealAccepted['status'], 'Permanent-ban appeal must support explicit acceptance.');
$assertSame('active', (string)$db->fetchValue('SELECT status FROM mgw_users WHERE mgw_id=:id', ['id'=>$target]), 'Accepted permanent-ban appeal must restore active account state.');
$service->assertAllowed($target, 'gameplay');
$assertions++;

$snapshot = $service->userSnapshot($target);
$assertSame(3, count($snapshot['actions']), 'User moderation snapshot must expose warning, restriction and ban review history.');
$assertSame(2, count($snapshot['appeals']), 'User moderation snapshot must expose both appeals.');
$reportSnapshot = $service->reportSnapshot('RPT-MVP22300000000001');
$assertSame(3, count($reportSnapshot['actions']), 'Admin report snapshot must keep every moderation decision.');
$assertSame(2, count($reportSnapshot['appeals']), 'Admin report snapshot must keep linked appeals.');
$assertTrue(count($reportSnapshot['events']) >= 7, 'Moderation audit must retain action, review and appeal events.');

fwrite(STDOUT, "MVP-22.3 moderation workflow OK ($assertions assertions, sqlite).\n");
