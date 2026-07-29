<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$read = static function (string $path) use ($root): string {
    $content = file_get_contents($root . '/' . $path);
    if (!is_string($content)) throw new RuntimeException('Cannot read v109 source: ' . $path);
    return $content;
};
$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

$entry = $read('app/assets/js/production-clean-entry-v109.js');
$main = $read('app/assets/js/main-v109.js');
$invite = $read('app/assets/js/production-v109-invite-speed.js');
$selfCancel = $read('app/assets/js/production-v109-self-cancel-refresh-guard.js');
$share = $read('app/assets/js/production-v109-share-speed.js');
$shareFallback = $read('app/assets/js/production-v109-share-fallback-guard.js');
$notifications = $read('app/assets/js/production-v109-notifications.js');
$presenceClient = $read('app/assets/js/production-v109-presence.js');
$presenceService = $read('bot/services/PresenceService.php');
$searchClient = $read('app/assets/js/production-v109-search-speed.js');
$searchEndpoint = $read('bot/search-speed.php');
$registry = $read('bot/runtime/ProductionPrimaryApplicationEntrypoints.php');
$php = $read('app/v109.php');
$welcome = $read('bot/helpers/UserWelcomeGuard.php');

$assert(
    str_contains($main, "import './main-v105.js?v=105';")
        && str_contains($main, "window.__MGW_BUILD__ = 'v109-mvp14-speed-ui-only'"),
    'v109 must retain the accepted v105 application and game graph.'
);

$assert(
    str_contains($entry, 'initV109InviteSpeed();')
        && str_contains($entry, 'initV109SelfCancelRefreshGuard();')
        && str_contains($entry, 'initV109ShareSpeed();')
        && str_contains($entry, 'initV109Notifications();')
        && str_contains($entry, 'initV109Presence();')
        && str_contains($entry, 'initV109SearchSpeed();')
        && !str_contains($entry, 'initV102ShareController')
        && !str_contains($entry, 'initV105FastNotifications')
        && !str_contains($entry, 'initV104Presence'),
    'v109 must replace only the identified slow UI owners.'
);

$assert(
    str_contains($invite, "window.addEventListener('click', ownInviteClick, true)")
        && strpos($invite, 'renderOptimisticOwnerSheet(context, opponentName);')
            < strpos($invite, "inviteRequest('create_direct'")
        && str_contains($invite, 'closeSheet();')
        && !str_contains($invite, "toast('Приглашение отменено.')")
        && str_contains($selfCancel, 'event.stopImmediatePropagation();'),
    'Direct invite and self-cancel must paint immediately without a duplicate self notification.'
);

$assert(
    str_contains($share, 'function warmContext(context)')
        && str_contains($share, 'const RETURN_RELEASE_MS = 450;')
        && str_contains($share, 'const CALLBACK_TIMEOUT_MS = 12000;')
        && str_contains($share, '.shareMessage(preparedId')
        && str_contains($share, "telegram.onEvent('activated', releaseReturnedNativeAttemptSoon)")
        && !str_contains($share, 'Ждём результата отправки')
        && !str_contains($share, 'showSharingSheet'),
    'Prepared Telegram sharing must be prewarmed and must not leave a blocking native-return state.'
);

$assert(
    str_contains($shareFallback, "origin.closest('[data-v109-discard-draft]')")
        && str_contains($shareFallback, 'closeSheet();')
        && str_contains($shareFallback, "action:'discard_draft'"),
    'Fallback share cancellation must close immediately and discard asynchronously.'
);

$assert(
    str_contains($notifications, 'speed?.rawFetch')
        && str_contains($notifications, 'replaceItems(snapshot.notificationItems)')
        && str_contains($notifications, 'const RETRY_DELAYS_MS = [0, 160, 420, 850];')
        && str_contains($notifications, 'const horizontalSwipe')
        && str_contains($notifications, 'const upwardSwipe')
        && !str_contains($notifications, 'translate3d('),
    'Notifications must bypass stale optimistic reads and never freely drag the toast.'
);

$assert(
    str_contains($presenceClient, "window.addEventListener('pagehide', sendLeaveBeacon")
        && str_contains($presenceClient, 'Deactivated means backgrounded, not offline')
        && !str_contains($presenceClient, "telegram.onEvent('deactivated'")
        && str_contains($presenceService, 'private const ONLINE_WINDOW_SEC = 75;'),
    'Two open Telegram accounts must remain online while backgrounding stays bounded.'
);

$assert(
    str_contains($searchClient, 'const SPEED_CHECK_MS = 2200;')
        && str_contains($searchClient, 'const RETRY_CHECK_MS = 900;')
        && str_contains($searchClient, 'const MAX_CHECKS = 3;')
        && str_contains($searchClient, 'scheduleAttempt(generation, attempt + 1, RETRY_CHECK_MS);')
        && str_contains($searchClient, '/bot/search-speed.php')
        && str_contains($searchEndpoint, "time() - 12")
        && str_contains($searchEndpoint, "(string)(\$item['room'] ?? 'match') !== 'match'")
        && str_contains($registry, "'bot/search-speed.php' => 'search_speed'"),
    'Bot fallback speed must retry a bounded guarded checkpoint without changing game actions.'
);

$assert(
    str_contains($php, 'production-clean-entry-v109.js?v=109')
        && str_contains($php, 'main-v109.js?v=109')
        && str_contains($php, 'Cache-Control: no-store, no-cache, must-revalidate, max-age=0')
        && str_contains($welcome, '/app/v110.php?v=110')
        && str_contains($welcome, 'v109'),
    'v109 must remain a valid no-store rollback build after the current launch advances to v110.'
);

fwrite(STDOUT, "ProductionV109SpeedUiOnlyContractTest: {$assertions} assertions passed\n");
