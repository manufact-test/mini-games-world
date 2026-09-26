<?php
declare(strict_types=1);

require_once __DIR__ . '/../database/DatabaseConnectionInterface.php';
require_once __DIR__ . '/../database/DatabaseExceptionClassifier.php';
require_once __DIR__ . '/../database/PdoDatabaseConnection.php';
require_once __DIR__ . '/../database/DatabaseMigrationInterface.php';
require_once __DIR__ . '/../incident/IncidentRecoveryService.php';
require_once __DIR__ . '/../accounts/MgwIdGenerator.php';
require_once __DIR__ . '/../accounts/AccountIdentityService.php';

$dsn = trim((string)getenv('MGW_INCIDENT_MYSQL_DSN'));
$user = (string)getenv('MGW_INCIDENT_MYSQL_USER');
$pass = (string)getenv('MGW_INCIDENT_MYSQL_PASS');
if ($dsn === '') {
    fwrite(STDOUT, "Mvp22_9IncidentRecoveryMySqlIntegrationTest skipped: no DSN.\n");
    return;
}

$pdo = new PDO($dsn, $user, $pass, [
    PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES=>false,
]);
$db = new PdoDatabaseConnection($pdo);

$tables = [
    'mgw_incident_audit',
    'mgw_incident_restore_status',
    'mgw_incident_evidence',
    'mgw_incident_key_checks',
    'mgw_incident_actions',
    'mgw_incidents',
    'mgw_sessions',
];
$db->execute('SET FOREIGN_KEY_CHECKS=0');
foreach ($tables as $table) $db->execute('DROP TABLE IF EXISTS ' . $table);
$db->execute('SET FOREIGN_KEY_CHECKS=1');

(require __DIR__ . '/../database/migrations/20260926_0068_create_incident_recovery.php')->up($db);

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};
$assertThrows = static function (callable $callback, string $contains, string $message) use (&$assertions): void {
    $assertions++;
    try {
        $callback();
    } catch (Throwable $error) {
        if ($contains === '' || str_contains($error->getMessage(), $contains)) return;
        throw new RuntimeException($message . ': unexpected error ' . $error->getMessage());
    }
    throw new RuntimeException($message . ': no exception');
};

$service = new IncidentRecoveryService($db);
$incident = $service->createIncident(
    'MySQL incident recovery proof',
    'MySQL lifecycle without destructive production changes.',
    'telegram:mysql-admin-one'
);
$incidentId = (string)$incident['incident_id'];
$assert($incidentId !== '', 'MySQL incident must be created.');

$action = $service->requestHighRiskAction(
    $incidentId,
    'enable_security_mode',
    'MySQL second-admin proof.',
    'telegram:mysql-admin-one',
    ['baseline_flags'=>[
        'maintenance_mode'=>false,
        'maintenance_message'=>'',
        'financial_read_only'=>false,
        'features'=>['matchmaking'=>true],
        'games'=>['chess'=>true],
    ]]
);
$assert((string)$action['status_code'] === IncidentRecoveryService::ACTION_PENDING, 'MySQL action must wait for second review.');
$assertThrows(
    fn() => $service->claimHighRiskAction((string)$action['action_id'], 'telegram:mysql-admin-one', 'Self review'),
    'другой администратор',
    'MySQL must reject same-admin confirmation.'
);
$service->claimHighRiskAction((string)$action['action_id'], 'telegram:mysql-admin-two', 'Independent MySQL review.');
$completed = $service->completeHighRiskAction(
    (string)$action['action_id'],
    ['ok'=>true,'changed'=>true],
    'telegram:mysql-admin-two'
);
$assert((string)$completed['status_code'] === IncidentRecoveryService::ACTION_COMPLETED, 'MySQL confirmed action must complete.');

$service->addEvidence(
    $incidentId,
    'backup_reference',
    'MySQL backup reference',
    'backup:mysql-integration-safe',
    str_repeat('c', 64),
    'telegram:mysql-admin-one'
);
$restore = $service->updateRestoreStatus(
    $incidentId,
    'verified',
    'backup:mysql-integration-safe',
    'branch:rollback-proof',
    str_repeat('d', 40),
    'MySQL restore reference verified.',
    'telegram:mysql-admin-one'
);
$assert((string)$restore['status_code'] === 'verified', 'MySQL restore status must persist.');

$db->execute(<<<'SQL'
CREATE TABLE mgw_sessions (
    session_key_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY,
    mgw_id VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    device_id BIGINT UNSIGNED NULL,
    provider VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    issued_at_utc DATETIME(6) NOT NULL,
    last_seen_at_utc DATETIME(6) NOT NULL,
    expires_at_utc DATETIME(6) NOT NULL,
    revoked_at_utc DATETIME(6) NULL,
    INDEX idx_mgw_sessions_expiry (expires_at_utc, revoked_at_utc)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
$now = new DateTimeImmutable('2026-09-26 20:00:00', new DateTimeZone('UTC'));
foreach ([
    [str_repeat('1',64),'MGWSQL0000000000000001','2026-09-27 20:00:00.000000',null],
    [str_repeat('2',64),'MGWSQL0000000000000002','2026-09-27 20:00:00.000000',null],
    [str_repeat('3',64),'MGWSQL0000000000000003','2026-09-25 20:00:00.000000',null],
] as [$hash,$mgwId,$expires,$revoked]) {
    $db->execute(
        'INSERT INTO mgw_sessions (
            session_key_hash,mgw_id,device_id,provider,issued_at_utc,last_seen_at_utc,expires_at_utc,revoked_at_utc
         ) VALUES (
            :hash,:mgw_id,NULL,:provider,:issued,:seen,:expires,:revoked
         )',
        [
            'hash'=>$hash,
            'mgw_id'=>$mgwId,
            'provider'=>'telegram',
            'issued'=>'2026-09-26 10:00:00.000000',
            'seen'=>'2026-09-26 19:00:00.000000',
            'expires'=>$expires,
            'revoked'=>$revoked,
        ]
    );
}
$identity = new AccountIdentityService($db);
$assert($identity->activeSessionCount($now) === 2, 'MySQL must count only active sessions.');
$assert($identity->revokeAllActiveSessions($now) === 2, 'MySQL must revoke only active sessions.');
$assert($identity->activeSessionCount($now) === 0, 'MySQL must leave no active session after global revoke.');

$disable = $service->requestHighRiskAction(
    $incidentId,
    'disable_security_mode',
    'MySQL recovery complete.',
    'telegram:mysql-admin-one'
);
$service->claimHighRiskAction((string)$disable['action_id'], 'telegram:mysql-admin-two', 'Independent disable review.');
$service->completeHighRiskAction((string)$disable['action_id'], ['ok'=>true,'changed'=>true], 'telegram:mysql-admin-two');
$resolved = $service->setIncidentStatus($incidentId, 'resolved', 'MySQL lifecycle complete.', 'telegram:mysql-admin-one');
$assert((string)$resolved['incident_status'] === 'resolved', 'MySQL incident must resolve.');

$snapshot = $service->snapshot();
$assert($snapshot['active_incident'] === null, 'MySQL resolved incident must leave no active incident.');
$assert(count($snapshot['incidents']) === 1, 'MySQL incident history must remain durable.');

$db->execute('SET FOREIGN_KEY_CHECKS=0');
foreach ($tables as $table) $db->execute('DROP TABLE IF EXISTS ' . $table);
$db->execute('SET FOREIGN_KEY_CHECKS=1');

fwrite(STDOUT, "MVP-22.9 incident recovery MySQL 8.4 OK ({$assertions} assertions).\n");
