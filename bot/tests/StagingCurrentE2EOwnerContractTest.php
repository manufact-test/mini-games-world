<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$currentConfig = file_get_contents($root . '/e2e/playwright.config.mjs');
$legacyConfig = file_get_contents($root . '/e2e/playwright.legacy.config.mjs');
$currentSpec = file_get_contents($root . '/e2e/staging/current-core.spec.mjs');
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

$assert(str_contains($currentConfig, "testMatch: 'current-core.spec.mjs'")
    && !str_contains($currentConfig, 'supersededScenarios'),
    'Blocking staging Playwright must run only the current canonical core, not the historical version-pinned catalogue.');
$assert(str_contains($legacyConfig, "testIgnore: ['current-core.spec.mjs']")
    && str_contains($legacyConfig, 'supersededScenarios'),
    'Historical staging acceptance scenarios must remain available under the separate legacy config.');
$assert(str_contains($package, 'test:e2e:staging:legacy')
    && str_contains($package, 'playwright.legacy.config.mjs'),
    'Legacy staging acceptance must remain explicitly runnable rather than being deleted.');

$assert(str_contains($currentSpec, "readFileSync(resolve(repoRoot, 'bot/helpers/WebAppLaunchUrl.php')")
    && str_contains($currentSpec, "private const ENTRY_PATH")
    && !str_contains($currentSpec, "const APP_ROUTE = `${STAGING_ORIGIN}/app/`;"),
    'Current staging E2E must derive the active app route from WebAppLaunchUrl instead of DirectoryIndex /app/.');
$assert(str_contains($launch, "private const ENTRY_PATH = '/app/v110.php?v=1127';"),
    'Contract fixture expects the current canonical Telegram v110 launch path.');
$assert(str_contains($currentSpec, "'x-mgw-client-bootstrap'")
    && str_contains($currentSpec, "'x-mgw-game-zone'")
    && str_contains($currentSpec, "window.__MGW_APP_BOOTSTRAP_V2__?.ready === true"),
    'Current staging core must prove the current bootstrap owner and unified game-zone runtime.');

$assert(str_contains($currentSpec, "Number(playerA.bootstrap.match_economy.entry_cost)")
    && str_contains($currentSpec, "Number(started.game?.bet || 0)).toBe(entryCost)")
    && !str_contains($currentSpec, 'bet: 10'),
    'Current staging core must use the server-owned entry cost instead of stale hard-coded economy fixtures.');
$assert(str_contains($currentSpec, "Number(playerA.profile.user.balance)")
    && !str_contains($currentSpec, 'balance_match'),
    'Current staging core must validate the unified balance owner, not the retired balance_match field.');
$assert(str_contains($currentSpec, 'async function firstUiTap(')
    && str_contains($currentSpec, 'await locator.click();')
    && str_contains($currentSpec, "expect(String(payload?.game?.board || '')[cell]).not.toBe('-');"),
    'Current staging core must prove the first TTT UI tap commits once through the live input path.');
$assert(str_contains($currentSpec, "action: 'create_direct'")
    && str_contains($currentSpec, "action: 'accept'")
    && str_contains($currentSpec, "action: 'start'"),
    'Current staging core must exercise the real two-player direct invitation lifecycle.');
$assert(str_contains($currentSpec, "action: 'history'")
    && str_contains($currentSpec, 'winnerHistory.economy.ledger_delta')
    && str_contains($currentSpec, 'loserHistory.economy.ledger_delta'),
    'Current staging core must prove Result/History economy consistency from the authoritative ledger projection.');
$assert(str_contains($currentSpec, "'/bot/presence.php'")
    && str_contains($currentSpec, 'serverErrors'),
    'Current staging core must surface current presence/server 5xx responses instead of ignoring them.');
$assert(str_contains($currentSpec, 'await resetTechnicalPlayers();'),
    'Current staging core must self-clean its A/B state after the browser scenario to prevent stale invite accumulation.');

fwrite(STDOUT, "StagingCurrentE2EOwnerContractTest: {$assertions} assertions passed\n");
