<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$read = static function (string $relative) use ($root): string {
    $source = file_get_contents($root . '/' . $relative);
    if (!is_string($source)) throw new RuntimeException('Missing staging context source: ' . $relative);
    return $source;
};

$session = $read('app/assets/js/session.js');
$client = $read('app/assets/js/api/client.js');
$presence = $read('app/assets/js/production-v110-presence.js');
$presenceEndpoint = $read('bot/presence.php');
$authService = $read('bot/services/StagingTestAuthService.php');
$broker = $read('bot/staging-test-auth.php');

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

$assert(str_contains($session, "const SESSION_KEY = 'mgw_device_session_id'")
    && str_contains($session, "const DEVICE_KEY = 'mgw_device_id'")
    && str_contains($session, "randomId('sess')")
    && str_contains($session, "randomId('device')"),
    'Browser contexts must keep separate stable session and device identities.');

$assert(str_contains($client, 'sessionId:getSessionId()')
    && str_contains($client, 'deviceId:getDeviceId()'),
    'Canonical application requests must carry both session and device identities.');

$assert(str_contains($presence, 'const presenceLeaseId = createPresenceLeaseId();')
    && str_contains($presence, 'presenceLeaseId,')
    && str_contains($presence, 'return `presence_${random}`;'),
    'Every opened test document must own a distinct presence lease.');

$assert(str_contains($presenceEndpoint, "$payload['presenceLeaseId'] ?? ''")
    && str_contains($presenceEndpoint, '$presence->touch($accountId, $sessionId, $presenceLeaseId)')
    && str_contains($presenceEndpoint, '$presence->leave($accountId, $sessionId, $presenceLeaseId)'),
    'Presence transport must preserve the document lease through ping and leave.');

$assert(str_contains($authService, "'A' => [")
    && str_contains($authService, "'B' => [")
    && str_contains($authService, "'id' => 'stg_test_player_a'")
    && str_contains($authService, "'id' => 'stg_test_player_b'")
    && !str_contains($authService, "payload['userId']")
    && !str_contains($authService, "payload['accountId']"),
    'The broker must expose exactly two fixed identities without arbitrary account selection.');

$assert(str_contains($authService, "hash('sha256', 'session|' . $sessionId)")
    && str_contains($authService, 'Staging test session replay was rejected.')
    && str_contains($authService, "hash('sha256', 'device|' . $deviceId)"),
    'A copied cookie must remain bound to its original session and device context.');

$assert(str_contains($broker, 'HTTP_AUTHORIZATION')
    && str_contains($broker, 'StagingTestAuthService::COOKIE_NAME')
    && !str_contains($broker, '$_GET')
    && !str_contains($broker, '?slot='),
    'Protected credentials and player slots must never be selected through a URL query.');

$assert(str_contains($authService, "!== 'staging'")
    && str_contains($authService, "self::STAGING_HOST")
    && str_contains($authService, 'assertPaymentsDisabled()'),
    'Test context authentication must fail closed outside isolated non-live staging.');

fwrite(STDOUT, "ProductionMvp14R13StagingTestContextIsolationContractTest: {$assertions} assertions passed\n");
