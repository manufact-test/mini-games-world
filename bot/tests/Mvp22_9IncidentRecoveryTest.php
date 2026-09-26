<?php
declare(strict_types=1);

require_once __DIR__ . '/../database/DatabaseConnectionInterface.php';
require_once __DIR__ . '/../database/DatabaseExceptionClassifier.php';
require_once __DIR__ . '/../database/PdoDatabaseConnection.php';
require_once __DIR__ . '/../database/DatabaseMigrationInterface.php';
require_once __DIR__ . '/../incident/IncidentRecoveryService.php';
require_once __DIR__ . '/../accounts/MgwIdGenerator.php';
require_once __DIR__ . '/../accounts/AccountIdentityService.php';

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
$assertThrows = static function (callable $callback, string $contains, string $message) use (&$assertions): void {
    $assertions++;
    try {
        $callback();
    } catch (Throwable $error) {
        if ($contains === '' || str_contains($error->getMessage(), $contains)) return;
        throw new RuntimeException($message . ': unexpected error: ' . $error->getMessage());
    }
    throw new RuntimeException($message . ': no exception');
};

$pdo = new PDO('sqlite::memory:');
$pdo->exec('PRAGMA foreign_keys = ON');
$db = new PdoDatabaseConnection($pdo);
(require __DIR__ . '/../database/migrations/20260926_0068_create_incident_recovery.php')->up($db);

$service = new IncidentRecoveryService($db);
$incident = $service->createIncident(
    'Подозрительная активность',
    'Тестовый инцидент без production-изменений.',
    'telegram:admin-one'
);
$incidentId = (string)$incident['incident_id'];
$assertSame('open', $incident['incident_status'], 'New incident must start open.');

$snapshot = $service->snapshot();
$assertSame($incidentId, (string)($snapshot['active_incident']['incident_id'] ?? ''), 'Snapshot must expose active incident.');
$assertSame(3, count($snapshot['key_checks']), 'Key checklist must be initialized.');
$assertSame('not_started', (string)($snapshot['restore_status']['status_code'] ?? ''), 'Restore status must start as not_started.');

$assertThrows(
    fn() => $service->createIncident('Второй', '', 'telegram:admin-two'),
    'текущий активный инцидент',
    'Only one active incident may exist.'
);

$key = $service->updateKeyCheck(
    $incidentId,
    'telegram_bot_token',
    'verified',
    'Проверено в защищённом хранилище; значение не копировалось.',
    'telegram:admin-one'
);
$assertSame('verified', $key['status_code'], 'Key checklist status must persist.');

$evidence = $service->addEvidence(
    $incidentId,
    'workflow_run',
    'Проверка CI',
    'workflow:36270210413',
    str_repeat('a', 64),
    'telegram:admin-one'
);
$assertSame('workflow_run', $evidence['evidence_type'], 'Evidence reference must persist.');

$assertThrows(
    fn() => $service->addEvidence(
        $incidentId,
        'other',
        'Нельзя',
        'token=actual-secret-value',
        '',
        'telegram:admin-one'
    ),
    'Не сохраняйте секреты',
    'Evidence must reject obvious secret material.'
);

$assertThrows(
    fn() => $service->updateRestoreStatus(
        $incidentId,
        'verified',
        '',
        '',
        '',
        '',
        'telegram:admin-one'
    ),
    'хотя бы одну ссылку',
    'Verified restore status requires evidence/reference.'
);

$restore = $service->updateRestoreStatus(
    $incidentId,
    'verified',
    'backup:staging-safe-copy',
    'branch:backup/mvp22-9-before-recovery',
    str_repeat('b', 40),
    'Restore path checked without destructive execution.',
    'telegram:admin-one'
);
$assertSame('verified', $restore['status_code'], 'Restore verification must persist.');

$baseline = [
    'maintenance_mode'=>false,
    'maintenance_message'=>'',
    'financial_read_only'=>false,
    'features'=>['matchmaking'=>true,'shop'=>true],
    'games'=>['chess'=>true],
];
$enable = $service->requestHighRiskAction(
    $incidentId,
    'enable_security_mode',
    'Локализовать инцидент.',
    'telegram:admin-one',
    ['baseline_flags'=>$baseline]
);
$assertSame(IncidentRecoveryService::ACTION_PENDING, $enable['status_code'], 'High-risk action must wait for second review.');

