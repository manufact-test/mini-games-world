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

$assert($matchHistory !== '' && str_contains($matchHistory, "const game=item.game_title||'Матч';"), 'Actual History modal must display the real game title.');
$assert($matchHistory !== '' && str_contains($matchHistory, "const economy=item.economy&&typeof item.economy==='object'?item.economy:null;"), 'Actual History modal must consume the canonical viewer economy projection.');
$assert($matchHistory !== '' && str_contains($matchHistory, 'economy.ledger_delta'), 'Actual History modal must display the viewer ledger delta.');
$assert($matchHistory !== '' && str_contains($matchHistory, 'Вход:') && str_contains($matchHistory, 'Награда:') && str_contains($matchHistory, 'Итог:') && str_contains($matchHistory, 'Баланс:'), 'Actual History modal must label all four economy values explicitly.');
$assert($matchHistory !== '' && !str_contains($matchHistory, 'const payout=item.payout'), 'Actual History modal must never use the global winner payout as the viewer result.');
$assert($matchHistory !== '' && !str_contains($matchHistory, 'short_id'), 'Actual History modal must not expose technical match hashes.');
$assert($matchHistory !== '' && !str_contains($matchHistory, 'ставка'), 'Actual History modal must not use the old raw stake-only presentation.');

$assert(is_string($result) && str_contains($result, 'await api.history()'), 'Result sheet may use the history read endpoint but it must now hit the lightweight read path.');
$assert(is_string($result) && !str_contains($result, '${game.payout'), 'Result sheet must remain free of raw global payout arithmetic.');
$assert(is_string($result) && str_contains($result, 'id="newOpponent"') && str_contains($result, 'id="goHome"'), 'Accepted result action IDs must remain unchanged.');

$assert(
    str_contains((string)($manifest['imports']['./assets/js/screens/home-screen.js?v=74'] ?? ''), 'v=80&mvp16=settings-row-owner&mvp17=match-history-economy'),
    'Active v110 manifest must cache-bust the actual History modal owner while preserving the accepted settings-row identity.'
);
$assert(is_string($launch) && str_contains($launch, "private const ENTRY_PATH = '/app/v110.php?v=1127';"), 'Corrective pass must remain anchored to the actual Telegram v110 entry.');

fwrite(STDOUT, "Mvp17_5ResultHistoryFix2ContractTest: {$assertions} assertions passed\n");
