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
require $root . '/accounts/AndroidAccountReauthService.php';

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
        throw new RuntimeException(
            $message . ': expected ' . var_export($expected, true) . ', got ' . var_export($actual, true)
        );
    }
};
$assertThrowsReason = static function (
    callable $callback,
    string $reason,
    string $message
) use (&$assertions): void {
    $assertions++;
    try {
        $callback();
    } catch (AndroidAccountReauthException $error) {
        if ($error->reason === $reason) return;
        throw new RuntimeException($message . ': unexpected reason ' . $error->reason);
    }
    throw new RuntimeException($message . ': no AndroidAccountReauthException was thrown');
};

$pdo = new PDO('sqlite::memory:');
$pdo->exec('PRAGMA foreign_keys = ON');
$database = new PdoDatabaseConnection($pdo);
$runner = new MigrationRunner($database, $databaseDir . '/migrations');
$migration = $runner->migrate(false);
$assert($migration['executed_count'] > 0, 'Android reauth test must apply the current schema.');
$assertSame(
    'mgw_android_reauth_challenges',
    (string)$database->fetchValue(
        "SELECT name FROM sqlite_master WHERE type='table' AND name='mgw_android_reauth_challenges'"
    ),
    'Android reauth challenge table must exist.'
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
        'network_max_requests'=>100,
        'subject_max_requests'=>100,
    ],
];

$auth = new AndroidDeviceAuthService($config, $database);
$reauth = new AndroidAccountReauthService($config, $database);
$assertSame(true, $reauth->enabled(), 'Android reauth must be staging/account-DB gated.');

$raw1 = str_repeat("\x51", 32);
$credential1 = rtrim(strtr(base64_encode($raw1), '+/', '-_'), '=');
$subject1 = hash('sha256', $raw1);
$first = $auth->bootstrapCredential($credential1, '203.0.113.71');
$session1 = (string)$first['session_token'];
$user1 = $first['user'];
$mgw1 = (string)$user1['mgw_id'];

$assertSame(false, $reauth->hasRecentGrant($user1, $session1), 'Normal Android session must not count as sensitive-action reauth.');

$challenge = $reauth->createChallenge($user1, $session1);
$challengeId = (string)$challenge['challenge_id'];
$assertSame('pending', $challenge['status'], 'Android reauth must start pending.');
$assert(
    preg_match('/^ar_[a-f0-9]{24}$/', $challengeId) === 1,
    'Android reauth challenge id must be opaque and bounded.'
);
$assertSame(
    'mgw://android-reauth?challenge=' . $challengeId,
    (string)$challenge['native_url'],
    'WebView may receive only the public native challenge route.'
);

$row = $database->fetchAll(
    'SELECT * FROM mgw_android_reauth_challenges WHERE challenge_id=:challenge_id',
    ['challenge_id'=>$challengeId]
)[0] ?? [];
$assertSame($mgw1, (string)($row['mgw_id'] ?? ''), 'Challenge must bind to the current MGW account.');
$assertSame($subject1, (string)($row['android_subject'] ?? ''), 'Challenge must bind to the current Android identity.');
$assertSame(
    hash('sha256', 'session|' . $session1),
    (string)($row['session_key_hash'] ?? ''),
    'Challenge must bind to the exact current Android session hash.'
);

$serialized = json_encode($database->fetchAll('SELECT * FROM mgw_android_reauth_challenges'), JSON_THROW_ON_ERROR);
$assert(!str_contains($serialized, $credential1), 'Raw Android credential must never enter reauth storage.');
$assert(!str_contains($serialized, $session1), 'Raw Android session token must never enter reauth storage.');

$raw2 = str_repeat("\x52", 32);
$credential2 = rtrim(strtr(base64_encode($raw2), '+/', '-_'), '=');
$secondIdentity = $auth->bootstrapCredential($credential2, '203.0.113.72');
$sessionCountBeforeNativeProof = (int)$database->fetchValue("SELECT COUNT(*) FROM mgw_sessions WHERE provider='android_device'");

