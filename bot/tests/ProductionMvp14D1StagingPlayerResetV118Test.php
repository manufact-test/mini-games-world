<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$endpoint = file_get_contents($root . '/bot/staging-test-auth.php');
$service = file_get_contents($root . '/bot/services/StagingTestPlayerStateResetService.php');
$config = file_get_contents($root . '/e2e/playwright.config.mjs');
$setup = file_get_contents($root . '/e2e/staging-global-setup.mjs');
if (!is_string($endpoint) || !is_string($service) || !is_string($config) || !is_string($setup)) {
    throw new RuntimeException('Missing staging test-player reset sources.');
}

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

$assert(str_contains($endpoint, "if ($action === 'reset_test_players')")
        && str_contains($endpoint, 'substr_count($providedCredential, \'.\') !== 2')
        && str_contains($endpoint, 'verifyAndConsume($providedCredential)'),
    'The reset action must require a fresh GitHub Actions OIDC token.');
$assert(str_contains($service, "private const TEST_PLAYER_IDS = ['stg_test_player_a', 'stg_test_player_b']")
        && str_contains($service, 'private const MATCH_BALANCE = 100;'),
    'Only the two fixed staging players may receive the deterministic Match balance.');
$assert(str_contains($service, "['balance_match'] = self::MATCH_BALANCE")
        && str_contains($service, 'new RuntimeEconomyRepository($this->config, $this->router)')
        && str_contains($service, 'auditParity($snapshot)'),
    'The reset must update JSON and converge the database-backed economy through the canonical repository.');
$assert(str_contains($service, "environment'] ?? ''))) !== 'staging'")
        && str_contains($service, "private const STAGING_HOST = 'seashell-okapi-889488.hostingersite.com'")
        && str_contains($service, 'refuses live payments'),
    'The reset must be unavailable outside the exact isolated staging host with live payments disabled.');
$assert(str_contains($config, "globalSetup:'./staging-global-setup.mjs'")
        && str_contains($setup, "body:JSON.stringify({ action:'reset_test_players' })")
        && str_contains($setup, "payload?.match_balance !== 100"),
    'Every live staging suite must prove the deterministic reset before opening browser contexts.');
$assert(!str_contains($service, 'main')
        && str_contains($service, "'production_changed' => false")
        && str_contains($service, "'live_payments_used' => false"),
    'The reset surface must remain staging-only and report that production and live payments were untouched.');

fwrite(STDOUT, "ProductionMvp14D1StagingPlayerResetV118Test: {$assertions} assertions passed\n");
