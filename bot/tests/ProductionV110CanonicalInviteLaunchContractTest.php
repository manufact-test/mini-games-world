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
$canonicalBase = WebAppLaunchUrl::base($config);

$assert(
    str_starts_with($canonicalBase, 'https://example.test/app/v110.php?v=1233&'),
    'Canonical base URL must select the factual v1233 Telegram entrypoint.'
);
$assert(
    WebAppLaunchUrl::invitation($config, $token)
        === $canonicalBase . '&invite=' . $normalizedToken,
    'Canonical invitation URL must append one normalized token to the factual entrypoint.'
);
$assert(
    WebAppLaunchUrl::invitation($config, 'not-a-token') === $canonicalBase,
    'Invalid tokens must never create a second or malformed launch route.'
);
$assert(
    WebAppLaunchUrl::base([]) === '' && WebAppLaunchUrl::invitation([], $token) === '',
    'Missing base_url must fail closed instead of emitting a relative production route.'
);

$launchSource = $read('bot/helpers/WebAppLaunchUrl.php');
$welcome = $read('bot/helpers/UserWelcomeGuard.php');
$invites = $read('bot/invites.php');
$linkEntry = $read('app/assets/js/games/invite-link-entry-v110r12.js');
$shell = $read('app/assets/js/main-v110-handoff-shell.js');
$manifest = $read('app/runtime/client/version-manifest.php');
$v110 = $read('app/v110.php');

$assert(
    str_contains($launchSource, "private const ENTRY_PATH = '/app/v110.php?v=1233&"),
    'WebAppLaunchUrl must own the factual v1233 Telegram launch identity.'
);
$assert(
    str_contains($welcome, 'WebAppLaunchUrl::base($this->config)')
        && str_contains($welcome, 'WebAppLaunchUrl::invitation($this->config, $inviteToken)')
        && str_contains($invites, 'return WebAppLaunchUrl::invitation($config, $token);')
        && substr_count($welcome, "require_once __DIR__ . '/WebAppLaunchUrl.php';") === 1
        && substr_count($invites, "require_once __DIR__ . '/helpers/WebAppLaunchUrl.php';") === 1,
    'Start/menu and invite-message backends must depend on the shared factual URL builder exactly once.'
);
$assert(
    !str_contains($welcome, '/app/?v=85')
        && !str_contains($invites, '/app/?v=85')
        && str_contains($invites, 'return WebAppLaunchUrl::invitation($config, $token);')
        && str_contains($invites, "return $baseUrl . '/invite/' . rawurlencode($normalizedToken);")
        && str_contains($invites, "'?start=invite_'")
        && str_contains($invites, "$shareUrl = mgw_invite_share_url($config, $token);")
        && str_contains($invites, "$telegramOpenUrl = mgw_invite_telegram_open_url($config, $token);"),
    'Invite links must keep distinct canonical owners: v110 WebApp fallback, public /invite landing, and Telegram start_param open URL.'
);
$assert(
    str_contains($shell, "openIncomingInviteFromTelegram } from './games/invite-link-entry-v110r12.js?v=1124&mvp24=room-copy-removed-v1'")
        && str_contains($manifest, "'./assets/js/games/invite-link-entry-v110r12.js?v=1124&mvp24=room-copy-removed-v1'")
        && str_contains($manifest, "./assets/js/games/invite-link-entry-v110r12.js?v=1125&mvp24=room-copy-removed-v1&mvp27_1=active-shell-copy-v1")
        && str_contains($linkEntry, "startParam.startsWith('invite_')")
        && str_contains($linkEntry, "new URLSearchParams(window.location.search).get('invite')")
        && str_contains($linkEntry, "action:'open_link'")
        && str_contains($linkEntry, 'const invite = result?.opened_invite || null;'),
    'The factual shell/import-manifest graph must retain one canonical invite-link owner.'
);
$assert(
    str_contains($v110, '$manifestPath = __DIR__ . \'/runtime/client/version-manifest.php\';')
        && str_contains($v110, '$versionManifest = require $manifestPath;')
        && str_contains($v110, "'@mgw/clean-entry'")
        && str_contains($v110, "'@mgw/main'")
        && str_contains($v110, '$bootstrapTarget = $assets[\'bootstrap\'];')
        && str_contains($v110, 'data-hotfix-build="v110-mvp16-route-scoped-polling-v1167"')
        && str_contains($v110, 'Cache-Control: no-store, no-cache, must-revalidate, max-age=0'),
    'Canonical invitation launches must reach the manifest-owned no-store v110 entry graph.'
);

fwrite(STDOUT, "ProductionV110CanonicalInviteLaunchContractTest: {$assertions} assertions passed\n");
