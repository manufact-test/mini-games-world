<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$runtimeRoot = $root . '/app/runtime';
$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};
$read = static function (string $relative) use ($root): string {
    $content = file_get_contents($root . '/' . $relative);
    if (!is_string($content)) throw new RuntimeException('Cannot read clean runtime file: ' . $relative);
    return $content;
};

$required = [
    'app/runtime/index.php',
    'app/runtime/index.html',
    'app/runtime/api.php',
    'app/runtime/assets/css/app.css',
    'app/runtime/assets/js/entry.js',
    'app/runtime/assets/js/core/app.js',
    'app/runtime/assets/js/core/store.js',
    'app/runtime/assets/js/core/router.js',
    'app/runtime/assets/js/core/launch.js',
    'app/runtime/assets/js/core/error-boundary.js',
    'app/runtime/assets/js/core/api-client.js',
    'app/runtime/assets/js/core/installation.js',
    'app/runtime/server/contracts/RuntimeRepository.php',
    'app/runtime/server/RuntimeConfig.php',
    'app/runtime/server/storage/JsonFileRuntimeRepository.php',
    'app/runtime/server/RuntimeBootstrapService.php',
    'app/runtime/server/RuntimeKernel.php',
];
foreach ($required as $path) {
    $assert(is_file($root . '/' . $path), 'Missing clean runtime foundation file: ' . $path);
}

$jsFiles = [];
$phpFiles = [];
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($runtimeRoot));
foreach ($iterator as $file) {
    if (!$file->isFile()) continue;
    $extension = strtolower($file->getExtension());
    if ($extension === 'js') $jsFiles[] = $file->getPathname();
    if ($extension === 'php') $phpFiles[] = $file->getPathname();
}
sort($jsFiles);
sort($phpFiles);

$allJs = '';
foreach ($jsFiles as $path) {
    $content = file_get_contents($path);
    if (!is_string($content)) throw new RuntimeException('Cannot read runtime JS: ' . $path);
    $allJs .= "\n" . $content;
}

$allPhp = '';
foreach ($phpFiles as $path) {
    $content = file_get_contents($path);
    if (!is_string($content)) throw new RuntimeException('Cannot read runtime PHP: ' . $path);
    $allPhp .= "\n" . $content;
}

$forbiddenJs = [
    'production-v',
    'main-v',
    'MutationObserver',
    'window.fetch =',
    '/app/?v=',
    '/app/v110.php',
    'stopImmediatePropagation',
    '__MGW_V',
    '/bot/',
];
foreach ($forbiddenJs as $needle) {
    $assert(!str_contains($allJs, $needle), 'Clean runtime must not contain legacy owner pattern: ' . $needle);
}

$forbiddenPhp = [
    'bot/core/bootstrap.php',
    'StorageFactory',
    'RuntimeStorageRouter',
    'DatabaseConfigLoader',
    'PdoDatabaseConnection',
    'RuntimePrimaryEntrypointBridgeGuard',
    'synchronizeCurrentJson',
];
foreach ($forbiddenPhp as $needle) {
    $assert(!str_contains($allPhp, $needle), 'Clean server must not load legacy storage or bridge pattern: ' . $needle);
}

$indexPhp = $read('app/runtime/index.php');
$indexHtml = $read('app/runtime/index.html');
$apiPhp = $read('app/runtime/api.php');
$entry = $read('app/runtime/assets/js/entry.js');
$app = $read('app/runtime/assets/js/core/app.js');
$store = $read('app/runtime/assets/js/core/store.js');
$launch = $read('app/runtime/assets/js/core/launch.js');
$apiClient = $read('app/runtime/assets/js/core/api-client.js');
$repository = $read('app/runtime/server/storage/JsonFileRuntimeRepository.php');
$config = $read('app/runtime/server/RuntimeConfig.php');

$assert(substr_count($indexHtml, '<script type="module"') === 1, 'Clean runtime must expose exactly one module entry.');
$assert(str_contains($indexHtml, './assets/js/entry.js?v=2'), 'Clean runtime document must load only its own current entry module.');
$assert(substr_count($entry, 'import ') === 1 && str_contains($entry, "./core/app.js"), 'Clean runtime entry must delegate to one clean app bootstrap.');
$assert(!str_contains($indexPhp, '../index.html') && !str_contains($indexPhp, 'v110'), 'Clean runtime PHP entry must not rewrite or reuse the legacy document.');
$assert(str_contains($apiClient, "../../../api.php") && !str_contains($apiClient, '/bot/'), 'The clean client must call only its own API endpoint.');
$assert(str_contains($app, "api.bootstrap") && str_contains($app, "mgw:clean-runtime-ready"), 'Clean app readiness must follow its own server bootstrap.');
$assert(str_contains($store, "server:null") && str_contains($store, "storage:null") && str_contains($store, "installation:null"), 'Clean store must own explicit server state slices.');
$assert(str_contains($store, "activeMatch:null") && str_contains($store, "matchResult:null") && str_contains($store, "notifications:[]"), 'Clean store must retain explicit product state slices.');
$assert(str_contains($launch, "runtime:'mgw-clean-v1'") && str_contains($launch, "inviteToken"), 'Canonical launch parser must own standard and invite launch context.');
$assert(str_contains($apiPhp, "RuntimeConfig::fromEnvironment()") && str_contains($apiPhp, "new JsonFileRuntimeRepository"), 'Clean endpoint must construct one explicit staging adapter.');
$assert(substr_count($allPhp, 'implements RuntimeRepository') === 1, 'The clean server must contain exactly one repository adapter implementation.');
$assert(str_contains($config, "environment: 'staging'") && str_contains($config, "MGW_CLEAN_RUNTIME_DATA_DIR"), 'Clean server config must be staging-only and separately configurable.');
$assert(str_contains($repository, "flock($lock, LOCK_EX)") && str_contains($repository, "rename($temporary, $this->stateFile)"), 'Clean JSON adapter must lock and publish atomically.');
$assert(!str_contains($repository, 'invite_token'), 'Clean staging persistence must not store invite tokens.');

fwrite(STDOUT, "Mvp14R3CleanRuntimeArchitectureContractTest: {$assertions} assertions passed\n");
