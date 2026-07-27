<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$assertions = 0;

$read = static function (string $path) use ($root): string {
    $content = file_get_contents($root . '/' . $path);
    if (!is_string($content)) throw new RuntimeException('Cannot read v98 source: ' . $path);
    return $content;
};
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

$entry = $read('app/assets/js/production-regression-fix-entry-v98.js');
$transport = $read('app/assets/js/production-v98-passive-session-transport.js');
$ui = $read('app/assets/js/production-v98-ui-owner.js');
$game = $read('app/assets/js/screens/game-screen-v98.js');
$phpEntry = $read('app/v98.php');
$welcome = $read('bot/helpers/UserWelcomeGuard.php');

$sessionPosition = strpos($entry, 'initSessionOwnershipFix();');
$transportPosition = strpos($entry, 'initV98PassiveSessionTransport();');
$earlyPosition = strpos($entry, 'initV98UiOwnerEarly();');
$v97Position = strpos($entry, 'initProductionV97RuntimeOwner();');
$afterPosition = strpos($entry, 'initV98UiOwnerAfter();');
$assert(
    $sessionPosition !== false
        && $transportPosition > $sessionPosition
        && $earlyPosition > $transportPosition
        && $v97Position > $earlyPosition
        && $afterPosition > $v97Position,
    'V98 must scope identity, install passive transport and visible guards before reclaiming the v97 action queue.'
);

$assert(
    str_contains($transport, "'bootstrap'")
        && str_contains($transport, "'game_state'")
        && str_contains($transport, 'data.session = passiveSession(data.session);')
        && str_contains($transport, 'data.game = null;')
        && str_contains($transport, 'data.active_game = null;')
        && str_contains($transport, 'publishPendingGame(data.active_game, data.me || null, true);')
        && str_contains($transport, 'if (!window.__MGW_V98_PASSIVE_SESSION_LOCK__?.locked)')
        && !str_contains($transport, "PASSIVE_API_ACTIONS = new Set(['start_search'")
        && !str_contains($transport, "PASSIVE_API_ACTIONS = new Set(['game_action'"),
    'Only passive reads may hide a secondary-device lock; bootstrap must wait for readiness and invite sync must not reopen a locked match.'
);

$assert(
    str_contains($ui, "'Поиск отменён.'")
        && str_contains($ui, "element.classList.remove('show')")
        && str_contains($ui, "origin.closest('[data-open-player-picker]')")
        && str_contains($ui, "hold.className = 'sheet mgw-player-picker-hold'")
        && str_contains($ui, "sheet.querySelector('.invite-player-list')"),
    'Search cancellation must be silent and the prepared invite sheet must remain visible until the final player list exists.'
);

$assert(
    str_contains($ui, 'window.__MGW_V98_PASSIVE_SESSION_LOCK__')
        && str_contains($ui, 'event.stopImmediatePropagation();')
        && str_contains($ui, "button.matches('[data-invite-action=\"start\"]')")
        && str_contains($ui, 'window.__MGW_V97_START_GAME_POLLING__ = startGamePolling;'),
    'A passive lock must surface only on an explicit launch attempt and v97 must use the stable v98 polling bridge.'
);

$assert(
    str_contains($game, 'gameSurfaceFingerprint(game, me.id)')
        && strpos($game, 'surface.dataset.mgwV97Fingerprint') < strpos($game, 'surface.dataset.mgwV98Fingerprint')
        && str_contains($game, 'if (forceSurface || surfaceMissing || fingerprint !== renderedFingerprint)')
        && str_contains($game, 'renderGameSurface({')
        && str_contains($game, 'startLegacyGamePolling(id);'),
    'Active polling must prefer the latest optimistic fingerprint, avoid replacing an unchanged board and retain the reviewed result-sheet path.'
);

$startPosition = strpos($ui, 'startGamePolling(id);');
$showPosition = strpos($ui, "showScreen('game');", $startPosition === false ? 0 : $startPosition);
$assert(
    $startPosition !== false && $showPosition !== false && $startPosition < $showPosition,
    'The first complete game surface must be prepared before the game screen becomes visible.'
);

$assert(
    str_contains($phpEntry, 'production-regression-fix-entry-v98.js?v=98')
        && str_contains($phpEntry, 'Cache-Control: no-store, no-cache, must-revalidate, max-age=0')
        && str_contains($welcome, '/app/v100.php?v=100'),
    'The retained v98 fallback must remain no-store while Telegram advances to the v100 entrypoint.'
);

fwrite(STDOUT, "ProductionV98PassiveSessionUiContractTest: {$assertions} assertions passed\n");
