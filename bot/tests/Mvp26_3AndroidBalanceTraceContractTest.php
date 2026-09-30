<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$endpoint = file_get_contents($root . '/bot/staging-android-balance-trace.php');
$client = file_get_contents($root . '/app/assets/js/diagnostics/android-balance-trace-v1.js');
$shell = file_get_contents($root . '/app/assets/js/main-v110-handoff-shell.js');
$manifest = file_get_contents($root . '/app/runtime/client/version-manifest.php');
if (!is_string($endpoint) || !is_string($client) || !is_string($shell) || !is_string($manifest)) {
    throw new RuntimeException('Diagnostic sources unavailable.');
}

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

$assert(str_contains($endpoint, "environment !== 'staging'"), 'Endpoint must be staging-only.');
$assert(str_contains($endpoint, 'AndroidDeviceAuthService::COOKIE_NAME'), 'Writes must require an Android HttpOnly session cookie.');
$assert(str_contains($endpoint, "'zero'=>true") && str_contains($endpoint, "'nonzero'=>true"), 'Only balance state classes must be accepted.');
$assert(!str_contains($endpoint, "'balance'=>") && !str_contains($endpoint, "'mgw_id'=>"), 'Endpoint must not persist exact balance or identity.');
$assert(str_contains($client, "balanceState(value)") && str_contains($client, "'zero'") && str_contains($client, "'nonzero'"), 'Client must reduce balances to state classes.');
$assert(str_contains($shell, "api.bootstrap.response"), 'Bootstrap response must be traced.');
$assert(str_contains($shell, "api.profile.response"), 'Profile response must be traced.');
$assert(str_contains($shell, "api.store.response"), 'Store response must be traced.');
$assert(str_contains($shell, "boot.balance.selected"), 'Selected first-paint balance must be traced.');
$assert(str_contains($client, "dom.balance.change"), 'Visible DOM balance changes must be traced.');
$assert(str_contains($manifest, 'main-v110-handoff-shell.js?v=1164') && str_contains($manifest, 'diag=android-balance-trace-v1'), 'Manifest must publish a fresh diagnostic shell cache identity.');

fwrite(STDOUT, "Mvp26_3AndroidBalanceTraceContractTest: {$assertions} assertions passed\n");
