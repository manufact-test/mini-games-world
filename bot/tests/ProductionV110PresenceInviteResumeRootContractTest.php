<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$read = static function (string $path) use ($root): string {
    $content = file_get_contents($root . '/' . $path);
    if (!is_string($content)) throw new RuntimeException('Cannot read current source: ' . $path);
    return $content;
};
$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

$main = $read('app/assets/js/main-v110-handoff-shell.js');
$presence = $read('app/assets/js/production-v110-presence.js');
$stats = $read('app/assets/js/stats-owner-v110.js');
$invites = $read('app/assets/js/games/game-invites-v110.js');
$home = $read('app/assets/js/screens/home-screen.js');
$notifications = $read('app/assets/js/screens/notifications-screen-v110r12.js');
$auth = $read('bot/services/AuthService.php');
$api = $read('bot/api.php');
$presenceService = $read('bot/services/PresenceService.php');
$inviteStorage = $read('bot/services/invites/GameInviteStorageTrait.php');
$notificationEndpoint = $read('bot/notifications.php');
$php = $read('app/v110.php');

$assert(str_contains($main, "stats-owner-v110.js?v=1119")
    && str_contains($main, 'beginStatsRequest()')
    && str_contains($main, 'applyStatsSnapshot(statsTicket, result.stats)')
    && !str_contains($main, 'state.stats = result.stats'),
    'All home statistics responses must be ordered by one canonical stats owner.');

$assert(str_contains($stats, 'sequence < runtime.applied')
    && str_contains($stats, 'next.online_players = stableOnlineCount(next.online_players)')
    && str_contains($stats, 'state.stats = next')
    && str_contains($stats, 'renderStats(state.stats)'),
    'A stale request must never overwrite a newer visible statistics snapshot.');

$assert(str_contains($presence, "document.addEventListener('mgw:app-ready'")
    && str_contains($presence, "window.addEventListener('pageshow'")
    && str_contains($presence, 'cancelInFlightRequests()')
    && str_contains($presence, 'REQUEST_TIMEOUT_MS = 4500')
    && str_contains($presence, 'new AbortController()'),
    'Mobile resume must cancel suspended requests and start a fresh bounded presence request.');

$assert(str_contains($presence, '// Presence transport starts before the profile bootstrap.')
    && str_contains($presence, "  startPresence();\n}")
    && str_contains($presence, "if (runtime.pingBusy || document.visibilityState !== 'visible') return false;")
    && !str_contains($presence, 'runtime.pingBusy || !runtime.appReady')
    && str_contains($presence, "if (!runtime.appReady || document.visibilityState !== 'visible') return false;"),
    'The document lease must start before bootstrap while visible status rendering stays gated by app readiness.');

$assert(!str_contains($auth, 'touchAuthenticatedPresence')
    && str_contains($api, "\$action === 'bootstrap'")
    && str_contains($api, '$presenceService->touch('),
    'Generic authentication must not resurrect a leaving session; bootstrap remains the application-state owner.');

$assert(str_contains($presenceService, 'LEAVE_GRACE_SEC = 12')
    && str_contains($presenceService, "'leave_after'")
    && str_contains($presenceService, 'readSessionState(')
    && str_contains($presenceService, '$sessionId . "\\0presence:" . $presenceLeaseId'),
    'Explicit leave must use one bounded document handoff lease instead of an immediate delete race.');

$assert(str_contains($invites, 'function hasActionableInvite()')
    && str_contains($invites, "['pending', 'accepted']")
    && str_contains($invites, 'mgw:before-game-launch')
    && str_contains($home, "new CustomEvent('mgw:before-game-launch'"),
    'Any game launch during an actionable invitation must reopen the canonical invitation actions.');

$assert(str_contains($notifications, 'sheetState.generation')
    && str_contains($notifications, 'isCurrentSheet(generation)')
    && str_contains($notifications, 'sheetState.pinned')
    && str_contains($notifications, 'data-notifications-owner="r12"'),
    'Late notification responses must update the current owner only and never reopen a closed sheet.');

$assert(!str_contains($inviteStorage, "'Срок приглашения истёк'")
    && !str_contains($inviteStorage, "'Время ожидания истекло'")
    && str_contains($inviteStorage, "['invite_expired', 'invite_timed_out']")
    && str_contains($notificationEndpoint, "['invite_expired', 'invite_timed_out']"),
    'Passive expiration and timeout must stay notification-free and hidden from existing history.');

$assert(str_contains($php, 'production-clean-entry-v110.js?v=1119')
    && str_contains($php, 'main-v110.js?v=1119')
    && str_contains($php, 'v110-mvp14r12-invite-notification-stability'),
    'The integrated presence task must use the final production entrypoint.');

fwrite(STDOUT, "ProductionV110PresenceInviteResumeRootContractTest: {$assertions} assertions passed\n");
