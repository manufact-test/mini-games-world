<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
require_once $root . '/bot/helpers/WebAppLaunchUrl.php';

$read = static function (string $path) use ($root): string {
    $content = file_get_contents($root . '/' . $path);
    if (!is_string($content)) throw new RuntimeException('Cannot read canonical invite source: ' . $path);
    return $content;
};

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

$config = ['base_url' => 'https://example.test/'];
$token = 'ABCDEF0123456789ABCDEF01';
$normalizedToken = strtolower($token);

$assert(
    WebAppLaunchUrl::base($config) === 'https://example.test/app/v110.php?v=1123',
    'Canonical base URL must select the clean v1123 Telegram entrypoint.'
);
$assert(
    WebAppLaunchUrl::invitation($config, $token)
        === 'https://example.test/app/v110.php?v=1123&invite=' . $normalizedToken,
    'Canonical invitation URL must append one normalized token to the clean entrypoint.'
);
$assert(
    WebAppLaunchUrl::invitation($config, 'not-a-token')
        === 'https://example.test/app/v110.php?v=1123',
    'Invalid tokens must never create a second or malformed launch route.'
);
$assert(
    WebAppLaunchUrl::base([]) === '' && WebAppLaunchUrl::invitation([], $token) === '',
    'Missing base_url must fail closed instead of emitting a relative production route.'
);

$welcome = $read('bot/helpers/UserWelcomeGuard.php');
$invites = $read('bot/invites.php');
$linkEntry = $read('app/assets/js/games/invite-link-entry-v110r12.js');
$shell = $read('app/assets/js/main-v110-handoff-shell.js');
$v110 = $read('app/v110.php');

$assert(
    str_contains($welcome, 'WebAppLaunchUrl::base($this->config)')
        && str_contains($welcome, 'WebAppLaunchUrl::invitation($this->config, $inviteToken)')
        && str_contains($welcome, "Active canonical path: '/app/v110.php?v=1123'.")
        && str_contains($invites, 'return WebAppLaunchUrl::invitation($config, $token);')
        && substr_count($welcome, "require_once __DIR__ . '/WebAppLaunchUrl.php';") === 1
        && substr_count($invites, "require_once __DIR__ . '/helpers/WebAppLaunchUrl.php';") === 1,
    'Start/menu and invite-message backends must depend on the shared fresh URL builder exactly once.'
);
$assert(
    !str_contains($welcome, '/app/?v=85')
        && !str_contains($invites, '/app/?v=85')
        && str_contains($invites, "'?start=invite_'")
        && str_contains($invites, 'return mgw_invite_webapp_url($config, $token);'),
    'Shared links may prefer Telegram start_param, but every WebApp fallback must remain canonical v110.'
);
$assert(
    str_contains($shell, "openIncomingInviteFromTelegram } from './games/invite-link-entry-v110r12.js?v=1123'")
        && str_contains($linkEntry, "startParam.startsWith('invite_')")
        && str_contains($linkEntry, "new URLSearchParams(window.location.search).get('invite')")
        && str_contains($linkEntry, "action:'open_link'")
        && str_contains($linkEntry, 'const invite = result?.opened_invite || null;'),
    'The active one-shot link owner must accept Telegram start_param and canonical invite query tokens through one open_link action.'
);
$assert(
    str_contains($v110, 'production-clean-entry-v110.js?v=1120')
        && str_contains($v110, 'main-v110.js?v=1123')
        && str_contains($v110, 'data-hotfix-build="v110-mvp14r12-invite-notification-presence-stability"')
        && str_contains($v110, 'Cache-Control: no-store, no-cache, must-revalidate, max-age=0'),
    'Canonical invitation launches must reach the exact clean no-store production build with the final v1123 shell.'
);

fwrite(STDOUT, "ProductionV110CanonicalInviteLaunchContractTest: {$assertions} assertions passed\n");
