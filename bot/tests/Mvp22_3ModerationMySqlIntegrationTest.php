<?php
declare(strict_types=1);

require_once __DIR__ . '/../database/DatabaseConnectionInterface.php';
require_once __DIR__ . '/../database/DatabaseExceptionClassifier.php';
require_once __DIR__ . '/../database/PdoDatabaseConnection.php';
require_once __DIR__ . '/../database/DatabaseMigrationInterface.php';
require_once __DIR__ . '/../accounts/MgwIdGenerator.php';
require_once __DIR__ . '/../moderation/ModerationService.php';

$dsn = trim((string)getenv('MGW_MODERATION_MYSQL_DSN'));
$user = (string)getenv('MGW_MODERATION_MYSQL_USER');
$pass = (string)getenv('MGW_MODERATION_MYSQL_PASS');
if ($dsn === '') {
    fwrite(STDOUT, "Mvp22_3ModerationMySqlIntegrationTest skipped: no DSN.\n");
    return;
}

$pdo = new PDO($dsn, $user, $pass, [
    PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES=>false,
]);
$db = new PdoDatabaseConnection($pdo);

$tables = [
    'mgw_moderation_events',
    'mgw_moderation_appeals',
    'mgw_moderation_actions',
    'mgw_player_reports',
    'mgw_match_players',
    'mgw_matches',
    'mgw_users',
];
$db->execute('SET FOREIGN_KEY_CHECKS=0');
foreach ($tables as $table) $db->execute('DROP TABLE IF EXISTS ' . $table);
$db->execute('SET FOREIGN_KEY_CHECKS=1');

$db->execute(<<<'SQL'
CREATE TABLE mgw_users (
    mgw_id VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY,
    status VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'active',
    nickname VARCHAR(160) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
    updated_at_utc DATETIME(6) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
$db->execute(<<<'SQL'
CREATE TABLE mgw_matches (
    match_id VARCHAR(96) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

(require __DIR__ . '/../database/migrations/20260819_0012_create_player_reports.php')->up($db);
(require __DIR__ . '/../database/migrations/20260925_0063_create_moderation_restrictions_appeals.php')->up($db);

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};
$assertReason = static function (callable $callback, string $reason, string $message) use (&$assertions): void {
    $assertions++;
    try {
        $callback();
    } catch (ModerationException $error) {
        if ($error->reason === $reason) return;
        throw new RuntimeException($message . ': expected ' . $reason . ', got ' . $error->reason);
    }
    throw new RuntimeException($message . ': no exception');
};

$reporter = 'MGW-0123456789ABCDEH';
$target = 'MGW-0123456789ABCDEJ';
$now = '2026-09-25 12:00:00.000000';
foreach ([[$reporter,'Reporter SQL'],[$target,'Target SQL']] as [$id,$nickname]) {
    $db->execute(
        'INSERT INTO mgw_users (mgw_id,status,nickname,updated_at_utc)
         VALUES (:id,:status,:nickname,:updated)',
        ['id'=>$id,'status'=>'active','nickname'=>$nickname,'updated'=>$now]
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
        'report_id'=>'RPT-MYSQL-MVP22300001',
        'reporter'=>$reporter,
        'target'=>$target,
        'reason'=>'stalling',
        'details'=>'MySQL moderation fixture',
        'status'=>'open',
        'created'=>$now,
        'updated'=>$now,
    ]
);

$service = new ModerationService($db);
$restriction = $service->restrict(
    'RPT-MYSQL-MVP22300001',
    'gameplay',
    86400,
    '24 hour restriction MySQL proof',
    'telegram:mysql-admin-one'
);
$assert($restriction['status'] === 'active', 'MySQL restriction must become active.');
$assertReason(
    fn() => $service->assertAllowed($target, 'gameplay'),
    'restricted',
    'MySQL gameplay restriction must enforce its scope.'
);

$appeal = $service->submitAppeal($target, (string)$restriction['action_id'], 'Appeal MySQL restriction.');
$assert($appeal['status'] === 'open', 'MySQL appeal must be stored.');
$reviewed = $service->reviewAppeal(
    (string)$appeal['appeal_id'],
    'accept',
    'Restriction revoked after review.',
    'telegram:mysql-admin-two'
);
$assert($reviewed['status'] === 'accepted', 'MySQL appeal acceptance must persist.');
$service->assertAllowed($target, 'gameplay');
$assertions++;

$ban = $service->recommendPermanentBan(
    'RPT-MYSQL-MVP22300001',
    'Permanent ban recommendation MySQL proof.',
    'telegram:mysql-admin-one'
);
$assert($ban['status'] === 'pending_second_review', 'MySQL ban must wait for second review.');
$assertReason(
    fn() => $service->reviewPermanentBan(
        (string)$ban['action_id'],
        'approve',
        'Same-admin attempt.',
        'telegram:mysql-admin-one'
    ),
    'second_admin_required',
    'MySQL permanent ban must reject same-admin approval.'
);
$confirmed = $service->reviewPermanentBan(
    (string)$ban['action_id'],
    'approve',
    'Independent second review.',
    'telegram:mysql-admin-two'
);
$assert($confirmed['status'] === 'confirmed', 'MySQL second-admin approval must confirm ban.');
$assert((string)$db->fetchValue('SELECT status FROM mgw_users WHERE mgw_id=:id', ['id'=>$target]) === 'banned', 'MySQL confirmed ban must set canonical user status.');

$snapshot = $service->reportSnapshot('RPT-MYSQL-MVP22300001');
$assert(count($snapshot['actions']) === 2, 'MySQL report snapshot must contain restriction and ban.');
$assert(count($snapshot['appeals']) === 1, 'MySQL report snapshot must contain appeal.');
$assert(count($snapshot['events']) >= 5, 'MySQL moderation audit trail must be durable.');

$db->execute('SET FOREIGN_KEY_CHECKS=0');
foreach ($tables as $table) $db->execute('DROP TABLE IF EXISTS ' . $table);
$db->execute('SET FOREIGN_KEY_CHECKS=1');

fwrite(STDOUT, "MVP-22.3 moderation MySQL 8.4 integration OK ($assertions assertions).\n");
