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

$assert(is_string($client) && str_contains($client, "import { state } from '../state.js?v=27';"), 'Shared API history owner must inspect the current finished game.');
$assert(is_string($client) && str_contains($client, 'HISTORY_FRESHNESS_DELAYS_MS = [80, 120, 180, 260]'), 'History freshness retry must be explicitly bounded.');
$assert(is_string($client) && str_contains($client, "String(game?.status || '') === 'finished'"), 'History freshness retry must activate only for a finished active game.');
$assert(is_string($client) && str_contains($client, "String(item?.id || '') === gameId"), 'History freshness retry must wait for the exact finished game id.');
$assert(is_string($client) && str_contains($client, 'attempt <= HISTORY_FRESHNESS_DELAYS_MS.length'), 'History freshness loop must have a fixed upper bound.');
$assert(is_string($client) && str_contains($client, 'history: () => requestHistory()'), 'All History consumers must share the freshness-aware API owner.');

$assert(is_string($result) && str_contains($result, 'await api.history()'), 'Result owner must consume the shared History API owner.');
$assert(is_string($result) && str_contains($result, "matches.find(item => String(item?.id || '') === gameId)"), 'Result must select the exact finished match after freshness convergence.');
$assert(is_string($result) && str_contains($result, 'resultSummaryMarkup(match)'), 'Result must render canonical economy markup once the exact match is available.');

$cleanEntryUrl = (string)($manifest['imports']['@mgw/clean-entry'] ?? '');
$assert(str_contains($cleanEntryUrl, 'v=1125&mvp16=canonical-avatar-owner&mvp17=history-single-owner'), 'Active clean entry must preserve its accepted prefix and cache-bust the single History owner change.');
foreach ([34, 38, 46, 47] as $version) {
    $url = (string)($manifest['imports']["./assets/js/api/client.js?v={$version}"] ?? '');
    $assert(str_contains($url, 'v=1134&mvp16=profile-corrective&mvp17=history-fresh-match'), "API client alias v{$version} must preserve its accepted prefix and cache-bust History freshness logic.");
}

fwrite(STDOUT, "Mvp17_5ResultHistorySingleOwnerRaceContractTest: {$assertions} assertions passed\n");
