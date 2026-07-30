<?php
declare(strict_types=1);

use Mgw\CleanRuntime\Server\RuntimeBootstrapService;
use Mgw\CleanRuntime\Server\RuntimeConfig;
use Mgw\CleanRuntime\Server\RuntimeKernel;
use Mgw\CleanRuntime\Server\Storage\JsonFileRuntimeRepository;

$root = dirname(__DIR__, 2);
require_once $root . '/app/runtime/server/contracts/RuntimeRepository.php';
require_once $root . '/app/runtime/server/RuntimeConfig.php';
require_once $root . '/app/runtime/server/storage/JsonFileRuntimeRepository.php';
require_once $root . '/app/runtime/server/RuntimeBootstrapService.php';
require_once $root . '/app/runtime/server/RuntimeKernel.php';

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
    $config = new RuntimeConfig('staging', $temporary, 'test-clean-server');
    $repository = new JsonFileRuntimeRepository($config->dataDirectory);
    $service = new RuntimeBootstrapService($config, $repository);
    $kernel = new RuntimeKernel($service);

    $initialHealth = $repository->health();
    $assert($initialHealth['adapter'] === 'json_file_staging', 'The clean server must expose exactly the staging JSON adapter.');
    $assert($initialHealth['state_present'] === false, 'The staging repository must start without a committed state file.');
    $assert($initialHealth['writable'] === true, 'The isolated staging directory must be writable.');

    $installationId = 'install_123456789012345678901234';
    $payload = [
        'installation_id' => $installationId,
        'launch' => [
            'runtime' => 'mgw-clean-v1',
            'path' => '/app/runtime/index.php',
            'source' => 'invite',
            'invite_present' => true,
            'telegram_available' => true,
        ],
    ];

    $first = $service->bootstrap($payload);
    $assert($first['ok'] === true, 'The first clean bootstrap must succeed.');
    $assert($first['server']['environment'] === 'staging', 'The clean server must be staging-only.');
    $assert($first['storage']['adapter'] === 'json_file_staging', 'Bootstrap must report the one active adapter.');
    $assert($first['storage']['revision'] === 1, 'The first atomic write must publish revision one.');
    $assert($first['installation']['launch_count'] === 1, 'The first installation launch count must be one.');

    $second = $service->bootstrap($payload);
    $assert($second['storage']['revision'] === 2, 'The second atomic write must advance one revision.');
    $assert($second['installation']['launch_count'] === 2, 'The installation projection must be updated by one owner.');
    $assert($second['installation']['first_seen_at'] === $first['installation']['first_seen_at'], 'The first seen timestamp must remain stable.');

    $stateFile = $temporary . '/runtime-state.json';
    $stored = file_get_contents($stateFile);
    $assert(is_string($stored) && $stored !== '', 'The staging adapter must publish a state file.');
    $decoded = json_decode((string)$stored, true, 512, JSON_THROW_ON_ERROR);
    $assert(($decoded['schema_version'] ?? null) === 1, 'The clean staging schema must be explicit.');
    $assert(($decoded['revision'] ?? null) === 2, 'The committed staging revision must match the bootstrap projection.');
    $assert(($decoded['installations'][$installationId]['last_launch']['invite_present'] ?? null) === true, 'Only invite presence may be stored.');
    $assert(!str_contains((string)$stored, 'invite_token'), 'The clean staging repository must not persist invite tokens.');

    $healthResponse = $kernel->handle('GET', 'health', []);
    $assert($healthResponse['status'] === 200 && $healthResponse['body']['ok'] === true, 'The clean health action must be available.');
    $assert($healthResponse['body']['storage']['state_present'] === true, 'Health must observe the isolated state after bootstrap.');

    $bootstrapResponse = $kernel->handle('POST', 'bootstrap', $payload);
    $assert($bootstrapResponse['status'] === 200, 'The clean kernel must route POST bootstrap.');
    $assert($bootstrapResponse['body']['installation']['launch_count'] === 3, 'Kernel bootstrap must use the same repository owner.');

    $unknown = $kernel->handle('POST', 'legacy_action', []);
    $assert($unknown['status'] === 404 && $unknown['body']['ok'] === false, 'Unknown or legacy actions must fail closed.');

    $api = file_get_contents($root . '/app/runtime/api.php');
    $serverFiles = '';
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/app/runtime/server'));
    foreach ($iterator as $file) {
        if (!$file->isFile() || strtolower($file->getExtension()) !== 'php') continue;
        $content = file_get_contents($file->getPathname());
        if (is_string($content)) $serverFiles .= "\n" . $content;
    }
    $combined = (string)$api . $serverFiles;
    foreach (['bot/core/bootstrap.php', 'StorageFactory', 'RuntimeStorageRouter', 'DatabaseConfigLoader', 'PdoDatabaseConnection', 'RuntimePrimaryEntrypointBridgeGuard'] as $forbidden) {
        $assert(!str_contains($combined, $forbidden), 'Clean server must not load legacy or bridge dependency: ' . $forbidden);
    }
    $assert(substr_count($combined, 'implements RuntimeRepository') === 1, 'Exactly one clean runtime repository adapter must be implemented.');
    $assert(str_contains($serverFiles, 'flock($lock, LOCK_EX)') && str_contains($serverFiles, 'rename($temporary, $this->stateFile)'), 'The staging adapter must use locking and atomic publication.');
} finally {
    $cleanup($temporary);
}

fwrite(STDOUT, "Mvp14R3CleanServerBootstrapTest: {$assertions} assertions passed\n");
