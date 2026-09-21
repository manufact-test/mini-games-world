<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$service = file_get_contents($root . '/bot/services/StagingTestPlayerStateResetService.php');
$playwright = file_get_contents($root . '/e2e/playwright.config.mjs');
if (!is_string($service) || !is_string($playwright)) {
    throw new RuntimeException('Cannot read staging reset sources.');
}

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

$assert(str_contains($service, "'tournament_cleanup'"),
    'Staging reset must expose a bounded tournament cleanup stage.');
$assert(str_contains($service, 'cleanupRuntimeTournamentRegistrations()'),
    'Staging reset must own tournament cleanup explicitly.');
$cleanupPos = strpos($service, '$tournamentCleanup = $this->cleanupRuntimeTournamentRegistrations();');
$economyPos = strpos($service, '$economy = new RuntimeEconomyRepository($this->config, $this->router);');
$assert($cleanupPos !== false && $economyPos !== false && $cleanupPos < $economyPos,
    'Tournament reservation cleanup must run before JSON-to-ledger economy convergence.');
$assert(str_contains($service, 'WHERE legacy_user_id = :legacy_user_id')
        && str_contains($service, 'foreach (self::TEST_PLAYER_IDS as $legacyUserId)'),
    'Cleanup must remain scoped to the fixed A/B technical identities.');
$assert(str_contains($service, '$service->leave($mgwId, $accountRef)'),
    'Stale tournament state must be released through the canonical TournamentRegistrationService owner.');
$assert(!str_contains($service, 'DELETE FROM mgw_tournament_registrations'),
    'Staging reset must never delete tournament registrations directly.');
$assert(str_contains(
        $service,
        'Staging test tournament cleanup refuses a registered test player after registration close.'
    ),
    'Reset must still refuse scheduled/progressed tournament states.');
$assert(str_contains($service, 'withdrawAutoClosedTestTournamentRegistration')
        && str_contains($service, 'TournamentRegistrationService::STATE_WAITING_FOR_DATE')
        && str_contains($service, "registration_closed_reason'] ?? '') === 'full'")
        && str_contains($service, 'empty($tournament[\'scheduled_start_at_utc\'])'),
    'Reset may unwind only an unscheduled full auto-close caused by the technical A/B registration.');
$assert(str_contains($service, "reason'=>'staging_test_player_cleanup_after_auto_close'")
        && str_contains($service, 'TournamentRegistrationService::STATE_REGISTRATION_OPEN')
        && str_contains($service, 'registration_closed_at_utc=NULL')
        && str_contains($service, 'registration_closed_reason=NULL'),
    'Auto-close cleanup must release the A/B reservation and restore the manual registration seat.');
$assert(str_contains($playwright, "MGW_STAGING_LIVE_TOURNAMENT_E2E === '1'")
        && str_contains($playwright, "currentTests.push('tournament-registration-live.spec.mjs')")
        && str_contains($playwright, 'testMatch: currentTests'),
    'Automatic blocking staging E2E must not mutate the official tournament unless explicitly opted in.');
$assert(str_contains($service, 'TournamentRegistrationService::REGISTRATION_WITHDRAWN')
        && str_contains($service, "['balance']['reserved_amount'] ?? -1) !== 0"),
    'Canonical leave must prove both withdrawn registration and released reservation.');
$assert(str_contains($service, 'Staging test tournament cleanup did not restore A/B tournament parity.'),
    'Reset must verify no A/B active tournament registration or reservation remains.');
$assert(str_contains($service, "'tournament_registrations_withdrawn'")
        && str_contains($service, "'tournament_parity'"),
    'Reset response must expose auditable tournament cleanup evidence.');

fwrite(STDOUT, "Mvp21_1StagingTournamentResetContractTest: {$assertions} assertions passed\n");
