<?php
declare(strict_types=1);

use Mgw\CleanRuntime\Server\Auth\RuntimeAuthenticationService;
use Mgw\CleanRuntime\Server\Auth\TelegramInitDataVerifier;
use Mgw\CleanRuntime\Server\Context\RuntimeRequestContextFactory;
use Mgw\CleanRuntime\Server\Match\RuntimeMatchService;
use Mgw\CleanRuntime\Server\Match\TicTacToeRules;
use Mgw\CleanRuntime\Server\RuntimeApplicationService;
use Mgw\CleanRuntime\Server\RuntimeConfig;
use Mgw\CleanRuntime\Server\RuntimeKernel;
use Mgw\CleanRuntime\Server\Session\RuntimeSessionService;
use Mgw\CleanRuntime\Server\Storage\JsonFileRuntimeStore;

$root = dirname(__DIR__, 2);
foreach ([
    '/app/runtime/server/contracts/RuntimeStateStore.php',
    '/app/runtime/server/RuntimeConfig.php',
    '/app/runtime/server/auth/AuthenticationException.php',
    '/app/runtime/server/auth/AuthenticatedIdentity.php',
    '/app/runtime/server/auth/TelegramInitDataVerifier.php',
    '/app/runtime/server/auth/RuntimeAuthenticationService.php',
    '/app/runtime/server/context/RuntimeRequestContext.php',
    '/app/runtime/server/context/RuntimeRequestContextFactory.php',
    '/app/runtime/server/storage/JsonFileRuntimeStore.php',
    '/app/runtime/server/session/RuntimeSessionService.php',
    '/app/runtime/server/match/TicTacToeRules.php',
    '/app/runtime/server/match/RuntimeMatchService.php',
    '/app/runtime/server/RuntimeApplicationService.php',
    '/app/runtime/server/RuntimeKernel.php',
] as $file) require_once $root . $file;

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};
$temporary = sys_get_temp_dir() . '/mgw-clean-runtime-' . bin2hex(random_bytes(8));
$cleanup = static function (string $directory) use (&$cleanup): void {
    if (!is_dir($directory)) return;
    $items = scandir($directory);
    if (!is_array($items)) return;
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') continue;
        $path = $directory . '/' . $item;
        if (is_dir($path)) $cleanup($path); else @unlink($path);
    }
    @rmdir($directory);
};

try {
    $config = new RuntimeConfig(
        environment: 'staging',
        dataDirectory: $temporary,
        build: 'test-clean-server-v3',
        allowBrowserStagingIdentity: true,
    );
    $store = new JsonFileRuntimeStore($config->dataDirectory);
    $authentication = new RuntimeAuthenticationService($config, new TelegramInitDataVerifier('', 86400, 300));
    $contexts = new RuntimeRequestContextFactory($authentication);
    $sessions = new RuntimeSessionService($config);
    $matches = new RuntimeMatchService($config, new TicTacToeRules());
    $application = new RuntimeApplicationService($config, $store, $contexts, $sessions, $matches);
    $kernel = new RuntimeKernel($application);

    $health = $store->health();
    $assert($health['adapter'] === 'json_file_staging', 'The clean server must expose one staging JSON adapter.');
    $assert($health['schema_version'] === 3, 'The clean server must expose schema v3.');
    $assert($health['state_present'] === false, 'The v3 staging store must start empty.');
    $assert($health['writable'] === true, 'The v3 staging directory must be writable.');

    $payload = [
        'installation_id' => 'install_123456789012345678901234',
        'session_id' => 'session_123456789012345678901234',
        'init_data' => '',
        'launch' => [
            'runtime' => 'mgw-clean-v1',
            'path' => '/app/runtime/index.php',
            'source' => 'standard',
            'invite_present' => false,
            'telegram_available' => false,
        ],
        'presence' => [
            'visibility' => 'visible',
            'platform' => 'ci',
            'timezone_offset' => -180,
        ],
    ];

    $first = $application->bootstrap($payload);
    $assert($first['ok'] === true, 'The first clean v3 bootstrap must succeed.');
    $assert($first['server']['build'] === 'test-clean-server-v3', 'Bootstrap must report the exact clean build.');
    $assert($first['storage']['revision'] === 1, 'The first v3 transaction must publish revision one.');
    $assert($first['account']['auth_method'] === 'browser_staging', 'Browser staging must use its explicit identity.');
    $assert($first['session']['locked'] === false, 'The first clean session must be unlocked.');
    $assert($first['presence']['state'] === 'online', 'Bootstrap must publish online presence.');
    $assert($first['balances']['match'] === 100, 'A new clean staging account must receive the isolated test balance.');
    $assert($first['matchmaking'] === null && $first['active_match'] === null && $first['match_result'] === null, 'Bootstrap must start with one explicit idle match projection.');

    $second = $application->bootstrap($payload);
    $assert($second['storage']['revision'] === 2, 'The second bootstrap must advance one revision.');
    $assert($second['installation']['launch_count'] === 2, 'The installation launch count must remain owned by the session service.');
    $assert($second['account']['id'] === $first['account']['id'], 'One installation must retain one browser staging account.');

    $stateFile = $temporary . '/runtime-state-v3.json';
    $stored = file_get_contents($stateFile);
    $assert(is_string($stored) && $stored !== '', 'The clean v3 state file must be published.');
    $decoded = json_decode((string)$stored, true, 512, JSON_THROW_ON_ERROR);
    $accountId = (string)$first['account']['id'];
    $sessionId = (string)$first['session']['id'];
    $assert(($decoded['schema_version'] ?? null) === 3, 'The committed schema version must be three.');
    $assert(($decoded['revision'] ?? null) === 2, 'The committed revision must match the response.');
    $assert(isset($decoded['accounts'][$accountId]), 'The clean account must be stored by account id.');
    $assert(isset($decoded['sessions'][$sessionId]), 'The clean session must be stored by session id.');
    $assert(isset($decoded['presence'][$sessionId]), 'Presence must be stored by session, not overwrite another device.');
    $assert(isset($decoded['queue'], $decoded['games'], $decoded['commands'], $decoded['ledger']), 'The v3 state must declare the full match contour collections.');
    $assert(!str_contains((string)$stored, 'init_data') && !str_contains((string)$stored, 'invite_token'), 'The v3 state must persist no launch secrets.');

    $heartbeat = $kernel->handle('POST', 'heartbeat', $payload);
    $assert($heartbeat['status'] === 200, 'The clean kernel must route heartbeat.');
    $assert($heartbeat['body']['storage']['revision'] === 3, 'Heartbeat must use the same atomic state store.');
    $assert($heartbeat['body']['presence']['state'] === 'online', 'Heartbeat must refresh clean presence.');

    $healthResponse = $kernel->handle('GET', 'health', []);
    $assert($healthResponse['status'] === 200 && $healthResponse['body']['ok'] === true, 'The clean health route must remain available.');
    $assert($healthResponse['body']['storage']['state_present'] === true, 'Health must observe the v3 state file.');

    $unknown = $kernel->handle('POST', 'legacy_action', []);
    $assert($unknown['status'] === 404 && $unknown['body']['ok'] === false, 'Unknown or legacy actions must fail closed.');
} finally {
    $cleanup($temporary);
}

fwrite(STDOUT, "Mvp14R3CleanServerBootstrapTest: {$assertions} assertions passed\n");
