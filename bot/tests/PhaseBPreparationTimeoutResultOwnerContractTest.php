<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$gamePath = 'app/assets/js/screens/game-screen-v102.js';
$game = file_get_contents($root . '/' . $gamePath);
$fingerprint = file_get_contents($root . '/bot/helpers/staging-e2e-runtime-files.txt');
$manifest = require $root . '/app/runtime/client/version-manifest.php';
$locale = json_decode((string)file_get_contents($root . '/app/locales/ru.json'), true, 512, JSON_THROW_ON_ERROR);
if (!is_string($game) || !is_string($fingerprint) || !is_array($manifest) || !is_array($locale)) {
    throw new RuntimeException('Phase B result-owner sources unavailable.');
}

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

$target = (string)(($manifest['imports'] ?? [])['./assets/js/screens/game-screen-v102.js?v=102'] ?? '');
$assert(
    str_contains($target, 'clock=phase-b-single-writer')
        && str_contains($target, 'mvp27_1=game-screen-localized-v1'),
    'Canonical manifest must preserve Phase-B Result ownership and publish the localized Game Screen owner.'
);
$assert(str_contains($fingerprint, $gamePath), 'Canonical Game Screen result owner must remain in exact staging fingerprint coverage.');

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
$assert(
    str_contains($resultOwner, "title = gameText('result.not_started_title');")
        && str_contains($resultOwner, "text = gameText('result.not_started_text');"),
    'Preparation timeout must resolve its title and explanation through canonical localization.'
);
$assert(
    (($locale['game_screen']['result']['not_started_title'] ?? null) === 'Матч не начался')
        && (($locale['game_screen']['result']['not_started_text'] ?? null) === 'Соперник не подключился вовремя. Ставка возвращена на баланс.'),
    'Accepted RU preparation-timeout copy must remain unchanged in the canonical catalog.'
);
$assert(substr_count($game, "game.finish_reason === 'preparation_timeout'") === 1,
    'Preparation-timeout result semantics must have exactly one client owner.');
$assert(
    str_contains($resultOwner, "let title = gameText('result.draw_title');")
        && str_contains($resultOwner, "let text = chessDrawText(game) || gameText('result.draw_text');"),
    'Ordinary draw semantics must remain the localized default outside the dedicated timeout branch.'
);
$assert(
    (($locale['game_screen']['result']['draw_title'] ?? null) === 'Ничья')
        && (($locale['game_screen']['result']['draw_text'] ?? null) === 'Матч завершён вничью.'),
    'Accepted RU ordinary-draw copy must remain unchanged in the canonical catalog.'
);
$assert(!str_contains($resultOwner, 'sleep(') && !str_contains($resultOwner, 'setTimeout(() => { title'),
    'Result semantics must not depend on timing patches.');

fwrite(STDOUT, "PhaseBPreparationTimeoutResultOwnerContractTest: {$assertions} assertions passed\n");
