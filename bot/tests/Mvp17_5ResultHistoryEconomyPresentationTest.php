<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$services = $root . '/bot/services';
$tests = $root . '/bot/tests';
$assets = $root . '/app/assets/js';

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

require_once $services . '/HistoryService.php';

$db = new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, display_name TEXT, nickname TEXT, photo_url TEXT, mgw_id TEXT, balance INTEGER NOT NULL DEFAULT 0)');
$db->exec('CREATE TABLE games (
    id TEXT PRIMARY KEY,
    game_type TEXT NOT NULL,
    status TEXT NOT NULL,
    room TEXT NOT NULL,
    bet INTEGER NOT NULL DEFAULT 0,
    payout INTEGER NOT NULL DEFAULT 0,
    winner_id INTEGER,
    ended_at TEXT,
    created_at TEXT
)');
$db->exec('CREATE TABLE game_players (game_id TEXT NOT NULL, user_id INTEGER NOT NULL, mark TEXT, position INTEGER)');
$db->exec('CREATE TABLE ledger (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL, game_id TEXT, amount INTEGER NOT NULL, type TEXT NOT NULL, created_at TEXT)');
$db->exec("INSERT INTO users(id,display_name,nickname,photo_url,mgw_id,balance) VALUES
    (1,'Me','Me','','MGW-0000000000000001',1015),
    (2,'Other','Other','','MGW-0000000000000002',1000)");
$db->exec("INSERT INTO games(id,game_type,status,room,bet,payout,winner_id,ended_at,created_at) VALUES
    ('g-win','tictactoe','finished','room-win',10,20,1,'2026-08-19 10:00:00','2026-08-19 09:58:00'),
    ('g-loss','tictactoe','finished','room-loss',10,20,2,'2026-08-19 09:00:00','2026-08-19 08:58:00')");
$db->exec("INSERT INTO game_players(game_id,user_id,mark,position) VALUES
    ('g-win',1,'X',0),('g-win',2,'O',1),
    ('g-loss',1,'X',0),('g-loss',2,'O',1)");
$db->exec("INSERT INTO ledger(user_id,game_id,amount,type,created_at) VALUES
    (1,'g-win',-10,'game_entry','2026-08-19 09:58:30'),
    (1,'g-win',18,'game_reward','2026-08-19 10:00:01'),
    (1,'g-loss',-10,'game_entry','2026-08-19 08:58:30')");

$history = new HistoryService($db);
$rows = $history->userHistory(1, 10);
$assert(count($rows) === 2, 'History must return both finished matches.');

$byId = [];
foreach ($rows as $row) {
    $byId[(string)($row['id'] ?? '')] = $row;
}

$winEconomy = $byId['g-win']['economy'] ?? null;
$assert(is_array($winEconomy), 'Winning history row must expose economy projection.');
$assert(($winEconomy['entry_delta'] ?? null) === -10, 'Winning history entry delta must come from ledger.');
$assert(($winEconomy['reward_delta'] ?? null) === 18, 'Winning history reward delta must come from ledger.');
$assert(($winEconomy['ledger_delta'] ?? null) === 8, 'Winning history ledger delta must be canonical viewer net delta.');
$assert(($winEconomy['new_balance'] ?? null) === 1015, 'Latest match new balance must reconcile from the current authoritative balance.');

$lossEconomy = $byId['g-loss']['economy'] ?? null;
$assert(is_array($lossEconomy), 'Loss history row must expose economy projection.');
$assert(($lossEconomy['entry_delta'] ?? null) === -10, 'Loss history entry delta must come from ledger.');
$assert(($lossEconomy['reward_delta'] ?? null) === 0, 'Loss history reward delta must remain zero without reward ledger.');
$assert(($lossEconomy['ledger_delta'] ?? null) === -10, 'Loss history ledger delta must reflect the entry debit only.');
$assert(($lossEconomy['new_balance'] ?? null) === 1007, 'Older match new balance must backtrack later ledger movement deterministically.');

$resultClient = file_get_contents($assets . '/screens/game-screen-v102.js');
$profileClient = file_get_contents($assets . '/screens/profile-screen-v110.js');
$manifest = require $root . '/app/runtime/client/version-manifest.php';
$launch = file_get_contents($root . '/helpers/WebAppLaunchUrl.php');

$assert(is_string($resultClient) && str_contains($resultClient, 'await api.history()'), 'Result sheet must hydrate from canonical server history.');
$assert(is_string($resultClient) && str_contains($resultClient, 'economy.ledger_delta'), 'Result sheet must display the server-projected ledger delta.');
$assert(is_string($resultClient) && str_contains($resultClient, 'economy.new_balance'), 'Result sheet must display server-projected new balance.');
$assert(is_string($resultClient) && str_contains($resultClient, 'За игру: ${escapeHtml(delta)} · Баланс: ${escapeHtml(balance)}'), 'Result must show only viewer net delta and final balance.');
$assert(is_string($resultClient) && !str_contains($resultClient, 'Вход: ${escapeHtml(entry)}'), 'Result must not repeat the entry debit as a separate visible row.');
$assert(is_string($resultClient) && !str_contains($resultClient, 'Награда: ${escapeHtml(reward)}'), 'Result must not repeat the reward credit as a separate visible row.');
$assert(is_string($resultClient) && str_contains($resultClient, 'resultSummaryPlaceholder(game, me'), 'Result must reserve the final two-line summary shape before economy hydration.');
$assert(is_string($resultClient) && str_contains($resultClient, 'window.requestAnimationFrame(() => openResultSheet(game, me));'), 'Result must open on the next paint without the legacy extra 80ms delay.');
$assert(is_string($resultClient) && !str_contains($resultClient, 'window.setTimeout(() => openResultSheet(game, me), 80)'), 'Result must not restore the visible 80ms terminal delay.');
$assert(is_string($resultClient) && !str_contains($resultClient, 'const variant = columns > 0'), 'Result context must stay compact: game and opponent only, without board-size metadata.');
$assert(is_string($resultClient) && !str_contains($resultClient, '${game.payout'), 'Result copy must not calculate or display money from raw game payout.');
$assert(is_string($resultClient) && str_contains($resultClient, 'id="newOpponent"') && str_contains($resultClient, 'id="goHome"'), 'Accepted result action IDs must remain unchanged for rematch policy ownership.');
$assert(is_string($profileClient) && str_contains($profileClient, 'match?.economy'), 'Profile history must consume the same canonical match economy projection.');
$assert(is_string($profileClient) && str_contains($profileClient, 'economy.ledger_delta'), 'Profile history must display canonical ledger delta.');
$assert(
    str_contains((string)($manifest['imports']['./assets/js/screens/game-screen-v102.js?v=102'] ?? ''), 'v=106&clock=phase-b-single-writer&battleship=leave-guard&mvp17=result-history-economy&live=owner-v3&result=compact-fast-v1'),
    'Active v110 manifest must publish the compact fast Result owner while preserving accepted game ownership prefixes.'
);
$assert(
    str_contains((string)($manifest['imports']['./assets/js/screens/profile-screen-v110.js?v=1108'] ?? ''), 'v=1119&mvp16=profile-pass-a&mvp17=result-history-economy'),
    'Active v110 manifest must preserve the accepted current Profile pass A cache identity and append the economy presentation cache marker.'
);
$assert(
    str_contains((string)($manifest['imports']['./assets/js/games/game-invites-v110.js?v=1137&ux=1'] ?? ''), 'game-invites-v110-rematch-policy-v175.js?v=1&fp=2'),
    'Accepted MVP-17.5 rematch presentation policy must remain frozen.'
);
$assert(is_string($launch) && str_contains($launch, "private const ENTRY_PATH = '/app/v110.php?v=1127';"), 'Result/history test must remain anchored to actual Telegram v110 launch.');

fwrite(STDOUT, "Mvp17_5ResultHistoryEconomyPresentationTest: {$assertions} assertions passed\n");
