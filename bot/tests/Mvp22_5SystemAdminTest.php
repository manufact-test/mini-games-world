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

require dirname(__DIR__) . '/system/SystemAdminService.php';
require dirname(__DIR__) . '/system/RuntimeFeatureFlagAdminService.php';

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

$pdo = new PDO('sqlite::memory:');
$db = new PdoDatabaseConnection($pdo);

$migration = require dirname(__DIR__) . '/database/migrations/20260926_0065_create_system_admin_control.php';
$migration->up($db);

$db->execute('CREATE TABLE mgw_users (mgw_id TEXT PRIMARY KEY, status TEXT NOT NULL DEFAULT "active")');
$db->execute('CREATE TABLE mgw_identities (mgw_id TEXT NOT NULL, provider TEXT NOT NULL)');
$db->execute(<<<'SQL'
CREATE TABLE mgw_account_ownership (
    account_ref TEXT NOT NULL PRIMARY KEY,
    mgw_id TEXT NOT NULL,
    legacy_user_id TEXT NOT NULL,
    ownership_status TEXT NOT NULL,
    source_type TEXT NOT NULL,
    source_ref TEXT NOT NULL
)
SQL);
$db->execute(<<<'SQL'
CREATE TABLE mgw_rating_control (
    control_key TEXT PRIMARY KEY,
    competition_state TEXT NOT NULL,
    current_season_id TEXT NOT NULL,
    tracking_started_at_utc TEXT NOT NULL,
    activated_at_utc TEXT NULL,
    updated_at_utc TEXT NOT NULL
)
SQL);
$db->execute(
    'INSERT INTO mgw_rating_control (
        control_key, competition_state, current_season_id,
        tracking_started_at_utc, activated_at_utc, updated_at_utc
     ) VALUES (
        "global", "preseason", "preseason",
        "2026-09-20 00:00:00.000000", NULL, "2026-09-20 00:00:00.000000"
     )'
);

for ($i = 1; $i <= 499; $i++) {
    $db->execute('INSERT INTO mgw_users (mgw_id) VALUES (:id)', ['id'=>sprintf('MGW%06d', $i)]);
}
$db->execute('INSERT INTO mgw_users (mgw_id) VALUES ("MGWDEV001")');
$db->execute('INSERT INTO mgw_identities (mgw_id, provider) VALUES ("MGWDEV001", "development")');

$db->execute('INSERT INTO mgw_users (mgw_id, status) VALUES ("MGWFIXRET", "staging_fixture_retired")');

$db->execute('INSERT INTO mgw_users (mgw_id) VALUES ("MGWFIXV2")');
$db->execute(
    'INSERT INTO mgw_account_ownership (
        account_ref, mgw_id, legacy_user_id, ownership_status, source_type, source_ref
     ) VALUES (
        "legacy:stg_tour_v2_abcdef123456", "MGWFIXV2", "stg_tour_v2_abcdef123456",
        "active", "runtime_identity", "development:stg_tour_v2_abcdef123456"
     )'
);

$db->execute('INSERT INTO mgw_users (mgw_id) VALUES ("MGWFIXOLD")');
$db->execute(
    'INSERT INTO mgw_account_ownership (
        account_ref, mgw_id, legacy_user_id, ownership_status, source_type, source_ref
     ) VALUES (
        "legacy:stg_tour_abcdef123456", "MGWFIXOLD", "stg_tour_abcdef123456",
        "active", "staging_fixture_repair", "manual-acceptance:test"
     )'
);

$service = new SystemAdminService($db);
$first = $service->snapshot('production');
$assertSame(
    499,
    $first['users']['canonical_count'],
    'Development identities, retired staging fixtures and active tournament fixtures must not count toward readiness'
);
$assertSame(false, $first['readiness']['reached'], '499 real accounts must remain below readiness threshold');
$assertSame(false, $first['activation']['production_action_available'], 'Production activation must remain gated below threshold');

$db->execute('INSERT INTO mgw_users (mgw_id) VALUES ("MGW000500")');
$threshold = $service->snapshot('production');
$assertSame(500, $threshold['users']['canonical_count'], 'Canonical readiness count must include the next real account without synthetic fixtures');
$assertSame(true, $threshold['readiness']['reached'], '500 users must create durable readiness state');
$assertSame(true, $threshold['readiness']['alert_visible'], 'Readiness alert must remain visible until acknowledged or ACTIVE');

$incompleteRejected = false;
try {
    $service->acceptStaging(str_repeat('a', 40), [], '', 'telegram:1');
} catch (InvalidArgumentException) {
    $incompleteRejected = true;
}
$assert($incompleteRejected, 'Incomplete staging checklist must fail closed');

$complete = array_fill_keys(SystemAdminService::CHECKLIST_KEYS, true);
$service->acceptStaging(str_repeat('b', 40), $complete, 'manual staging acceptance', 'telegram:1');
$accepted = $service->snapshot('production');
$assertSame(true, $accepted['staging_acceptance']['accepted'], 'Complete checklist must create durable acceptance');
$assertSame(str_repeat('b', 40), $accepted['staging_acceptance']['sha'], 'Accepted exact staging SHA must be preserved');
$assertSame(true, $accepted['activation']['production_action_available'], 'Threshold + staging acceptance must unlock explicit production action');

$service->acknowledgeReadiness('telegram:1', 'threshold reviewed');
$acknowledged = $service->snapshot('production');
$assertSame(true, $acknowledged['readiness']['acknowledged'], 'Readiness acknowledgement must be durable');
$assertSame(false, $acknowledged['readiness']['alert_visible'], 'Acknowledged readiness alert must stop showing');
$assert(count($acknowledged['recent_audit']) >= 3, 'Readiness and staging actions must append audit history');

$tempDir = sys_get_temp_dir() . '/mgw-flags-' . bin2hex(random_bytes(5));
if (!mkdir($tempDir, 0700, true) && !is_dir($tempDir)) {
    throw new RuntimeException('Unable to create temp flag directory.');
}
$runtimeFile = $tempDir . '/runtime.php';
file_put_contents($runtimeFile, "<?php\nreturn ['unknown_future_key'=>['keep'=>true],'features'=>['matchmaking'=>true]];\n");
$flags = new RuntimeFeatureFlagAdminService($runtimeFile);
$result = $flags->update([
    'maintenance_mode'=>true,
    'maintenance_message'=>'Проверка',
    'financial_read_only'=>true,
    'features'=>[
        'matchmaking'=>false,
        'invitations'=>true,
        'payments'=>false,
        'shop'=>true,
        'tournaments'=>true,
        'ads'=>false,
    ],
    'games'=>array_fill_keys(RuntimeFeatureFlagAdminService::GAMES, true),
]);
$assertSame(true, $result['changed'], 'Flag editor must report actual changes');
$persisted = require $runtimeFile;
$assertSame(true, $persisted['unknown_future_key']['keep'] ?? false, 'Flag editor must preserve unknown runtime keys');
$assertSame(false, $persisted['features']['matchmaking'] ?? true, 'Flag editor must update existing canonical runtime feature');
$assertSame(true, $persisted['maintenance_mode'] ?? false, 'Flag editor must persist maintenance mode');
@unlink($runtimeFile);
@rmdir($tempDir);

fwrite(STDOUT, "Mvp22_5SystemAdminTest: {$assertions} assertions passed\n");
