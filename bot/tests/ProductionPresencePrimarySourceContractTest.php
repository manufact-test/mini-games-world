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
$presenceClient = $read('app/assets/js/production-v110-presence.js');
$statsOwner = $read('app/assets/js/stats-owner-v110.js');

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
    substr_count($presenceClient, "beginStatsRequest('presence')") === 2
        && str_contains($presenceClient, 'applyStatsSnapshot(statsTicket, data?.stats)'),
    'Presence ping and status responses must use the dedicated presence statistics channel.'
);
$assert(
    str_contains($statsOwner, "issued:{ api:0, presence:0 }")
        && str_contains($statsOwner, "applied:{ api:0, presence:0 }")
        && str_contains($statsOwner, "if (owner === 'presence')")
        && str_contains($statsOwner, "if (key === 'online_players') continue;")
        && !str_contains($statsOwner, 'ONLINE_DROP_GRACE_MS')
        && !str_contains($statsOwner, 'pendingOnlineDrop')
        && !str_contains($statsOwner, 'stableOnlineCount('),
    'Presence must be the only online counter authority while API statistics remain independently ordered without UI smoothing.'
);

fwrite(STDOUT, "ProductionPresencePrimarySourceContractTest: {$assertions} assertions passed\n");
