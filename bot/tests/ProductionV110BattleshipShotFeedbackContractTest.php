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
        && str_contains($feedbackCss, 'animation:none;')
        && str_contains($feedbackCss, '.battleship-cell.mgw-pending-shot{')
        && str_contains($feedbackCss, 'transition:none;'),
    'Pending Battleship fire must appear as one stable marker without a separate ring/dot motion.'
);

$assert(
    str_contains($feedbackCss, 'animation:battleship-hit-impact-continuous .55s')
        && str_contains($feedbackCss, 'animation:battleship-sunk-impact-continuous .7s')
        && str_contains($feedbackCss, '0%{transform:scale(.985)')
        && !str_contains($feedbackCss, 'transform:scale(.55)')
        && !str_contains($feedbackCss, 'transform:scale(.5) rotate(-8deg)'),
    'Authoritative hit/sunk feedback must keep the original durations while removing the large bounce.'
);

$assert(
    str_contains($feedbackCss, 'animation:battleship-miss-dot-continuous .24s')
        && str_contains($feedbackCss, 'animation:battleship-hit-dot-continuous .55s'),
    'Authoritative miss/hit markers must resolve from the same pending marker geometry.'
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
    str_contains($renderer, "const delay = result === 'miss' ? 900 : 1250;"),
    'Battleship field handoff must retain the accepted 900ms miss / 1250ms hit+sunk timing.'
);

$assert(
    str_contains($mainCss, "@import url('./games/battleship/shot-feedback.css?v=59&ack=3&motion=original-duration');"),
    'Main CSS must publish the original-duration smooth Battleship shot feedback layer.'
);

$assert(
    str_contains($v110, 'main.css?v=151&sk=3&icons=c1efd5af&render=27&palette=notification-semantic&battleship=shot-smooth-original-duration')
        && str_contains($v110, 'games/battleship/renderer.js?v=59&shot=pending-ack-no-stale-repaint')
        && str_contains($v110, 'v110-mvp14-battleship-shot-smooth-original-duration-v1153')
        && str_contains($v110, 'X-MGW-Battleship-Shot-Feedback: immediate-single-marker-original-result-duration'),
    'Canonical Telegram v110 must publish the fresh original-duration shot feedback graph.'
);

fwrite(STDOUT, "ProductionV110BattleshipShotFeedbackContractTest: {$assertions} assertions passed\n");
