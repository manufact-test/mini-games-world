<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$source = file_get_contents($root . '/app/assets/js/games/game-invites-v110.js');
$policy = file_get_contents($root . '/app/assets/js/games/game-invites-v110-rematch-policy-v175.js');
$manifest = file_get_contents($root . '/app/runtime/client/version-manifest.php');
if (!is_string($source) || !is_string($policy) || !is_string($manifest)) {
    throw new RuntimeException('Unable to read rematch runtime.');
}

$start = strpos($source, 'async function createRematch(gameId, button){');
$end = strpos($source, "\nasync function syncNow", $start === false ? 0 : $start);
if ($start === false || $end === false || $end <= $start) {
    throw new RuntimeException('createRematch owner not found.');
}
$block = substr($source, $start, $end - $start);

if (!str_contains($block, 'rematchPendingGameIds.has(gameId)')) {
    throw new RuntimeException('Rematch duplicate-request Set guard missing.');
}
if (str_contains($block, 'Предлагаем реванш…')) {
    throw new RuntimeException('Rematch must not expose the old long loading label.');
}
$optimistic = strpos($block, 'data-rematch-pending');
$request = strpos($block, "await inviteRequest('rematch', { gameId })");
if ($optimistic === false || $request === false || $optimistic > $request) {
    throw new RuntimeException('Optimistic rematch surface must render before the server request completes.');
}
if (!str_contains($block, 'Реванш предложен') || !str_contains($block, 'Ждём ответа соперника.')) {
    throw new RuntimeException('Optimistic rematch copy missing.');
}
if (!str_contains($block, 'if (optimisticSurfaceOpen && rollbackHtml) openSheet(rollbackHtml);')) {
    throw new RuntimeException('Rematch failure rollback contract missing.');
}

// MVP-17.5 inserts one bot-opaque presentation owner in the real v110 import
// graph. The accepted optimistic rematch lifecycle remains the underlying owner.
if (!str_contains($manifest, "game-invites-v110-rematch-policy-v175.js?v=1")) {
    throw new RuntimeException('Bot-opaque rematch presentation cache-bust missing.');
}
if (!str_contains($policy, "./game-invites-v110.js?v=1142&zone=unified&rematch=optimistic&terminal=self-silent")) {
    throw new RuntimeException('Accepted optimistic rematch owner must remain under the MVP-17.5 presentation policy.');
}
if (!str_contains($policy, 'game?.rematch_available === true') || str_contains($policy, 'is_bot_game')) {
    throw new RuntimeException('Direct rematch presentation must be capability-driven and bot-opaque.');
}

fwrite(STDOUT, "Rematch optimistic UX contract: OK\n");