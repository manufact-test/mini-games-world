<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);

$read = static function (string $relative) use ($root): string {
    $source = file_get_contents($root . '/' . $relative);
    if (!is_string($source)) {
        throw new RuntimeException('Cannot read regression source: ' . $relative);
    }
    return $source;
};

$assertions = 0;
$assertContains = static function (string $needle, string $haystack, string $message) use (&$assertions): void {
    $assertions++;
    if (!str_contains($haystack, $needle)) {
        throw new RuntimeException($message . ': missing ' . $needle);
    }
};
$assertNotContains = static function (string $needle, string $haystack, string $message) use (&$assertions): void {
    $assertions++;
    if (str_contains($haystack, $needle)) {
        throw new RuntimeException($message . ': unexpected ' . $needle);
    }
};
$assertTrue = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};
$assertSame = static function (mixed $expected, mixed $actual, string $message) use (&$assertions): void {
    $assertions++;
    if ($expected !== $actual) {
        throw new RuntimeException($message . ': expected ' . var_export($expected, true)
            . ', got ' . var_export($actual, true));
    }
};

$launch = $read('bot/helpers/WebAppLaunchUrl.php');
$manifest = $read('app/runtime/client/version-manifest.php');
$cleanEntry = $read('app/assets/js/production-clean-entry-v110.js');
$invites = $read('app/assets/js/games/game-invites-v110.js');
$inviteEndpoint = $read('bot/invites.php');

$assertContains(
    "private const ENTRY_PATH = '/app/v110.php?v=1127';",
    $launch,
    'MVP-18.7 must exercise the accepted Telegram launch owner'
);
$assertContains(
    "'@mgw/main' => './assets/js/main-v110-reconnect-v174.js?v=2'",
    $manifest,
    'MVP-18.7 must retain the accepted reconnect wrapper'
);
$assertContains(
    "'./assets/js/games/game-invites-v110.js?v=1142&zone=unified&rematch=optimistic&terminal=self-silent' => './assets/js/games/game-invites-v110.js?v=1144&zone=unified&rematch=optimistic&terminal=self-silent&social=1&share=telegram-native'",
    $manifest,
    'MVP-18.7 must route the frozen wrapper specifier to the current Telegram-native invite owner'
);
$assertContains(
    "'./assets/js/games/game-invites-v110.js?v=1143&zone=unified&rematch=optimistic&terminal=self-silent&social=1' => './assets/js/games/game-invites-v110.js?v=1144&zone=unified&rematch=optimistic&terminal=self-silent&social=1&share=telegram-native'",
    $manifest,
    'Friends and the wrapper must converge on one invite owner identity'
);
$assertContains(
    "initV101InviteSyncDedupe();",
    $cleanEntry,
    'The accepted duplicate-sync owner must remain in the active v110 graph'
);
$assertContains(
    "initV110AcceptanceRuntime();",
    $cleanEntry,
    'The accepted v110 runtime must remain in the active graph'
);

$assertContains(
    "tg.onEvent('shareMessageSent', () => settleNativeShare(true));",
    $invites,
    'Telegram successful native-share callback must settle through the invite owner'
);
$assertContains(
    "tg.onEvent('shareMessageFailed', event => {",
    $invites,
    'Telegram failed native-share callback must remain handled'
);
$assertContains(
    "tg.shareMessage(preparedId, result => {",
    $invites,
    'Prepared Telegram share must retain callback-backed settlement'
);
$assertContains(
    "settleNativeShare(Boolean(result), result === false ? 'USER_DECLINED' : '', attempt);",
    $invites,
    'Native callback result must be normalized by the canonical settlement owner'
);
$assertContains(
    "if (sent === true) {\n    void confirmSharedInvite(attempt);",
    $invites,
    'Only a successful native share may confirm the public invite'
);
$assertContains(
    "inviteRequest('confirm_shared', { token:String(attempt.invite?.token || '') });",
    $invites,
    'Successful Telegram share must reuse the existing confirm_shared action'
);
$assertContains(
    "restoreWarmShareDraft(attempt);",
    $invites,
    'User-declined native share must remain recoverable without activating the draft'
);
$assertContains(
    "void discardDraft(attempt.invite);",
    $invites,
    'Terminal native share failure must retire the prepared draft through the existing owner'
);
$assertContains(
    "const SHARE_CALLBACK_TIMEOUT_MS = 12000;",
    $invites,
    'The existing bounded native callback timeout must remain explicit'
);

