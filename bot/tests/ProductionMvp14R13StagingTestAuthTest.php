<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
require_once $root . '/bot/services/StagingTestAuthService.php';

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};
$expectFailure = static function (Closure $callback, string $message) use (&$assertions): void {
    $assertions++;
    try {
        $callback();
    } catch (Throwable) {
        return;
    }
    throw new RuntimeException($message);
};

$tempDir = sys_get_temp_dir() . '/mgw-staging-test-auth-' . bin2hex(random_bytes(6));
if (!mkdir($tempDir, 0700, true) && !is_dir($tempDir)) {
    throw new RuntimeException('Unable to create staging test-auth fixture directory.');
}
$remove = static function (string $path) use (&$remove): void {
    if (is_dir($path)) {
        foreach (array_diff(scandir($path) ?: [], ['.', '..']) as $entry) {
            $remove($path . DIRECTORY_SEPARATOR . $entry);
        }
        @rmdir($path);
    } else {
        @unlink($path);
    }
};

try {
    $secret = str_repeat('s', 48);
    $config = [
        'environment' => 'staging',
        'base_url' => 'https://seashell-okapi-889488.hostingersite.com',
        'data_dir' => $tempDir,
        'setup_secret' => $secret,
        'external_payments_enabled' => false,
        'payment_mode' => 'prepared',
    ];
    $server = ['HTTP_HOST' => 'seashell-okapi-889488.hostingersite.com'];
    $service = new StagingTestAuthService($config);

    $issuedA = $service->issue('A', $secret, $server);
    $assert(($issuedA['slot'] ?? null) === 'A'
        && str_starts_with((string)($issuedA['token'] ?? ''), 'mgwstg_')
        && (int)($issuedA['ttl_seconds'] ?? 0) === 900,
        'Slot A must receive one opaque short-lived staging session.');

    $payloadA = [
        'sessionId' => 'sess_player_a_context',
        'deviceId' => 'device_player_a_context',
        'presenceLeaseId' => 'presence_player_a_document_1',
    ];
    $userA = $service->authenticate(
        $payloadA,
        [StagingTestAuthService::COOKIE_NAME => $issuedA['token']],
        $server
    );
    $assert(($userA['id'] ?? null) === 'stg_test_player_a'
        && ($userA['staging_test_slot'] ?? null) === 'A'
        && !empty($userA['is_dev_user'])
        && !empty($userA['is_staging_test_user']),
        'Slot A must resolve only to the fixed isolated Test Player A identity.');

    $payloadA['presenceLeaseId'] = 'presence_player_a_document_2';
    $sameA = $service->authenticate(
        $payloadA,
        [StagingTestAuthService::COOKIE_NAME => $issuedA['token']],
        $server
    );
    $assert(($sameA['id'] ?? null) === 'stg_test_player_a',
        'A renewed document lease must preserve the same player and device session.');

    $expectFailure(static function () use ($service, $issuedA, $server): void {
        $service->authenticate([
            'sessionId' => 'sess_copied_cookie_context',
            'deviceId' => 'device_copied_cookie_context',
        ], [StagingTestAuthService::COOKIE_NAME => $issuedA['token']], $server);
    }, 'Copying a staging cookie into another browser context must be rejected as replay.');

    $issuedA2 = $service->issue('A', $secret, $server);
    $assert(!hash_equals((string)$issuedA['token'], (string)$issuedA2['token']),
        'Reissuing slot A must rotate its opaque session token.');
    $expectFailure(static function () use ($service, $issuedA, $server, $payloadA): void {
        $service->authenticate(
            $payloadA,
            [StagingTestAuthService::COOKIE_NAME => $issuedA['token']],
            $server
        );
    }, 'Reissuing a slot must revoke its previous session immediately.');

    $issuedB = $service->issue('B', $secret, $server);
    $userB = $service->authenticate([
        'sessionId' => 'sess_player_b_context',
        'deviceId' => 'device_player_b_context',
        'presenceLeaseId' => 'presence_player_b_document_1',
    ], [StagingTestAuthService::COOKIE_NAME => $issuedB['token']], $server);
    $assert(($userB['id'] ?? null) === 'stg_test_player_b'
        && ($userB['id'] ?? null) !== ($userA['id'] ?? null),
        'Player B must have a stable account identity distinct from Player A.');

    $expectFailure(static fn() => $service->issue('C', $secret, $server),
        'The broker must reject every player selector except fixed slots A and B.');
    $expectFailure(static fn() => $service->issue('A', 'wrong-secret', $server),
        'The broker must reject an invalid bearer secret.');

    $production = $config;
    $production['environment'] = 'production';
    $productionService = new StagingTestAuthService($production);
    $expectFailure(static fn() => $productionService->issue('A', $secret, $server),
        'Production must fail closed when test-player issuance is attempted.');
    $assert($productionService->authenticate(
        $payloadA,
        [StagingTestAuthService::COOKIE_NAME => $issuedA2['token']],
        $server
    ) === null, 'Production must never authenticate a staging test cookie.');

    $liveConfig = $config;
    $liveConfig['payment_mode'] = 'live';
    $expectFailure(static fn() => (new StagingTestAuthService($liveConfig))->issue('A', $secret, $server),
        'Test-player issuance must fail closed whenever live payments are enabled.');

    $registryPath = $tempDir . '/.runtime/staging-test-auth/sessions.json';
    $registry = file_get_contents($registryPath);
    $assert(is_string($registry)
        && !str_contains($registry, (string)$issuedA2['token'])
        && !str_contains($registry, 'sess_player_a_context')
        && !str_contains($registry, 'device_player_a_context')
        && !str_contains($registry, 'presence_player_a_document_2'),
        'The private registry must store hashes rather than raw cookies or browser identifiers.');

    $endpoint = file_get_contents($root . '/bot/staging-test-auth.php');
    $auth = file_get_contents($root . '/bot/services/AuthService.php');
    $session = file_get_contents($root . '/app/assets/js/session.js');
    $client = file_get_contents($root . '/app/assets/js/api/client.js');
    foreach ([$endpoint, $auth, $session, $client] as $source) {
        $assert(is_string($source), 'Required staging test-auth source is missing.');
    }

    $assert(str_contains($endpoint, '$method !== \'POST\'')
        && str_contains($endpoint, "header('Allow: POST')")
        && str_contains($endpoint, '/^Bearer\\s+(.+)$/i')
        && !str_contains($endpoint, '$_GET'),
        'The public broker must be POST-only, bearer-protected and free of URL secrets.');
    $assert(str_contains($endpoint, 'StagingTestAuthService::COOKIE_NAME')
        && str_contains($endpoint, "'http_only' => true")
        && str_contains($endpoint, "'secure' => true")
        && str_contains($endpoint, "'same_site' => 'Strict'"),
        'The broker must publish only a secure HttpOnly SameSite cookie.');
    $assert(str_contains($endpoint, "'error' => 'test_auth_unavailable'")
        && !str_contains($endpoint, '$error->getMessage()'),
        'Public broker failures must remain generic.');
    $assert(str_contains($auth, 'new StagingTestAuthService($this->config)')
        && strpos($auth, 'StagingTestAuthService') < strpos($auth, 'browserDevUserAllowed'),
        'Authentication must resolve protected staging sessions before localhost-only dev fallback.');
    $assert(str_contains($session, "const DEVICE_KEY = 'mgw_device_id'")
        && str_contains($session, 'export function getDeviceId()')
        && str_contains($client, 'deviceId:getDeviceId()'),
        'Each browser context must publish a stable device ID separate from its session ID.');
} finally {
    $remove($tempDir);
}

fwrite(STDOUT, "ProductionMvp14R13StagingTestAuthTest: {$assertions} assertions passed\n");
