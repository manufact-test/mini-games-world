<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$read = static function (string $path) use ($root): string {
    $content = file_get_contents($root . '/' . $path);
    if (!is_string($content)) throw new RuntimeException('Cannot read Battleship root-cause source: ' . $path);
    return $content;
};
$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

$entry = $read('app/assets/js/production-clean-entry-v110.js');
$gateway = $read('app/assets/js/production-v100-optimistic-models.js');
$bridge = $read('app/assets/js/production-v102-battleship-bridge.js');
$game = $read('app/assets/js/screens/game-screen-v102.js');
$lifecycle = $read('app/assets/js/production-v110-match-lifecycle.js');
$gameCss = $read('app/assets/css/screens/game.css');
$mainCss = $read('app/assets/css/main.css');
$v110 = $read('app/v110.php');
$battleship = $read('bot/games/battleship/BattleshipService.php');

$assert(
    str_contains($entry, 'initV102BattleshipBridge();')
        && str_contains($entry, "production-v110-match-lifecycle.js?v=1106&release=battleship-action-quarantine"),
    'Canonical v110 must register the existing Battleship setup owner and publish the quarantined leave lifecycle.'
);

$assert(
    str_contains($gateway, 'registeredBattleshipSetupBuilder()')
        && str_contains($gateway, 'window.__MGW_V102_BUILD_BATTLESHIP_SETUP__')
        && !str_contains($gateway, '__MGW_REGRESSION_BUILD__')
        && str_contains($bridge, 'window.__MGW_V102_BUILD_BATTLESHIP_SETUP__ = buildV102BattleshipSetupOptimistic;'),
    'Battleship setup speed must depend on explicit owner registration, never on a historical build-name string.'
);

$assert(
    str_contains($game, "const localBattleshipSetup = type === 'battleship' && String(base?.phase || '') === 'setup';")
        && str_contains($game, 'if (localBattleshipSetup && !optimistic) return;')
        && str_contains($game, 'if (item.surrenderPending) {')
        && str_contains($game, 'item.queue.length = 0;')
        && str_contains($game, "if (!window.__MGW_V110_MATCH_LIFECYCLE__?.initialized)"),
    'Invalid local setup actions must not poison the network queue and v110 must remain the single confirm-leave owner.'
);

$quarantine = strpos($lifecycle, 'quarantineGameActions(snapshot.id);');
$leaveRequest = strpos($lifecycle, 'const result = await api.leaveGame(String(snapshot.id));');
$home = strpos($lifecycle, "showScreen('home');");
$assert(
    $quarantine !== false
        && $leaveRequest !== false
        && $home !== false
        && $quarantine < $leaveRequest
        && $home < $leaveRequest
        && str_contains($lifecycle, 'item.surrenderPending = true;')
        && str_contains($lifecycle, 'if (Array.isArray(item.queue)) item.queue.length = 0;')
        && str_contains($lifecycle, 'retireGameRuntime(snapshot.id);'),
    'Manual leave must quarantine pending game actions and leave the playable surface before waiting for the server.'
);

$assert(
    str_contains($gameCss, 'flex:0 0 80px;width:80px;min-width:80px')
        && str_contains($gameCss, 'padding:7px 13px 7px 9px;border-radius:13px;text-align:right')
        && !str_contains($gameCss, '[data-game-type="battleship"] .timer')
        && str_contains($mainCss, "./screens/game.css?v=61&timer=shared-frame"),
    'Battleship must inherit the accepted shared 80px timer frame and 13px right anchor.'
);

$assert(
    str_contains($v110, 'production-v100-optimistic-models.js?v=104&clock=ttt-fresh60&battleship=registered-owner')
        && str_contains($v110, 'game-screen-v102.js?v=104&clock=phase-b-single-writer&battleship=leave-guard')
        && str_contains($v110, 'production-clean-entry-v110.js?v=1124&clock=single-writer&release=battleship-action-quarantine')
        && str_contains($v110, 'main.css?v=147&sk=3&icons=c1efd5af&render=23&palette=notification-semantic')
        && str_contains($v110, 'X-MGW-Battleship-Setup: v102-registered-optimistic-owner')
        && str_contains($v110, 'X-MGW-Battleship-Leave: v110-action-quarantine')
        && str_contains($v110, 'X-MGW-Game-Timer-Frame: shared-80px-13px'),
    'Canonical Telegram v110 must publish every changed Battleship owner and the shared timer frame through fresh immutable identities.'
);

$assert(
    str_contains($battleship, "\$this->settlement->finish(\$db, \$game, \$winnerId, 'player_left', \$userId);")
        && str_contains($battleship, "if ((\$game['status'] ?? '') === 'finished') return \$game;"),
    'Battleship surrender must keep the existing authoritative server settlement owner.'
);

fwrite(STDOUT, "ProductionV110BattleshipRootCauseContractTest: {$assertions} assertions passed\n");
