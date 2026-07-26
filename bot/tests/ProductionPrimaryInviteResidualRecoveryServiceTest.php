<?php
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/storage/contracts/StorageTransactionInterface.php';
require_once $root . '/storage/contracts/StorageAdapterInterface.php';
require_once $root . '/database/DatabaseConnectionInterface.php';
require_once $root . '/database/PdoDatabaseConnection.php';
require_once $root . '/runtime/RuntimePrimaryProjectionAuditorInterface.php';
require_once $root . '/runtime/RuntimePrimaryStateSchemaInstaller.php';
require_once $root . '/runtime/ProductionPrimaryInviteResidualRecoveryService.php';

if (!extension_loaded('pdo_sqlite')) {
    throw new RuntimeException('ProductionPrimaryInviteResidualRecoveryServiceTest requires pdo_sqlite.');
}

final class ProductionInviteResidualTestAuditor implements RuntimePrimaryProjectionAuditorInterface
{
    public function __construct(private DatabaseConnectionInterface $database) {}

    public function auditOnly(array $snapshot, int $stateRevision, string $stateSha256): array
    {
        $inviteCount = (int)$this->database->fetchValue('SELECT COUNT(*) FROM mgw_invites');
        $notificationCount = (int)$this->database->fetchValue('SELECT COUNT(*) FROM mgw_notifications');
        $sourceInvites = count(array_filter($snapshot['invites'] ?? [], 'is_array'));
        $sourceNotifications = count(array_filter($snapshot['notifications'] ?? [], 'is_array'));
        $ok = $inviteCount === $sourceInvites && $notificationCount === $sourceNotifications;
        return [
            'ok' => $ok,
            'parity_ok' => $ok,
            'read_only' => true,
            'state_revision' => $stateRevision,
            'state_sha256' => $stateSha256,
            'projected_modules' => [
                'accounts', 'realtime', 'economy', 'notifications', 'invites',
                'history', 'shop', 'payments', 'weekly_bonus',
            ],
            'all_module_fingerprint' => hash('sha256', 'test|' . $stateRevision . '|' . $stateSha256),
        ];
    }
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
$assertThrows = static function (callable $callback, string $contains, string $message) use (&$assertions): void {
    $assertions++;
    try {
        $callback();
    } catch (Throwable $error) {
        if (str_contains(strtolower($error->getMessage()), strtolower($contains))) return;
        throw new RuntimeException($message . ': unexpected error ' . $error->getMessage());
    }
    throw new RuntimeException($message . ': no error was thrown');
};

$canonicalize = static function (mixed $value) use (&$canonicalize): mixed {
    if (!is_array($value)) return $value;
    if (!array_is_list($value)) ksort($value, SORT_STRING);
    foreach ($value as $key => $item) $value[$key] = $canonicalize($item);
    return $value;
};
$canonicalJson = static fn(mixed $value): string => json_encode(
    $canonicalize($value),
    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
);

$fixture = static function (bool $read = false) use ($canonicalJson): array {
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec('PRAGMA foreign_keys = ON');
    $database = new PdoDatabaseConnection($pdo);
    (new RuntimePrimaryStateSchemaInstaller($database))->install();

    $database->execute(<<<'SQL'
CREATE TABLE mgw_users (
    mgw_id TEXT PRIMARY KEY,
    status TEXT NOT NULL
)
SQL);
    $database->execute(<<<'SQL'
CREATE TABLE mgw_account_ownership (
    account_ref TEXT PRIMARY KEY,
    mgw_id TEXT NOT NULL,
    legacy_user_id TEXT NOT NULL UNIQUE,
    ownership_status TEXT NOT NULL
)
SQL);
    $database->execute(<<<'SQL'
CREATE TABLE mgw_invites (
    invite_id TEXT PRIMARY KEY,
    token TEXT NOT NULL UNIQUE,
    status TEXT NOT NULL,
    source TEXT NOT NULL,
    inviter_ref TEXT NOT NULL,
    inviter_mgw_id TEXT NULL,
    inviter_legacy_user_id TEXT NULL,
    inviter_name TEXT NOT NULL,
    invitee_ref TEXT NULL,
    invitee_mgw_id TEXT NULL,
    invitee_legacy_user_id TEXT NULL,
    invitee_name TEXT NULL,
    game_type TEXT NOT NULL,
    game_title TEXT NOT NULL,
    room TEXT NOT NULL,
    bet INTEGER NOT NULL,
    board_size INTEGER NOT NULL,
    board_columns INTEGER NULL,
    board_rows INTEGER NULL,
    source_match_id TEXT NULL,
    match_id TEXT NULL,
    version INTEGER NOT NULL,
    created_at_utc TEXT NOT NULL,
    updated_at_utc TEXT NOT NULL,
    expires_at_utc TEXT NULL,
    shared_at_utc TEXT NULL,
    opened_at_utc TEXT NULL,
    accepted_at_utc TEXT NULL,
    ready_deadline_at_utc TEXT NULL,
    started_at_utc TEXT NULL,
    declined_at_utc TEXT NULL,
    cancelled_at_utc TEXT NULL,
    cancelled_by_ref TEXT NULL
)
SQL);
    $database->execute(<<<'SQL'
CREATE TABLE mgw_invite_events (
    event_id INTEGER PRIMARY KEY AUTOINCREMENT,
    invite_id TEXT NOT NULL,
    event_key TEXT NOT NULL,
    event_type TEXT NOT NULL,
    actor_ref TEXT NULL,
    payload_json TEXT NULL,
    created_at_utc TEXT NOT NULL,
    FOREIGN KEY (invite_id) REFERENCES mgw_invites (invite_id) ON DELETE CASCADE
)
SQL);
    $database->execute(<<<'SQL'
CREATE TABLE mgw_notifications (
    notification_id TEXT PRIMARY KEY,
    event_key TEXT NOT NULL,
    recipient_ref TEXT NOT NULL,
    mgw_id TEXT NULL,
    legacy_user_id TEXT NULL,
    type TEXT NOT NULL,
    title TEXT NOT NULL,
    message TEXT NOT NULL,
    tone TEXT NULL,
    invite_token TEXT NULL,
    payload_json TEXT NULL,
    created_at_utc TEXT NOT NULL,
    read_at_utc TEXT NULL,
    hidden_at_utc TEXT NULL
)
SQL);
    $database->execute(<<<'SQL'
CREATE TABLE mgw_matches (
    match_id TEXT PRIMARY KEY,
    invite_id TEXT NULL,
    source_match_id TEXT NULL
)
SQL);

    $legacyUserId = '972585905';
    $mgwId = 'MGW-RECOVERYTEST01';
    $accountRef = 'legacy:' . $legacyUserId;
    $inviteId = 'invite_db_only_expired_1';
    $token = '0123456789abcdef01234567';
    $notificationId = 'notification_db_only_expired_1';
    $eventKey = 'invite:' . $inviteId . ':expired:' . $legacyUserId;
    $gameTitle = 'Крестики-нолики';
    $message = 'Матч «' . $gameTitle . '» не начался.';

    $snapshot = [
        'users' => [
            $legacyUserId => [
                'id' => $legacyUserId,
                'mgw_id' => $mgwId,
                'display_name' => 'Recovery Test',
            ],
        ],
        'invites' => [],
        'notifications' => [],
    ];
    $stateJson = $canonicalJson($snapshot);
    $stateSha = hash('sha256', $stateJson);
    $database->execute(
        'INSERT INTO mgw_runtime_primary_state
         (singleton_id, revision, state_json, state_sha256, created_at_utc, updated_at_utc)
         VALUES (1, 1, :state_json, :state_sha256, :created_at, :updated_at)',
        [
            'state_json' => $stateJson,
            'state_sha256' => $stateSha,
            'created_at' => '2026-07-24T18:24:09+00:00',
            'updated_at' => '2026-07-24T18:24:09+00:00',
        ]
    );
    $database->execute(
        'INSERT INTO mgw_users (mgw_id, status) VALUES (:mgw_id, :status)',
        ['mgw_id' => $mgwId, 'status' => 'active']
    );
    $database->execute(
        'INSERT INTO mgw_account_ownership
         (account_ref, mgw_id, legacy_user_id, ownership_status)
         VALUES (:account_ref, :mgw_id, :legacy_user_id, :status)',
        [
            'account_ref' => $accountRef,
            'mgw_id' => $mgwId,
            'legacy_user_id' => $legacyUserId,
            'status' => 'active',
        ]
    );
    $database->execute(
        'INSERT INTO mgw_invites (
            invite_id, token, status, source, inviter_ref, inviter_mgw_id,
            inviter_legacy_user_id, inviter_name, invitee_ref, invitee_mgw_id,
            invitee_legacy_user_id, invitee_name, game_type, game_title, room, bet,
            board_size, board_columns, board_rows, source_match_id, match_id, version,
            created_at_utc, updated_at_utc, expires_at_utc, shared_at_utc, opened_at_utc,
            accepted_at_utc, ready_deadline_at_utc, started_at_utc, declined_at_utc,
            cancelled_at_utc, cancelled_by_ref
         ) VALUES (
            :invite_id, :token, :status, :source, :inviter_ref, :inviter_mgw_id,
            :inviter_legacy_user_id, :inviter_name, NULL, NULL, NULL, NULL,
            :game_type, :game_title, :room, :bet, :board_size, :board_columns,
            :board_rows, NULL, NULL, :version, :created_at, :updated_at, :expires_at,
            :shared_at, NULL, NULL, NULL, NULL, NULL, NULL, NULL
         )',
        [
            'invite_id' => $inviteId,
            'token' => $token,
            'status' => 'expired',
            'source' => 'link',
            'inviter_ref' => $accountRef,
            'inviter_mgw_id' => $mgwId,
            'inviter_legacy_user_id' => $legacyUserId,
            'inviter_name' => 'Recovery Test',
            'game_type' => 'tictactoe',
            'game_title' => $gameTitle,
            'room' => 'match',
            'bet' => 10,
            'board_size' => 3,
            'board_columns' => 3,
            'board_rows' => 3,
            'version' => 1,
            'created_at' => '2026-07-24 21:43:26.000000',
            'updated_at' => '2026-07-24 21:58:27.000000',
            'expires_at' => '2026-07-24 21:58:26.000000',
            'shared_at' => '2026-07-24 21:43:26.000000',
        ]
    );
    $database->execute(
        'INSERT INTO mgw_invite_events
         (invite_id, event_key, event_type, actor_ref, payload_json, created_at_utc)
         VALUES (:invite_id, :event_key, :event_type, NULL, :payload, :created_at)',
        [
            'invite_id' => $inviteId,
            'event_key' => 'expired',
            'event_type' => 'expired',
            'payload' => '{}',
            'created_at' => '2026-07-24 21:58:27.000000',
        ]
    );
    $payload = [
        'id' => $notificationId,
        'event_key' => $eventKey,
        'user_id' => $legacyUserId,
        'type' => 'invite_expired',
        'title' => 'Срок приглашения истёк',
        'message' => $message,
        'tone' => 'warning',
        'invite_token' => $token,
        'created_at' => '2026-07-24T21:58:27+00:00',
        'read_at' => null,
    ];
    $database->execute(
        'INSERT INTO mgw_notifications (
            notification_id, event_key, recipient_ref, mgw_id, legacy_user_id,
            type, title, message, tone, invite_token, payload_json, created_at_utc,
            read_at_utc, hidden_at_utc
         ) VALUES (
            :notification_id, :event_key, :recipient_ref, :mgw_id, :legacy_user_id,
            :type, :title, :message, :tone, :invite_token, :payload_json, :created_at,
            :read_at, NULL
         )',
        [
            'notification_id' => $notificationId,
            'event_key' => $eventKey,
            'recipient_ref' => $accountRef,
            'mgw_id' => $mgwId,
            'legacy_user_id' => $legacyUserId,
            'type' => 'invite_expired',
            'title' => 'Срок приглашения истёк',
            'message' => $message,
            'tone' => 'warning',
            'invite_token' => $token,
            'payload_json' => $canonicalJson($payload),
            'created_at' => '2026-07-24 21:58:27.000000',
            'read_at' => $read ? '2026-07-24 22:00:00.000000' : null,
        ]
    );

    return [$database, $stateSha];
};

