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
$fingerprintManifest = $read($root . '/helpers/staging-e2e-runtime-files.txt');
$versions = require $app . '/runtime/client/version-manifest.php';

ob_start();
require $app . '/v110.php';
$rendered = (string)ob_get_clean();

$assertSame('v2-route-scoped-polling', $versions['version'] ?? null, 'Client query versions must have one explicit manifest owner');
$assertSame(1, substr_count($rendered, '<script type="module" src="'), 'Telegram /start render must expose one top-level module script');
$assertTrue(str_contains($rendered, $versions['assets']['bootstrap']), 'Telegram /start render must expose the manifest-owned bootstrap-v2 target');
$assertTrue(str_contains($rendered, $versions['imports']['@mgw/clean-entry']), 'Rendered import map must resolve accepted clean-entry through the manifest');
$assertTrue(str_contains($rendered, $versions['imports']['@mgw/main']), 'Rendered import map must resolve accepted main-v110 through the manifest');
$assertTrue(str_contains($v110, 'X-MGW-Client-Bootstrap: v2-single-owner') && str_contains($v110, 'X-MGW-Query-Version-Manifest: v2-route-scoped-polling'), 'v110 must advertise bootstrap and current version-manifest ownership');
$assertTrue(!str_contains($v110, 'v=1147&mvp16=route-scoped-polling'), 'Resolved active target versions must not be duplicated inside v110.php');

$cleanPos = strpos($bootstrap, "await import('@mgw/clean-entry');");
$mainPos = strpos($bootstrap, "await import('@mgw/main');");
$assertTrue($cleanPos !== false && $mainPos !== false && $cleanPos < $mainPos, 'Bootstrap must preserve accepted clean-entry then main-v110 initialization order');
$assertTrue(str_contains($bootstrap, 'window.__MGW_APP_BOOTSTRAP_V2__'), 'Bootstrap must expose one idempotent runtime marker');
$assertTrue(!str_contains($bootstrap, '?v=') && !str_contains($bootstrap, '&mvp'), 'Bootstrap must not become a second query-version owner');
$assertTrue(!str_contains($bootstrap, 'setInterval') && !str_contains($bootstrap, 'setTimeout'), 'Bootstrap owner must not create a second polling/timer layer');

$assertTrue(str_contains($state, "screen: 'home'"), 'Canonical app state must own current screen');
$assertTrue(str_contains($router, 'const ROUTES = Object.freeze({'), 'Router v2 must own an explicit route registry');
$assertTrue(str_contains($router, 'export function routeRegistry()') && str_contains($router, 'export function isKnownRoute(name)'), 'Route registry must expose read/validation APIs');
$assertTrue(str_contains($router, 'export function currentScreen()'), 'Router v2 must expose current-screen ownership');
$assertTrue(str_contains($router, 'export function showScreen(name)'), 'Router v2 must preserve backward-compatible showScreen API');
$assertTrue(str_contains($router, 'export function registerScreenCleanup(name, cleanup)'), 'Router v2 must expose centralized screen cleanup registration');
$assertTrue(str_contains($router, 'runScreenCleanups(previous, next)'), 'Router must execute registered cleanup before leaving a screen');
$assertTrue(str_contains($router, "new CustomEvent('mgw:screen-changed'"), 'Router v2 must publish one screen transition lifecycle event');
$assertTrue(str_contains($router, 'export function onScreenEnter') && str_contains($router, 'export function onScreenLeave'), 'Router v2 must preserve scoped enter/leave subscriptions');
$assertTrue(!str_contains($router, 'setInterval') && !str_contains($router, 'setTimeout'), 'Router v2 must not create timer ownership');

$assertSame('./assets/js/state.js?v=30&mvp16=router-lifecycle', $versions['imports']['./assets/js/state.js?v=27'] ?? null, 'Manifest must resolve legacy state imports to one state successor');
$assertSame('./assets/js/router.js?v=28&mvp16=lifecycle', $versions['imports']['./assets/js/router.js?v=27'] ?? null, 'Manifest must resolve legacy router imports to one router successor');
$assertTrue(str_contains($fingerprintManifest, 'app/runtime/client/version-manifest.php'), 'Exact runtime fingerprint must cover query-version manifest owner');
$assertTrue(str_contains($fingerprintManifest, 'app/assets/js/app-bootstrap-v2.js') && str_contains($fingerprintManifest, 'app/assets/js/router.js'), 'Exact runtime fingerprint must cover bootstrap and router owners');

fwrite(STDOUT, "Mvp161ClientBootstrapRouterTest passed: {$assertions} assertions.\n");
