<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$assertions = 0;

$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};
$read = static function (string $path) use ($root): string {
    $content = file_get_contents($root . '/' . $path);
    if (!is_string($content)) throw new RuntimeException('Cannot read ' . $path);
    return $content;
};

$authenticatedEndpoints = [
    'bot/api.php' => ['getUserFromRequest($payload)'],
    'bot/account-data.php' => ['AccountReauthGuard::authorize', 'getUserFromRequest($payload)'],
    'bot/cosmetic-store.php' => ['getUserFromRequest($payload)'],
    'bot/friends.php' => ['getUserFromRequest($payload)'],
    'bot/game-clock.php' => ['new AuthService($config)', 'getUserFromRequest($payload)'],
    'bot/game-live-v108.php' => ['new AuthService($config)', 'getUserFromRequest($payload)'],
    'bot/game-watch.php' => ['getUserFromRequest($payload, false)'],
    'bot/game-reaction.php' => ['getUserFromRequest($payload)'],
    'bot/invite-opponents.php' => ['new AuthService($config)', 'getUserFromRequest($payload)'],
    'bot/invite-watch.php' => ['getUserFromRequest($payload)'],
    'bot/invites.php' => ['new AuthService($config)', 'getUserFromRequest($payload)'],
    'bot/leaderboard.php' => ['getUserFromRequest($payload)'],
    'bot/moderation.php' => ['getUserFromRequest($payload)'],
    'bot/notifications.php' => ['new AuthService($config)', 'getUserFromRequest($payload)'],
    'bot/presence.php' => ['new AuthService($config)', 'getUserFromRequest($payload)'],
    'bot/profile.php' => ['getUserFromRequest($payload)'],
    'bot/profile-v2.php' => ['getUserFromRequest($payload)'],
    'bot/rating-archive.php' => ['getUserFromRequest($payload)'],
    'bot/search-speed.php' => ['getUserFromRequest($payload)'],
    'bot/shop-history.php' => ['getUserFromRequest($payload)'],
    'bot/support.php' => ['getUserFromRequest($payload)'],
    'bot/tournament-hall.php' => ['getUserFromRequest($payload)'],
    'bot/tournament-status.php' => ['getUserFromRequest($payload)'],
];

foreach ($authenticatedEndpoints as $path => $needles) {
    $source = $read($path);
    foreach ($needles as $needle) {
        $assert(str_contains($source, $needle), $path . ' must retain authenticated actor ownership: ' . $needle);
    }
}

$auth = $read('bot/services/AuthService.php');
$assert(str_contains($auth, "hash_equals(\$calculated, \$hash)"), 'Telegram initData signature must be constant-time verified.');
$assert(str_contains($auth, "['auth_date']"), 'Telegram initData auth_date must remain part of freshness validation.');
$assert(str_contains($auth, 'telegram_init_data_max_age_sec'), 'Telegram initData must retain a bounded age policy.');
$assert(str_contains($auth, 'StagingTestAuthService'), 'Test identity must remain an explicit separate staging auth owner.');

$website = $read('bot/account-data-website-hook.php');
$assert(str_contains($website, "hash_hmac('sha256'"), 'Website lifecycle hook must use SHA-256 HMAC.');
$assert(str_contains($website, '$timestamp . "\\n" . $body'), 'Website lifecycle hook must sign timestamp plus exact raw body.');
$assert(str_contains($website, 'abs(time() - $timestamp) > 300'), 'Website lifecycle hook must reject stale signed requests.');
$assert(str_contains($website, 'hash_equals($expected, $provided)'), 'Website lifecycle hook HMAC comparison must be constant-time.');

$adminEndpoints = [
    'bot/admin-analytics.php','bot/admin-compensation.php','bot/admin-economy.php',
    'bot/admin-incident.php','bot/admin-notifications.php','bot/admin-operations.php',
    'bot/admin-rating.php','bot/admin-read.php','bot/admin-replay.php','bot/admin-reports.php',
    'bot/admin-support.php','bot/admin-system.php','bot/admin-test-coins.php','bot/admin-tournaments.php',
];
foreach ($adminEndpoints as $path) {
    $source = $read($path);
    $assert(str_contains($source, 'AdminWebAuth::authorize'), $path . ' must authorize through AdminWebAuth.');
}
$adminAuth = $read('bot/helpers/AdminWebAuth.php');
$assert(str_contains($adminAuth, 'MAX_AGE_SECONDS = 15 * 60'), 'Web Admin initData must remain short-lived.');
$assert(str_contains($adminAuth, 'getTelegramUserFromInitData($initData, false)'), 'Web Admin must verify Telegram-signed identity.');
$assert(str_contains($adminAuth, 'isAdmin('), 'Web Admin must verify the authenticated Telegram subject against admin_ids.');

