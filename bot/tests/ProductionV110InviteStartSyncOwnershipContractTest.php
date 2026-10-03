<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$path = $root . '/app/assets/js/games/game-invites-v110.js';
$source = file_get_contents($path);
if (!is_string($source)) {
    throw new RuntimeException('Cannot read game-invites-v110.js');
}

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

$assert(
    str_contains($source, 'let inviteStartPending = false;'),
    'Invite start must expose one local transition owner.'
);
$assert(
    str_contains($source, "if (action === 'start') beginInviteStartTransition();"),
    'Explicit Start must acquire the invite-sync transition before its request.'
);
$assert(
    str_contains($source, "function beginInviteStartTransition(){\n  inviteStartPending = true;\n  window.clearTimeout(syncTimer);\n  syncTimer = null;\n}"),
    'Start transition must cancel only the scheduled passive sync timer.'
);
$assert(
    str_contains($source, "async function syncNow({ announce = true } = {}){\n  if (inviteStartPending || syncBusy || document.visibilityState !== 'visible') return null;"),
    'Passive invite sync must not start while explicit Start owns the transition.'
);
$assert(
    str_contains($source, "function scheduleSync(delay = nextSyncInterval()){\n  window.clearTimeout(syncTimer);\n  syncTimer = null;\n  if (inviteStartPending) return;"),
    'An aborted in-flight sync must not re-arm another sync during Start.'
);
$assert(
    str_contains($source, "enterGame(result.game);\n      if (action === 'start') endInviteStartTransition(false);"),
    'Successful Start must enter the authoritative active game before releasing sync ownership.'
);
$assert(
    substr_count($source, "endInviteStartTransition(true);") >= 2,
    'Non-started and failed Start paths must resume passive sync.'
);
$assert(
    str_contains($source, "function endInviteStartTransition(resumeSync){\n  inviteStartPending = false;\n  if (resumeSync) scheduleSync(0);\n}"),
    'Start transition release must resume sync only when no active game was entered.'
);
$assert(
    !str_contains($source, 'ignoredInviteSyncAborts')
        && !str_contains($source, 'backgroundControllers')
        && !str_contains($source, 'setTimeout(() => beginInviteStartTransition'),
    'The owner fix must not weaken E2E diagnostics, globally abort background work, or add a timing workaround.'
);

fwrite(STDOUT, "ProductionV110InviteStartSyncOwnershipContractTest: {$assertions} assertions passed\n");
