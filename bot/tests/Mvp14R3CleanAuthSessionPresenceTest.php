<?php
declare(strict_types=1);

use Mgw\CleanRuntime\Server\Auth\AuthenticatedIdentity;
use Mgw\CleanRuntime\Server\Auth\AuthenticationException;
use Mgw\CleanRuntime\Server\Auth\RuntimeAuthenticationService;
use Mgw\CleanRuntime\Server\Auth\TelegramInitDataVerifier;
use Mgw\CleanRuntime\Server\RuntimeBootstrapService;
use Mgw\CleanRuntime\Server\RuntimeConfig;
use Mgw\CleanRuntime\Server\Storage\JsonFileRuntimeRepository;

$root = dirname(__DIR__, 2);
require_once $root . '/app/runtime/server/contracts/RuntimeRepository.php';
require_once $root . '/app/runtime/server/RuntimeConfig.php';
require_once $root . '/app/runtime/server/auth/AuthenticationException.php';
require_once $root . '/app/runtime/server/auth/AuthenticatedIdentity.php';
require_once $root . '/app/runtime/server/auth/TelegramInitDataVerifier.php';
require_once $root . '/app/runtime/server/auth/RuntimeAuthenticationService.php';
require_once $root . '/app/runtime/server/storage/JsonFileRuntimeRepository.php';
require_once $root . '/app/runtime/server/RuntimeBootstrapService.php';

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};
$expect = static function (string $class, callable $callback, string $message) use (&$assertions): void {
    $assertions++;
    try {
        $callback();
    } catch (Throwable $error) {
        if ($error instanceof $class) return;
        throw new RuntimeException($message . ' Wrong exception: ' . $error::class);
    }
    throw new RuntimeException($message . ' No exception was thrown.');
};
$sign = static function (array $fields, string $botToken): string {
    ksort($fields, SORT_STRING);
    $parts = [];
    foreach ($fields as $key => $value) $parts[] = $key . '=' . $value;
    $secret = hash_hmac('sha256', $botToken, 'WebAppData', true);
    $fields['hash'] = hash_hmac('sha256', implode("\n", $parts), $secret);
    return http_build_query($fields, '', '&', PHP_QUERY_RFC3986);
};
$cleanup = static function (string $directory) use (&$cleanup): void {
    if (!is_dir($directory)) return;
    foreach (scandir($directory) ?: [] as $item) {
        if ($item === '.' || $item === '..') continue;
        $path = $directory . '/' . $item;
        if (is_dir($path)) $cleanup($path); else @unlink($path);
    }
    @rmdir($directory);
};

