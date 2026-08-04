<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$endpoint = file_get_contents($root . '/bot/invite-opponents.php');
$entry = file_get_contents($root . '/app/v114.php');
$confirm = file_get_contents($root . '/app/assets/js/opponents-authoritative-confirm-v122.js');
if (!is_string($endpoint) || !is_string($entry) || !is_string($confirm)) {
    throw new RuntimeException('Missing D1 opponent authoritative v122 sources.');
}

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

$assert(str_contains($endpoint, 'function mgw_invite_opponents_storage(array $config): StorageAdapterInterface'),
    'The endpoint must centralize authoritative storage selection.');
$assert(str_contains($endpoint, "if ($environment === 'staging')"),
    'Staging must have an explicit authoritative source branch.');
$assert(str_contains($endpoint, 'new DatabasePrimaryStateStorageAdapter(')
        && str_contains($endpoint, 'PdoConnectionFactory::create($databaseConfig)'),
    'Staging opponent reads must use the same DB-primary state as API mutations.');
$assert(str_contains($endpoint, '$storage->readOnly(')
        && !str_contains($endpoint, '$storage->transaction('),
    'The player picker endpoint must remain read-only.');
$assert(str_contains($endpoint, "'authoritative' => true")
        && str_contains($endpoint, "'storage_driver' => $storage->driver()"),
    'The response must identify a confirmed canonical storage sample.');
$assert(str_contains($endpoint, "return StorageFactory::createJson("),
    'Production and rollback environments must retain the existing guarded storage path.');

$assert(str_contains($entry, 'opponents-authoritative-confirm-v122.js?v=122'),
    'The no-cache shell must publish the v122 authoritative confirmation layer.');
$assert(!str_contains($entry, 'opponents-authoritative-confirm-v117.js?v=117'),
    'The superseded v117 confirmation layer must not remain active.');
$assert(str_contains($entry, 'v122-mvp14-opponents-authoritative-source'),
    'The live shell must expose the v122 build identity.');

$assert(str_contains($confirm, 'REQUIRED_AUTHORITATIVE_EMPTY_RESPONSES = 2'),
    'A single empty snapshot must never become the final visible state.');
$assert(str_contains($confirm, "payload?.authoritative === true")
        && str_contains($confirm, "payload?.storage_driver === 'database'"),
    'Only a DB-primary marked response may confirm a final empty list.');
$assert(substr_count($confirm, 'RETRY_DELAYS_MS = [80, 140, 240, 400, 650, 950]') === 1,
    'The bounded confirmation window must cover six transient empty samples.');
$assert(str_contains($confirm, "throw new Error('Authoritative opponent list was not confirmed.')"),
    'Unconfirmed transport empties must fail as loading/error, never as no players.');
$assert(str_contains($confirm, "cache:'no-store'")
        && str_contains($confirm, "headers.set('Cache-Control', 'no-cache')"),
    'Confirmation samples must bypass browser and intermediary caches.');

fwrite(STDOUT, "ProductionMvp14D1OpponentAuthoritativeSourceV122Test: {$assertions} assertions passed\n");