$assertThrows(
    fn() => $service->claimHighRiskAction(
        (string)$enable['action_id'],
        'telegram:admin-one',
        'Self approval'
    ),
    'другой администратор',
    'Requesting admin must not approve their own high-risk action.'
);

$claimed = $service->claimHighRiskAction(
    (string)$enable['action_id'],
    'telegram:admin-two',
    'Независимая проверка.'
);
$assertSame(IncidentRecoveryService::ACTION_EXECUTING, $claimed['status_code'], 'Second admin must claim action before execution.');

$completed = $service->completeHighRiskAction(
    (string)$enable['action_id'],
    ['ok'=>true,'changed'=>true],
    'telegram:admin-two'
);
$assertSame(IncidentRecoveryService::ACTION_COMPLETED, $completed['status_code'], 'Confirmed action must complete durably.');
$assertSame(false, $service->securityModeBaseline($incidentId)['maintenance_mode'] ?? null, 'Security-mode baseline must preserve prior runtime state.');

$pendingRevoke = $service->requestHighRiskAction(
    $incidentId,
    'revoke_all_sessions',
    'Перевыпустить активные сессии.',
    'telegram:admin-one'
);
$assertThrows(
    fn() => $service->setIncidentStatus(
        $incidentId,
        'resolved',
        'Нельзя завершать с ожидающим действием.',
        'telegram:admin-one'
    ),
    'ожидающие опасные действия',
    'Pending high-risk action must block incident resolution.'
);
$rejected = $service->rejectHighRiskAction(
    (string)$pendingRevoke['action_id'],
    'telegram:admin-two',
    'Отзыв сессий не требуется.'
);
$assertSame(IncidentRecoveryService::ACTION_REJECTED, $rejected['status_code'], 'Second admin must be able to reject the request.');

$disable = $service->requestHighRiskAction(
    $incidentId,
    'disable_security_mode',
    'Вернуть обычный режим после проверки.',
    'telegram:admin-one'
);
$service->claimHighRiskAction((string)$disable['action_id'], 'telegram:admin-two', 'Восстановление подтверждено.');
$service->completeHighRiskAction((string)$disable['action_id'], ['ok'=>true,'changed'=>true], 'telegram:admin-two');

$resolved = $service->setIncidentStatus(
    $incidentId,
    'resolved',
    'Восстановление проверено.',
    'telegram:admin-one'
);
$assertSame('resolved', $resolved['incident_status'], 'Incident must resolve after pending actions are cleared.');

$assertThrows(
    fn() => $service->setIncidentStatus(
        $incidentId,
        'open',
        'Reopen attempt',
        'telegram:admin-one'
    ),
    'нельзя вернуть',
    'Resolved incident must not reopen.'
);

$db->execute(<<<'SQL'
CREATE TABLE mgw_sessions (
    session_key_hash TEXT PRIMARY KEY,
    mgw_id TEXT NOT NULL,
    device_id INTEGER NULL,
    provider TEXT NOT NULL,
    issued_at_utc TEXT NOT NULL,
    last_seen_at_utc TEXT NOT NULL,
    expires_at_utc TEXT NOT NULL,
    revoked_at_utc TEXT NULL
)
SQL);
$now = new DateTimeImmutable('2026-09-26 20:00:00', new DateTimeZone('UTC'));
$rows = [
    ['a','MGW-A','2026-09-27 20:00:00.000000',null],
    ['b','MGW-B','2026-09-27 20:00:00.000000',null],
    ['c','MGW-C','2026-09-25 20:00:00.000000',null],
    ['d','MGW-D','2026-09-27 20:00:00.000000','2026-09-26 19:00:00.000000'],
];
foreach ($rows as [$hash,$mgwId,$expires,$revoked]) {
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
$assertSame(2, $identity->activeSessionCount($now), 'Only unrevoked, unexpired sessions are active.');
$assertSame(2, $identity->revokeAllActiveSessions($now), 'Global revoke must touch only active sessions.');
$assertSame(0, $identity->activeSessionCount($now), 'No active session may remain after revoke.');

$final = $service->snapshot();
$assertSame(null, $final['active_incident'], 'Resolved incident must leave no active incident.');
$assert(count($final['incidents']) === 1, 'Resolved incident must remain in history.');

fwrite(STDOUT, "MVP-22.9 incident recovery SQLite OK ({$assertions} assertions).\n");
