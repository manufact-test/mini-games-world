<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/services/StagingTestAuthService.php';

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$root = sys_get_temp_dir() . '/mgw-staging-auth-revoke-' . bin2hex(random_bytes(6));
$registryDir = $root . '/.runtime/staging-test-auth';
if (!mkdir($registryDir, 0700, true) && !is_dir($registryDir)) {
    throw new RuntimeException('Unable to create temporary staging auth registry.');
}

$token = 'mgwstg_' . str_repeat('A', 43);
$tokenHash = hash('sha256', $token);
$registryPath = $registryDir . '/sessions.json';
$registry = [
    'schema_version' => 1,
    'sessions' => [
        $tokenHash => [
            'slot' => 'A',
            'issued_at' => time(),
            'expires_at' => time() + 900,
            'last_seen_at' => null,
            'request_count' => 0,
            'session_id_sha256' => null,
            'device_id_sha256' => null,
            'last_presence_lease_sha256' => null,
        ],
    ],
];
file_put_contents(
    $registryPath,
    json_encode($registry, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . PHP_EOL
);

$config = [
    'environment' => 'staging',
    'base_url' => 'https://seashell-okapi-889488.hostingersite.com',
    'data_dir' => $root,
    'external_payments_enabled' => false,
    'payment_mode' => 'disabled',
    'telegram_stars_mode' => 'disabled',
    'google_play_billing_mode' => 'disabled',
    'staging_test_auth_secret' => str_repeat('s', 40),
];
$server = ['HTTP_HOST' => 'seashell-okapi-889488.hostingersite.com'];
$service = new StagingTestAuthService($config);

$lockHandle = fopen($registryPath, 'c+');
if ($lockHandle === false || !flock($lockHandle, LOCK_EX | LOCK_NB)) {
    throw new RuntimeException('Unable to hold temporary staging auth registry lock.');
}

try {
    $startedAt = microtime(true);
    $removedWhileBusy = $service->revokeCurrent(
        [StagingTestAuthService::COOKIE_NAME => $token],
        $server
    );
    $elapsed = microtime(true) - $startedAt;

    $assert($removedWhileBusy === false, 'Busy registry revoke must fall back without claiming removal.');
    $assert($elapsed < 0.5, 'Busy registry revoke must be non-blocking.');
} finally {
    flock($lockHandle, LOCK_UN);
    fclose($lockHandle);
}

$removedAfterRelease = $service->revokeCurrent(
    [StagingTestAuthService::COOKIE_NAME => $token],
    $server
);
$assert($removedAfterRelease === true, 'Revoke must remove the current session once the registry lock is available.');

$after = json_decode((string)file_get_contents($registryPath), true, 32, JSON_THROW_ON_ERROR);
$assert(!isset($after['sessions'][$tokenHash]), 'Successful revoke must remove the exact token hash from the registry.');

$source = (string)file_get_contents(dirname(__DIR__) . '/services/StagingTestAuthService.php');
$assert(
    str_contains($source, '}, true) === true;'),
    'Only revokeCurrent must opt into the non-blocking registry path.'
);
$assert(
    substr_count($source, '}, true) === true;') === 1,
    'Non-blocking registry ownership must remain scoped to one revokeCurrent call.'
);
$assert(
    str_contains($source, 'LOCK_EX | ($nonBlocking ? LOCK_NB : 0)'),
    'Registry owner must use LOCK_NB only when explicitly requested.'
);
$assert(
    !str_contains($source, 'sleep(') && !str_contains($source, 'usleep('),
    'Staging auth registry must not hide lock contention with sleeps or retries.'
);

@unlink($registryPath);
@rmdir($registryDir);
@rmdir(dirname($registryDir));
@rmdir($root);

fwrite(STDOUT, "StagingTestAuthNonBlockingRevokeTest: {$assertions} assertions passed\n");
