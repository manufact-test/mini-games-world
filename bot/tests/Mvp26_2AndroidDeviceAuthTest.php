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

if (!extension_loaded('pdo_sqlite')) {
    throw new RuntimeException('Mvp26_2AndroidDeviceAuthTest requires pdo_sqlite.');
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
$assert($migration['executed_count'] > 0, 'Focused Android auth test must apply the current schema.');
$assertSame(
    'mgw_android_auth_attempts',
    (string)$database->fetchValue(
        "SELECT name FROM sqlite_master WHERE type='table' AND name='mgw_android_auth_attempts'"
    ),
    'Android auth attempt table must exist.'
);

$config = [
    'environment'=>'staging',
    'storage_driver'=>'json',
    'bot_token'=>'123456789:mvp26-android-test-secret-not-live',
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
        'network_max_requests'=>3,
        'subject_max_requests'=>2,
    ],
];

$service = new AndroidDeviceAuthService($config, $database);
$assertSame(true, $service->enabled(), 'Android device auth must be available only on configured staging DB account routing.');

$assertThrows(
    static fn() => $service->bootstrapCredential('not-a-device-credential', '203.0.113.44'),
    'credential',
    'Malformed Android device credential must fail before account creation.'
);
$assertSame(0, (int)$database->fetchValue('SELECT COUNT(*) FROM mgw_android_auth_attempts'), 'Malformed credential must not consume persistent account-attempt state.');

$rawCredential = str_repeat("\x42", 32);
$credential = rtrim(strtr(base64_encode($rawCredential), '+/', '-_'), '=');
$subject = hash('sha256', $rawCredential);
$first = $service->bootstrapCredential($credential, '203.0.113.44');

$assert(preg_match('/^ada_[a-f0-9]{64}$/', (string)$first['session_token']) === 1, 'Server must issue one opaque Android session token.');
$assertSame(false, $first['reused'], 'First Android device bootstrap must issue a new session.');
$assert(MgwIdGenerator::isValid((string)($first['user']['mgw_id'] ?? '')), 'Android identity must resolve one canonical MGW account.');
$assertSame('android_' . $subject, $first['user']['id'] ?? null, 'Legacy runtime key must be stable and derived only from the one-way provider subject.');
$assertSame('android_device', $first['user']['mgw_identity_provider'] ?? null, 'Android provider must remain explicit.');
$assertSame('legacy:' . $subject, $first['user']['mgw_account_ref'] ?? null, 'Android runtime must reuse the existing account ownership owner.');

$assertSame(
    $subject,
    (string)$database->fetchValue(
        'SELECT provider_subject FROM mgw_identities WHERE provider=:provider',
        ['provider'=>'android_device']
    ),
    'Database identity must store only the SHA-256 provider subject.'
);
$assertSame(
    hash('sha256', 'session|' . (string)$first['session_token']),
    (string)$database->fetchValue(
        'SELECT session_key_hash FROM mgw_sessions WHERE provider=:provider',
        ['provider'=>'android_device']
    ),
    'Android session must use the existing one-way MGW session owner.'
);

$databaseSnapshot = json_encode([
    'identities'=>$database->fetchAll('SELECT * FROM mgw_identities'),
    'sessions'=>$database->fetchAll('SELECT * FROM mgw_sessions'),
    'attempts'=>$database->fetchAll('SELECT * FROM mgw_android_auth_attempts'),
], JSON_THROW_ON_ERROR);
$assert(!str_contains($databaseSnapshot, $credential), 'Raw Android credential must never be persisted.');
$assert(!str_contains($databaseSnapshot, (string)$first['session_token']), 'Raw Android session token must never be persisted.');
$assert(!str_contains($databaseSnapshot, '203.0.113.44'), 'Raw network address must never be persisted.');
$assertSame(2, (int)$database->fetchValue('SELECT COUNT(*) FROM mgw_android_auth_attempts'), 'One bootstrap must write only hashed network and subject attempt rows.');

$authenticated = $service->authenticateCookie((string)$first['session_token']);
$assertSame($first['user']['mgw_id'], $authenticated['mgw_id'] ?? null, 'HttpOnly Android session must resolve the same MGW account.');
$assertSame(null, $service->authenticateCookie('ada_' . str_repeat('0', 64)), 'Unknown Android session must fail closed.');

$sessionCount = (int)$database->fetchValue("SELECT COUNT(*) FROM mgw_sessions WHERE provider='android_device'");
$second = $service->bootstrapCredential($credential, '203.0.113.44', (string)$first['session_token']);
$assertSame(true, $second['reused'], 'Cold launch with a valid cookie must reuse the server session.');
$assertSame($first['session_token'], $second['session_token'], 'Session reuse must not rotate a valid cookie on every app open.');
$assertSame($sessionCount, (int)$database->fetchValue("SELECT COUNT(*) FROM mgw_sessions WHERE provider='android_device'"), 'Session reuse must not leak durable session rows.');

$assertThrows(
    static fn() => $service->bootstrapCredential($credential, '203.0.113.44', (string)$first['session_token']),
    'rate limit',
    'Bounded subject window must stop repeated bootstrap amplification.'
);

$productionConfig = $config;
$productionConfig['environment'] = 'production';
$production = new AndroidDeviceAuthService($productionConfig, $database);
$assertSame(false, $production->enabled(), 'Android device auth slice must fail closed in production.');
$assertSame(null, $production->authenticateCookie((string)$first['session_token']), 'Production must not accept staging Android cookies.');
$assertThrows(
    static fn() => $production->bootstrapCredential($credential, '203.0.113.55'),
    'unavailable',
    'Production must reject the internal Android bootstrap path.'
);

$authSource = file_get_contents($root . '/services/AuthService.php') ?: '';
$telegramPos = strpos($authSource, '$initData !==');
$stagingPos = strpos($authSource, 'StagingTestAuthService');
$androidPos = strpos($authSource, 'AndroidDeviceAuthService');
$browserPos = strpos($authSource, 'browserDevUserAllowed');
$assert($telegramPos !== false && $stagingPos !== false && $androidPos !== false && $browserPos !== false, 'AuthService must expose all reviewed auth boundaries.');
$assert($telegramPos < $androidPos, 'Telegram signed auth must remain ahead of the Android cookie adapter.');
$assert($stagingPos < $androidPos, 'Canonical staging E2E auth must remain ahead of Android device auth.');
$assert($androidPos < $browserPos, 'Android cookie auth must remain ahead of localhost browser-dev fallback.');

$endpointSource = file_get_contents($root . '/android-auth.php') ?: '';
$assert(str_contains($endpointSource, "REQUEST_METHOD") && str_contains($endpointSource, "POST"), 'Android bootstrap endpoint must be POST-only.');
$assert(str_contains($endpointSource, "'secure'=>true") && str_contains($endpointSource, "'httponly'=>true"), 'Android session cookie must be Secure and HttpOnly.');
$assert(str_contains($endpointSource, "'samesite'=>'Strict'"), 'Android session cookie must be SameSite=Strict.');
$assert(str_contains($endpointSource, 'WebAppLaunchUrl::base'), 'Android bootstrap must redirect through the canonical active launch owner.');

$ownershipSource = file_get_contents($root . '/accounts/RuntimeAccountOwnershipService.php') ?: '';
$assert(str_contains($ownershipSource, "'android_device'"), 'Runtime ownership must explicitly recognize the Android identity adapter.');

fwrite(STDOUT, "Mvp26_2AndroidDeviceAuthTest: {$assertions} assertions passed\n");
