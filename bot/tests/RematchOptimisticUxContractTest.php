<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$source = file_get_contents($root . '/app/assets/js/games/game-invites-v110.js');
$manifest = file_get_contents($root . '/app/runtime/client/version-manifest.php');
if (!is_string($source) || !is_string($manifest)) {
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
if (!str_contains($manifest, "game-invites-v110.js?v=1142&zone=unified&rematch=optimistic&terminal=self-silent")) {
    throw new RuntimeException('Optimistic rematch cache-bust missing.');
}

fwrite(STDOUT, "Rematch optimistic UX contract: OK\n");
