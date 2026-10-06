<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$read = static function (string $path) use ($root): string {
    $content = file_get_contents($root . '/' . $path);
    if (!is_string($content)) throw new RuntimeException('Cannot read Battleship ready-reset source: ' . $path);
    return $content;
};
$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

$service = $read('bot/games/battleship/BattleshipService.php');
$model = $read('app/assets/js/production-v102-battleship-models.js');
$renderer = $read('app/assets/js/games/battleship/renderer.js');
$v110 = $read('app/v110.php');
$manifest = require $root . '/app/runtime/client/version-manifest.php';
$imports = is_array($manifest['imports'] ?? null) ? $manifest['imports'] : [];

$assert(
    str_contains($service, 'private function reopenSetupAfterEdit(array &$game, string $userId): void')
        && str_contains($service, "\$game['battleship_fleets'][\$userId]['ready'] = false;")
        && str_contains($service, "\$game['battleship_fleets'][\$userId]['ready_at'] = null;")
        && substr_count($service, '$this->reopenSetupAfterEdit($game, $userId);') === 4,
    'Every successful Battleship setup edit must revoke authoritative ready state through one helper.'
);

$assert(
    !str_contains($service, "if (!empty(\$game['battleship_fleets'][\$userId]['ready'])) throw new RuntimeException('Флот уже подтверждён.');"),
    'Ready players must remain able to revise their fleet while the authoritative phase is still setup.'
);

$assert(
    str_contains($model, 'game.my_ready = false;')
        && str_contains($model, 'const readyGame = applyFleet(next, fleet);')
        && str_contains($model, 'readyGame.my_ready = true;'),
    'Optimistic fleet edits must immediately revoke ready, while the explicit ready action restores it.'
);

$assert(
    str_contains($renderer, '${complete && !game?.my_ready ? `')
        && str_contains($renderer, 'data-battleship-randomize')
        && str_contains($renderer, 'data-battleship-clear'),
    'The Ready callout must disappear only while my_ready is true without removing fleet revision controls.'
);

$assert(
    ($imports['./assets/js/production-v102-battleship-models.js?v=102'] ?? null)
        === './assets/js/production-v102-battleship-models.js?v=103&ready=authoritative-reset'
        && is_string($imports['./assets/js/games/battleship/renderer.js?v=56'] ?? null)
        && ($imports['./assets/js/games/battleship/renderer.js?v=56'] ?? '') !== ''
        && str_contains($v110, "\$battleshipRendererImportKey = './assets/js/games/battleship/renderer.js?v=56';")
        && str_contains($v110, "header('X-MGW-Battleship-Ready: authoritative-reset-after-edit');"),
    'Canonical Telegram v110 must publish the ready-reset model and consume the current Battleship renderer through the version manifest.'
);

fwrite(STDOUT, "ProductionV110BattleshipReadyResetContractTest: {$assertions} assertions passed.\n");