$webhook = $read('bot/webhook.php');
$assert(str_contains($webhook, 'TelegramWebhookSecurity::incomingAuthorized($config, $_SERVER)'), 'Telegram webhook must authenticate ingress before processing.');
$webhookSecurity = $read('bot/helpers/TelegramWebhookSecurity.php');
$assert(str_contains($webhookSecurity, 'HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'), 'Webhook security must own Telegram secret-token header verification.');
$assert(str_contains($webhookSecurity, 'hash_equals'), 'Webhook secret comparison must be constant-time.');

$oidc = $read('bot/services/GitHubActionsOidcVerifier.php');
foreach ([
    "private const AUDIENCE = 'mini-games-world-staging-e2e'",
    "private const REPOSITORY_ID = '1295733209'",
    "private const REPOSITORY_OWNER_ID = '301880503'",
    "private const STAGING_REF = 'refs/heads/agent/mvp-13-2-staging'",
    "private const WORKFLOW_REF = 'manufact-test/mini-games-world/.github/workflows/staging-playwright-e2e.yml@refs/heads/agent/mvp-13-2-staging'",
    "['event_name'] ?? '') !== 'push'",
    'consumeJti(',
] as $needle) {
    $assert(str_contains($oidc, $needle), 'GitHub OIDC staging boundary missing: ' . $needle);
}

$stagingTest = $read('bot/services/StagingTestAuthService.php');
foreach ([
    "private const STAGING_HOST = 'seashell-okapi-889488.hostingersite.com'",
    "environment'] ?? ''))) !== 'staging'",
    "$baseScheme === 'https'",
    "$baseHost === self::STAGING_HOST",
    "$requestHost === self::STAGING_HOST",
    'assertPaymentsDisabled',
    "'httponly' => true",
    "'samesite' => 'Strict'",
    'Staging test session replay was rejected.',
] as $needle) {
    $assert(str_contains($stagingTest, $needle), 'Staging test auth isolation missing: ' . $needle);
}

foreach ([
    'bot/staging-projection-diagnostic.php',
    'bot/staging-invite-mismatch-diagnostic.php',
    'bot/staging-fresh-invite-recovery.php',
    'bot/staging-test-only-invite-recovery.php',
    'bot/staging-webhook-security.php',
] as $path) {
    $source = $read($path);
    $assert(str_contains($source, 'GitHubActionsOidcVerifier'), $path . ' must require the canonical GitHub OIDC owner.');
}

$social = $read('bot/social/SocialPlayerProfileReader.php');
$assert(!str_contains($social, 'provider_username'), 'Public social profile must not expose provider username.');
$assert(!str_contains($social, 'last_seen_at'), 'Public social profile must not expose last-seen telemetry.');
$assert(str_contains($social, 'public_mgw_id'), 'Public social profile must expose only the public MGW identifier.');
$assert(str_contains($social, 'member_since'), 'Public social profile may expose membership date.');

$rateSupport = $read('bot/support.php');
$rateFriends = $read('bot/friends.php');
$assert(str_contains($rateSupport, 'UserActionRateLimiter'), 'Support writes must retain server-side abuse limiting.');
$assert(str_contains($rateFriends, 'UserActionRateLimiter'), 'Player reports/friend writes must retain server-side abuse limiting.');

$supportService = $read('bot/support/SupportTicketService.php');
$reportService = $read('bot/social/PlayerReportService.php');
$assert(str_contains($supportService, 'USER_WRITE_REPLAY_WINDOW_SECONDS'), 'Support sensitive writes must retain replay suppression.');
$assert(str_contains($reportService, 'SUBMIT_REPLAY_WINDOW_SECONDS'), 'Player reports must retain replay suppression.');

$bootstrap = $read('bot/core/bootstrap.php');
$displayPos = strpos($bootstrap, "ini_set('display_errors', '0')");
$configPos = strpos($bootstrap, "getenv('MGW_CONFIG_FILE')");
$assert($displayPos !== false && $configPos !== false && $displayPos < $configPos, 'Browser diagnostics must fail closed before private config discovery.');

$secretWorkflow = $read('.github/workflows/mvp25-5-secret-scan.yml');
$assert(str_contains($secretWorkflow, "pull_request:"), 'Secret scan must run for staging pull requests.');
$assert(str_contains($secretWorkflow, "push:"), 'Secret scan must run on staging pushes.');
$assert(str_contains($secretWorkflow, 'node scripts/ci/check-secrets.mjs'), 'Secret scan must execute the tracked-secret scanner.');

$audit = $read('docs/MVP25_5_SECURITY_RESILIENCE_AUDIT.md');
$assert(str_contains($audit, '**Status:** CLOSED'), 'MVP-25.5 audit document must be closed only with this aggregate contract.');
$assert(!str_contains($audit, 'Still open after this slice:'), 'Closed MVP-25.5 audit must not retain an open-items list.');

fwrite(STDOUT, "Mvp25_5SecurityResilienceClosureContractTest: {$assertions} assertions passed\n");
