<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$model = file_get_contents($root . '/app/assets/js/production-v100-optimistic-models.js');
$renderer = file_get_contents($root . '/app/assets/js/games/battleship/renderer.js');
$mainCss = file_get_contents($root . '/app/assets/css/main.css');
$gameCss = file_get_contents($root . '/app/assets/css/games/battleship/game.css');
$v110 = file_get_contents($root . '/app/v110.php');
if (!is_string($model) || !is_string($renderer) || !is_string($mainCss) || !is_string($gameCss) || !is_string($v110)) {
    throw new RuntimeException('Cannot read Battleship authoritative shot feedback sources.');
}

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

$assert(
    str_contains($model, 'optimistic.pending_fire_cell = cell;')
        && str_contains($model, "className:'mgw-pending-shot'"),
    'Battleship must retain one technical pending-fire owner for queue/cell locking.'
);

$assert(
    !str_contains($mainCss, 'games/battleship/shot-feedback.css'),
    'Battleship pending-fire state must not publish a separate visual result-like layer.'
);

$assert(
    str_contains($gameCss, '.battleship-cell.interactive:active{transform:scale(.92)}'),
    'Battleship taps must retain immediate press feedback without drawing a fake shot result.'
);

$assert(
    str_contains($gameCss, '.battleship-cell.shot-impact.hit{animation:battleship-hit-impact .55s ease-out}')
        && str_contains($gameCss, '.battleship-cell.shot-impact.sunk{animation:battleship-sunk-impact .7s ease-out}'),
    'My shots and opponent shots must share the canonical authoritative result animation layer.'
);

$fireHandler = <<<'JS'
container.querySelectorAll('[data-battleship-cell][data-cell-state="unknown"]').forEach(button => button.addEventListener('click', () => {
      clearBattleTransitionTimer();
      onAction?.({ type:'fire', cell:Number(button.dataset.battleshipCell) });
JS;
$assert(
    str_contains($renderer, $fireHandler),
    'A newly accepted Battleship shot must still cancel the previous result presentation timer before dispatch.'
);

$assert(
    str_contains($renderer, "const delay = result === 'miss' ? 900 : 1250;"),
    'Battleship field handoff must retain the accepted 900ms miss / 1250ms hit+sunk timing.'
);

$assert(
    str_contains($v110, 'main.css?v=152&sk=3&icons=c1efd5af&render=28&palette=notification-semantic&battleship=authoritative-shot-only')
        && str_contains($v110, 'games/battleship/renderer.js?v=59&shot=pending-ack-no-stale-repaint')
        && str_contains($v110, 'v110-mvp14-battleship-authoritative-shot-only-v1154')
        && str_contains($v110, 'X-MGW-Battleship-Shot-Feedback: authoritative-result-only'),
    'Canonical Telegram v110 must publish the authoritative-only Battleship shot presentation graph.'
);

fwrite(STDOUT, "ProductionV110BattleshipShotFeedbackContractTest: {$assertions} assertions passed\n");
