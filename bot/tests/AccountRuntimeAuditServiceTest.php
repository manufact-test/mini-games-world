<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$databaseDir = $root . '/database';
require $databaseDir . '/DatabaseConnectionInterface.php';
require $databaseDir . '/PdoDatabaseConnection.php';
require $databaseDir . '/DatabaseMigrationInterface.php';
require $databaseDir . '/MigrationRepository.php';
require $databaseDir . '/MigrationRunner.php';
require $root . '/accounts/MgwIdGenerator.php';
require $root . '/accounts/AccountIdentityService.php';
require $root . '/accounts/AccountRuntimeAuditService.php';

if (!extension_loaded('pdo_sqlite')) {
    throw new RuntimeException('AccountRuntimeAuditServiceTest requires pdo_sqlite.');
}

$assertions = 0;
$assertSame = static function (mixed $expected, mixed $actual, string $message) use (&$assertions): void {
    $assertions++;
    if ($expected !== $actual) {
        throw new RuntimeException($message . ': expected ' . var_export($expected, true)
            . ', got ' . var_export($actual, true));
    }
};
$assertTrue = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('PRAGMA foreign_keys = ON');
$database = new PdoDatabaseConnection($pdo);
$runner = new MigrationRunner($database, $databaseDir . '/migrations');
$assertSame(7, $runner->migrate(false)['executed_count'], 'Audit test must apply all migrations');

$telegramSubject = '972585905';
$accounts = new AccountIdentityService($database, 3600);
$identity = $accounts->resolveTelegramUser([
    'id' => $telegramSubject,
    'first_name' => 'Илья',
    'username' => 'audit_player',
], 'audit-session-one');
$mgwId = (string)$identity['mgw_id'];
$assertTrue(MgwIdGenerator::isValid($mgwId), 'Audit fixture must create a valid MGW ID');

$now = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');
$database->execute(
    'INSERT INTO mgw_account_ownership (
        account_ref, mgw_id, legacy_user_id, ownership_status,
        source_type, source_ref, source_sha256, created_at_utc, verified_at_utc
     ) VALUES (
        :account_ref, :mgw_id, :legacy_user_id, :ownership_status,
        :source_type, :source_ref, :source_sha256, :created_at_utc, :verified_at_utc
     )',
    [
        'account_ref' => 'legacy:' . $telegramSubject,
        'mgw_id' => $mgwId,
        'legacy_user_id' => $telegramSubject,
        'ownership_status' => 'active',
        'source_type' => 'legacy_json',
        'source_ref' => 'users.json:' . $telegramSubject,
        'source_sha256' => hash('sha256', 'audit-source|' . $telegramSubject),
        'created_at_utc' => $now,
        'verified_at_utc' => $now,
    ]
);

$service = new AccountRuntimeAuditService($database);
$clean = $service->audit(1, 20, true);
$assertSame(true, $clean['ok'], 'One linked and recently authenticated account must pass');
$assertSame(true, $clean['read_only'], 'Audit must declare read-only mode');
$assertSame(1, $clean['counts']['users'], 'Audit must report one MGW user');
$assertSame(1, $clean['counts']['telegram_identities'], 'Audit must report one Telegram identity');
$assertSame(1, $clean['counts']['ownerships'], 'Audit must report one ownership row');
$assertSame(1, $clean['counts']['devices'], 'Authentication must register one device');
$assertSame(1, $clean['counts']['active_sessions'], 'Authentication must register one active session');
$assertSame(true, $clean['identity_ownership_same_mgw_id'], 'Identity and ownership must resolve to one MGW ID');
$assertSame(true, $clean['recent_authentication_observed'], 'Recent authentication evidence must be visible');
$assertSame(false, $clean['sensitive_identifiers_exposed'], 'Audit must not expose sensitive identifiers');
$assertSame([], $clean['blockers'], 'Clean audit must have no blockers');
$assertSame(64, strlen((string)$clean['report_fingerprint']), 'Audit report must have a SHA-256 fingerprint');
$encoded = json_encode($clean, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$assertSame(false, str_contains((string)$encoded, $telegramSubject), 'Raw Telegram subject must not appear in the report');
$assertSame(false, str_contains((string)$encoded, 'audit-session-one'), 'Raw client session must not appear in the report');

$database->execute(
    'DELETE FROM mgw_account_ownership WHERE account_ref = :account_ref',
    ['account_ref' => 'legacy:' . $telegramSubject]
);
$missingOwnership = $service->audit(1, 20, true);
$assertSame(false, $missingOwnership['ok'], 'Missing ownership must fail the audit');
$assertTrue(
    str_contains(implode(' ', $missingOwnership['blockers']), 'ownership'),
    'Missing ownership blocker must be explicit'
);

$database->execute(
    'INSERT INTO mgw_account_ownership (
        account_ref, mgw_id, legacy_user_id, ownership_status,
        source_type, source_ref, source_sha256, created_at_utc, verified_at_utc
     ) VALUES (
        :account_ref, :mgw_id, :legacy_user_id, :ownership_status,
        :source_type, :source_ref, :source_sha256, :created_at_utc, :verified_at_utc
     )',
    [
        'account_ref' => 'legacy:' . $telegramSubject,
        'mgw_id' => $mgwId,
        'legacy_user_id' => $telegramSubject,
        'ownership_status' => 'active',
        'source_type' => 'legacy_json',
        'source_ref' => 'users.json:' . $telegramSubject,
        'source_sha256' => hash('sha256', 'audit-source|' . $telegramSubject),
        'created_at_utc' => $now,
        'verified_at_utc' => $now,
    ]
);
$accounts->resolveTelegramUser([
    'id' => '987654321',
    'first_name' => 'Другой игрок',
], 'audit-session-two');
$duplicateUser = $service->audit(1, 20, true);
$assertSame(false, $duplicateUser['ok'], 'Unexpected second account must fail the one-user staging audit');
$assertTrue(
    str_contains(implode(' ', $duplicateUser['blockers']), 'user count'),
    'Unexpected user count blocker must be explicit'
);

fwrite(STDOUT, "AccountRuntimeAuditServiceTest: {$assertions} assertions passed\n");