[$database, $stateSha] = $fixture();
$service = new ProductionPrimaryInviteResidualRecoveryService(
    $database,
    new ProductionInviteResidualTestAuditor($database)
);
$preview = $service->preview();
$assertSame(true, $preview['ok'], 'Exact DB-only expired pair preview must pass');
$assertSame(true, $preview['ready'], 'Exact DB-only expired pair must be executable');
$assertSame(1, $preview['db_only_invite_count'], 'Preview must find one DB-only invite');
$assertSame(1, $preview['db_only_notification_count'], 'Preview must find one DB-only notification');
$assertSame(1, $preview['invite_event_count'], 'Preview must capture dependent invite events');
$assertSame(0, $preview['related_match_count'], 'Residual invite must have no match references');
$assertSame(1, $preview['state_revision'], 'Preview must bind state revision');
$assertSame($stateSha, $preview['state_sha256'], 'Preview must bind state fingerprint');
$assertTrue(
    preg_match('/\A[a-f0-9]{64}\z/', (string)$preview['plan_fingerprint']) === 1,
    'Preview must expose a SHA-256 execution fingerprint'
);
$assertThrows(
    static fn() => $service->run(str_repeat('f', 64)),
    'fingerprint changed',
    'Wrong execution fingerprint must make no writes'
);
$assertSame(1, (int)$database->fetchValue('SELECT COUNT(*) FROM mgw_invites'), 'Wrong fingerprint must preserve invite');
$assertSame(1, (int)$database->fetchValue('SELECT COUNT(*) FROM mgw_notifications'), 'Wrong fingerprint must preserve notification');

