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
$assert(str_contains($endpoint, "if (\$environment === 'staging')"), 'Staging must have an explicit authoritative source branch.');
$assert(str_contains($endpoint, 'new DatabasePrimaryStateStorageAdapter(')
        && str_contains($endpoint, 'PdoConnectionFactory::create($databaseConfig)'),
    'Staging opponent reads must use DB-primary state.');
$assert(str_contains($endpoint, '$storage->readOnly(') && !str_contains($endpoint, '$storage->transaction('),
    'The player picker endpoint must remain read-only.');
$assert(str_contains($endpoint, "'authoritative' => true")
        && str_contains($endpoint, "'storage_driver' => \$storage->driver()"),
    'The response must identify a canonical storage sample.');
$assert(str_contains($endpoint, 'return StorageFactory::createJson('),
    'Production and rollback contexts must retain the guarded storage route.');
$assert(str_contains($entry, 'opponents-authoritative-confirm-v122.js?v=122')
        && !str_contains($entry, 'opponents-authoritative-confirm-v117.js?v=117'),
    'v123 must publish only v122 opponent confirmation.');
$assert(str_contains($entry, 'v123-mvp14-d1-two-manual-regressions'), 'The integrated shell must expose v123.');
$assert(str_contains($confirm, 'REQUIRED_AUTHORITATIVE_EMPTY_RESPONSES = 2'),
    'A single empty snapshot must never become final.');
$assert(str_contains($confirm, 'payload?.authoritative === true')
        && str_contains($confirm, "payload?.storage_driver === 'database'"),
    'Only a DB-primary marked response may confirm empty.');
$assert(str_contains($confirm, 'RETRY_DELAYS_MS = [80, 140, 240, 400, 650, 950]'),
    'Six transient empty samples must remain loading.');
$assert(str_contains($confirm, "throw new Error('Authoritative opponent list was not confirmed.')"),
    'Unconfirmed transport empties must not render no players.');
$assert(str_contains($confirm, "cache:'no-store'")
        && str_contains($confirm, "headers.set('Cache-Control', 'no-cache')"),
    'Confirmation samples must bypass caches.');

fwrite(STDOUT, "ProductionMvp14D1OpponentAuthoritativeSourceV122Test: {$assertions} assertions passed\n");
