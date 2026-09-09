<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$endpoint = file_get_contents($root . '/bot/invite-opponents.php');
$entry = file_get_contents($root . '/app/v114.php');
$confirm = file_get_contents($root . '/app/assets/js/opponents-authoritative-confirm-v122.js');
if (!is_string($endpoint) || !is_string($entry) || !is_string($confirm)) {
    throw new RuntimeException('Missing D1 opponent authoritative v125 sources.');
}

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

$assert(str_contains($endpoint, "StorageFactory::createJson((string)(\$config['data_dir']"),
    'The player picker must use the canonical JSON profile directory.');
$assert(!str_contains($endpoint, 'DatabasePrimaryStateStorageAdapter')
        && !str_contains($endpoint, 'PdoConnectionFactory::create('),
    'The incomplete DB projection must not become the player profile catalog.');
$assert(str_contains($endpoint, '$storage->readOnly(')
        && !str_contains($endpoint, '$storage->transaction('),
    'The player picker endpoint must remain read-only.');
$assert(str_contains($endpoint, '$onlineOpponentIds')
        && str_contains($endpoint, '$unresolvedOnlineCount')
        && str_contains($endpoint, "'unresolved_online_count' =>"),
    'Live presence completeness must be reconciled against the JSON profile directory.');
$assert(str_contains($endpoint, "'authoritative' => \$complete")
        && str_contains($endpoint, "'complete' => \$complete")
        && str_contains($endpoint, "'storage_driver' => \$storage->driver()"),
    'The response must mark only a complete catalog-plus-presence sample authoritative.');
$assert(str_contains($endpoint, "'online_opponent_count'")
        && str_contains($endpoint, "'presence_observed_at' => gmdate(DATE_ATOM)"),
    'Only aggregate presence diagnostics and observation time may be exposed.');
$assert(str_contains($entry, 'opponents-authoritative-confirm-v122.js?v=122')
        && !str_contains($entry, 'opponents-authoritative-confirm-v117.js?v=117'),
    'The staging shell must publish only v122 opponent confirmation.');
$assert(str_contains($confirm, 'REQUIRED_AUTHORITATIVE_EMPTY_RESPONSES = 3')
        && str_contains($confirm, 'MIN_EMPTY_CONFIRMATION_MS = 3200'),
    'Final empty must require several complete samples spanning the transient window.');
$assert(str_contains($confirm, 'payload?.authoritative === true')
        && str_contains($confirm, 'payload?.complete === true')
        && str_contains($confirm, "payload?.storage_driver === 'json'")
        && str_contains($confirm, 'Number(payload?.unresolved_online_count || 0) === 0'),
    'Only a complete JSON-catalog and presence sample may confirm empty.');
$assert(str_contains($confirm, 'RETRY_DELAYS_MS = [150, 250, 400, 600, 850, 1100]'),
    'Six transient empty samples must remain neutral loading for more than three seconds.');
$assert(str_contains($confirm, "throw new Error('Authoritative opponent list was not confirmed.')"),
    'Unconfirmed transport empties must not render no players.');
$assert(str_contains($confirm, "cache:'no-store'")
        && str_contains($confirm, "headers.set('Cache-Control', 'no-cache')"),
    'Confirmation samples must bypass caches.');

fwrite(STDOUT, "ProductionMvp14D1OpponentAuthoritativeSourceV122Test: {$assertions} assertions passed\n");
