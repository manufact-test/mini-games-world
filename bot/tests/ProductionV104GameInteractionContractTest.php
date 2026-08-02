<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$read = static function (string $path) use ($root): string {
    $content = file_get_contents($root . '/' . $path);
    if (!is_string($content)) throw new RuntimeException('Cannot read v104 source: ' . $path);
    return $content;
};
$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

$entry = $read('app/assets/js/production-clean-entry-v104.js');
$main = $read('app/assets/js/main-v104.js');
$presenceClient = $read('app/assets/js/production-v104-presence.js');
$inviteControls = $read('app/assets/js/production-v104-invite-game-controls.js');
$ticTacToe = $read('app/assets/js/production-v104-tictactoe-stability.js');
$result = $read('app/assets/js/production-v104-result-instant.js');
$poll = $read('app/assets/js/production-v104-game-poll-tuning.js');
$presencePhp = $read('bot/presence.php');
$presenceService = $read('bot/services/PresenceService.php');
$stats = $read('bot/services/StatsService.php');
$php = $read('app/v104.php');
$welcome = $read('bot/helpers/UserWelcomeGuard.php');

$guardPosition = strpos($entry, 'initV104InviteGameControls();');
$sharePosition = strpos($entry, 'initV102ShareController();');
$assert(
    str_contains($entry, 'initV102HistoryController();')
        && str_contains($entry, 'initV102BattleshipBridge();')
        && str_contains($entry, 'initV103TargetedInteractions();')
        && $guardPosition !== false
        && $sharePosition !== false
        && $guardPosition < $sharePosition
        && str_contains($entry, 'initV104TicTacToeStability();')
        && str_contains($entry, 'initV104ResultInstant();')
        && !str_contains($entry, 'initV101ResultSpeed();'),
    'v104 must retain accepted owners, block passive invite entry before share prewarm and use one result owner.'
);

$assert(
    str_contains($main, "window.__MGW_BUILD__ = 'v104-mvp14-game-interaction-finalization'")
        && str_contains($main, "./screens/search-screen-v102.js?v=102")
        && str_contains($main, "./screens/game-screen-v102-safe.js?v=102")
        && !str_contains($main, 'game-screen-v99.js'),
    'v104 must preserve the accepted v102 search and game owners.'
);

$assert(
    str_contains($presenceClient, 'const HEARTBEAT_MS = 4000;')
        && str_contains($presenceClient, 'const STATUS_MS = 1200;')
        && str_contains($presenceClient, "window.addEventListener('pagehide'")
        && str_contains($presenceClient, 'navigator.sendBeacon')
        && str_contains($presenceClient, "requestPresence('leave')") === false
        && str_contains($presenceClient, "priority:'low'")
        && str_contains($presenceClient, 'mgwPrefetch:true'),
    'Presence must use per-device heartbeat, fast home-only status polling, a beacon exit signal and low-priority abortable reads.'
);

$assert(
    str_contains($presencePhp, "['status', 'ping', 'leave']")
        && str_contains($presencePhp, '$db->readOnly')
        && str_contains($presencePhp, '$presence->touch($accountId, $sessionId, $presenceLeaseId)')
        && str_contains($presencePhp, '$presence->leave($accountId, $sessionId, $presenceLeaseId)')
        && !str_contains($presencePhp, '$db->transaction')
        && !str_contains($presencePhp, '$data[\'users\']'),
    'Presence ping and leave must stay outside application JSON while status reads existing statistics only.'
);

$assert(
    !str_contains($presenceService, 'sys_get_temp_dir()')
        && str_contains($presenceService, "\$GLOBALS['config']['data_dir']")
        && str_contains($presenceService, "'.runtime'")
        && str_contains($presenceService, "'presence'")
        && str_contains($presenceService, '$leaseKey = $presenceLeaseId === \'\'')
        && str_contains($presenceService, "'session-' . hash('sha256', \$leaseKey) . '.presence")
        && str_contains($presenceService, 'private const ONLINE_WINDOW_SEC = 75;')
        && str_contains($presenceService, 'private const LEAVE_GRACE_SEC = 12;')
        && str_contains($presenceService, "'leave_after'")
        && str_contains($presenceService, 'readSessionState(')
        && !str_contains($presenceService, 'presence_sessions')
        && !str_contains($presenceService, "['last_seen_at']")
        && str_contains($stats, '$this->presence->onlineAccountIds()'),
    'Online counting must use one configured bounded runtime root, independent document leases and no users JSON fields.'
);

$assert(
    str_contains($inviteControls, "origin.closest('[data-invite-friend]')")
        && str_contains($inviteControls, "origin.closest('[data-open-player-picker]')")
        && str_contains($inviteControls, "origin.closest('[data-create-link-invite]')")
        && str_contains($inviteControls, "origin.closest('[data-direct-opponent]')")
        && str_contains($inviteControls, 'event.stopImmediatePropagation();')
        && str_contains($inviteControls, "inviteRequest('create_direct'")
        && str_contains($inviteControls, 'renderSendingSheet(context);')
        && str_contains($inviteControls, 'renderOwnerWaiting(inviteResult);'),
    'Passive devices must be blocked at every invite-creation entry and direct invites must show an immediate local transition.'
);

$assert(
    str_contains($inviteControls, 'speed?.gamePollControllers')
        && str_contains($inviteControls, 'speed?.backgroundControllers')
        && str_contains($inviteControls, "origin.closest('#confirmLeaveGame")
        && !str_contains($inviteControls, "#confirmLeaveGame, [data-create-link-invite]"),
    'Leave and invite mutations must abort competing reads without cancelling the prepared Telegram share request.'
);

$assert(
    str_contains($ticTacToe, 'runtime.pending = {')
        && str_contains($ticTacToe, "cell.textContent = label")
        && str_contains($ticTacToe, "['game_action','make_move']")
        && str_contains($ticTacToe, 'board[pending.cell] === pending.symbol')
        && str_contains($ticTacToe, 'MAX_PENDING_MS = 5000'),
    'A valid Tic Tac Toe mark must stay pinned until authoritative confirmation or error rollback.'
);

$assert(
    str_contains($poll, 'APP_CONFIG.gameIntervalMs = Math.min')
        && str_contains($poll, ', 500);')
        && !str_contains($poll, 'searchIntervalMs'),
    'Only active-game confirmation polling may be shortened to the balanced 500 ms cadence in v104.'
);

$assert(
    str_contains($result, "document.addEventListener('mgw:v101-finished-response'")
        && str_contains($result, 'data-result-game-id')
        && str_contains($result, 'openSheet(`')
        && !str_contains($result, 'setTimeout(')
        && !str_contains($result, 'requestAnimationFrame('),
    'The authoritative finished response must open one result sheet without an extra UI timer.'
);

$assert(
    str_contains($php, 'production-clean-entry-v104.js?v=104')
        && str_contains($php, 'main-v104.js?v=104')
        && str_contains($php, 'Cache-Control: no-store, no-cache, must-revalidate, max-age=0')
        && str_contains($welcome, '/app/v104.php?v=104'),
    'Only explicit rollback launches may activate v104.'
);

fwrite(STDOUT, "ProductionV104GameInteractionContractTest: {$assertions} assertions passed\n");
