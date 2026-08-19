<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$currentConfig = file_get_contents($root . '/e2e/playwright.config.mjs');
$legacyConfig = file_get_contents($root . '/e2e/playwright.legacy.config.mjs');
$currentSpec = file_get_contents($root . '/e2e/staging/current-core-final.spec.mjs');
$package = file_get_contents($root . '/package.json');
$launch = file_get_contents($root . '/bot/helpers/WebAppLaunchUrl.php');

if (!is_string($currentConfig) || !is_string($legacyConfig)
    || !is_string($currentSpec) || !is_string($package) || !is_string($launch)) {
    throw new RuntimeException('Cannot read current staging E2E ownership sources.');
}

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

$assert(str_contains($currentConfig, "testMatch: 'current-core-final.spec.mjs'")
    && !str_contains($currentConfig, 'supersededScenarios'),
    'Blocking staging Playwright must run only the final current core.');
$assert(str_contains($legacyConfig, "testIgnore: ['current-core-final.spec.mjs']")
    && str_contains($legacyConfig, 'supersededScenarios'),
    'Historical and superseded current-core scenarios must remain in the legacy config.');
$assert(str_contains($package, 'test:e2e:staging:legacy')
    && str_contains($package, 'playwright.legacy.config.mjs'),
    'Legacy staging acceptance must remain explicitly runnable.');
$assert(str_contains($currentSpec, "readFileSync(resolve(repoRoot, 'bot/helpers/WebAppLaunchUrl.php')")
    && str_contains($currentSpec, "private const ENTRY_PATH")
    && !str_contains($currentSpec, 'const ENTRY_URL = `${ORIGIN}/app/`;'),
    'Final core must derive the exact Telegram entry from WebAppLaunchUrl.');
$assert(str_contains($launch, "private const ENTRY_PATH = '/app/v110.php?v=1127';"),
    'Owner contract expects the current canonical Telegram v110 launch path.');
$assert(str_contains($currentSpec, "'x-mgw-client-bootstrap'")
    && str_contains($currentSpec, "'x-mgw-game-zone'")
    && str_contains($currentSpec, "window.__MGW_APP_BOOTSTRAP_V2__?.ready === true"),
    'Final core must prove the v110 bootstrap and unified game-zone owners.');
$assert(str_contains($currentSpec, 'A.bootstrap.match_economy.entry_cost')
    && str_contains($currentSpec, 'started.game?.bet || 0)).toBe(entryCost)')
    && !str_contains($currentSpec, 'bet: 10'),
    'Final core must use server-owned entry cost instead of stale fixtures.');
$assert(str_contains($currentSpec, 'A.profile.user.balance')
    && !str_contains($currentSpec, 'balance_match'),
    'Final core must validate unified balance rather than retired balance_match.');
$assert(str_contains($currentSpec, 'async function observedAction(')
    && str_contains($currentSpec, 'page.waitForResponse(')
    && str_contains($currentSpec, "'start', 'start invite'"),
    'Write-action assertions must observe the actual browser HTTP response in Node rather than trust page.evaluate serialization.');
$assert(str_contains($currentSpec, 'async function firstUiTap(')
    && str_contains($currentSpec, 'await button.click();')
    && str_contains($currentSpec, "expect(String(payload?.game?.board || '')[cell]).not.toBe('-');"),
    'Final core must prove the first TTT UI tap commits on its first click.');
$assert(str_contains($currentSpec, "'/bot/invites.php', { action: 'sync' }")
    && str_contains($currentSpec, 'invite_events')
    && str_contains($currentSpec, "['accept', 'decline']"),
    'Direct invite receipt must follow the active v110 invite_events owner.');
$assert(str_contains($currentSpec, "action: 'create_direct'")
    && str_contains($currentSpec, "action: 'accept'")
    && str_contains($currentSpec, "action: 'start'"),
    'Final core must exercise the real two-player direct invitation lifecycle.');
$assert(str_contains($currentSpec, "action: 'history'")
    && str_contains($currentSpec, 'winnerHistory.economy.ledger_delta')
    && str_contains($currentSpec, 'loserHistory.economy.ledger_delta'),
    'Final core must prove Result/History economy consistency from the authoritative ledger projection.');
$assert(str_contains($currentSpec, "'/bot/presence.php'")
    && str_contains($currentSpec, 'serverErrors'),
    'Final core must surface current presence/server 5xx responses.');
$assert(str_contains($currentSpec, 'await resetPlayers();'),
    'Final core must self-clean A/B state after the browser scenario.');

fwrite(STDOUT, "StagingCurrentE2EOwnerContractTest: {$assertions} assertions passed\n");
