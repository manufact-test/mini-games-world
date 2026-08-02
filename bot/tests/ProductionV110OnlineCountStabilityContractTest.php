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
$shell = $read('app/assets/js/main-v110-handoff-shell.js');

$assert(
    str_contains($owner, 'ONLINE_DROP_GRACE_MS = 6500')
        && str_contains($owner, 'pendingOnlineDrop:null'),
    'The visible online counter must own one bounded transient-drop guard.'
);
$assert(
    str_contains($owner, 'if (sequence < runtime.applied) return false;')
        && str_contains($owner, 'next.online_players = stableOnlineCount(next.online_players);'),
    'The accepted response-order guard must remain active before online smoothing.'
);
$assert(
    str_contains($owner, "document.visibilityState !== 'visible'")
        && str_contains($owner, 'next >= current')
        && str_contains($owner, 'return next;'),
    'Hidden documents and real increases must apply immediately without artificial holding.'
);
$assert(
    str_contains($owner, 'return current;')
        && str_contains($owner, 'now - Number(pending.startedAt || 0) < ONLINE_DROP_GRACE_MS')
        && str_contains($owner, 'runtime.pendingOnlineDrop = null;'),
    'A one-sample decrease must stay invisible, while a persistent decrease must eventually apply.'
);
$assert(
    str_contains($presence, 'applyStatsSnapshot(statsTicket, data?.stats);')
        && substr_count($shell, "from './stats-owner-v110.js?v=1119'") === 1,
    'Presence and bootstrap polling must still share the same single statistics owner.'
);

fwrite(STDOUT, "ProductionV110OnlineCountStabilityContractTest: {$assertions} assertions passed\n");
