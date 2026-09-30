<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$databaseDir = $root . '/database';

require $databaseDir . '/DatabaseConnectionInterface.php';
require $databaseDir . '/DatabaseConfig.php';
require $databaseDir . '/PdoDatabaseConnection.php';
require $databaseDir . '/PdoConnectionFactory.php';
require $databaseDir . '/DatabaseMigrationInterface.php';
require $databaseDir . '/MigrationRepository.php';
require $databaseDir . '/MigrationRunner.php';
require $root . '/storage/RuntimeStorageRouter.php';
require $root . '/accounts/MgwIdGenerator.php';
require $root . '/accounts/AccountIdentityService.php';
require $root . '/accounts/RuntimeAccountOwnershipService.php';
require $root . '/accounts/AndroidAuthAttemptLimiter.php';
require $root . '/accounts/AndroidDeviceAuthService.php';
require $root . '/accounts/AccountReauthGuard.php';

if (!extension_loaded('pdo_sqlite')) {
    throw new RuntimeException('Mvp26_4AndroidAccountReauthTest requires pdo_sqlite.');
}

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};
$assertSame = static function (mixed $expected, mixed $actual, string $message) use (&$assertions): void {
    $assertions++;
    if ($expected !== $actual) {
        throw new RuntimeException($message . ': expected ' . var_export($expected, true)
            . ', got ' . var_export($actual, true));
    }
};
$assertReason = static function (callable $callback, string $reason, string $message) use (&$assertions): void {
    $assertions++;
    try {
        $callback();
    } catch (AccountReauthException $error) {
        if ($error->reason === $reason) return;
        throw new RuntimeException($message . ': unexpected reason ' . $error->reason);
    }
    throw new RuntimeException($message . ': no AccountReauthException was thrown');
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

$pdo = new PDO('sqlite::memory:');
$pdo->exec('PRAGMA foreign_keys = ON');
$database = new PdoDatabaseConnection($pdo);
$runner = new MigrationRunner($database, $databaseDir . '/migrations');
$migration = $runner->migrate(false);
$assert($migration['executed_count'] > 0, 'Android reauth test must apply the current schema.');
$assertSame(
    1,
    (int)$database->fetchValue(
        "SELECT COUNT(*) FROM pragma_table_info('mgw_sessions') WHERE name='last_reauthenticated_at_utc'"
    ),
    'Canonical MGW session must own the Android reauth timestamp.'
);

$config = [
    'environment'=>'staging',
    'storage_driver'=>'json',
    'bot_token'=>'mvp26-reauth-test-secret-not-live',
    'mgw_account_session_ttl_sec'=>3600,
    'database'=>[
        'enabled'=>true,
        'driver'=>'mysql',
        'host'=>'localhost',
        'port'=>3306,
        'name'=>'mgw_test',
        'user'=>'mgw_test',
        'password'=>'test-only',
        'charset'=>'utf8mb4',
    ],
    'feature_flags'=>[
        'database_runtime'=>[
            'enabled'=>true,
            'modules'=>['accounts'=>true],
        ],
    ],
    'android_auth_rate_limits'=>[
        'window_seconds'=>900,
        'network_max_requests'=>20,
        'subject_max_requests'=>20,
        'reauth_window_seconds'=>900,
        'reauth_network_max_requests'=>20,
        'reauth_subject_max_requests'=>20,
    ],
];

$service = new AndroidDeviceAuthService($config, $database);
$rawCredential = str_repeat("\x52", 32);
$credential = rtrim(strtr(base64_encode($rawCredential), '+/', '-_'), '=');
$boot = $service->bootstrapCredential($credential, '203.0.113.80');
$token = (string)$boot['session_token'];
$sourceMgwId = (string)$boot['user']['mgw_id'];
$sessionHash = hash('sha256', 'session|' . $token);

// Reproduce the accepted account-link end-state: the Android identity/session are
// moved onto an existing Telegram MGW owner while the native credential stays the
// same. Reauth must follow the moved canonical session, not the retired source.
$accounts = new AccountIdentityService($database, 3600, (string)$config['bot_token']);
$telegram = $accounts->resolveProviderIdentity(
    'telegram',
    '900000001',
    'telegram_web',
    ['username'=>'linked_reauth_target'],
    'telegram-reauth-target-session'
);
$mgwId = (string)$telegram['mgw_id'];
(new RuntimeAccountOwnershipService($database))->ensure('telegram', '900000001', $mgwId);
$database->execute(
    'UPDATE mgw_identities SET mgw_id=:target_mgw_id
     WHERE mgw_id=:source_mgw_id AND provider=:provider',
    [
        'target_mgw_id'=>$mgwId,
        'source_mgw_id'=>$sourceMgwId,
        'provider'=>'android_device',
    ]
);
$database->execute(
    'UPDATE mgw_sessions SET mgw_id=:target_mgw_id
     WHERE session_key_hash=:session_key_hash AND provider=:provider',
    [
        'target_mgw_id'=>$mgwId,
        'session_key_hash'=>$sessionHash,
        'provider'=>'android_device',
    ]
);
$linked = $service->authenticateCookie($token);
$assertSame($mgwId, $linked['mgw_id'] ?? null, 'Android session must resolve the linked Telegram MGW owner before reauth.');
$assertSame('900000001', $linked['id'] ?? null, 'Linked Android session must preserve the target Telegram runtime owner.');

$assertSame(
    null,
    $service->authenticateRecentlyReauthenticatedCookie($token, AccountReauthGuard::MAX_AGE_SECONDS),
    'Ordinary Android login must not count as sensitive-action reauth.'
);

$previousCookie = $_COOKIE[AndroidDeviceAuthService::COOKIE_NAME] ?? null;
$_COOKIE[AndroidDeviceAuthService::COOKIE_NAME] = $token;

$assertReason(
    static fn() => AccountReauthGuard::authorize($config, ['initData'=>'','sessionId'=>''], null, $service),
    'android_reauth_required',
    'Valid Android session without fresh user presence must fail closed.'
);

$otherRaw = str_repeat("\x53", 32);
$otherCredential = rtrim(strtr(base64_encode($otherRaw), '+/', '-_'), '=');
$assertThrows(
    static fn() => $service->reauthenticateCredential($otherCredential, $token, '203.0.113.80'),
    'does not match',
    'A different device credential must never reauthenticate the active session.'
);

$reauth = $service->reauthenticateCredential($credential, $token, '203.0.113.80');
$assertSame($mgwId, $reauth['user']['mgw_id'] ?? null, 'Native reauth must remain on the exact canonical MGW account.');
$assert(
    trim((string)($reauth['reauthenticated_at_utc'] ?? '')) !== '',
    'Native reauth must return a server-owned reauthentication timestamp.'
);

$fresh = $service->authenticateRecentlyReauthenticatedCookie(
    $token,
    AccountReauthGuard::MAX_AGE_SECONDS
);
$assertSame($mgwId, $fresh['mgw_id'] ?? null, 'Fresh Android reauth must resolve the same MGW session.');

$guarded = AccountReauthGuard::authorize(
    $config,
    ['initData'=>'','sessionId'=>''],
    null,
    $service
);
$assertSame($mgwId, $guarded['mgw_id'] ?? null, 'Sensitive Account Data action must accept the fresh Android proof.');

$expired = (new DateTimeImmutable('now', new DateTimeZone('UTC')))
    ->modify('-10 minutes')
    ->format('Y-m-d H:i:s.u');
$database->execute(
    'UPDATE mgw_sessions SET last_reauthenticated_at_utc=:expired
     WHERE session_key_hash=:session_key_hash',
    ['expired'=>$expired, 'session_key_hash'=>$sessionHash]
);

$assertSame(
    null,
    $service->authenticateRecentlyReauthenticatedCookie(
        $token,
        AccountReauthGuard::MAX_AGE_SECONDS
    ),
    'Android reauth must expire after the bounded freshness window.'
);
$assertReason(
    static fn() => AccountReauthGuard::authorize($config, ['initData'=>'','sessionId'=>''], null, $service),
    'android_reauth_required',
    'Expired Android reauth must require explicit user presence again.'
);

$attemptScopes = array_map(
    static fn(array $row): string => (string)$row['scope_code'],
    $database->fetchAll(
        "SELECT scope_code FROM mgw_android_auth_attempts
         WHERE scope_code LIKE 'reauth_%'
         ORDER BY scope_code"
    )
);
$assert(in_array('reauth_network', $attemptScopes, true), 'Reauth network attempts must use a separate persisted scope.');
$assert(in_array('reauth_subject', $attemptScopes, true), 'Reauth subject attempts must use a separate persisted scope.');

$databaseSnapshot = json_encode([
    'sessions'=>$database->fetchAll('SELECT * FROM mgw_sessions'),
    'attempts'=>$database->fetchAll('SELECT * FROM mgw_android_auth_attempts'),
], JSON_THROW_ON_ERROR);
$assert(!str_contains($databaseSnapshot, $credential), 'Raw Android credential must never be persisted by reauth.');
$assert(!str_contains($databaseSnapshot, $token), 'Raw Android session token must never be persisted by reauth.');
$assert(!str_contains($databaseSnapshot, '203.0.113.80'), 'Raw network address must never be persisted by reauth.');

$productionConfig = $config;
$productionConfig['environment'] = 'production';
$production = new AndroidDeviceAuthService($productionConfig, $database);
$assertThrows(
    static fn() => $production->reauthenticateCredential($credential, $token, '203.0.113.80'),
    'unavailable',
    'Internal Android reauth must remain fail-closed in production.'
);

$endpointSource = file_get_contents($root . '/android-reauth.php') ?: '';
$assert(str_contains($endpointSource, "REQUEST_METHOD") && str_contains($endpointSource, "POST"), 'Android reauth endpoint must be POST-only.');
$assert(str_contains($endpointSource, 'AndroidDeviceAuthService::COOKIE_NAME'), 'Android reauth endpoint must require the existing HttpOnly session.');
$assert(!str_contains($endpointSource, 'setcookie('), 'Android reauth must not create a parallel auth cookie.');
$assert(!str_contains($endpointSource, '$credential) .'), 'Endpoint diagnostics must never concatenate the raw credential into logs.');

if ($previousCookie === null) unset($_COOKIE[AndroidDeviceAuthService::COOKIE_NAME]);
else $_COOKIE[AndroidDeviceAuthService::COOKIE_NAME] = $previousCookie;

fwrite(STDOUT, "Mvp26_4AndroidAccountReauthTest: {$assertions} assertions passed\n");
