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
    'app/runtime/assets/js/core/session.js',
    'app/runtime/assets/js/core/client-context.js',
    'app/runtime/assets/js/core/server-projection.js',
    'app/runtime/assets/js/core/presence-owner.js',
    'app/runtime/assets/js/core/match-owner.js',
    'app/runtime/server/contracts/RuntimeStateStore.php',
    'app/runtime/server/RuntimeConfig.php',
    'app/runtime/server/auth/AuthenticationException.php',
    'app/runtime/server/auth/AuthenticatedIdentity.php',
    'app/runtime/server/auth/TelegramInitDataVerifier.php',
    'app/runtime/server/auth/RuntimeAuthenticationService.php',
    'app/runtime/server/context/RuntimeRequestContext.php',
    'app/runtime/server/context/RuntimeRequestContextFactory.php',
    'app/runtime/server/storage/JsonFileRuntimeStore.php',
    'app/runtime/server/session/RuntimeSessionService.php',
    'app/runtime/server/match/TicTacToeRules.php',
    'app/runtime/server/match/RuntimeMatchService.php',
    'app/runtime/server/RuntimeApplicationService.php',
    'app/runtime/server/RuntimeKernel.php',
];
foreach ($required as $path) {
    $assert(is_file($root . '/' . $path), 'Missing clean runtime file: ' . $path);
}
foreach ([
    'app/runtime/server/contracts/RuntimeRepository.php',
    'app/runtime/server/storage/JsonFileRuntimeRepository.php',
    'app/runtime/server/RuntimeBootstrapService.php',
] as $removed) {
    $assert(!is_file($root . '/' . $removed), 'Replaced v2 owner must be removed: ' . $removed);
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

foreach ([
    'production-v',
    'main-v',
    'MutationObserver',
    'window.fetch =',
    '/app/?v=',
    '/app/v110.php',
    'stopImmediatePropagation',
    '__MGW_V',
    '/bot/',
    'mgw_dev_user_id',
] as $needle) {
    $assert(!str_contains($allJs, $needle), 'Clean runtime must not contain legacy client owner pattern: ' . $needle);
}
foreach ([
    'bot/core/bootstrap.php',
    'StorageFactory',
    'RuntimeStorageRouter',
    'DatabaseConfigLoader',
    'PdoDatabaseConnection',
    'RuntimePrimaryEntrypointBridgeGuard',
    'synchronizeCurrentJson',
    'services/AuthService.php',
    'services/SessionService.php',
    'services/GameService.php',
    'ChessRuntimeService',
    'RuntimeAccountIdentityResolver',
    'mgw_dev_user_id',
    'JsonFileRuntimeRepository',
    'RuntimeRepository',
    'RuntimeBootstrapService',
] as $needle) {
    $assert(!str_contains($allPhp, $needle), 'Clean server must not contain replaced or legacy owner pattern: ' . $needle);
}

$indexPhp = $read('app/runtime/index.php');
$indexHtml = $read('app/runtime/index.html');
$apiPhp = $read('app/runtime/api.php');
$entry = $read('app/runtime/assets/js/entry.js');
$app = $read('app/runtime/assets/js/core/app.js');
$store = $read('app/runtime/assets/js/core/store.js');
$router = $read('app/runtime/assets/js/core/router.js');
$launch = $read('app/runtime/assets/js/core/launch.js');
$apiClient = $read('app/runtime/assets/js/core/api-client.js');
$serverProjection = $read('app/runtime/assets/js/core/server-projection.js');
$presenceOwner = $read('app/runtime/assets/js/core/presence-owner.js');
$matchOwner = $read('app/runtime/assets/js/core/match-owner.js');
$stateStore = $read('app/runtime/server/storage/JsonFileRuntimeStore.php');
$config = $read('app/runtime/server/RuntimeConfig.php');
$auth = $read('app/runtime/server/auth/RuntimeAuthenticationService.php');
$verifier = $read('app/runtime/server/auth/TelegramInitDataVerifier.php');
$kernel = $read('app/runtime/server/RuntimeKernel.php');
$matchService = $read('app/runtime/server/match/RuntimeMatchService.php');

$assert(substr_count($indexHtml, '<script type="module"') === 1, 'Clean runtime must expose exactly one module entry.');
$assert(str_contains($indexHtml, './assets/js/entry.js?v=4'), 'Clean runtime document must load the current clean entry module.');
$assert(substr_count($entry, 'import ') === 1 && str_contains($entry, './core/app.js'), 'Clean entry must delegate to one application bootstrap.');
$assert(!str_contains($indexPhp, '../index.html') && !str_contains($indexPhp, 'v110'), 'Clean PHP entry must not reuse the legacy document.');
$assert(str_contains($apiClient, '../../../api.php') && !str_contains($apiClient, '/bot/'), 'Clean client must call only its own endpoint.');
$assert(str_contains($apiClient, "post('match_start_search'") && str_contains($apiClient, "post('match_surrender'"), 'Clean API client must expose its own match commands.');
$assert(substr_count($app, 'createPresenceOwner(') === 1, 'Clean app must create exactly one presence owner.');
$assert(substr_count($app, 'createMatchOwner(') === 1, 'Clean app must create exactly one match owner.');
$assert(substr_count($presenceOwner, 'export function createPresenceOwner') === 1, 'Exactly one clean presence owner implementation is allowed.');
$assert(substr_count($matchOwner, 'export function createMatchOwner') === 1, 'Exactly one clean match owner implementation is allowed.');
$assert(str_contains($matchOwner, "root.addEventListener('click'") && !str_contains($matchOwner, 'capture:true'), 'Match actions must be owned by one normal root listener.');
$assert(substr_count($matchOwner, 'setInterval') === 1, 'The one match owner must own exactly one bounded poll cadence.');
$assert(str_contains($presenceOwner, "import { applyServerProjection }") && str_contains($matchOwner, "import { applyServerProjection }"), 'Presence and match responses must share one monotonic projection guard.');
$assert(str_contains($serverProjection, 'nextRevision < currentRevision') && str_contains($serverProjection, 'return false'), 'Older server revisions must never overwrite newer client state.');
$assert(str_contains($router, "'search'") && str_contains($router, "'match'") && str_contains($router, "'result'"), 'The clean router must own match lifecycle screens.');
$assert(str_contains($store, 'matchmaking:null') && str_contains($store, 'activeMatch:null') && str_contains($store, 'matchResult:null'), 'Clean store must own explicit match state slices.');
$assert(str_contains($launch, "runtime:'mgw-clean-v1'") && str_contains($launch, 'inviteToken'), 'Canonical launch parser must remain the one launch owner.');
$assert(str_contains($apiPhp, 'new JsonFileRuntimeStore') && substr_count($allPhp, 'implements RuntimeStateStore') === 1, 'Clean endpoint must construct exactly one state store adapter.');
$assert(str_contains($apiPhp, 'new RuntimeSessionService') && str_contains($apiPhp, 'new RuntimeMatchService'), 'Clean endpoint must construct one session and one match domain service.');
$assert(str_contains($kernel, "'match_start_search'") && str_contains($kernel, "'match_surrender'"), 'Clean kernel must own the complete match route set.');
$assert(str_contains($config, "build: 'mgw-clean-server-v3'") && str_contains($config, 'MGW_CLEAN_RUNTIME_DATA_DIR'), 'Clean v3 server must remain staging-only and separately configured.');
$assert(str_contains($auth, 'browser_staging') && strpos($auth, "if (\$initData !== '')") < strpos($auth, 'browser_staging'), 'Telegram verification must precede browser staging identity.');
$assert(str_contains($verifier, 'hash_equals') && str_contains($verifier, 'auth_date') && str_contains($verifier, 'parseUniqueQuery'), 'Clean Telegram verifier must check signature, age and duplicate fields.');
$assert(str_contains($stateStore, 'runtime-state-v3.json') && str_contains($stateStore, 'SCHEMA_VERSION = 3'), 'Match lifecycle must publish a fresh v3 staging state file.');
$assert(str_contains($stateStore, 'flock($lock, LOCK_EX)') && str_contains($stateStore, 'rename($temporary, $this->stateFile)'), 'Clean state store must lock and publish atomically.');
$assert(str_contains($matchService, 'payout_done') && str_contains($matchService, 'commandSeen'), 'Clean match lifecycle must own atomic settlement and idempotency.');
$assert(str_contains($matchService, "'status' => 'finished'") && str_contains($matchService, "'current_game_id'] = null"), 'Clean match finish must release player state in the same transaction.');
$assert(!str_contains($stateStore, 'init_data') && !str_contains($stateStore, 'invite_token'), 'Clean staging persistence must store neither Telegram initData nor invite tokens.');

fwrite(STDOUT, "Mvp14R3CleanRuntimeArchitectureContractTest: {$assertions} assertions passed\n");