$assertContains(
    "function mgw_invite_share_url(array $config, string $token): string",
    $inviteEndpoint,
    'Public universal invite URL must remain a separate stable owner'
);
$assertContains(
    "return $baseUrl . '/invite/' . rawurlencode($normalizedToken);",
    $inviteEndpoint,
    'Copy/external invite URL must remain the public /invite/TOKEN route'
);
$assertContains(
    "function mgw_invite_telegram_open_url(array $config, string $token): string",
    $inviteEndpoint,
    'Telegram share transport must have an explicit Telegram-native URL builder'
);
$assertContains(
    "'?start=invite_' . rawurlencode($normalizedToken)",
    $inviteEndpoint,
    'Telegram-native invite must use the existing /start invite_TOKEN ingress'
);
$assertContains(
    "['text' => '🎮 Открыть приглашение', 'url' => $telegramOpenUrl]",
    $inviteEndpoint,
    'Prepared Telegram message must open the Telegram-native ingress'
);
$assertContains(
    "$result['invite']['share_url'] = $shareUrl;",
    $inviteEndpoint,
    'Public share URL must stay available for explicit copy'
);
$assertContains(
    "$result['invite']['telegram_open_url'] = $telegramOpenUrl;",
    $inviteEndpoint,
    'Telegram-native open URL must be projected separately from the public URL'
);
$assertContains(
    "const telegramOpenUrl = String(invite.telegram_open_url || publicShareUrl);",
    $invites,
    'Fallback Telegram share must prefer the Telegram-native URL'
);
$assertContains(
    "copyInviteLink(shareUrl)",
    $invites,
    'Explicit copy action must continue copying the public universal URL'
);

$assertNotContains(
    'leaveSearch(',
    $inviteEndpoint,
    'Invite endpoint must not silently cancel public matchmaking search'
);

// Exercise reconnect behavior directly instead of importing the full MVP-17.7
// reliability fixture, whose historical exact migration-count assertion is no
// longer valid after later additive migrations.
require_once $root . '/bot/services/PresenceService.php';
require_once $root . '/bot/services/ReconnectLifecycleService.php';
if (!function_exists('now_iso')) {
    function now_iso(): string { return gmdate('c'); }
}
if (!function_exists('make_id')) {
    function make_id(string $prefix = 'id'): string {
        static $sequence = 0;
        $sequence++;
        return $prefix . '_mvp18_7_' . $sequence;
    }
}

$presenceDir = sys_get_temp_dir() . '/mgw-mvp18-7-reconnect-' . bin2hex(random_bytes(6));
$presence = new PresenceService($presenceDir);
$reconnect = new ReconnectLifecycleService(['commission_rate' => 0.10], $presence);
$makeUser = static fn(string $id, string $gameId): array => [
    'id' => $id,
    'username' => $id,
    'balance' => 900,
    'status' => 'playing',
    'current_game_id' => $gameId,
    'stats' => [],
];
$makeGame = static fn(string $id, array $players, string $type = 'tictactoe'): array => [
    'id' => $id,
    'game_type' => $type,
    'status' => 'active',
    'launch_phase' => 'active',
    'room' => 'match',
    'bet' => 100,
    'player_ids' => $players,
    'turn' => $players[0],
    'turn_started_at' => gmdate('c'),
    'updated_at' => gmdate('c'),
];
$reconnectDb = [
    'users' => [
        'p1' => $makeUser('p1', 'disconnect-game'),
        'p2' => $makeUser('p2', 'disconnect-game'),
        'q1' => $makeUser('q1', 'isolation-game'),
        'q2' => $makeUser('q2', 'isolation-game'),
    ],
    'games' => [
        'disconnect-game' => $makeGame('disconnect-game', ['p1', 'p2']),
        'isolation-game' => $makeGame('isolation-game', ['q1', 'q2'], 'chess'),
    ],
    'transactions' => [],
];
foreach ([['p1','s1'], ['p2','s2'], ['q1','sq1'], ['q2','sq2']] as [$player, $session]) {
    $presence->touch($player, $session);
}
$ageForegroundLease = static function (string $directory, string $accountId, int $secondsAgo): void {
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($iterator as $fileInfo) {
        if (!$fileInfo->isFile() || !str_ends_with($fileInfo->getFilename(), '.presence')) continue;
        $accountFile = dirname($fileInfo->getPathname()) . DIRECTORY_SEPARATOR . '.account';
        if (trim((string)@file_get_contents($accountFile)) !== $accountId) continue;
        @file_put_contents($fileInfo->getPathname(), json_encode([
            'touched_at' => time() - $secondsAgo,
            'leave_after' => 0,
            'mode' => 'foreground',
        ], JSON_UNESCAPED_SLASHES), LOCK_EX);
    }
};

$isolationBefore = $reconnectDb['games']['isolation-game'];
$ageForegroundLease($presenceDir, 'p2', 20);
$reconnect->synchronize($reconnectDb, 'p1', 's1', 'status');
$assertTrue(!empty($reconnectDb['games']['disconnect-game']['reconnect_v2']['paused']), 'Stale opponent lease must enter reconnect pause');
$assertTrue(isset($reconnectDb['games']['disconnect-game']['reconnect_v2']['players']['p2']), 'Disconnected opponent must own reconnect deadline');
$assertSame($isolationBefore, $reconnectDb['games']['isolation-game'], 'Reconnect pause must remain match-scoped');

$presence->touch('p2', 's2');
$reconnect->synchronize(
    $reconnectDb,
    'p2',
    's2',
    'ping',
    ['state' => 'disconnected', 'last_foreground_at' => time() - 20]
);
$assertTrue(empty($reconnectDb['games']['disconnect-game']['reconnect_v2']), 'Fresh reconnect ping before deadline must restore match');
$assertSame($isolationBefore, $reconnectDb['games']['isolation-game'], 'Reconnect restore must remain match-scoped');

fwrite(STDOUT, 'PASS: MVP-18.7 friends/invites regression owner contract (' . $assertions . " assertions)\n");
