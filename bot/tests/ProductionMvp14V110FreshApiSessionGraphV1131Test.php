<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$read = static function (string $path) use ($root): string {
    $content = file_get_contents($root . '/' . $path);
    if (!is_string($content)) {
        throw new RuntimeException('Cannot read fresh API/session graph source: ' . $path);
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
$client = $read('app/assets/js/api/client.js');
$session = $read('app/assets/js/session.js');

$assert(str_contains($entry, '<script type="importmap">')
    && str_contains($entry, '"./assets/js/api/client.js?v=47": "./assets/js/api/client.js?v=1131"')
    && str_contains($entry, '"./assets/js/session.js?v=21": "./assets/js/session.js?v=1131"')
    && str_contains($entry, '"./assets/js/session.js?v=27": "./assets/js/session.js?v=1131"'),
    'The ordinary v110 route must centrally remap every retained API/session legacy specifier to v1131.');

$assert(str_contains($entry, './assets/js/main-v110.js?v=1131')
    && str_contains($entry, 'v110-mvp14r12-fresh-api-session-graph-v1131')
    && str_contains($entry, 'X-MGW-Frontend-Build: v110-mvp14r12-fresh-api-session-graph-v1131'),
    'The ordinary v110 publication must expose one immutable v1131 build identity.');

$assert(str_contains($client, "import { getSessionId, getDeviceId } from '../session.js?v=1131';")
    && !str_contains($client, "from '../session.js?v=21'")
    && !str_contains($client, "from '../session.js?v=27'"),
    'The fresh API client must bind directly to the fresh session module.');

$assert(str_contains($client, 'sessionId:getSessionId()')
    && str_contains($client, 'deviceId:getDeviceId()'),
    'Every request through the canonical API client must carry both sessionId and deviceId.');

$assert(str_contains($session, 'export function getSessionId()')
    && str_contains($session, 'export function getDeviceId()')
    && str_contains($session, "const DEVICE_KEY = 'mgw_device_id';"),
    'The fresh session owner must export the complete API client contract.');

$assert(substr_count($entry, 'type="importmap"') === 1,
    'The ordinary v110 document must publish exactly one import-map owner.');

fwrite(STDOUT, 'ProductionMvp14V110FreshApiSessionGraphV1131Test: ' . $assertions . " assertions passed\n");
