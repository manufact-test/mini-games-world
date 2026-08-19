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
    "'./assets/js/games/game-invites-v110.js?v=1142&zone=unified&rematch=optimistic&terminal=self-silent' => './assets/js/games/game-invites-v110.js?v=1143&zone=unified&rematch=optimistic&terminal=self-silent&social=1'",
    $manifest,
    'MVP-18.7 must retain the accepted social-aware invite owner'
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

$assertNotContains(
    'leaveSearch(',
    $inviteEndpoint,
    'Invite endpoint must not silently cancel public matchmaking search'
);

fwrite(STDOUT, 'PASS: MVP-18.7 friends/invites regression owner contract (' . $assertions . " assertions)\n");
