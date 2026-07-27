<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$assertions = 0;

$read = static function (string $path) use ($root): string {
    $content = file_get_contents($root . '/' . $path);
    if (!is_string($content)) throw new RuntimeException('Cannot read v96 source: ' . $path);
    return $content;
};

$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

$index = $read('app/index.html');
$entry = $read('app/assets/js/production-regression-fix-entry.js');
$session = $read('app/assets/js/production-session-ownership-fix.js');
$icons = $read('app/assets/js/production-deterministic-icons.js');
$coordinator = $read('app/assets/js/production-cross-game-coordinator.js');
$optimistic = $read('app/assets/js/production-cross-game-optimistic.js');
$main = $read('app/assets/js/main.js');
$admin = $read('bot/helpers/AdminPaymentRejectGuard.php');
$welcome = $read('bot/helpers/UserWelcomeGuard.php');

$entryPosition = strpos($index, 'production-regression-fix-entry.js?v=96');
$mainPosition = strpos($index, 'main.js?v=96');
$assert(
    $entryPosition !== false
        && $mainPosition !== false
        && $entryPosition < $mainPosition
        && str_contains($index, 'data-hotfix-build="v96-mvp14-root-cause-stabilization"'),
    'The retained v96 fallback entry must remain internally consistent.'
);

$sessionInit = strpos($entry, 'initSessionOwnershipFix();');
$stabilityInit = strpos($entry, 'initProductionUiStabilityFix();');
$assert(
    $sessionInit !== false
        && $stabilityInit !== false
        && $sessionInit < $stabilityInit
        && str_contains($entry, "window.__MGW_REGRESSION_BUILD__ = 'v96-mvp14-root-cause-stabilization'"),
    'Per-account session ownership must be installed before any fetch cache wrapper.'
);

$assert(
    str_contains($session, "const SCOPED_SESSION_PREFIX = 'mgw_device_session_id:user:'")
        && str_contains($session, 'rewriteSessionId(init, scopedSessionId)')
        && str_contains($session, "error === OWNERSHIP_ERROR")
        && str_contains($session, 'syncScopedSession(true)')
        && str_contains($session, 'Сессия этого устройства устарела.'),
    'A Telegram-user-scoped session must replace the global key and retry safe ownership collisions once.'
);

$assert(
    str_contains($icons, "icon.dataset.mgwSvgIcon === gameType && icon.querySelector('svg')")
        && str_contains($icons, 'icon.innerHTML = markup;'),
    'Game icons must trust an actual SVG node instead of a stale data marker.'
);

$assert(
    str_contains($coordinator, 'api.startSearch = coordinatedStartSearch;')
        && str_contains($coordinator, 'scheduleCrossGameCoordinatorAfterMain')
        && str_contains($coordinator, 'normalizeViewer(result?.me)')
        && str_contains($coordinator, 'runtime.queue.push(item);')
        && str_contains($coordinator, 'while (runtime.queue.length)')
        && str_contains($coordinator, 'onAction:nextAction => submitRenderedAction(game.id, nextAction)')
        && !str_contains($coordinator, 'onAction:() => null'),
    'The retained v96 fallback must keep its reviewed action queue contract.'
);

$assert(
    str_contains($optimistic, 'const continuations = checkersCaptureMoves(board, to, side, pending);')
        && str_contains($optimistic, 'optimistic.forced_piece = to;')
        && str_contains($optimistic, 'optimistic.turn = viewerId;')
        && str_contains($optimistic, 'pending.forEach(cell =>'),
    'Optimistic checkers must preserve and continue a multi-capture before passing the turn.'
);

$assert(
    str_contains($main, "window.__MGW_BUILD__ = 'v96-mvp14-root-cause-stabilization'")
        && str_contains($main, 'const firstInteraction = await warmFirstInteractionData().catch')
        && !str_contains($main, 'Не удалось подготовить данные интерфейса. Откройте приложение снова.'),
    'Optional first-click warmers must not fail an authenticated application boot.'
);

$assert(
    str_contains($admin, "str_contains(\$replyText, 'Отклонение пополнения')")
        && str_contains($admin, "str_contains(\$replyText, 'Отклонение заявки')")
        && str_contains($admin, "\$message['external_reply']")
        && str_contains($admin, "'deleteMessage'"),
    'All historical payment ForceReply variants must be recognized and cleaned safely.'
);

$assert(
    str_contains($welcome, "'/app/v99.php?v=99'")
        && !str_contains($welcome, "'/app/?v=85'"),
    'New bot start buttons must advance beyond the retained v96 fallback to the current cache-busted Mini App.'
);

fwrite(STDOUT, "ProductionV96RootCauseStabilizationTest: {$assertions} assertions passed\n");