$now = 1760000000;
$botToken = '123456789:test-clean-runtime-bot-token';
$user = [
    'id' => 987654321,
    'first_name' => 'Илья',
    'last_name' => 'Тест',
    'username' => 'mgw_test',
    'language_code' => 'ru',
];
$baseFields = [
    'auth_date' => (string)($now - 30),
    'query_id' => 'AAE-test-query',
    'user' => json_encode($user, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
];
$validInitData = $sign($baseFields, $botToken);
$verifier = new TelegramInitDataVerifier($botToken, 3600, 120, static fn(): int => $now);

$identity = $verifier->verify($validInitData);
$assert($identity instanceof AuthenticatedIdentity, 'Valid Telegram initData must return one identity value.');
$assert($identity->accountId === 'tg_987654321', 'Telegram account id must be derived from the signed Telegram id.');
$assert($identity->method === 'telegram' && $identity->telegramId === '987654321', 'Telegram auth method and id must be explicit.');
$assert($identity->firstName === 'Илья' && $identity->username === 'mgw_test', 'Signed Telegram profile fields must be projected.');

$tampered = preg_replace('/hash=[a-f0-9]{64}/', 'hash=' . str_repeat('0', 64), $validInitData);
$expect(AuthenticationException::class, fn() => $verifier->verify((string)$tampered), 'A forged Telegram hash must fail closed.');

$stale = $sign(array_replace($baseFields, ['auth_date' => (string)($now - 7200)]), $botToken);
$expect(AuthenticationException::class, fn() => $verifier->verify($stale), 'Expired Telegram initData must be rejected.');

$future = $sign(array_replace($baseFields, ['auth_date' => (string)($now + 600)]), $botToken);
$expect(AuthenticationException::class, fn() => $verifier->verify($future), 'Telegram initData beyond clock skew must be rejected.');

$duplicate = $validInitData . '&auth_date=' . ($now - 20);
$expect(AuthenticationException::class, fn() => $verifier->verify($duplicate), 'Duplicate Telegram fields must be rejected before signature validation.');

$temporary = sys_get_temp_dir() . '/mgw-clean-auth-' . bin2hex(random_bytes(8));
try {
    $config = new RuntimeConfig(
        environment: 'staging',
        dataDirectory: $temporary,
        build: 'test-clean-auth',
        botToken: $botToken,
        telegramInitDataMaxAgeSec: 3600,
        telegramInitDataClockSkewSec: 120,
        sessionTimeoutSec: 180,
        presenceTtlSec: 75,
        allowBrowserStagingIdentity: true,
    );
    $authentication = new RuntimeAuthenticationService($config, $verifier);
    $installationId = 'install_auth_12345678901234567890';
    $sessionOne = 'session_auth_12345678901234567890';
    $sessionTwo = 'session_auth_09876543210987654321';

    $telegramPayload = [
        'installation_id' => $installationId,
        'session_id' => $sessionOne,
        'init_data' => $validInitData,
        'launch' => [
            'runtime' => 'mgw-clean-v1',
            'path' => '/app/runtime/index.php',
            'source' => 'standard',
            'invite_present' => false,
            'telegram_available' => true,
        ],
        'presence' => [
            'visibility' => 'visible',
            'platform' => 'telegram_android',
            'timezone_offset' => -180,
        ],
    ];

    $authenticated = $authentication->authenticate($telegramPayload, $installationId);
    $assert($authenticated->accountId === 'tg_987654321', 'The clean auth owner must return the Telegram identity.');

    $forgedPayload = array_replace($telegramPayload, ['init_data' => (string)$tampered]);
    $expect(AuthenticationException::class, fn() => $authentication->authenticate($forgedPayload, $installationId), 'Invalid Telegram data must never fall back to a browser identity.');

    $missingTelegramData = array_replace($telegramPayload, ['init_data' => '']);
    $expect(AuthenticationException::class, fn() => $authentication->authenticate($missingTelegramData, $installationId), 'Telegram launch without initData must fail instead of creating a staging user.');

    $browserPayload = array_replace($telegramPayload, [
        'init_data' => '',
        'launch' => array_replace($telegramPayload['launch'], ['telegram_available' => false]),
    ]);
    $browserIdentity = $authentication->authenticate($browserPayload, $installationId);
    $assert($browserIdentity->method === 'browser_staging', 'Direct browser staging must use only its explicit staging identity.');
    $assert(str_starts_with($browserIdentity->accountId, 'stg_'), 'Browser staging account ids must be isolated from Telegram ids.');

    $repository = new JsonFileRuntimeRepository($temporary);
    $service = new RuntimeBootstrapService($config, $repository, $authentication);
    $first = $service->bootstrap($telegramPayload);
    $assert($first['account']['id'] === 'tg_987654321', 'Bootstrap must persist the authenticated Telegram account.');
    $assert($first['session']['id'] === $sessionOne && $first['session']['active_session_id'] === $sessionOne, 'The first clean session must own the idle account.');
    $assert($first['session']['locked'] === false, 'The first clean session must not be locked.');
    $assert($first['presence']['state'] === 'online' && $first['presence']['visibility'] === 'visible', 'Bootstrap must publish one online presence record.');

    $heartbeat = $service->heartbeat($telegramPayload);
    $assert($heartbeat['storage']['revision'] === 2, 'Heartbeat must advance the same atomic staging state.');
    $assert($heartbeat['account']['id'] === $first['account']['id'], 'Heartbeat must remain bound to the authenticated account.');
    $assert(strtotime($heartbeat['presence']['expires_at']) > strtotime($heartbeat['presence']['last_seen_at']), 'Presence expiry must be later than the heartbeat.');

    $secondSessionPayload = array_replace($telegramPayload, ['session_id' => $sessionTwo]);
    $second = $service->bootstrap($secondSessionPayload);
    $assert($second['session']['active_session_id'] === $sessionTwo, 'A new idle-device session may become the active session.');
    $assert($second['session']['locked'] === false, 'Idle session takeover must remain explicit and unlocked.');
    $assert($second['account']['id'] === $first['account']['id'], 'Multiple sessions must not create duplicate Telegram accounts.');

    $unknownSessionPayload = array_replace($telegramPayload, ['session_id' => 'session_unknown_12345678901234567']);
    $expect(RuntimeException::class, fn() => $service->heartbeat($unknownSessionPayload), 'Heartbeat must reject a session that was never bootstrapped.');

    $stored = file_get_contents($temporary . '/runtime-state-v2.json');
    $assert(is_string($stored) && $stored !== '', 'The clean auth contour must publish the replacement staging state.');
    $assert(!str_contains((string)$stored, $validInitData), 'Raw Telegram initData must never be persisted.');
    $assert(!str_contains((string)$stored, (string)($baseFields['query_id'] ?? '')), 'Telegram query ids must never be persisted.');
    $assert(!str_contains((string)$stored, 'hash='), 'Telegram hashes must never be persisted.');
    $decoded = json_decode((string)$stored, true, 512, JSON_THROW_ON_ERROR);
    $assert(count($decoded['accounts'] ?? []) === 1, 'One Telegram user must produce exactly one clean account record.');
    $assert(count($decoded['sessions'] ?? []) === 2, 'Two bootstrapped devices must produce two explicit clean sessions.');
    $assert(($decoded['accounts']['tg_987654321']['active_session_id'] ?? null) === $sessionTwo, 'The account must expose one active session owner.');

    $noBrowserConfig = new RuntimeConfig(
        environment: 'staging',
        dataDirectory: $temporary . '/strict',
        build: 'test-clean-auth-strict',
        botToken: $botToken,
        allowBrowserStagingIdentity: false,
    );
    $strictAuth = new RuntimeAuthenticationService($noBrowserConfig, $verifier);
    $expect(AuthenticationException::class, fn() => $strictAuth->authenticate($browserPayload, $installationId), 'Browser staging identity must be removable without adding another auth path.');

    $unconfiguredVerifier = new TelegramInitDataVerifier('', 3600, 120, static fn(): int => $now);
    $unconfiguredAuth = new RuntimeAuthenticationService($config, $unconfiguredVerifier);
    $expect(RuntimeException::class, fn() => $unconfiguredAuth->authenticate($telegramPayload, $installationId), 'Telegram auth without a configured token must fail and must not fall back.');
} finally {
    $cleanup($temporary);
}

fwrite(STDOUT, "Mvp14R3CleanAuthSessionPresenceTest: {$assertions} assertions passed\n");
