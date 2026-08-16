<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$app = dirname($root) . '/app';
$assertions = 0;

$assertTrue = static function (bool $actual, string $message) use (&$assertions): void {
    $assertions++;
    if (!$actual) throw new RuntimeException($message);
};
$assertSame = static function (mixed $expected, mixed $actual, string $message) use (&$assertions): void {
    $assertions++;
    if ($expected !== $actual) {
        throw new RuntimeException($message . ': expected ' . var_export($expected, true)
            . ', got ' . var_export($actual, true));
    }
};
$read = static fn(string $path): string => file_get_contents($path) ?: throw new RuntimeException('Cannot read ' . $path);

$bootstrap = $read($app . '/assets/js/app-bootstrap-v2.js');
$router = $read($app . '/assets/js/router.js');
$state = $read($app . '/assets/js/state.js');
$v110 = $read($app . '/v110.php');
$manifest = $read($root . '/helpers/staging-e2e-runtime-files.txt');

ob_start();
require $app . '/v110.php';
$rendered = (string)ob_get_clean();

$assertSame(1, substr_count($rendered, '<script type="module" src="'), 'Telegram /start render must expose one top-level module script');
$assertTrue(str_contains($rendered, './assets/js/app-bootstrap-v2.js?v=1&mvp16=single-owner'), 'Telegram /start render must expose the bootstrap-v2 owner');
$assertTrue(!str_contains($rendered, './assets/js/production-clean-entry-v110.js?v=1124'), 'Clean-entry must not remain an independent top-level script');
$assertTrue(!str_contains($rendered, './assets/js/main-v110.js?v=1139'), 'Main-v110 must not remain an independent top-level script');
$assertTrue(str_contains($v110, "X-MGW-Client-Bootstrap: v2-single-owner") && str_contains($v110, "X-MGW-Router: v2-lifecycle"), 'v110 must advertise bootstrap/router successor ownership');

$cleanPos = strpos($bootstrap, "await import('./production-clean-entry-v110.js?v=1124");
$mainPos = strpos($bootstrap, "await import('./main-v110.js?v=1139");
$assertTrue($cleanPos !== false && $mainPos !== false && $cleanPos < $mainPos, 'Bootstrap must preserve accepted clean-entry then main-v110 initialization order');
$assertTrue(str_contains($bootstrap, 'window.__MGW_APP_BOOTSTRAP_V2__'), 'Bootstrap must expose one idempotent runtime marker');
$assertTrue(!str_contains($bootstrap, 'setInterval') && !str_contains($bootstrap, 'setTimeout'), 'Bootstrap owner must not create a second polling/timer layer');

$assertTrue(str_contains($state, "screen: 'home'"), 'Canonical app state must own current screen');
$assertTrue(str_contains($router, "import { state } from './state.js?v=27';"), 'Router v2 must project current screen into canonical state');
$assertTrue(str_contains($router, 'export function currentScreen()'), 'Router v2 must expose current-screen ownership');
$assertTrue(str_contains($router, 'export function showScreen(name)'), 'Router v2 must preserve backward-compatible showScreen API');
$assertTrue(str_contains($router, "new CustomEvent('mgw:screen-changed'"), 'Router v2 must publish one screen transition lifecycle event');
$assertTrue(str_contains($router, 'export function onScreenEnter') && str_contains($router, 'export function onScreenLeave'), 'Router v2 must expose scoped enter/leave subscriptions');
$assertTrue(!str_contains($router, 'setInterval') && !str_contains($router, 'setTimeout'), 'Router v2 must not create timer ownership');

$assertTrue(str_contains($v110, '"./assets/js/state.js?v=27": "./assets/js/state.js?v=30&mvp16=router-lifecycle"'), 'v110 import map must route all legacy state imports to the one state successor');
$assertTrue(str_contains($v110, '"./assets/js/router.js?v=27": "./assets/js/router.js?v=28&mvp16=lifecycle"'), 'v110 import map must route all legacy router imports to the one router successor');
$assertTrue(str_contains($manifest, 'app/assets/js/app-bootstrap-v2.js') && str_contains($manifest, 'app/assets/js/router.js'), 'Exact runtime fingerprint must cover bootstrap and router owners');

fwrite(STDOUT, "Mvp161ClientBootstrapRouterTest passed: {$assertions} assertions.\n");
