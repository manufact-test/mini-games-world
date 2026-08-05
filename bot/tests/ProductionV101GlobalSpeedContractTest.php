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
$poll = $read('app/assets/js/production-v101-poll-tuning.js');
$speed = $read('app/assets/js/production-v101-speed-runtime.js');
$dedupe = $read('app/assets/js/production-v101-invite-sync-dedupe.js');
$cacheSafety = $read('app/assets/js/production-v101-cache-safety.js');
$share = $read('app/assets/js/production-v101-share-controller.js');
$watch = $read('app/assets/js/production-v101-fast-invite-watch.js');
$result = $read('app/assets/js/production-v101-result-speed.js');
$phpEntry = $read('app/v101.php');
$welcome = $read('bot/helpers/UserWelcomeGuard.php');

$sessionPosition = strpos($entry, 'initSessionOwnershipFix();');
$transportPosition = strpos($entry, 'initV99SessionTransport();');
$pollPosition = strpos($entry, 'initV101PollTuning();');
$speedPosition = strpos($entry, 'initV101SpeedRuntime();');
$dedupePosition = strpos($entry, 'initV101InviteSyncDedupe();');
$cachePosition = strpos($entry, 'initV101CacheSafety();');
$assert(
    $sessionPosition !== false
        && $transportPosition > $sessionPosition
        && $pollPosition > $transportPosition
        && $speedPosition > $pollPosition
        && $dedupePosition > $speedPosition
        && $cachePosition > $dedupePosition
        && str_contains($entry, 'initV101ShareController();')
        && str_contains($entry, 'initV101FastInviteWatch();')
        && str_contains($entry, 'initV101ResultSpeed();'),
    'Identity and session protection must remain outside the ordered v101 performance layer.'
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
    str_contains($poll, 'APP_CONFIG.searchIntervalMs = Math.min')
        && str_contains($poll, ', 900);')
        && str_contains($poll, 'APP_CONFIG.gameIntervalMs = Math.min')
        && str_contains($poll, ', 800);')
        && !str_contains($poll, 'statsIntervalMs')
        && !str_contains($poll, 'gameAction'),
    'Shared polling cadence may be shortened without changing game actions, rules or background stats cadence.'
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
    str_contains($dedupe, "url.pathname.endsWith('/bot/invites.php')")
        && str_contains($dedupe, "String(body?.action || '') !== 'sync'")
        && str_contains($dedupe, 'runtime.inFlight.get(meta.key)')
        && str_contains($dedupe, 'responseFromSnapshot(await existing)')
        && !str_contains($dedupe, "action === 'accept'")
        && !str_contains($dedupe, "action === 'start'"),
    'Only identical concurrent invite sync reads may be deduplicated; mutations must remain independent.'
);

$assert(
    str_contains($cacheSafety, "id.startsWith('start') && id.endsWith('SearchBtn')")
        && str_contains($cacheSafety, "['accept','start'].includes(inviteAction)")
        && str_contains($cacheSafety, "document.addEventListener('mgw:v99-game-found'")
        && str_contains($cacheSafety, "id === 'storeCreateOrder'")
        && str_contains($cacheSafety, "controller.abort('cache-invalidated-by-state-change')")
        && !str_contains($cacheSafety, "[data-open-player-picker]")
        && !str_contains($cacheSafety, 'state.user ='),
    'Balance-changing flows must abort stale refreshes without cancelling the direct player picker prefetch.'
);

$assert(
    str_contains($share, "origin.closest('[data-invite-friend]')")
        && str_contains($share, "origin.closest('[data-open-player-picker]')")
        && str_contains($share, 'cancelWarmPreparation();')
        && str_contains($share, 'controller:new AbortController()')
        && str_contains($share, 'signal:entry.controller.signal')
        && str_contains($share, 'mgwPrefetch:Boolean(options.prefetch)')
        && str_contains($share, 'obtainDraft(context, key)')
        && str_contains($share, 'telegram.shareMessage(preparedId')
        && !str_contains($share, 'Готовим ссылку')
        && !str_contains($share, 'Ждём результата отправки')
        && !str_contains($share, 'notifications-loading')
        && !str_contains($share, '✈️'),
    'Share preparation must stay background-only, reusable and independently cancellable before the direct player picker.'
);

$assert(
    str_contains($watch, 'const BURST_DELAYS_MS = [280, 680, 1100];')
        && str_contains($watch, "document.addEventListener('mgw:game-dismissed', startFastBurst)")
        && str_contains($watch, "action:'sync'")
        && str_contains($watch, "new CustomEvent('mgw:notification-sync'")
        && !str_contains($watch, 'setInterval(')
        && !str_contains($watch, "showScreen('game')")
        && !str_contains($watch, 'enterGame('),
    'Rematch acceleration must use a bounded low-load burst and must never enter a game.'
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
        && str_contains($welcome, '/app/v104.php?v=104'),
    'The retained no-store v101 speed entrypoint must remain valid while Telegram advances to v104.'
);

fwrite(STDOUT, "ProductionV101GlobalSpeedContractTest: {$assertions} assertions passed\n");
