<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$read = static function (string $path) use ($root): string {
    $content = file_get_contents($root . '/' . $path);
    if (!is_string($content)) throw new RuntimeException('Cannot read R12 source: ' . $path);
    return $content;
};
$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

$php = $read('app/v110.php');
$main = $read('app/assets/js/main-v110.js');
$shell = $read('app/assets/js/main-v110-handoff-shell.js');
$clean = $read('app/assets/js/production-clean-entry-v110.js');
$notifications = $read('app/assets/js/screens/notifications-screen-v110r12.js');
$terminal = $read('app/assets/js/games/invite-terminal-actions-v110r12.js');
$invites = $read('app/assets/js/games/game-invites-v110.js');
$opponents = $read('bot/invite-opponents.php');
$presenceClient = $read('app/assets/js/production-v110-presence.js');
$presenceService = $read('bot/services/PresenceService.php');
$lifecycle = $read('app/assets/js/production-v110-match-lifecycle.js');
$outbox = $read('bot/runtime/RuntimePrimaryProjectionOutboxWriter.php');
$atomic = $read('bot/runtime/ProductionPrimaryAtomicStorageAdapter.php');
$launch = $read('bot/helpers/WebAppLaunchUrl.php');
$welcome = $read('bot/helpers/UserWelcomeGuard.php');

$build = 'v110-mvp14r12-notification-invite-presence-stability';
$assert(str_contains($php, 'production-clean-entry-v110.js?v=1118')
    && str_contains($php, 'main-v110.js?v=1118')
    && str_contains($php, $build)
    && str_contains($main, $build)
    && str_contains($main, 'main-v110-handoff-shell.js?v=1118')
    && str_contains($shell, $build)
    && str_contains($clean, $build),
    'All final browser entry owners must publish one R12 build and v1118 cache boundary.');

$assert(str_contains($shell, 'notifications-screen-v110r12.js?v=1118')
    && substr_count($shell, 'initNotificationsScreen();') === 1
    && str_contains($notifications, 'data-notifications-owner="r12"')
    && str_contains($notifications, 'sheetState.pinned')
    && str_contains($notifications, 'LOCAL_AUTHORITY_MS = 12000')
    && str_contains($notifications, 'CLOSE_GUARD_MS = 1100')
    && str_contains($notifications, 'renderLoading();'),
    'R12 must have one notification owner with exact first-frame authority and no false empty state.');

$terminalPosition = strpos($shell, 'initInviteTerminalActions();');
$invitePosition = strpos($shell, 'initGameInvites();');
$assert(str_contains($shell, 'invite-terminal-actions-v110r12.js?v=1118')
    && $terminalPosition !== false
    && $invitePosition !== false
    && $terminalPosition < $invitePosition
    && str_contains($terminal, "new CustomEvent('mgw:notification-sync'")
    && str_contains($terminal, 'announce:false')
    && !str_contains($terminal, "mgw:invite-action-local-result")
    && !str_contains($terminal, 'closeSheet('),
    'Decline and cancel must update the same open card without a self toast or sheet close.');

$assert(str_contains($opponents, '$presence->onlineAccountIds()')
    && str_contains($opponents, "foreach (\$data['users'] ?? [] as \$candidateId => \$candidate)")
    && str_contains($opponents, 'array_slice($result, 0, 10)'),
    'The player picker must use shared presence plus bounded recent human users.');

$assert(str_contains($shell, 'production-v110-presence.js?v=1118')
    && str_contains($presenceClient, '// Presence transport starts before the profile bootstrap.')
    && str_contains($presenceClient, "  startPresence();\n}")
    && !str_contains($presenceClient, 'runtime.pingBusy || !runtime.appReady')
    && str_contains($presenceService, 'private const LEAVE_GRACE_SEC = 12;')
    && str_contains($presenceService, '$sessionId . "\\0presence:" . $presenceLeaseId'),
    'Presence must establish a unique document lease before bootstrap and preserve a bounded handoff grace.');

$assert(str_contains($invites, 'tg.shareMessage(preparedId')
    && str_contains($invites, "String(errorCode || '') === 'USER_DECLINED'")
    && str_contains($invites, 'restoreWarmShareDraft(attempt);')
    && str_contains($invites, 'function openFallbackShare(invite)')
    && str_contains($invites, 'https://t.me/share/url'),
    'Native editable Telegram share must remain primary while the explicit unsupported-device fallback stays isolated.');

$homePosition = strpos($lifecycle, "showScreen('home');");
$leavePosition = strpos($lifecycle, 'const result = await api.leaveGame(String(snapshot.id));');
$assert($homePosition !== false && $leavePosition !== false && $homePosition < $leavePosition
    && !str_contains($lifecycle, 'openSheet('),
    'The accepted immediate game-cancel return to home must remain unchanged.');

$assert(str_contains($outbox, 'COMPLETED_RETENTION_ROWS = 16')
    && str_contains($atomic, 'v3-production-atomic-bounded-outbox-parity')
    && str_contains($atomic, 'history_compacted')
    && str_contains($atomic, '$this->auditor->auditOnly('),
    'The recovered bounded outbox architecture must remain intact.');

$assert(str_contains($launch, "private const ENTRY_PATH = '/app/v110.php?v=1118';")
    && str_contains($welcome, "Active canonical path: '/app/v110.php?v=1118'."),
    'Every Telegram launch path must use the final v1118 production entrypoint.');

fwrite(STDOUT, "ProductionV110R12FinalIntegrationContractTest: {$assertions} assertions passed\n");
