<?php
declare(strict_types=1);

require dirname(__DIR__) . '/database/DatabaseConnectionInterface.php';
require dirname(__DIR__) . '/database/DatabaseExceptionClassifier.php';
require dirname(__DIR__) . '/database/PdoDatabaseConnection.php';
require dirname(__DIR__) . '/database/DatabaseMigrationInterface.php';
require dirname(__DIR__) . '/accounts/MgwIdGenerator.php';
require dirname(__DIR__) . '/storage/contracts/StorageTransactionInterface.php';
require dirname(__DIR__) . '/storage/contracts/StorageAdapterInterface.php';
require dirname(__DIR__) . '/storage/contracts/SelectiveReadStorageInterface.php';
require dirname(__DIR__) . '/storage/contracts/ExclusiveSnapshotStorageInterface.php';
require dirname(__DIR__) . '/storage/contracts/ProjectionDirtyStorageInterface.php';
require dirname(__DIR__) . '/storage/contracts/ProjectionSnapshotStorageInterface.php';
require dirname(__DIR__) . '/storage/JsonDatabase.php';
require dirname(__DIR__) . '/storage/JsonStorageAdapter.php';
require dirname(__DIR__) . '/accounts/AccountDataZipWriter.php';
require dirname(__DIR__) . '/accounts/AccountDataLifecycleService.php';
require dirname(__DIR__) . '/accounts/AccountReauthGuard.php';

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

$dsn = trim((string)getenv('MGW_ACCOUNT_DATA_MYSQL_DSN'));
if ($dsn !== '') {
    $pdo = new PDO(
        $dsn,
        (string)getenv('MGW_ACCOUNT_DATA_MYSQL_USER'),
        (string)getenv('MGW_ACCOUNT_DATA_MYSQL_PASS'),
        [
            PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES=>false,
        ]
    );
} else {
    $pdo = new PDO('sqlite::memory:');
    $pdo->exec('PRAGMA foreign_keys=ON');
}
$db = new PdoDatabaseConnection($pdo);

$tables = [
    'mgw_account_data_requests',
    'mgw_match_players',
    'mgw_invites',
    'mgw_match_queue',
    'mgw_social_relations',
    'mgw_notifications',
    'mgw_equipped_items',
    'mgw_ledger_entries',
    'mgw_account_ownership',
    'mgw_sessions',
    'mgw_devices',
    'mgw_identities',
    'mgw_users',
];
if ($db->driver() === 'mysql') $db->execute('SET FOREIGN_KEY_CHECKS=0');
foreach ($tables as $table) $db->execute('DROP TABLE IF EXISTS ' . $table);
if ($db->driver() === 'mysql') $db->execute('SET FOREIGN_KEY_CHECKS=1');

(require dirname(__DIR__) . '/database/migrations/20260716_0002_create_accounts_identities_sessions.php')->up($db);
(require dirname(__DIR__) . '/database/migrations/20260718_0007_create_account_ownership.php')->up($db);
(require dirname(__DIR__) . '/database/migrations/20260816_0009_add_canonical_profile_identity.php')->up($db);
(require dirname(__DIR__) . '/database/migrations/20260926_0067_create_account_data_lifecycle.php')->up($db);

