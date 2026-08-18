<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$repoRoot = dirname($root);

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

$cleanEntry = file_get_contents($repoRoot . '/app/assets/js/production-clean-entry-v110.js');
$client = file_get_contents($repoRoot . '/app/assets/js/api/client.js');
$home = file_get_contents($repoRoot . '/app/assets/js/screens/home-screen.js');
$result = file_get_contents($repoRoot . '/app/assets/js/screens/game-screen-v102.js');
$gameWatch = file_get_contents($root . '/game-watch.php');
$manifest = require $repoRoot . '/app/runtime/client/version-manifest.php';

$matchHistoryStart = is_string($home) ? strpos($home, 'function renderMatchHistorySheet') : false;
$matchHistoryEnd = is_string($home) ? strpos($home, 'function bindHistoryTabs', is_int($matchHistoryStart) ? $matchHistoryStart : 0) : false;
$matchHistory = is_int($matchHistoryStart) && is_int($matchHistoryEnd)
    ? substr($home, $matchHistoryStart, $matchHistoryEnd - $matchHistoryStart)
    : '';

$assert(is_string($cleanEntry), 'Clean v110 entry must be readable.');
$assert(!str_contains((string)$cleanEntry, 'production-v102-history-controller.js'), 'Legacy capture-phase History controller must not be loaded by the active clean entry.');
$assert(!str_contains((string)$cleanEntry, 'initV102HistoryController'), 'Legacy History controller initializer must not compete with home-screen ownership.');

$assert(is_string($home) && str_contains($home, "document.getElementById('matchHistoryBtn')?.addEventListener('click',openMatchHistorySheet);"), 'Home screen must remain the single Match History click owner.');
$assert($matchHistory !== '' && str_contains($matchHistory, 'item.game_title'), 'Match History must render the canonical game title.');
$assert($matchHistory !== '' && str_contains($matchHistory, 'economy.ledger_delta'), 'Compact Match History must render the authoritative viewer ledger delta.');
$assert($matchHistory !== '' && !str_contains($matchHistory, 'economy.entry'), 'Compact Match History must not repeat viewer entry cost in every card.');
$assert($matchHistory !== '' && !str_contains($matchHistory, 'economy.reward'), 'Compact Match History must not repeat viewer reward in every card.');
$assert($matchHistory !== '' && !str_contains($matchHistory, 'economy.new_balance'), 'Compact Match History must not repeat new balance in every card.');
$assert($matchHistory !== '' && !str_contains($matchHistory, 'short_id'), 'Match History must not expose technical match hashes.');
$assert($matchHistory !== '' && !str_contains($matchHistory, 'ставка'), 'Match History must not expose the legacy stake-only row.');

$assert(is_string($client) && str_contains($client, "import { state } from '../state.js?v=27';"), 'Shared API owner must identify the currently finished game.');
$assert(is_string($client) && str_contains($client, "const RESULT_WATCH_URL = `${window.location.origin}/bot/game-watch.php`;"), 'Finished Result must use the active read-only watcher route.');
$assert(is_string($client) && str_contains($client, "String(game?.status || '') === 'finished'"), 'Locked Result read must activate only for a finished active game.');
$assert(is_string($client) && str_contains($client, "return requestUrl(RESULT_WATCH_URL, { gameId:targetGameId, mode:'result' });"), 'Finished Result must request one exact locked projection snapshot.');
$assert(is_string($client) && !str_contains($client, 'HISTORY_FRESHNESS_DELAYS_MS'), 'Result must not hide storage races behind arbitrary retry delays.');
$assert(is_string($client) && !str_contains($client, 'historyHasMatch'), 'Result must not poll full History until the match happens to appear.');
$assert(is_string($client) && str_contains($client, "return request('history');"), 'Normal History must keep its ordinary server read outside finished Result.');
$assert(is_string($client) && str_contains($client, "historyFast: () => request('history')"), 'Accepted prefetched menu History fast read must remain unchanged.');

$assert(is_string($gameWatch) && str_contains($gameWatch, 'LOCK_SH | LOCK_NB'), 'High-frequency watcher must preserve its accepted non-blocking games-only read.');
$assert(is_string($gameWatch) && str_contains($gameWatch, "clean_string(\$payload['mode'] ?? '', 24) === 'result'"), 'Watcher must expose the locked path only for explicit Result mode.');
$assert(is_string($gameWatch) && str_contains($gameWatch, "['users', 'games', 'transactions']"), 'Result mode must read game context and ledger rows from one coherent shared-lock snapshot.');
$assert(is_string($gameWatch) && str_contains($gameWatch, 'new HistoryService($config, new UserService($config))'), 'Result mode must reuse the existing server History presentation owner.');
$assert(is_string($gameWatch) && str_contains($gameWatch, '$formatter->matchHistory($resultSnapshot, $userId, 1)'), 'Result mode must format only the exact finished game through canonical Match History presentation.');
$assert(is_string($gameWatch) && str_contains($gameWatch, "!is_array(\$match['economy'] ?? null)"), 'Result mode must not publish a terminal summary before authoritative ledger economy exists.');
$assert(is_string($gameWatch) && str_contains($gameWatch, "'presentation_version' => 'mvp17-5-result-locked-projection-v1'"), 'Result response must expose its locked projection build marker.');

$assert(is_string($result) && str_contains($result, 'await api.history()'), 'Game screen must remain the single Result UI owner and consume the shared API abstraction.');
$assert(is_string($result) && str_contains($result, "matches.find(item => String(item?.id || '') === gameId)"), 'Result UI must still select the exact finished match id.');
$assert(is_string($result) && str_contains($result, 'resultSummaryMarkup(match)'), 'Result UI must render canonical economy markup from the server projection.');
$assert(is_string($result) && !str_contains($result, '${game.payout'), 'Result UI must not calculate money from raw global payout.');

$cleanEntryUrl = (string)($manifest['imports']['@mgw/clean-entry'] ?? '');
$assert(str_contains($cleanEntryUrl, 'v=1125&mvp16=canonical-avatar-owner&mvp17=history-single-owner'), 'Active clean entry must preserve the accepted single History owner prefix.');
foreach ([34, 38, 46, 47] as $version) {
    $url = (string)($manifest['imports']["./assets/js/api/client.js?v={$version}"] ?? '');
    $assert(
        str_contains($url, 'v=1134&mvp16=profile-corrective&mvp17=history-fresh-match&menu=fast-history&result=locked-watch'),
        "API client alias v{$version} must preserve accepted Profile/History prefixes and cache-bust locked Result routing."
    );
}

fwrite(STDOUT, "Mvp17_5ResultHistorySingleOwnerRaceContractTest: {$assertions} assertions passed\n");
