<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$invites = file_get_contents($root . '/app/assets/js/games/game-invites-v110.js');
$main = file_get_contents($root . '/app/assets/js/main-v110-handoff-shell.js');
$manifest = file_get_contents($root . '/app/runtime/client/version-manifest.php');
$endpoint = file_get_contents($root . '/bot/invites.php');
if (!is_string($invites) || !is_string($main) || !is_string($manifest) || !is_string($endpoint)) {
    throw new RuntimeException('MVP-26.3.12 sources unavailable.');
}

$checks = [
    str_contains($invites, "syncState(result, { preserveBalance:true });"),
    str_contains($invites, "options?.preserveBalance === true"),
    str_contains($invites, "Object.prototype.hasOwnProperty.call(state.user, 'balance')"),
    str_contains($invites, "balance:state.user.balance"),
    str_contains($invites, "syncState(result);"),
    str_contains($endpoint, "if ($action === 'sync')"),
    str_contains($endpoint, "readOnlySections("),
    str_contains($main, "game-invites-v110.js?v=1137&ux=1"),
    str_contains($manifest, "game-invites-v110.js?v=1149"),
    str_contains($manifest, "mvp26_3_12=invite-sync-wallet-nonowner-v1"),
    str_contains($manifest, "main-v110-handoff-shell.js?v=1168"),
];

foreach ($checks as $i => $ok) {
    if (!$ok) throw new RuntimeException('assertion ' . ($i + 1) . ' failed');
}

fwrite(STDOUT, 'Mvp26_3_12InviteSyncBalanceOwnershipContractTest: ' . count($checks) . " assertions passed\n");
