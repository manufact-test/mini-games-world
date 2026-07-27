<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$assertions = 0;
$read = static function (string $path) use ($root): string {
    $content = file_get_contents($root . '/' . $path);
    if (!is_string($content)) throw new RuntimeException('Cannot read v101 source: ' . $path);
    return $content;
};
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

$entry = $read('app/assets/js/production-clean-entry-v101.js');
$main = $read('app/assets/js/main-v101.js');
$speed = $read('app/assets/js/production-v101-speed-runtime.js');
$cacheSafety = $read('app/assets/js/production-v101-cache-safety.js');
$share = $read('app/assets/js/production-v101-share-controller.js');
$watch = $read('app/assets/js/production-v101-fast-invite-watch.js');
$result = $read('app/assets/js/production-v101-result-speed.js');
$phpEntry = $read('app/v101.php');
$welcome = $read('bot/helpers/UserWelcomeGuard.php');

$sessionPosition = strpos($entry, 'initSessionOwnershipFix();');
$transportPosition = strpos($entry, 'initV99SessionTransport();');
$speedPosition = strpos($entry, 'initV101SpeedRuntime();');
$cachePosition = strpos($entry, 'initV101CacheSafety();');
$assert(
    $sessionPosition !== false
        && $transportPosition > $sessionPosition
        && $speedPosition > $transportPosition
        && $cachePosition > $speedPosition
        && str_contains($entry, 'initV101ShareController();')
        && str_contains($entry, 'initV101FastInviteWatch();')
        && str_contains($entry, 'initV101ResultSpeed();'),
    'Identity and session protection must remain outside the v101 performance layer.'
);

$assert(
    str_contains($main, "./screens/search-screen-v100.js?v=100")
        && str_contains($main, "./screens/game-screen-v100-safe.js?v=100")
        && str_contains($main, "window.__MGW_BUILD__ = 'v101-mvp14-global-speed'")
        && !str_contains($main, 'game-screen-v99.js')
        && !str_contains($main, 'production-cross-game-coordinator'),
    'The speed release must preserve the reviewed v100 search/game owner without restoring historical owners.'
);

$assert(
    str_contains($speed, 'window.fetch = acceleratedFetch;')
        && str_contains($speed, 'abortTracked(runtime.gamePollControllers);')
        && str_contains($speed, 'abortTracked(runtime.backgroundControllers);')
        && str_contains($speed, "document.dispatchEvent(new CustomEvent('mgw:v101-finished-response'")
        && str_contains($speed, 'seedBootstrapCaches(meta.scope, data)')
        && str_contains($speed, 'optimisticNotificationRead')
        && str_contains($speed, 'schedulePassivePrefetch'),
    'One global request layer must prioritize actions, abort stale reads, seed cache and publish finished server responses.'
);

$assert(
    str_contains($speed, "['game_action','make_move']")
        && str_contains($speed, "['stats','profile','weekly_match_status','shop_status']")
        && !str_contains($speed, 'winner_id =')
        && !str_contains($speed, 'finish_reason ='),
    'The speed layer may schedule reads but must never predict winners or mutate game completion rules.'
);

$assert(
    str_contains($cacheSafety, "id.startsWith('start') && id.endsWith('SearchBtn')")
        && str_contains($cacheSafety, "['accept','start'].includes(inviteAction)")
        && str_contains($cacheSafety, "document.addEventListener('mgw:v99-game-found'")
        && str_contains($cacheSafety, "id === 'storeCreateOrder'")
        && !str_contains($cacheSafety, 'state.user ='),
    'Balance-changing flows must invalidate passive caches without becoming another state owner.'
);

$assert(
    str_contains($share, "origin.closest('[data-invite-friend]')")
        && str_contains($share, 'warmContext(defaultContext')
        && str_contains($share, 'obtainDraft(context, key)')
        && str_contains($share, 'telegram.shareMessage(preparedId')
        && !str_contains($share, 'Готовим ссылку')
        && !str_contains($share, 'Ждём результата отправки')
        && !str_contains($share, 'notifications-loading')
        && !str_contains($share, '✈️'),
    'Telegram prepared messages must warm before the share click without restoring the old blocking sheet.'
);

$assert(
    str_contains($watch, 'const FAST_INTERVAL_MS = 350;')
        && str_contains($watch, "document.addEventListener('mgw:game-dismissed'")
        && str_contains($watch, "action:'sync'")
        && str_contains($watch, "new CustomEvent('mgw:notification-sync'")
        && !str_contains($watch, "showScreen('game')")
        && !str_contains($watch, 'enterGame('),
    'The narrow rematch watch may accelerate notification delivery but must never enter a game.'
);

$assert(
    str_contains($result, "String(game.status || '') !== 'finished'")
        && str_contains($result, 'runtime.resultOpened.add(id);')
        && str_contains($result, "new CustomEvent('mgw:game-finished'")
        && str_contains($result, "new CustomEvent('mgw:v100-search-request'")
        && !str_contains($result, 'calculateWinner')
        && !str_contains($result, 'detectWinner'),
    'The fast result sheet must use only a server-confirmed finished game and preserve existing follow-up events.'
);

$assert(
    str_contains($phpEntry, 'production-clean-entry-v101.js?v=101')
        && str_contains($phpEntry, 'main-v101.js?v=101')
        && str_contains($phpEntry, 'Cache-Control: no-store, no-cache, must-revalidate, max-age=0')
        && str_contains($welcome, '/app/v101.php?v=101'),
    'New Telegram launches must use the no-store v101 speed entrypoint.'
);

fwrite(STDOUT, "ProductionV101GlobalSpeedContractTest: {$assertions} assertions passed\n");
