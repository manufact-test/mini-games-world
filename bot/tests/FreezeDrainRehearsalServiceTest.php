<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/storage/contracts/StorageTransactionInterface.php';
require_once dirname(__DIR__) . '/storage/contracts/StorageAdapterInterface.php';
require_once dirname(__DIR__) . '/storage/JsonDatabase.php';
require_once dirname(__DIR__) . '/storage/JsonStorageAdapter.php';
require_once dirname(__DIR__) . '/database/DatabaseConfig.php';
require_once dirname(__DIR__) . '/storage/RuntimeStorageRouter.php';
require_once dirname(__DIR__) . '/core/RuntimeConfigLoader.php';
require_once dirname(__DIR__) . '/cutover/FreezeDrainRehearsalService.php';

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

$root = sys_get_temp_dir() . '/mgw-freeze-drain-' . bin2hex(random_bytes(6));
$dataDir = $root . '/data';
$privateDir = $root . '/private';
mkdir($dataDir, 0700, true);
mkdir($privateDir, 0700, true);

$deleteTree = static function (string $path) use (&$deleteTree): void {
    if (!is_dir($path)) {
        if (is_file($path)) @unlink($path);
        return;
    }
    foreach (scandir($path) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') continue;
        $child = $path . '/' . $entry;
        if (is_dir($child)) $deleteTree($child);
        else @unlink($child);
    }
    @rmdir($path);
};

try {
    $config = [
        'environment' => 'staging',
        'storage_driver' => 'json',
        'data_dir' => $dataDir,
        'feature_flags' => [
            'features' => [
                'matchmaking' => true,
                'invitations' => true,
                'payments' => true,
            ],
            'database_runtime' => [
                'enabled' => false,
                'modules' => [],
            ],
        ],
    ];

    $storage = new JsonStorageAdapter($dataDir);
    $storage->transaction(static function (array &$data): void {
        $data['users'] = [
            'player_searching' => [
                'id' => 'player_searching',
                'status' => 'searching',
                'current_game_id' => null,
            ],
            'player_active' => [
                'id' => 'player_active',
                'status' => 'playing',
                'current_game_id' => 'game_active',
            ],
        ];
        $data['queue'] = [[
            'id' => 'queue_one',
            'user_id' => 'player_searching',
            'room' => 'match',
        ]];
        $data['games'] = [
            'game_active' => [
                'id' => 'game_active',
                'status' => 'active',
                'game_type' => 'domino',
                'room' => 'match',
                'player_ids' => ['player_active', 'bot_one'],
            ],
        ];
        $data['invites'] = [[
            'id' => 'invite_one',
            'status' => 'pending',
        ]];
    });

    $controlFile = $privateDir . '/cutover-rehearsal.json';
    $service = new FreezeDrainRehearsalService($config, $storage, $controlFile);

    $before = $service->status();
    $assert($before['freeze']['active'] === false, 'Freeze must be inactive initially.');
    $assert($before['drain']['active_games'] === 1, 'Initial active game count must be visible.');
    $assert($before['drain']['queue_entries'] === 1, 'Initial queue count must be visible.');

    $frozen = $service->freeze();
    $assert($frozen['freeze']['active'] === true, 'Freeze must become active.');
    $assert($frozen['queue_cleanup']['removed_queue_entries'] === 1, 'Freeze must clear queued matchmaking.');
    $assert($frozen['queue_cleanup']['reset_searching_users'] === 1, 'Freeze must reset searching users.');
    $assert($frozen['queue_cleanup']['active_games_untouched'] === true, 'Freeze must not modify active games.');
    $assert($frozen['drain']['active_games'] === 1, 'Active game must continue after freeze.');
    $assert($frozen['drain']['queue_entries'] === 0, 'Queue must be empty after freeze.');
    $assert($frozen['drain']['ready'] === false, 'Drain must wait while a game is active.');

    $snapshot = $storage->readOnly(static fn(array $data): array => $data);
    $assert(($snapshot['games']['game_active']['status'] ?? '') === 'active', 'Freeze must preserve active game state.');
    $assert(($snapshot['users']['player_searching']['status'] ?? '') === 'idle', 'Searching user must return to idle.');

    $runtimeFile = $privateDir . '/runtime.php';
    file_put_contents($runtimeFile, <<<'PHP'
<?php
return [
    'features' => [
        'matchmaking' => true,
        'invitations' => true,
        'payments' => true,
    ],
];
PHP
    );
    $merged = RuntimeConfigLoader::merge($config, $privateDir . '/config.php');
    $assert(($merged['feature_flags']['features']['matchmaking'] ?? null) === false, 'Frozen runtime must block matchmaking.');
    $assert(($merged['feature_flags']['features']['invitations'] ?? null) === false, 'Frozen runtime must block invitations.');
    $assert(($merged['feature_flags']['features']['payments'] ?? null) === true, 'Freeze must not change unrelated payment flag.');
    $assert(($merged['feature_flags']['cutover_rehearsal']['active'] ?? null) === true, 'Frozen runtime must expose rehearsal state.');

    $repeat = $service->freeze();
    $assert($repeat['idempotent'] === true, 'Repeated freeze must be idempotent.');
    $assert($repeat['queue_cleanup']['removed_queue_entries'] === 0, 'Repeated freeze must not remove nonexistent queue rows.');

    $storage->transaction(static function (array &$data): void {
        $data['games']['game_active']['status'] = 'finished';
        $data['users']['player_active']['status'] = 'idle';
        $data['users']['player_active']['current_game_id'] = null;
    });
    $drained = $service->status();
    $assert($drained['drain']['ready'] === true, 'Drain must become ready after active games reach zero.');
    $assert($drained['switch_rehearsal']['ready'] === false, 'Full DB switch must remain blocked without all DB modules.');
    $assert(in_array('economy', $drained['database_runtime']['missing_modules'], true), 'Missing economy DB runtime must be reported.');
    $assert(in_array('payments', $drained['database_runtime']['missing_modules'], true), 'Missing payments DB runtime must be reported.');

    $released = $service->release('test completed');
    $assert($released['freeze']['active'] === false, 'Release must disable freeze.');
    $assert(($released['freeze']['state'] ?? '') === 'released', 'Release state must be recorded.');

    $mergedAfterRelease = RuntimeConfigLoader::merge($config, $privateDir . '/config.php');
    $assert(($mergedAfterRelease['feature_flags']['features']['matchmaking'] ?? null) === true, 'Release must restore runtime matchmaking flag.');
    $assert(($mergedAfterRelease['feature_flags']['features']['invitations'] ?? null) === true, 'Release must restore runtime invitation flag.');
    $assert(!isset($mergedAfterRelease['feature_flags']['cutover_rehearsal']), 'Released control must not inject rehearsal flags.');

    $productionConfig = $config;
    $productionConfig['environment'] = 'production';
    $productionService = new FreezeDrainRehearsalService(
        $productionConfig,
        $storage,
        $privateDir . '/production-cutover-rehearsal.json'
    );
    $productionBlocked = false;
    try {
        $productionService->freeze();
    } catch (RuntimeException $error) {
        $productionBlocked = str_contains($error->getMessage(), 'staging or local');
    }
    $assert($productionBlocked, 'Production freeze must fail closed.');

    fwrite(STDOUT, "FreezeDrainRehearsalServiceTest: {$assertions} assertions passed\n");
} finally {
    $deleteTree($root);
}
