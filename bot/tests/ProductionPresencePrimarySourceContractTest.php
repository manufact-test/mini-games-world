<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$read = static function (string $path) use ($root): string {
    $content = file_get_contents($root . '/' . $path);
    if (!is_string($content)) throw new RuntimeException('Cannot read ' . $path);
    return $content;
};
$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

$entrypoints = $read('bot/runtime/ProductionPrimaryApplicationEntrypoints.php');
$factory = $read('bot/storage/StorageFactory.php');
$presence = $read('bot/presence.php');
$client = $read('app/assets/js/production-v110-presence.js');

$assert(
    str_contains($entrypoints, "'bot/presence.php' => 'presence'")
        && str_contains($factory, 'ProductionPrimaryApplicationEntrypoints::resolve(')
        && str_contains($factory, 'ProductionPrimaryEntrypointBootstrap::installIfEnabled('),
    'The production presence endpoint must resolve through the guarded DB-primary entrypoint selector.'
);
$assert(
    str_contains($presence, 'StorageFactory::createJson(')
        && !str_contains($presence, 'new JsonDatabase(')
        && !str_contains($presence, 'new JsonStorageAdapter('),
    'Presence must request canonical storage through StorageFactory instead of opening JSON directly.'
);
$assert(
    str_contains($presence, '$presence->touch(')
        && str_contains($presence, '$db->readOnly(')
        && str_contains($presence, '$stats->build($data)'),
    'The same presence request must update the lease and build statistics from the selected canonical storage.'
);
$assert(
    str_contains($client, 'beginStatsRequest()')
        && str_contains($client, 'applyStatsSnapshot($statsTicket, data?.stats)')
        && !str_contains($client, 'ONLINE_DROP_GRACE_MS'),
    'The client must keep the accepted request-order owner and must not hide stale server snapshots with UI smoothing.'
);

fwrite(STDOUT, "ProductionPresencePrimarySourceContractTest: {$assertions} assertions passed\n");
