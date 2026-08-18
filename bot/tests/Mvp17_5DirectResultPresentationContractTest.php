<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$repoRoot = dirname($root);

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

$api = file_get_contents($root . '/api.php');
$gameClient = file_get_contents($repoRoot . '/app/assets/js/screens/game-screen-v102.js');
$homeClient = file_get_contents($repoRoot . '/app/assets/js/screens/home-screen.js');
$manifest = require $repoRoot . '/app/runtime/client/version-manifest.php';

$assert(is_string($api) && str_contains($api, 'function mgw_public_game_with_result('), 'Terminal API must have one result-presentation owner.');
$assert(is_string($api) && str_contains($api, "'result_presentation'"), 'Terminal game responses must carry a viewer-specific result presentation.');
$assert(is_string($api) && str_contains($api, '$history->matchHistory($data, $userId, 12)'), 'Result presentation must project from the current authoritative history/ledger snapshot.');
$assert(is_string($api) && substr_count($api, 'mgw_public_game_with_result($data,') >= 6, 'All terminal-capable game response paths must use the shared result projection.');

$helperStart = is_string($api) ? strpos($api, 'function mgw_public_game_with_result(') : false;
$helperEnd = is_int($helperStart) ? strpos($api, "\ntry {", $helperStart) : false;
$helper = is_int($helperStart) && is_int($helperEnd) ? substr($api, $helperStart, $helperEnd - $helperStart) : '';
$assert($helper !== '', 'Result projection helper must be readable.');
$assert(!str_contains($helper, 'is_bot_game') && !str_contains($helper, 'bot_difficulty') && !str_contains($helper, 'bot_id'), 'Direct result projection must remain bot-opaque.');
$assert(str_contains($helper, "'economy' => is_array(\$match['economy'] ?? null) ? \$match['economy'] : null"), 'Direct result projection must carry canonical economy values without raw payout arithmetic.');

$assert(is_string($gameClient) && str_contains($gameClient, 'await api.history()'), 'Accepted client result owner remains unchanged in this corrective route/cache pass.');
$assert(is_string($gameClient) && !str_contains($gameClient, '${game.payout'), 'Result client must remain free of raw game payout copy.');
$assert(is_string($homeClient) && str_contains($homeClient, 'Вход:') && str_contains($homeClient, 'Награда:') && str_contains($homeClient, 'Итог:') && str_contains($homeClient, 'Баланс:'), 'Actual menu History owner must retain the corrected economy labels.');
$assert(is_string($homeClient) && !str_contains($homeClient, 'const payout=item.payout'), 'Actual menu History owner must not restore raw winner payout.');

$assert(
    str_contains((string)($manifest['imports']['@mgw/main'] ?? ''), 'mvp17=result-history-fix3'),
    'Top-level Telegram module graph must receive a new cache identity for the corrective pass.'
);
$assert(
    str_contains((string)($manifest['imports']['./assets/js/screens/home-screen.js?v=74'] ?? ''), 'mvp17=match-history-economy&fix=2'),
    'Actual History owner must receive a fresh cache identity.'
);
$assert(
    str_contains((string)($manifest['imports']['./assets/js/screens/game-screen-v102.js?v=102'] ?? ''), 'mvp17=result-history-economy&fix=3'),
    'Result owner must receive a fresh cache identity.'
);

fwrite(STDOUT, "Mvp17_5DirectResultPresentationContractTest: {$assertions} assertions passed\n");
