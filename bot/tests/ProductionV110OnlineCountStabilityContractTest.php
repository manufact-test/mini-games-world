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

$owner = $read('app/assets/js/stats-owner-v110.js');
$presence = $read('app/assets/js/production-v110-presence.js');
$service = $read('bot/services/PresenceService.php');
$race = $read('bot/tests/ProductionV110PresenceDocumentLeaseRaceTest.php');
$shell = $read('app/assets/js/main-v110-handoff-shell.js');

$assert(
    !str_contains($owner, 'ONLINE_DROP_GRACE_MS')
        && !str_contains($owner, 'pendingOnlineDrop')
        && !str_contains($owner, 'stableOnlineCount')
        && !str_contains($owner, 'return current;'),
    'The visible online counter must not hide presence errors with a timer or retained stale value.'
);
$assert(
    str_contains($owner, "issued:{ api:0, presence:0 }")
        && str_contains($owner, "applied:{ api:0, presence:0 }")
        && str_contains($owner, "const owner = normalizeSource(ticket?.owner);")
        && str_contains($owner, "if (owner === 'presence')")
        && str_contains($owner, "if (key === 'online_players') continue;")
        && str_contains($owner, 'renderStats(state.stats);'),
    'API and presence responses must be independently ordered, and only presence may publish online_players.'
);
$assert(
    str_contains($presence, 'const presenceLeaseId = createPresenceLeaseId();')
        && str_contains($presence, '// Presence transport starts before the profile bootstrap.')
        && substr_count($presence, "beginStatsRequest('presence')") === 2
        && str_contains($presence, 'applyStatsSnapshot(statsTicket, data?.stats);'),
    'The client must solve Telegram reopen through document-scoped presence and the dedicated presence statistics channel.'
);
$assert(
    str_contains($service, 'private const LEAVE_GRACE_SEC = 12;')
        && str_contains($service, '$sessionId . "\\0presence:" . $presenceLeaseId')
        && str_contains($service, "'leave_after'"),
    'The server must preserve a bounded old-document handoff without merging separate document leases.'
);
$assert(
    str_contains($race, "touch('200', 'phone-device', 'old-page')")
        && str_contains($race, "touch('200', 'phone-device', 'new-page')")
        && str_contains($race, "leave('200', 'phone-device', 'old-page')")
        && str_contains($race, "online_players'] === 2")
        && str_contains($race, 'delayed leave from the old Telegram document'),
    'The executable regression must prove that an old document leave cannot reduce online while the new lease is alive.'
);
$assert(
    substr_count($shell, "from './stats-owner-v110.js?v=1121'") === 1
        && substr_count($shell, "from './production-v110-presence.js?v=1121'") === 1
        && substr_count($shell, "beginStatsRequest('api')") === 2,
    'The active shell must keep one API statistics channel and one presence statistics channel.'
);

fwrite(STDOUT, "ProductionV110OnlineCountStabilityContractTest: {$assertions} assertions passed\n");
