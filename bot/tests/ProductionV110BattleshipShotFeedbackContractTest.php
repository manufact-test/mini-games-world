<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$model = file_get_contents($root . '/app/assets/js/production-v100-optimistic-models.js');
$renderer = file_get_contents($root . '/app/assets/js/games/battleship/renderer.js');
$mainCss = file_get_contents($root . '/app/assets/css/main.css');
$feedbackCss = file_get_contents($root . '/app/assets/css/games/battleship/shot-feedback.css');
$v110 = file_get_contents($root . '/app/v110.php');
if (!is_string($model) || !is_string($renderer) || !is_string($mainCss) || !is_string($feedbackCss) || !is_string($v110)) {
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
        && str_contains($feedbackCss, 'transform:scale(1);')
        && str_contains($feedbackCss, 'animation:none;'),
    'Pending Battleship fire must be immediately visible and stable instead of starting a second scale animation.'
);

$assert(
    str_contains($feedbackCss, '.battleship-cell.shot-impact.miss i')
        && str_contains($feedbackCss, 'animation:battleship-shot-resolve-miss .18s')
        && str_contains($feedbackCss, '.battleship-cell.shot-impact.hit i')
        && str_contains($feedbackCss, 'animation:battleship-shot-resolve-hit .22s'),
    'Authoritative miss and hit states must resolve smoothly from the stable pending marker.'
);

$assert(
    str_contains($feedbackCss, '@keyframes battleship-hit-impact-smooth')
        && str_contains($feedbackCss, '0%{transform:scale(.97)')
        && !str_contains($feedbackCss, 'animation:battleship-hit-impact .55s ease-out')
        && !str_contains($feedbackCss, 'transform:scale(.55)'),
    'Battleship hit feedback must not reuse the old large bounce animation.'
);

$assert(
    !str_contains($feedbackCss, '.battleship-cell.mgw-pending-shot.miss')
        && !str_contains($feedbackCss, '.battleship-cell.mgw-pending-shot.hit')
        && !str_contains($feedbackCss, '.battleship-cell.mgw-pending-shot.sunk'),
    'Pending shot feedback must not guess the authoritative shot result.'
);

$fireHandler = <<<'JS'
container.querySelectorAll('[data-battleship-cell][data-cell-state="unknown"]').forEach(button => button.addEventListener('click', () => {
      clearBattleTransitionTimer();
      onAction?.({ type:'fire', cell:Number(button.dataset.battleshipCell) });
JS;
$assert(
    str_contains($renderer, $fireHandler),
    'A newly accepted Battleship shot must cancel the previous result presentation timer before dispatch.'
);

$assert(
    str_contains($mainCss, "@import url('./games/battleship/shot-feedback.css?v=58&ack=2&motion=smooth');"),
    'Main CSS must publish the smooth Battleship shot feedback layer.'
);

$assert(
    str_contains($v110, 'main.css?v=150&sk=3&icons=c1efd5af&render=26&palette=notification-semantic&battleship=shot-smooth')
        && str_contains($v110, 'games/battleship/renderer.js?v=59&shot=pending-ack-no-stale-repaint')
        && str_contains($v110, 'v110-mvp14-battleship-shot-smooth-v1152')
        && str_contains($v110, 'X-MGW-Battleship-Shot-Feedback: immediate-pending-dot-smooth-authoritative-resolve'),
    'Canonical Telegram v110 must publish the fresh smooth Battleship shot feedback graph.'
);

fwrite(STDOUT, "ProductionV110BattleshipShotFeedbackContractTest: {$assertions} assertions passed\n");
