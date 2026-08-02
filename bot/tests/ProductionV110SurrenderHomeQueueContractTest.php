<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$read = static function (string $path) use ($root): string {
    $content = file_get_contents($root . '/' . $path);
    if (!is_string($content)) throw new RuntimeException('Cannot read production v110 source: ' . $path);
    return $content;
};

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

$lifecycle = $read('app/assets/js/production-v110-match-lifecycle.js');
$search = $read('app/assets/js/screens/search-screen-v102.js');
$entry = $read('app/assets/js/production-clean-entry-v110.js');
$shell = $read('app/assets/js/main-v110-handoff-shell.js');

ob_start();
require $root . '/app/v110.php';
$html = ob_get_clean();
if (!is_string($html)) throw new RuntimeException('Cannot render v110 Telegram entrypoint.');

$assert(
    str_contains($html, './assets/js/production-clean-entry-v110.js?v=1119')
        && str_contains($html, './assets/js/main-v110.js?v=1119')
        && str_contains($html, 'data-hotfix-build="v110-mvp14r12-invite-notification-stability"'),
    'The Telegram v110 entrypoint must publish the exact final browser build.'
);

$home = strpos($lifecycle, "showScreen('home');");
$leave = strpos($lifecycle, 'const result = await api.leaveGame(String(snapshot.id));');
$release = strpos($lifecycle, 'runtime.leavePending = false;', $leave ?: 0);
$replay = strpos($lifecycle, 'window.queueMicrotask(() => queuedButton.click());');
$assert(
    $home !== false
        && $leave !== false
        && $release !== false
        && $replay !== false
        && $home < $leave
        && $leave < $release
        && $release < $replay,
    'Visible home, authoritative release and queued search replay must stay in strict order.'
);

$assert(
    str_contains($lifecycle, "origin.closest('#confirmLeaveGame')")
        && str_contains($lifecycle, 'queueSearchAfterRelease(startButton);')
        && str_contains($lifecycle, "button.textContent = 'Запускаем поиск…';")
        && str_contains($lifecycle, 'releaseQueuedSearchButton()')
        && !str_contains($lifecycle, 'api.startSearch('),
    'The lifecycle owner may queue play intent but must leave actual matchmaking to the search owner.'
);

$assert(
    !str_contains($lifecycle, 'renderPendingResult')
        && !str_contains($lifecycle, 'renderConfirmedResult')
        && !str_contains($lifecycle, 'data-v110-leave-pending')
        && !str_contains($lifecycle, 'openSheet('),
    'No blocked surrender result sheet may remain in the active production owner.'
);

$assert(
    str_contains($search, 'const START_IDS = new Set([')
        && str_contains($search, 'beginSearch(searchContext(button.id));')
        && str_contains($search, "document.addEventListener('mgw:v100-search-request'")
        && substr_count($shell, 'initSearchScreen();') === 1,
    'The existing v102 search screen must remain the single matchmaking owner.'
);

$assert(
    substr_count($entry, 'initV110MatchLifecycle();') === 1
        && str_contains($entry, 'production-v110-match-lifecycle.js?v=1104')
        && str_contains($entry, "window.__MGW_REGRESSION_BUILD__ = 'v110-mvp14r12-invite-notification-stability'"),
    'The production entry must retain exactly one accepted surrender owner in the final build.'
);

fwrite(STDOUT, "ProductionV110SurrenderHomeQueueContractTest: {$assertions} assertions passed\n");
