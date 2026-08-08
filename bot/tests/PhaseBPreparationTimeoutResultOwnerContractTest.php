<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$gamePath = 'app/assets/js/screens/game-screen-v102.js';
$game = file_get_contents($root . '/' . $gamePath);
$v110 = file_get_contents($root . '/app/v110.php');
$manifest = file_get_contents($root . '/bot/helpers/staging-e2e-runtime-files.txt');
foreach (['game' => $game, 'v110' => $v110, 'manifest' => $manifest] as $name => $content) {
    if (!is_string($content)) throw new RuntimeException('Missing source: ' . $name);
}

$blobPrefix = static fn(string $content): string => substr(sha1('blob ' . strlen($content) . "\0" . $content), 0, 12);
$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

$prefix = $blobPrefix($game);
$assert($prefix === '342fd6cfbb7f', 'Canonical game-screen blob prefix must match reviewed result-owner content.');
$assert(
    str_contains($v110, 'game-screen-v102.js?v=102&b=' . $prefix),
    'v110 import map must content-address the canonical game result owner.'
);
$assert(str_contains($manifest, $gamePath), 'Canonical game result owner must be included in exact staging fingerprint coverage.');

$sheetStart = strpos($game, 'function openResultSheet(game, me, options = {})');
$sheetEnd = strpos($game, 'function setResultActionsDisabled', $sheetStart === false ? 0 : $sheetStart);
$assert($sheetStart !== false && $sheetEnd !== false && $sheetStart < $sheetEnd, 'Canonical openResultSheet owner must remain identifiable.');
$sheet = substr($game, $sheetStart, $sheetEnd - $sheetStart);

$assert(
    str_contains($sheet, "if (game.finish_reason === 'preparation_timeout')")
        && str_contains($sheet, "title = 'Матч не начался';")
        && str_contains($sheet, "text = 'Соперник не подключился вовремя. Ставка возвращена на баланс.';"),
    'Preparation timeout must be rendered by the canonical result owner as a match that never started.'
);
$assert(
    str_contains($sheet, "let title = 'Ничья';")
        && str_contains($sheet, "let text = chessDrawText(game) || 'Коины возвращены на баланс.';"),
    'Default true-draw result wording must remain unchanged.'
);
$assert(
    strpos($sheet, "if (game.finish_reason === 'preparation_timeout')") < strpos($sheet, 'else if (game.winner_id)'),
    'Preparation-timeout semantics must resolve before winner/draw outcome branches.'
);
$assert(substr_count($game, "title = 'Матч не начался';") === 1, 'Preparation-timeout result title must have one UI owner only.');
$assert(substr_count($game, "Ставка возвращена на баланс.") === 1, 'Preparation-timeout result copy must have one UI owner only.');

fwrite(STDOUT, "PhaseBPreparationTimeoutResultOwnerContractTest: {$assertions} assertions passed\n");
