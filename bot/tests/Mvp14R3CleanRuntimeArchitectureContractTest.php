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
    'app/runtime/assets/css/app.css',
    'app/runtime/assets/js/entry.js',
    'app/runtime/assets/js/core/app.js',
    'app/runtime/assets/js/core/store.js',
    'app/runtime/assets/js/core/router.js',
    'app/runtime/assets/js/core/launch.js',
    'app/runtime/assets/js/core/error-boundary.js',
];
foreach ($required as $path) {
    $assert(is_file($root . '/' . $path), 'Missing clean runtime foundation file: ' . $path);
}

$jsFiles = [];
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($runtimeRoot));
foreach ($iterator as $file) {
    if (!$file->isFile() || strtolower($file->getExtension()) !== 'js') continue;
    $jsFiles[] = $file->getPathname();
}
sort($jsFiles);
$allJs = '';
foreach ($jsFiles as $path) {
    $content = file_get_contents($path);
    if (!is_string($content)) throw new RuntimeException('Cannot read runtime JS: ' . $path);
    $allJs .= "\n" . $content;
}

$forbidden = [
    'production-v',
    'main-v',
    'MutationObserver',
    'window.fetch =',
    '/app/?v=',
    '/app/v110.php',
    'stopImmediatePropagation',
    '__MGW_V',
];
foreach ($forbidden as $needle) {
    $assert(!str_contains($allJs, $needle), 'Clean runtime must not contain legacy owner pattern: ' . $needle);
}

$indexPhp = $read('app/runtime/index.php');
$indexHtml = $read('app/runtime/index.html');
$entry = $read('app/runtime/assets/js/entry.js');
$app = $read('app/runtime/assets/js/core/app.js');
$store = $read('app/runtime/assets/js/core/store.js');
$launch = $read('app/runtime/assets/js/core/launch.js');

$assert(substr_count($indexHtml, '<script type="module"') === 1, 'Clean runtime must expose exactly one module entry.');
$assert(str_contains($indexHtml, './assets/js/entry.js?v=1'), 'Clean runtime document must load only its own entry module.');
$assert(substr_count($entry, 'import ') === 1 && str_contains($entry, "./core/app.js"), 'Clean runtime entry must delegate to one clean app bootstrap.');
$assert(!str_contains($indexPhp, '../index.html') && !str_contains($indexPhp, 'v110'), 'Clean runtime PHP entry must not rewrite or reuse the legacy document.');
$assert(!str_contains($app, '/bot/api.php') && !str_contains($allJs, '/bot/'), 'The first clean core package must not silently connect to the legacy server graph.');
$assert(str_contains($store, "activeMatch:null") && str_contains($store, "matchResult:null") && str_contains($store, "notifications:[]"), 'Clean store must own explicit product state slices.');
$assert(str_contains($launch, "runtime:'mgw-clean-v1'") && str_contains($launch, "inviteToken"), 'Canonical launch parser must own standard and invite launch context.');
$assert(str_contains($app, "mgw:clean-runtime-ready"), 'Clean core must publish one explicit readiness event.');

fwrite(STDOUT, "Mvp14R3CleanRuntimeArchitectureContractTest: {$assertions} assertions passed\n");
