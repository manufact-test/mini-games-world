<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$path = $root . '/app/assets/js/production-v110-presence.js';
$source = file_get_contents($path);
if (!is_string($source)) {
    throw new RuntimeException('Cannot read production-v110-presence.js');
}

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

$assert(
    str_contains($source, 'function presenceTransportBusy(){')
        && str_contains($source, 'return runtime.pingBusy || runtime.statusBusy;'),
    'One document lease must expose one shared presence transport-busy owner.'
);
$assert(
    str_contains($source, "async function pingPresence(){\n  if (presenceTransportBusy() || document.visibilityState !== 'visible') return false;"),
    'Ping must not start while status already owns the same presence transport.'
);
$assert(
    str_contains($source, "async function refreshStatus(){\n  if (presenceTransportBusy() || !runtime.appReady || !canReadHomeStatus()) return false;"),
    'Status must not start while ping already owns the same presence transport.'
);
$assert(
    substr_count($source, "requestPresence('ping'") === 1
        && substr_count($source, "requestPresence('status'") === 1,
    'Ping and status must retain exactly one request path each.'
);
$assert(
    str_contains($source, 'runtime.pingController?.abort();')
        && str_contains($source, 'runtime.statusController?.abort();')
        && str_contains($source, 'runtime.pingBusy = false;')
        && str_contains($source, 'runtime.statusBusy = false;'),
    'Existing explicit lifecycle cancellation must remain the only forced handoff owner.'
);
$assert(
    str_contains($source, "const HEARTBEAT_MS = 4000;")
        && str_contains($source, "const STATUS_MS = 1200;")
        && str_contains($source, "const REQUEST_TIMEOUT_MS = 4500;"),
    'The root fix must not alter accepted presence cadence or request timeout policy.'
);
$assert(
    !str_contains($source, 'setTimeout(() => refreshStatus')
        && !str_contains($source, 'setTimeout(refreshStatus')
        && !str_contains($source, 'Promise.race(['),
    'The single-transport fix must not add status retries or racing transports.'
);

fwrite(STDOUT, "ProductionV110PresenceSingleTransportContractTest: {$assertions} assertions passed\n");
