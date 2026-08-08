<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$gamePath = 'app/assets/js/screens/game-screen-v102.js';
$game = file_get_contents($root . '/' . $gamePath);
$v110 = file_get_contents($root . '/app/v110.php');
$manifest = file_get_contents($root . '/bot/helpers/staging-e2e-runtime-files.txt');
if (!is_string($game) || !is_string($v110) || !is_string($manifest)) {
    throw new RuntimeException('Phase B result-owner sources unavailable.');
}

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};
$blobPrefix = static function (string $content): string {
    return substr(sha1('blob ' . strlen($content) . "\0" . $content), 0, 12);
};

$prefix = $blobPrefix($game);
$assert($prefix === '342fd6cfbb7f', 'Canonical game-screen blob must match the reviewed preparation-timeout result owner.');
$assert(
    str_contains($v110, 'game-screen-v102.js?v=102&b=' . $prefix),
    'v110 import map must content-address the canonical result-sheet owner.'
);
$assert(str_contains($manifest, $gamePath), 'Canonical game-screen result owner must be in exact staging fingerprint coverage.');

$functionStart = strpos($game, 'function openResultSheet(game, me, options = {})');
$functionEnd = $functionStart === false ? false : strpos($game, 'function setResultActionsDisabled', $functionStart);
$assert($functionStart !== false && $functionEnd !== false, 'Canonical openResultSheet owner must remain identifiable.');
$resultOwner = ($functionStart !== false && $functionEnd !== false)
    ? substr($game, $functionStart, $functionEnd - $functionStart)
    : '';

$preparationPos = strpos($resultOwner, "if (game.finish_reason === 'preparation_timeout')");
$winnerPos = strpos($resultOwner, 'else if (game.winner_id)');
$assert($preparationPos !== false && $winnerPos !== false && $preparationPos < $winnerPos,
    'Preparation timeout must be classified before winner/draw fallback semantics.');
$assert(str_contains($resultOwner, "title = 'Матч не начался';"),
    'Preparation timeout must not render as a draw title.');
$assert(str_contains($resultOwner, "text = 'Соперник не подключился вовремя. Ставка возвращена на баланс.';"),
    'Preparation timeout must explain the non-start and returned stake.');
$assert(substr_count($game, "game.finish_reason === 'preparation_timeout'") === 1,
    'Preparation-timeout result semantics must have exactly one client owner.');

$assert(str_contains($resultOwner, "let title = 'Ничья';"), 'Ordinary draw title must remain unchanged.');
$assert(str_contains($resultOwner, "let text = chessDrawText(game) || 'Коины возвращены на баланс.';"),
    'Ordinary draw refund copy must remain unchanged outside the dedicated timeout branch.');
$assert(!str_contains($resultOwner, 'sleep(') && !str_contains($resultOwner, 'setTimeout(() => { title'),
    'Result semantics must not depend on timing patches.');

fwrite(STDOUT, "PhaseBPreparationTimeoutResultOwnerContractTest: {$assertions} assertions passed\n");
