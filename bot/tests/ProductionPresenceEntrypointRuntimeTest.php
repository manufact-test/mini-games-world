<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
require_once $root . '/bot/runtime/ProductionPrimaryApplicationEntrypoints.php';

$presencePath = realpath($root . '/bot/presence.php');
if ($presencePath === false) throw new RuntimeException('Presence endpoint is missing.');

$resolved = ProductionPrimaryApplicationEntrypoints::resolve($root, [
    'SCRIPT_FILENAME' => $presencePath,
]);
if ($resolved !== 'presence') {
    throw new RuntimeException('Presence endpoint did not resolve to the production primary entrypoint.');
}
if (!ProductionPrimaryApplicationEntrypoints::supports('presence')) {
    throw new RuntimeException('Production primary bootstrap does not support the presence entrypoint.');
}
if ((ProductionPrimaryApplicationEntrypoints::pathMap()['bot/presence.php'] ?? '') !== 'presence') {
    throw new RuntimeException('Presence path mapping is not canonical.');
}

fwrite(STDOUT, "ProductionPresenceEntrypointRuntimeTest: 3 assertions passed\n");