$assertThrowsReason(
    static fn() => $reauth->confirmNative($challengeId, $credential2, '203.0.113.72'),
    'credential_mismatch',
    'Another Android identity must never confirm this session challenge.'
);
$assertSame(false, $reauth->hasRecentGrant($user1, $session1), 'Wrong-device proof must not create a grant.');

$confirmed = $reauth->confirmNative($challengeId, $credential1, '203.0.113.71');
$assertSame('confirmed', $confirmed['status'], 'Correct Keystore credential must confirm the challenge.');
$assert(
    trim((string)($confirmed['grant_expires_at'] ?? '')) !== '',
    'Confirmed challenge must expose only a short grant expiry.'
);
$assertSame(
    $sessionCountBeforeNativeProof,
    (int)$database->fetchValue("SELECT COUNT(*) FROM mgw_sessions WHERE provider='android_device'"),
    'Native reauth proof must not create or rotate browser sessions.'
);
$assertSame(true, $reauth->hasRecentGrant($user1, $session1), 'Confirmed proof must authorize this exact session briefly.');

$confirmedAgain = $reauth->confirmNative($challengeId, $credential1, '203.0.113.71');
$assertSame('confirmed', $confirmedAgain['status'], 'Native confirmation retry must be idempotent.');

$sameDeviceSecondSession = $auth->bootstrapCredential($credential1, '203.0.113.73');
$session2 = (string)$sameDeviceSecondSession['session_token'];
$user1Session2 = $sameDeviceSecondSession['user'];
$assert($session2 !== $session1, 'Test must establish a second session for the same Android identity.');
$assertSame(
    false,
    $reauth->hasRecentGrant($user1Session2, $session2),
    'A recent grant must not authorize another session on the same device/account.'
);

$expiring = $reauth->createChallenge($user1Session2, $session2);
$database->execute(
    "UPDATE mgw_android_reauth_challenges
     SET expires_at_utc='2000-01-01 00:00:00.000000'
     WHERE challenge_id=:challenge_id",
    ['challenge_id'=>$expiring['challenge_id']]
);
$assertThrowsReason(
    static fn() => $reauth->confirmNative((string)$expiring['challenge_id'], $credential1, '203.0.113.73'),
    'challenge_expired',
    'Expired native challenge must fail closed.'
);
$assertSame(
    'expired',
    (string)$database->fetchValue(
        'SELECT challenge_status FROM mgw_android_reauth_challenges WHERE challenge_id=:challenge_id',
        ['challenge_id'=>$expiring['challenge_id']]
    ),
    'Expired challenge must become terminal.'
);

$productionConfig = $config;
$productionConfig['environment'] = 'production';
$production = new AndroidAccountReauthService($productionConfig, $database);
$assertSame(false, $production->enabled(), 'Internal Android reauth must remain staging-only.');
$assertThrowsReason(
    static fn() => $production->createChallenge($user1, $session1),
    'android_reauth_unavailable',
    'Production must fail closed before external-provider rollout.'
);

$guardSource = file_get_contents($root . '/accounts/AccountReauthGuard.php') ?: '';
$assert(str_contains($guardSource, "'android_reauth_required'"), 'Sensitive-action guard must expose a distinct Android reauth requirement.');
$assert(str_contains($guardSource, 'hasRecentGrant'), 'Sensitive-action guard must require the recent native grant.');
$assert(!str_contains($guardSource, 'verifyCredentialIdentity('), 'Web/API guard must never receive the raw Android device credential.');

$endpointSource = file_get_contents($root . '/android-reauth.php') ?: '';
$assert(str_contains($endpointSource, "confirm_native"), 'Native endpoint must expose only the bounded credential confirmation action.');
$assert(str_contains($endpointSource, "REQUEST_METHOD") && str_contains($endpointSource, "POST"), 'Android reauth endpoint must remain POST-only.');

fwrite(STDOUT, "Mvp26_4AndroidAccountReauthTest: {$assertions} assertions passed\n");