$result = $service->run((string)$preview['plan_fingerprint']);
$assertSame(true, $result['ok'], 'Exact recovery execution must succeed');
$assertSame(1, $result['deleted']['invite_rows'], 'Execution must delete one invite');
$assertSame(1, $result['deleted']['notification_rows'], 'Execution must delete one notification');
$assertSame(1, $result['deleted']['invite_event_rows'], 'Execution must delete captured invite events');
$assertSame(true, $result['post_delete_all_module_parity'], 'Execution must pass post-delete parity');
$assertSame(false, $result['primary_state_changed'], 'Execution must preserve primary state');
$assertSame(0, (int)$database->fetchValue('SELECT COUNT(*) FROM mgw_invites'), 'Residual invite must be removed');
$assertSame(0, (int)$database->fetchValue('SELECT COUNT(*) FROM mgw_notifications'), 'Residual notification must be removed');
$assertSame(0, (int)$database->fetchValue('SELECT COUNT(*) FROM mgw_invite_events'), 'Residual invite events must be removed');
$assertSame(1, (int)$database->fetchValue('SELECT revision FROM mgw_runtime_primary_state WHERE singleton_id = 1'), 'Primary revision must stay unchanged');
$assertSame($stateSha, (string)$database->fetchValue('SELECT state_sha256 FROM mgw_runtime_primary_state WHERE singleton_id = 1'), 'Primary SHA must stay unchanged');

[$blockedDatabase] = $fixture(true);
$blockedService = new ProductionPrimaryInviteResidualRecoveryService(
    $blockedDatabase,
    new ProductionInviteResidualTestAuditor($blockedDatabase)
);
$blocked = $blockedService->preview();
$assertSame(false, $blocked['ready'], 'Read DB-only notification must block recovery');
$assertTrue(
    in_array('DB-only expiry notification has mutable read or hidden state.', $blocked['blocking_reasons'], true),
    'Blocked preview must explain mutable notification state'
);
$assertSame(1, (int)$blockedDatabase->fetchValue('SELECT COUNT(*) FROM mgw_invites'), 'Blocked preview must preserve invite');
$assertSame(1, (int)$blockedDatabase->fetchValue('SELECT COUNT(*) FROM mgw_notifications'), 'Blocked preview must preserve notification');

fwrite(STDOUT, "ProductionPrimaryInviteResidualRecoveryServiceTest: {$assertions} assertions passed\n");
