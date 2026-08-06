<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$read = static function (string $path) use ($root): string {
    $content = file_get_contents($root . '/' . $path);
    if (!is_string($content)) {
        throw new RuntimeException('Cannot read API/session publication source: ' . $path);
    }
    return $content;
};

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$entry = $read('app/v110.php');
$index = $read('app/index.html');
$client = $read('app/assets/js/api/client.js');
$session = $read('app/assets/js/session.js');
$store = $read('app/assets/js/screens/store-screen.js');
$weekly = $read('app/assets/js/screens/weekly-match-info.js');
$lifecycle = $read('app/assets/js/production-v110-match-lifecycle.js');

$assert(str_contains($entry, '<script type="importmap">'),
    'Ordinary Start must publish a native import map before active module scripts.');
$assert(strpos($entry, '<script type="importmap">') < strpos($entry, 'production-clean-entry-v110.js?v=1121'),
    'The import map must be parsed before either ordinary Start module graph begins.');

foreach (['34', '38', '46', '47'] as $version) {
    $assert(str_contains(
        $entry,
        '"./assets/js/api/client.js?v=' . $version . '": "./assets/js/api/client.js?v=48"'
    ), 'Every active legacy API client identity must resolve to canonical v48.');
}
foreach (['21', '27'] as $version) {
    $assert(str_contains(
        $entry,
        '"./assets/js/session.js?v=' . $version . '": "./assets/js/session.js?v=28"'
    ), 'Every active legacy session identity must resolve to canonical v28.');
}

$assert(str_contains($client, "getSessionId, getDeviceId } from '../session.js?v=28'"),
    'Canonical API client v48 must import the fresh device-aware session module.');
$assert(str_contains($client, 'deviceId:getDeviceId()'),
    'Every canonical API request must include the stable device identifier.');
$assert(str_contains($session, 'export function getDeviceId()'),
    'Canonical session v28 must export getDeviceId.');

$assert(str_contains($store, "../api/client.js?v=34")
    && str_contains($weekly, "../api/client.js?v=46")
    && str_contains($lifecycle, "./api/client.js?v=47"),
    'Historical consumers must remain unchanged and be reconciled only at the ordinary Start entry graph.');
$assert(!str_contains($index, 'api/client.js?v=48') && !str_contains($index, 'session.js?v=28'),
    'The technical /app/ graph must not be silently rewritten by the ordinary Start fix.');
$assert(!str_contains($entry, 'window.fetch =') && !str_contains($entry, 'MutationObserver'),
    'The publication fix must not add a request wrapper or visual patch layer.');

fwrite(STDOUT, 'ProductionMvp14D1ApiSessionPublicationV48Test: ' . $assertions . " assertions passed\n");
