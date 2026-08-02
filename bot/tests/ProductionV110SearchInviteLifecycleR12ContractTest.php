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

$search = $read('app/assets/js/screens/search-screen-v102.js');
$reconcile = $read('app/assets/js/games/search-invite-reconciliation-v110r12.js');
$shell = $read('app/assets/js/main-v110-handoff-shell.js');
$main = $read('app/assets/js/main-v110.js');
$entry = $read('app/v110.php');

$assert(
    str_contains($search, 'startPromise:null')
        && str_contains($search, 'stopPromise:null')
        && str_contains($search, 'searchRuntime.active || searchRuntime.starting || searchRuntime.startPromise || searchRuntime.stopPromise'),
    'Search lifecycle must reject duplicate starts while start, active search, or authoritative stop is in flight.'
);
$assert(
    str_contains($search, 'const pendingStart = searchRuntime.startPromise;')
        && str_contains($search, 'if (pendingStart)')
        && str_contains($search, 'try { await pendingStart; }')
        && str_contains($search, 'const result = await api.leaveSearch();'),
    'Cancellation must serialize leave_search after the exact pending start_search request.'
);
$assert(
    str_contains($search, "new CustomEvent('mgw:search-stopped'")
        && str_contains($search, 'detail:{ authoritative:true }'),
    'Only the authoritative search stop boundary may request invitation reconciliation.'
);
$assert(
    str_contains($reconcile, "document.addEventListener('mgw:search-stopped'")
        && str_contains($reconcile, "action:'sync'")
        && str_contains($reconcile, "new CustomEvent('mgw:notification-sync'")
        && str_contains($reconcile, 'announce:false'),
    'Search exit must restore still-active invite cards from canonical invite sync without showing another toast.'
);
$assert(
    !str_contains($reconcile, 'setInterval(')
        && !str_contains($reconcile, 'setTimeout(')
        && str_contains($reconcile, 'if (busy)')
        && str_contains($reconcile, 'queued = true;'),
    'Invite restoration must be event-driven and deduplicated, not a new polling or delay loop.'
);
$assert(
    str_contains($shell, "search-screen-v102.js?v=103")
        && str_contains($shell, "search-invite-reconciliation-v110r12.js?v=1124")
        && str_contains($shell, 'initSearchInviteReconciliation();')
        && str_contains($main, "main-v110-handoff-shell.js?v=1124")
        && str_contains($entry, "main-v110.js?v=1124"),
    'The full active v110 publication graph must deliver the search lifecycle fix as v1124.'
);

fwrite(STDOUT, "ProductionV110SearchInviteLifecycleR12ContractTest: {$assertions} assertions passed\n");