if ($db->driver() === 'mysql') {
    $db->execute("CREATE TABLE mgw_match_players (
        match_id VARCHAR(96) NOT NULL, seat INT NOT NULL, player_ref VARCHAR(255) NOT NULL,
        mgw_id VARCHAR(24) NULL, legacy_user_id VARCHAR(191) NULL, display_name VARCHAR(80) NULL,
        updated_at_utc DATETIME(6) NOT NULL, PRIMARY KEY(match_id,seat)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $db->execute("CREATE TABLE mgw_invites (
        invite_id VARCHAR(96) PRIMARY KEY, inviter_ref VARCHAR(255) NOT NULL,
        inviter_mgw_id VARCHAR(24) NULL, inviter_legacy_user_id VARCHAR(191) NULL, inviter_name VARCHAR(80) NOT NULL,
        invitee_ref VARCHAR(255) NULL, invitee_mgw_id VARCHAR(24) NULL, invitee_legacy_user_id VARCHAR(191) NULL,
        invitee_name VARCHAR(80) NULL, updated_at_utc DATETIME(6) NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $db->execute("CREATE TABLE mgw_match_queue (queue_id VARCHAR(96) PRIMARY KEY, mgw_id VARCHAR(24) NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $db->execute("CREATE TABLE mgw_social_relations (
        user_low_mgw_id VARCHAR(24) NOT NULL, user_high_mgw_id VARCHAR(24) NOT NULL,
        PRIMARY KEY(user_low_mgw_id,user_high_mgw_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $db->execute("CREATE TABLE mgw_notifications (
        notification_id VARCHAR(96) PRIMARY KEY, mgw_id VARCHAR(24) NULL, message TEXT NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $db->execute("CREATE TABLE mgw_equipped_items (
        mgw_id VARCHAR(24) NOT NULL, equip_slot VARCHAR(64) NOT NULL, item_id VARCHAR(64) NOT NULL,
        PRIMARY KEY(mgw_id,equip_slot)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $db->execute("CREATE TABLE mgw_ledger_entries (
        ledger_sequence BIGINT AUTO_INCREMENT PRIMARY KEY, mgw_id VARCHAR(24) NULL,
        legacy_user_id VARCHAR(191) NULL, account_ref VARCHAR(255) NOT NULL,
        category VARCHAR(64) NOT NULL, entry_sha256 CHAR(64) NOT NULL, created_at_utc DATETIME(6) NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} else {
    $db->execute('CREATE TABLE mgw_match_players (
        match_id TEXT NOT NULL, seat INTEGER NOT NULL, player_ref TEXT NOT NULL,
        mgw_id TEXT NULL, legacy_user_id TEXT NULL, display_name TEXT NULL,
        updated_at_utc TEXT NOT NULL, PRIMARY KEY(match_id,seat)
    )');
    $db->execute('CREATE TABLE mgw_invites (
        invite_id TEXT PRIMARY KEY, inviter_ref TEXT NOT NULL,
        inviter_mgw_id TEXT NULL, inviter_legacy_user_id TEXT NULL, inviter_name TEXT NOT NULL,
        invitee_ref TEXT NULL, invitee_mgw_id TEXT NULL, invitee_legacy_user_id TEXT NULL,
        invitee_name TEXT NULL, updated_at_utc TEXT NOT NULL
    )');
    $db->execute('CREATE TABLE mgw_match_queue (queue_id TEXT PRIMARY KEY, mgw_id TEXT NULL)');
    $db->execute('CREATE TABLE mgw_social_relations (
        user_low_mgw_id TEXT NOT NULL, user_high_mgw_id TEXT NOT NULL,
        PRIMARY KEY(user_low_mgw_id,user_high_mgw_id)
    )');
    $db->execute('CREATE TABLE mgw_notifications (
        notification_id TEXT PRIMARY KEY, mgw_id TEXT NULL, message TEXT NOT NULL
    )');
    $db->execute('CREATE TABLE mgw_equipped_items (
        mgw_id TEXT NOT NULL, equip_slot TEXT NOT NULL, item_id TEXT NOT NULL,
        PRIMARY KEY(mgw_id,equip_slot)
    )');
    $db->execute('CREATE TABLE mgw_ledger_entries (
        ledger_sequence INTEGER PRIMARY KEY AUTOINCREMENT, mgw_id TEXT NULL,
        legacy_user_id TEXT NULL, account_ref TEXT NOT NULL,
        category TEXT NOT NULL, entry_sha256 TEXT NOT NULL, created_at_utc TEXT NOT NULL
    )');
}

$mgwId = 'MGW-0123456789ABCDEF';
$legacy = '123456789';
$accountRef = 'legacy:' . $legacy;
$nowText = '2026-09-26 12:00:00.000000';

$db->execute(
    'INSERT INTO mgw_users (
        mgw_id,status,nickname,display_name,username,
        avatar_provider,avatar_external_ref,avatar_storage_key,avatar_mime_type,avatar_width,avatar_height,
        equipped_avatar_item_id,preferred_locale,created_at_utc,updated_at_utc,last_seen_at_utc
     ) VALUES (
        :mgw_id,:status,:nickname,:display_name,:username,
        :avatar_provider,:avatar_external_ref,NULL,NULL,NULL,NULL,
        :avatar_item,:locale,:created,:updated,:seen
     )',
    [
        'mgw_id'=>$mgwId,'status'=>'active','nickname'=>'PlayerOne','display_name'=>'Player One',
        'username'=>'player_one','avatar_provider'=>'telegram','avatar_external_ref'=>'photo',
        'avatar_item'=>'starter-default-01','locale'=>'ru',
        'created'=>$nowText,'updated'=>$nowText,'seen'=>$nowText,
    ]
);
$db->execute(
    'INSERT INTO mgw_identities (
        mgw_id,provider,provider_subject,provider_username,linked_at_utc,last_authenticated_at_utc
     ) VALUES (:mgw_id,:provider,:subject,:username,:linked,:auth)',
    [
        'mgw_id'=>$mgwId,'provider'=>'telegram','subject'=>$legacy,'username'=>'player_one',
        'linked'=>$nowText,'auth'=>$nowText,
    ]
);
$db->execute(
    'INSERT INTO mgw_account_ownership (
        account_ref,mgw_id,legacy_user_id,ownership_status,source_type,source_ref,source_sha256,created_at_utc,verified_at_utc
     ) VALUES (
        :account_ref,:mgw_id,:legacy,:status,:source_type,:source_ref,:sha,:created,:verified
     )',
    [
        'account_ref'=>$accountRef,'mgw_id'=>$mgwId,'legacy'=>$legacy,'status'=>'active',
        'source_type'=>'runtime_identity','source_ref'=>'telegram:' . $legacy,
        'sha'=>str_repeat('a',64),'created'=>$nowText,'verified'=>$nowText,
    ]
);
$db->execute(
    'INSERT INTO mgw_sessions (
        session_key_hash,mgw_id,device_id,provider,issued_at_utc,last_seen_at_utc,expires_at_utc,revoked_at_utc
     ) VALUES (:hash,:mgw_id,NULL,:provider,:issued,:seen,:expires,NULL)',
    [
        'hash'=>str_repeat('b',64),'mgw_id'=>$mgwId,'provider'=>'telegram',
        'issued'=>$nowText,'seen'=>$nowText,'expires'=>'2026-10-26 12:00:00.000000',
    ]
);
$db->execute(
    'INSERT INTO mgw_match_players (
        match_id,seat,player_ref,mgw_id,legacy_user_id,display_name,updated_at_utc
     ) VALUES (:match,0,:ref,:mgw_id,:legacy,:name,:updated)',
    ['match'=>'match-1','ref'=>$accountRef,'mgw_id'=>$mgwId,'legacy'=>$legacy,'name'=>'PlayerOne','updated'=>$nowText]
);
$db->execute(
    'INSERT INTO mgw_invites (
        invite_id,inviter_ref,inviter_mgw_id,inviter_legacy_user_id,inviter_name,
        invitee_ref,invitee_mgw_id,invitee_legacy_user_id,invitee_name,updated_at_utc
     ) VALUES (
        :invite,:ref,:mgw_id,:legacy,:name,NULL,NULL,NULL,NULL,:updated
     )',
    ['invite'=>'invite-1','ref'=>$accountRef,'mgw_id'=>$mgwId,'legacy'=>$legacy,'name'=>'PlayerOne','updated'=>$nowText]
);
$db->execute('INSERT INTO mgw_match_queue (queue_id,mgw_id) VALUES (:id,:mgw_id)', ['id'=>'queue-1','mgw_id'=>$mgwId]);
$db->execute('INSERT INTO mgw_notifications (notification_id,mgw_id,message) VALUES (:id,:mgw_id,:message)', ['id'=>'n1','mgw_id'=>$mgwId,'message'=>'hello']);
$db->execute('INSERT INTO mgw_equipped_items (mgw_id,equip_slot,item_id) VALUES (:mgw_id,:slot,:item)', ['mgw_id'=>$mgwId,'slot'=>'profile_avatar','item'=>'starter-default-01']);
$db->execute(
    'INSERT INTO mgw_ledger_entries (mgw_id,legacy_user_id,account_ref,category,entry_sha256,created_at_utc)
     VALUES (:mgw_id,:legacy,:account_ref,:category,:sha,:created)',
    [
        'mgw_id'=>$mgwId,'legacy'=>$legacy,'account_ref'=>$accountRef,
        'category'=>'test','sha'=>str_repeat('c',64),'created'=>$nowText,
    ]
);

$temp = sys_get_temp_dir() . '/mgw-account-data-' . bin2hex(random_bytes(5));
mkdir($temp, 0700, true);
$storage = new JsonStorageAdapter($temp);
$storage->transaction(static function (array &$data) use ($legacy): void {
    $data['users'][$legacy] = [
        'id'=>$legacy,
        'first_name'=>'PlayerOne',
        'username'=>'player_one',
        'mgw_id'=>'MGW-0123456789ABCDEF',
    ];
    $data['games']['g1'] = [
        'player_ids'=>[$legacy,'other'],
        'player_names'=>[$legacy=>'PlayerOne','other'=>'Other'],
        'winner_id'=>$legacy,
    ];
});

$config = [
    'data_dir'=>$temp,
    'account_data_export_dir'=>$temp . '/exports',
    'account_data_export_rate_limit_sec'=>3600,
    'account_data_export_retention_sec'=>3600,
];
$service = new AccountDataLifecycleService($db, $storage, $config);
$now = new DateTimeImmutable('2026-09-26T12:00:00+00:00');

$scheduled = $service->scheduleDeletion($mgwId, 'mini_app', 'self:telegram', $now);
$assertSame('scheduled', $scheduled['status'], 'Deletion request must start scheduled');
$assert(str_starts_with((string)$scheduled['execute_after_utc'], '2026-10-03 12:00:00'), 'Deletion grace must be exactly seven days');

$cancelled = $service->cancelDeletion($mgwId, $now->modify('+1 minute'));
$assertSame('cancelled', $cancelled['status'], 'Scheduled deletion must remain cancellable during grace period');

$scheduled = $service->scheduleDeletion($mgwId, 'mini_app', 'self:telegram', $now->modify('+2 minutes'));
$assertSame('scheduled', $scheduled['status'], 'Deletion may be rescheduled after cancellation');

$export = $service->createExport($mgwId, 'mini_app', 'self:telegram', $now->modify('+3 minutes'));
$assertSame('ready', $export['status'], 'Data export must become ready');
$assert((int)$export['artifact_size'] > 0, 'Data export must have a non-empty ZIP artifact');
$zipPath = $service->exportPathForUser((string)$export['request_id'], $mgwId, $now->modify('+4 minutes'));
$zipBytes = file_get_contents($zipPath);
$assert(is_string($zipBytes) && str_starts_with($zipBytes, "PK\x03\x04"), 'Export must be a ZIP archive');
$assert(str_contains($zipBytes, 'data.json'), 'ZIP must contain JSON export');
$assert(str_contains($zipBytes, 'index.html'), 'ZIP must contain human-readable HTML');
$assert(str_contains($zipBytes, 'csv/mgw_users.csv'), 'ZIP must contain CSV exports');
$assert(str_contains($zipBytes, 'images/README.txt'), 'ZIP must contain the images section even when no uploaded image exists');
$assert(!str_contains($zipBytes, str_repeat('c',64)), 'Integrity hashes must not be exposed in self-service export');

$rateLimited = false;
try {
    $service->createExport($mgwId, 'mini_app', 'self:telegram', $now->modify('+5 minutes'));
} catch (AccountDataLifecycleException $error) {
    $rateLimited = $error->reason === 'rate_limited';
}
$assert($rateLimited, 'Repeated export inside rate-limit window must be rejected');

$freshInit = 'auth_date=' . (string)strtotime('2026-09-26T12:00:00+00:00');
$assertSame(true, AccountReauthGuard::initDataIsFresh($freshInit, strtotime('2026-09-26T12:04:00+00:00')), 'Re-auth window must accept fresh auth_date');
$assertSame(false, AccountReauthGuard::initDataIsFresh($freshInit, strtotime('2026-09-26T12:06:01+00:00')), 'Re-auth window must reject stale auth_date');

$dueAt = new DateTimeImmutable((string)$scheduled['execute_after_utc'], new DateTimeZone('UTC'));
$retention = $service->runRetention($dueAt->modify('+1 second'));
$assertSame(1, $retention['deletions_completed'], 'Due deletion must finalize exactly once');

$user = $db->fetchAll('SELECT * FROM mgw_users WHERE mgw_id=:mgw_id', ['mgw_id'=>$mgwId])[0];
$assertSame('anonymized', $user['status'], 'Finalized account must be anonymized');
$assertSame('Удалённый игрок', $user['display_name'], 'Public display identity must be anonymized');
$assertSame(null, $user['username'], 'Username must be removed');

$identity = $db->fetchAll('SELECT * FROM mgw_identities WHERE mgw_id=:mgw_id', ['mgw_id'=>$mgwId])[0];
$assert((string)$identity['provider_subject'] !== $legacy, 'Provider subject must be released from deleted account');
$assertSame(null, $identity['provider_username'], 'Provider username must be removed');

$owner = $db->fetchAll('SELECT * FROM mgw_account_ownership WHERE mgw_id=:mgw_id', ['mgw_id'=>$mgwId])[0];
$assert((string)$owner['legacy_user_id'] !== $legacy, 'Legacy runtime identity must be released for future fresh registration');
$assert((string)$owner['account_ref'] !== $accountRef, 'Active account_ref must be tombstoned');

$assertSame(0, (int)$db->fetchValue('SELECT COUNT(*) FROM mgw_sessions WHERE mgw_id=:mgw_id', ['mgw_id'=>$mgwId]), 'Sessions must be revoked/removed at finalization');
$assertSame(0, (int)$db->fetchValue('SELECT COUNT(*) FROM mgw_notifications WHERE mgw_id=:mgw_id', ['mgw_id'=>$mgwId]), 'Personal notifications must be removed');
$assertSame(0, (int)$db->fetchValue('SELECT COUNT(*) FROM mgw_equipped_items WHERE mgw_id=:mgw_id', ['mgw_id'=>$mgwId]), 'Equipped profile preferences must be removed');
$assertSame(0, (int)$db->fetchValue('SELECT COUNT(*) FROM mgw_match_queue WHERE mgw_id=:mgw_id', ['mgw_id'=>$mgwId]), 'Active matchmaking queue must be removed');

$player = $db->fetchAll('SELECT * FROM mgw_match_players WHERE mgw_id=:mgw_id', ['mgw_id'=>$mgwId])[0];
$assertSame(null, $player['legacy_user_id'], 'Historical match row must drop legacy provider id');
$assertSame('Удалённый игрок', $player['display_name'], 'Historical match public name must be anonymized');

$ledger = $db->fetchAll('SELECT * FROM mgw_ledger_entries WHERE mgw_id=:mgw_id', ['mgw_id'=>$mgwId])[0];
$assertSame($legacy, (string)$ledger['legacy_user_id'], 'Append-only financial audit must remain byte-stable instead of breaking integrity history');
$assertSame(str_repeat('c',64), (string)$ledger['entry_sha256'], 'Append-only ledger hash must remain unchanged');

$runtime = $storage->readOnly(static fn(array $data): array => $data);
$assert(!isset($runtime['users'][$legacy]), 'Legacy runtime user must be removed');
$assert(!in_array($legacy, array_map('strval', $runtime['games']['g1']['player_ids'] ?? []), true), 'Legacy runtime game references must be tombstoned');

$secondRetention = $service->runRetention($dueAt->modify('+2 seconds'));
$assertSame(0, $secondRetention['deletions_completed'], 'Deletion finalization must be idempotent');

$expiry = new DateTimeImmutable((string)$export['artifact_expires_at_utc'], new DateTimeZone('UTC'));
$expired = $service->runRetention($expiry->modify('+1 second'));
$assertSame(1, $expired['exports_expired'], 'Expired export artifact must be cleaned by retention job');
$assertSame(false, is_file($zipPath), 'Expired ZIP must be removed from private storage');

$removeTree = static function (string $path) use (&$removeTree): void {
    if (!is_dir($path)) return;
    foreach (scandir($path) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') continue;
        $full = $path . '/' . $entry;
        if (is_dir($full)) $removeTree($full); else @unlink($full);
    }
    @rmdir($path);
};
$removeTree($temp);

fwrite(STDOUT, "MVP-22.8 account data lifecycle: {$assertions} assertions passed (" . $db->driver() . ").\n");
