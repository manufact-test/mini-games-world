<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$repoRoot = dirname($root);

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

$historyService = file_get_contents($root . '/services/HistoryService.php');
$home = file_get_contents($repoRoot . '/app/assets/js/screens/home-screen.js');
$result = file_get_contents($repoRoot . '/app/assets/js/screens/game-screen-v102.js');
$client = file_get_contents($repoRoot . '/app/assets/js/api/client.js');
$manifest = require $repoRoot . '/app/runtime/client/version-manifest.php';
$launch = file_get_contents($root . '/helpers/WebAppLaunchUrl.php');
$matchHistoryStart = is_string($home) ? strpos($home, 'function renderMatchHistorySheet') : false;
$matchHistoryEnd = is_string($home) ? strpos($home, 'function bindHistoryTabs', is_int($matchHistoryStart) ? $matchHistoryStart : 0) : false;
$matchHistory = is_int($matchHistoryStart) && is_int($matchHistoryEnd)
    ? substr($home, $matchHistoryStart, $matchHistoryEnd - $matchHistoryStart)
    : '';

$assert(is_string($historyService) && str_contains($historyService, '$repository->read($userId, $limit)'), 'History read path must use the staged DB snapshot without per-request full shadow synchronization.');
$assert(is_string($historyService) && !str_contains($historyService, '$repository->synchronizeAndRead($db, $userId, $limit)'), 'User-facing history must not run the heavy full shadow synchronization on every read.');
$assert(is_string($historyService) && str_contains($historyService, 'mergeCurrentMatchPresentation'), 'History must merge the current request snapshot so the just-finished match is immediately available.');
$assert(is_string($historyService) && str_contains($historyService, "PRESENTATION_VERSION = 'mvp17-5-history-economy-live-owner-v3'"), 'History server owner must expose the live redeploy presentation marker.');
$assert(is_string($historyService) && str_contains($historyService, "'presentation_version'"), 'History payload must carry the authoritative presentation version marker.');

$assert(is_string($home) && str_contains($home, "window.__MGW_MATCH_HISTORY_UI_BUILD__ = 'mvp17-5-history-economy-live-owner-v3';"), 'Actual History UI owner must carry the live redeploy build marker.');
$assert(is_string($home) && str_contains($home, "window.__MGW_HISTORY_MODAL_UX_BUILD__ = 'mvp17-5-prefetched-history-v3';"), 'History menu must expose the prefetched interaction build marker.');
$assert(is_string($home) && !str_contains($home, 'openHistoryLoadingSheet'), 'History must not own a temporary loading sheet.');
$assert(is_string($home) && !str_contains($home, 'Загружаем матчи…') && !str_contains($home, 'Загружаем историю…'), 'History must not render intermediate loading copy inside a modal.');
$assert(is_string($home) && !str_contains($home, 'setHistoryButtonsDisabled'), 'History buttons must not be disabled while data is loading.');
$assert(is_string($home) && str_contains($home, 'void refreshHistoryCache({ force:true }).catch(() => {});'), 'History cache must warm during home-screen initialization.');
$assert(is_string($home) && str_contains($home, "document.addEventListener('mgw:game-finished'"), 'Finished matches must schedule a background History cache refresh.');
$assert(is_string($home) && str_contains($home, 'void refreshHistoryCache().catch(() => {});'), 'Opening the More menu must refresh History in the background without blocking interaction.');
$assert(is_string($home) && substr_count($home, 'historyCache || await refreshHistoryCache({ force:true })') >= 2, 'Both Match History and Balance History must prefer already-prefetched data on click.');
$assert(is_string($home) && str_contains($home, 'historyCachePromise=api.historyFast()'), 'Menu History cache must use the fast one-shot History endpoint owner.');
$assert(is_string($client) && str_contains($client, "historyFast: () => request('history')"), 'API client must expose a fast one-shot History read for menu prefetch.');

$assert($matchHistory !== '' && str_contains($matchHistory, "const game=item.game_title||'Матч';"), 'Actual History modal must display the real game title.');
$assert($matchHistory !== '' && str_contains($matchHistory, "const economy=item.economy&&typeof item.economy==='object'?item.economy:null;"), 'Actual History modal must consume the canonical viewer economy projection.');
$assert($matchHistory !== '' && str_contains($matchHistory, 'economy.ledger_delta'), 'Actual History modal must display the viewer ledger delta.');
$assert($matchHistory !== '' && str_contains($matchHistory, 'const delta=economy?matchDelta(economy.ledger_delta):\'\';'), 'Compact Match History must reduce economy presentation to the authoritative viewer delta.');
$assert($matchHistory !== '' && !str_contains($matchHistory, 'Вход:') && !str_contains($matchHistory, 'Награда:') && !str_contains($matchHistory, 'Баланс:'), 'Compact Match History must not repeat entry/reward/new-balance bookkeeping inside every card.');
$assert($matchHistory !== '' && !str_contains($matchHistory, 'const payout=item.payout'), 'Actual History modal must never use the global winner payout as the viewer result.');
$assert($matchHistory !== '' && !str_contains($matchHistory, 'short_id'), 'Actual History modal must not expose technical match hashes.');
$assert($matchHistory !== '' && !str_contains($matchHistory, 'ставка'), 'Actual History modal must not use the old raw stake-only presentation.');

$assert(is_string($result) && str_contains($result, 'await api.history()'), 'Result sheet must keep the exact locked finished-match projection owner.');
$assert(is_string($result) && !str_contains($result, '${game.payout'), 'Result sheet must remain free of raw global payout arithmetic.');
$assert(is_string($result) && str_contains($result, 'id="newOpponent"') && str_contains($result, 'id="goHome"'), 'Accepted result action IDs must remain unchanged.');

$assert(
    str_contains((string)($manifest['imports']['./assets/js/screens/home-screen.js?v=74'] ?? ''), 'v=80&mvp16=settings-row-owner&mvp17=match-history-economy&live=owner-v3&ux=ready-only-history-sheet&perf=prefetched-history'),
    'Active v110 manifest must cache-bust prefetched History interaction while preserving the accepted home-screen prefix.'
);
foreach ([34, 38, 46, 47] as $version) {
    $url = (string)($manifest['imports']["./assets/js/api/client.js?v={$version}"] ?? '');
    $assert(str_contains($url, 'v=1135&mvp16=profile-corrective&mvp17=history-fresh-match&menu=fast-history'), "API alias v{$version} must preserve the accepted fast menu History prefix across the name-color cache bump.");
}
$assert(
    str_contains((string)($manifest['imports']['./assets/js/screens/game-screen-v102.js?v=102'] ?? ''), 'v=106&clock=phase-b-single-writer&battleship=leave-guard&mvp17=result-history-economy&live=owner-v3&result=compact-fast-v1'),
    'Active v110 manifest must publish the accepted compact Result owner cache identity.'
);
$assert(is_string($launch) && str_contains($launch, "private const ENTRY_PATH = '/app/v110.php?v=1127';"), 'Corrective pass must remain anchored to the actual Telegram v110 entry.');

fwrite(STDOUT, "Mvp17_5ResultHistoryFix2ContractTest: {$assertions} assertions passed\n");