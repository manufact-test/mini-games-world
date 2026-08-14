<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$model = file_get_contents($root . '/app/assets/js/production-v100-optimistic-models.js');
$mainCss = file_get_contents($root . '/app/assets/css/main.css');
$feedbackCss = file_get_contents($root . '/app/assets/css/games/battleship/shot-feedback.css');
$v110 = file_get_contents($root . '/app/v110.php');
if (!is_string($model) || !is_string($mainCss) || !is_string($feedbackCss) || !is_string($v110)) {
    throw new RuntimeException('Cannot read Battleship shot feedback sources.');
}

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

$assert(
    str_contains($model, 'optimistic.pending_fire_cell = cell;')
        && str_contains($model, "className:'mgw-pending-shot'"),
    'Battleship shot acknowledgement must reuse the existing pending_fire_cell owner.'
);

$assert(
    str_contains($feedbackCss, '.battleship-cell.mgw-pending-shot i')
        && str_contains($feedbackCss, 'background:rgba(220,213,255,.98);')
        && str_contains($feedbackCss, 'animation:battleship-shot-ack .22s ease-out;'),
    'Pending Battleship fire must paint a neutral center acknowledgement immediately.'
);

$assert(
    !str_contains($feedbackCss, '.battleship-cell.mgw-pending-shot.miss')
        && !str_contains($feedbackCss, '.battleship-cell.mgw-pending-shot.hit')
        && !str_contains($feedbackCss, '.battleship-cell.mgw-pending-shot.sunk'),
    'Pending shot feedback must not guess the authoritative shot result.'
);

$assert(
    str_contains($mainCss, "@import url('./games/battleship/shot-feedback.css?v=57&ack=1');"),
    'Main CSS must publish the Battleship pending-shot feedback layer.'
);

$assert(
    str_contains($v110, 'main.css?v=149&sk=3&icons=c1efd5af&render=25&palette=notification-semantic&battleship=shot-ack')
        && str_contains($v110, 'v110-mvp14-battleship-shot-feedback-v1151')
        && str_contains($v110, 'X-MGW-Battleship-Shot-Feedback: immediate-pending-dot'),
    'Canonical Telegram v110 must publish the fresh Battleship shot feedback graph.'
);

fwrite(STDOUT, "ProductionV110BattleshipShotFeedbackContractTest: {$assertions} assertions passed\n");
