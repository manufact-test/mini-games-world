<?php
declare(strict_types=1);

$root = dirname(__DIR__);
require $root . '/database/DatabaseConnectionInterface.php';
require $root . '/database/PdoDatabaseConnection.php';
require $root . '/database/DatabaseMigrationInterface.php';
require $root . '/accounts/MgwIdGenerator.php';
require $root . '/accounts/RuntimeAccountOwnershipService.php';
require $root . '/ledger/LedgerIntegrity.php';
require $root . '/ledger/LedgerWriteService.php';
require $root . '/tournaments/TournamentRegistrationService.php';
require $root . '/tournaments/StagingTournamentManualAcceptanceService.php';

if (!extension_loaded('pdo_sqlite')) {
    throw new RuntimeException('Mvp21_3ManualAcceptanceRosterFixtureTest requires pdo_sqlite.');
}

$assertions = 0;
$assertSame = static function (mixed $expected, mixed $actual, string $message) use (&$assertions): void {
    $assertions++;
    if ($expected !== $actual) {
        throw new RuntimeException($message . ': expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
};
$assertTrue = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};
$assertThrows = static function (callable $callback, string $contains, string $message) use (&$assertions): void {
    $assertions++;
    try {
        $callback();
    } catch (Throwable $error) {
        if (str_contains(mb_strtolower($error->getMessage()), mb_strtolower($contains))) return;
        throw new RuntimeException($message . ': unexpected error: ' . $error->getMessage());
    }
    throw new RuntimeException($message . ': no error was thrown');
};

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('PRAGMA foreign_keys = ON');
$db = new PdoDatabaseConnection($pdo);

(require $root . '/database/migrations/20260716_0002_create_accounts_identities_sessions.php')->up($db);
(require $root . '/database/migrations/20260717_0005_create_balances_ledger_reservations.php')->up($db);
(require $root . '/database/migrations/20260718_0007_create_account_ownership.php')->up($db);
(require $root . '/database/migrations/20260920_0049_create_official_tournaments.php')->up($db);
(require $root . '/database/migrations/20260920_0050_add_tournament_rules_consent.php')->up($db);
(require $root . '/database/migrations/20260920_0051_refresh_tournament_rules_copy.php')->up($db);
(require $root . '/database/migrations/20260921_0052_add_tournament_schedule.php')->up($db);

$ledger = new LedgerWriteService($db);
$tournaments = new TournamentRegistrationService($db, $ledger);

$draft = $tournaments->createDraft(
    'tictactoe',
    8,
    'Manual acceptance fixture',
    'test:admin',
    new DateTimeImmutable('2026-09-21T00:00:00Z')
);
$tournamentId = (string)$draft['tournament']['tournament_id'];
$opened = $tournaments->openRegistration(
    $tournamentId,
    'test:admin',
    new DateTimeImmutable('2026-09-21T00:01:00Z')
);
$rules = $opened['tournament']['rules'];
$consent = [
    'accepted'=>true,
    'version'=>(string)$rules['version'],
    'language'=>(string)$rules['language'],
    'sha256'=>(string)$rules['sha256'],
];

$runtimeUsers = [];
$runtimeResetEvidence = [];
$config = [
    'environment'=>'staging',
    'base_url'=>'https://seashell-okapi-889488.hostingersite.com',
    'external_payments_enabled'=>false,
    'payment_mode'=>'test',
    'telegram_stars_mode'=>'test',
    'google_play_billing_mode'=>'test',
];

$fixture = new StagingTournamentManualAcceptanceService(
    $config,
    $db,
    $ledger,
    $tournaments,
    static function (array $identity, int $slot) use (&$runtimeUsers): void {
        $runtimeUsers[$identity['mgw_id']] = [
            'slot'=>$slot,
            'legacy_user_id'=>$identity['legacy_user_id'],
            'account_ref'=>$identity['account_ref'],
        ];
    },
    static function (
        array $runtimeBalances,
        array $fixtureLegacyIds,
        string $resetTournamentId,
        string $resetAt
    ) use (
        &$runtimeResetEvidence,
        &$runtimeUsers
    ): array {
        $runtimeResetEvidence = [
            'balances'=>$runtimeBalances,
            'fixture_legacy_ids'=>$fixtureLegacyIds,
            'tournament_id'=>$resetTournamentId,
            'reset_at'=>$resetAt,
        ];
        $removed = 0;
        foreach ($fixtureLegacyIds as $legacyUserId) {
            foreach ($runtimeUsers as $mgwId=>$runtimeUser) {
                if ((string)($runtimeUser['legacy_user_id'] ?? '') === (string)$legacyUserId) {
                    unset($runtimeUsers[$mgwId]);
                    $removed++;
                }
            }
        }
        return [
            'updated_balances'=>count($runtimeBalances),
            'removed_fixture_users'=>$removed,
            'hidden_tournament_notifications'=>4,
        ];
    }
);

$server = ['HTTP_HOST'=>'seashell-okapi-889488.hostingersite.com'];
$availability = $fixture->availability($server);
$assertSame(true, $availability['available'], 'Open empty staging tournament must expose the manual roster fixture.');
$assertSame('ready', $availability['reason'], 'Fixture readiness reason must be explicit.');
$assertSame(7, $availability['target_registered_count'], 'Eight-player manual acceptance must leave the eighth seat live.');
$assertSame(7, $availability['remaining_fixture_slots'], 'Empty tournament must require seven synthetic seats.');

$legacyBrokenMgwId = 'MGW-STG-a1b2c3d4e5f6';
$legacyBrokenUserId = 'stg_tour_a1b2c3d4e5f6';
$legacyBrokenAccountRef = 'legacy:' . $legacyBrokenUserId;
$legacyNow = '2026-09-21 00:05:00.000000';
$db->execute(
    'INSERT INTO mgw_users (
        mgw_id,status,display_name,username,
        avatar_provider,avatar_external_ref,avatar_storage_key,avatar_mime_type,
        avatar_width,avatar_height,
        created_at_utc,updated_at_utc,last_seen_at_utc
     ) VALUES (
        :mgw_id,:status,:display_name,NULL,
        NULL,NULL,NULL,NULL,
        NULL,NULL,
        :created_at_utc,:updated_at_utc,:last_seen_at_utc
     )',
    [
        'mgw_id'=>$legacyBrokenMgwId,
        'status'=>'active',
        'display_name'=>'Legacy broken staging fixture',
        'created_at_utc'=>$legacyNow,
        'updated_at_utc'=>$legacyNow,
        'last_seen_at_utc'=>$legacyNow,
    ]
);
$ledger->postAvailableDelta([
    'operation_key'=>'legacy-broken-fixture:grant',
    'account_ref'=>$legacyBrokenAccountRef,
    'mgw_id'=>$legacyBrokenMgwId,
    'legacy_user_id'=>$legacyBrokenUserId,
    'asset_code'=>TournamentRegistrationService::ENTRY_ASSET,
    'available_delta'=>50000,
    'category'=>'test_grant',
    'source_type'=>'test',
]);
$tournaments->register(
    $legacyBrokenMgwId,
    $legacyBrokenAccountRef,
    new DateTimeImmutable('2026-09-21T00:06:00Z'),
    $consent
);
$repair = $fixture->repairLegacyFixtureOwnership($server);
$assertSame(1, $repair['repaired'], 'Legacy staging fixture ownership must be repaired exactly once.');
$assertSame(1, $repair['scanned'], 'Legacy staging fixture repair must scan only the matching registered fixture.');
$legacyOwnership = $db->fetchAll(
    'SELECT account_ref,mgw_id,legacy_user_id,ownership_status,source_type
     FROM mgw_account_ownership WHERE account_ref=:account_ref',
    ['account_ref'=>$legacyBrokenAccountRef]
);
$assertSame(1, count($legacyOwnership), 'Legacy fixture repair must create one ownership row.');
$assertSame('active', (string)$legacyOwnership[0]['ownership_status'], 'Repaired legacy fixture ownership must be active.');
$assertSame('staging_fixture_repair', (string)$legacyOwnership[0]['source_type'], 'Repair source must stay explicitly staging-scoped.');

$afterRepairAvailability = $fixture->availability($server);
$assertSame(1, $afterRepairAvailability['registered_count'], 'Legacy fixture registration must remain canonical after ownership repair.');
$assertSame(6, $afterRepairAvailability['remaining_fixture_slots'], 'One legacy participant means six v2 fixture seats remain.');

$prepared = $fixture->fillToOneManualSeat($server);
$assertSame('prepared', $prepared['status'], 'Fixture must prepare the live last-seat boundary.');
$assertSame(6, $prepared['created_count'], 'Fixture must add six v2 participants after repairing one legacy staging participant.');
$assertSame(7, $prepared['registered_count'], 'Prepared tournament must be exactly 7/8.');
$assertSame(8, $prepared['capacity'], 'Fixture must never shrink the canonical tournament capacity.');
$assertSame(1, $prepared['manual_seats_left'], 'Exactly one seat must remain for a real account.');
$assertSame('registration_open', $prepared['snapshot']['tournament']['state'], 'Registration must stay open at 7/8.');
$assertSame(6, count($runtimeUsers), 'Every newly-created v2 participant must also receive a runtime notification identity.');

$participantIds = $tournaments->registeredParticipantMgwIds($tournamentId);
$assertSame(7, count($participantIds), 'Canonical participant owner must contain seven synthetic registrations.');
foreach ($prepared['created_participants'] as $index => $participant) {
    $mgwId = (string)$participant['mgw_id'];
    $assertTrue(isset($runtimeUsers[$mgwId]), 'Synthetic participant must exist in runtime notification audience.');
    $accountRef = (string)$runtimeUsers[$mgwId]['account_ref'];
    $balance = $ledger->getBalance($accountRef, TournamentRegistrationService::ENTRY_ASSET);
    $assertSame(0, (int)$balance['available_amount'], 'Synthetic seat must reserve, not spend, its 50,000 entry.');
    $assertSame(50000, (int)$balance['reserved_amount'], 'Synthetic seat must use the canonical 50,000 reservation.');
    $assertTrue(
        MgwIdGenerator::isValid($mgwId),
        'Synthetic fixture identities must use the canonical internal MGW-ID format.'
    );
    $assertTrue(
        str_starts_with((string)$runtimeUsers[$mgwId]['legacy_user_id'], 'stg_tour_v2_'),
        'Synthetic fixture legacy identities must stay visibly staging-scoped.'
    );
    $ownership = $db->fetchAll(
        'SELECT account_ref,mgw_id,legacy_user_id,ownership_status
         FROM mgw_account_ownership WHERE account_ref=:account_ref',
        ['account_ref'=>$accountRef]
    );
    $assertSame(1, count($ownership), 'Each v2 synthetic participant must have exactly one ownership row.');
    $assertSame($mgwId, (string)$ownership[0]['mgw_id'], 'Ownership must point to the synthetic participant MGW-ID.');
    $assertSame('active', (string)$ownership[0]['ownership_status'], 'Synthetic participant ownership must be active.');
}

$again = $fixture->fillToOneManualSeat($server);
$assertSame('already_ready', $again['status'], 'Repeated fixture preparation must be idempotent.');
$assertSame(0, $again['created_count'], 'Repeated preparation must not create more participants.');
$assertSame(7, $again['registered_count'], 'Repeated preparation must keep exactly one manual seat.');

$ready = $fixture->availability($server);
$assertSame(false, $ready['available'], 'Fixture button must disable after 7/8 is prepared.');
$assertSame('manual_last_seat_ready', $ready['reason'], 'Admin must be told that the live last seat is ready.');

$manualMgwId = 'MGW-1234567890ABCDEF';
$manualLegacy = 'manual_fixture_user';
$now = '2026-09-21 00:10:00.000000';
$db->execute(
    'INSERT INTO mgw_users (
        mgw_id,status,display_name,username,
        avatar_provider,avatar_external_ref,avatar_storage_key,avatar_mime_type,
        avatar_width,avatar_height,
        created_at_utc,updated_at_utc,last_seen_at_utc
     ) VALUES (
        :mgw_id,:status,:display_name,NULL,
        NULL,NULL,NULL,NULL,
        NULL,NULL,
        :created_at_utc,:updated_at_utc,:last_seen_at_utc
     )',
    [
        'mgw_id'=>$manualMgwId,
        'status'=>'active',
        'display_name'=>'Manual Player',
        'created_at_utc'=>$now,
        'updated_at_utc'=>$now,
        'last_seen_at_utc'=>$now,
    ]
);
(new RuntimeAccountOwnershipService($db))->ensure('development', $manualLegacy, $manualMgwId);
$ledger->postAvailableDelta([
    'operation_key'=>'manual-fixture:grant',
    'account_ref'=>'legacy:' . $manualLegacy,
    'mgw_id'=>$manualMgwId,
    'legacy_user_id'=>$manualLegacy,
    'asset_code'=>TournamentRegistrationService::ENTRY_ASSET,
    'available_delta'=>50000,
    'category'=>'test_grant',
    'source_type'=>'test',
]);

$final = $tournaments->register(
    $manualMgwId,
    'legacy:' . $manualLegacy,
    new DateTimeImmutable('2026-09-21T00:11:00Z'),
    $consent
);
$assertSame(8, $final['tournament']['registered_count'], 'Real eighth registration must complete the canonical 8/8 roster.');
$assertSame('waiting_for_date', $final['tournament']['state'], 'Real eighth registration must trigger canonical automatic close.');
$assertSame(true, $final['transition']['registration_closed_now'], 'Last live seat must own the real full-roster transition.');
$manualBalance = $ledger->getBalance('legacy:' . $manualLegacy, TournamentRegistrationService::ENTRY_ASSET);
$assertSame(0, (int)$manualBalance['available_amount'], 'Manual eighth seat must reserve its own 50,000.');
$assertSame(50000, (int)$manualBalance['reserved_amount'], 'Manual eighth seat must preserve the canonical reservation.');

$afterClose = $fixture->availability($server);
$assertSame(false, $afterClose['available'], 'Fixture must be unavailable once registration closes.');
$assertSame('registration_not_open', $afterClose['reason'], 'Closed roster must not expose synthetic fill action.');


$scheduled = $tournaments->assignFinalDate(
    $tournamentId,
    '2026-09-21T01:00:00Z',
    'test:admin',
    new DateTimeImmutable('2026-09-21T00:12:00Z')
);
$assertSame('scheduled', $scheduled['tournament']['state'], 'Manual acceptance tournament must reach scheduled before reset coverage.');
$resetAvailability = $fixture->resetAvailability($server);
$assertSame(true, $resetAvailability['available'], 'Scheduled staging tournament must expose the bounded reset action.');
$assertSame('scheduled', $resetAvailability['state'], 'Reset availability must report the exact active state.');

$reset = $fixture->resetForFreshManualAcceptance(
    $server,
    'test:admin',
    new DateTimeImmutable('2026-09-21T00:13:00Z')
);
$assertSame('reset', $reset['status'], 'Scheduled staging tournament must reset through the bounded owner.');
$assertSame(8, $reset['released_reservations'], 'Reset must release every one of the eight tournament reservations.');
$assertSame(7, $reset['fixture_accounts_retired'], 'Reset must retire only the seven synthetic fixture accounts.');
$assertSame(1, $reset['real_accounts_released'], 'Reset must release the one real manual participant without retiring it.');
$assertSame(1, $reset['runtime_balances_updated'], 'Reset must publish the released real balance back to runtime state.');
$assertSame(6, $reset['runtime_fixture_users_removed'], 'Reset must remove the six v2 fixture runtime users created by this test.');
$assertSame(4, $reset['tournament_notifications_hidden'], 'Reset must retire old tournament bell events through the runtime notification source.');
$assertSame($tournamentId, $runtimeResetEvidence['tournament_id'], 'Runtime cleanup must target the exact reset tournament notification audience.');
$assertSame('2026-09-21 00:13:00.000000', $runtimeResetEvidence['reset_at'], 'Runtime cleanup must use the exact reset timestamp for read/hidden authority.');

$afterReset = $tournaments->snapshot();
$assertSame(null, $afterReset['tournament'], 'Reset must release the official active tournament slot.');
$assertSame(
    0,
    (int)$db->fetchValue(
        'SELECT COUNT(*) FROM mgw_tournament_registrations
         WHERE tournament_id=:tournament_id AND registration_state=:state',
        ['tournament_id'=>$tournamentId,'state'=>TournamentRegistrationService::REGISTRATION_REGISTERED]
    ),
    'Reset must leave no active tournament registrations.'
);
$assertSame(
    8,
    (int)$db->fetchValue(
        'SELECT COUNT(*) FROM mgw_tournament_registrations
         WHERE tournament_id=:tournament_id AND registration_state=:state',
        ['tournament_id'=>$tournamentId,'state'=>TournamentRegistrationService::REGISTRATION_WITHDRAWN]
    ),
    'Reset must preserve all eight registrations as withdrawn audit rows.'
);
$assertSame(
    0,
    (int)$db->fetchValue(
        "SELECT COUNT(*) FROM mgw_reservations
         WHERE source_type='official_tournament' AND source_ref=:tournament_id AND status='active'",
        ['tournament_id'=>$tournamentId]
    ),
    'Reset must leave no active tournament reservations.'
);
$manualBalanceAfterReset = $ledger->getBalance(
    'legacy:' . $manualLegacy,
    TournamentRegistrationService::ENTRY_ASSET
);
$assertSame(50000, (int)$manualBalanceAfterReset['available_amount'], 'Real participant must receive the exact reserved 50,000 back.');
$assertSame(0, (int)$manualBalanceAfterReset['reserved_amount'], 'Real participant must have no tournament hold after reset.');
$assertSame(
    50000,
    (int)($runtimeResetEvidence['balances'][$manualLegacy] ?? -1),
    'Runtime reset projection must carry the real participant released balance.'
);
$assertSame(
    'active',
    (string)$db->fetchValue('SELECT status FROM mgw_users WHERE mgw_id=:mgw_id', ['mgw_id'=>$manualMgwId]),
    'Reset must not deactivate the real participant account.'
);

$fixtureIdsAfterReset = array_merge(
    [$legacyBrokenMgwId],
    array_map(static fn(array $participant): string => (string)$participant['mgw_id'], $prepared['created_participants'])
);
foreach ($fixtureIdsAfterReset as $fixtureMgwId) {
    $fixtureRows = $db->fetchAll(
        'SELECT u.status,o.account_ref,o.legacy_user_id,o.ownership_status
         FROM mgw_users u
         INNER JOIN mgw_account_ownership o ON o.mgw_id=u.mgw_id
         WHERE u.mgw_id=:mgw_id',
        ['mgw_id'=>$fixtureMgwId]
    );
    $assertSame(1, count($fixtureRows), 'Retired fixture account must preserve one canonical ownership row for ledger audit.');
    $assertSame('staging_fixture_retired', (string)$fixtureRows[0]['status'], 'Only fixture MGW users must be retired.');
    $assertSame('active', (string)$fixtureRows[0]['ownership_status'], 'Fixture ledger ownership must remain active so zero balances never become orphaned.');
    $fixtureBalanceAfterReset = $ledger->getBalance(
        (string)$fixtureRows[0]['account_ref'],
        TournamentRegistrationService::ENTRY_ASSET
    );
    $assertSame(0, (int)$fixtureBalanceAfterReset['available_amount'], 'Disposable fixture grant must be revoked after reservation release.');
    $assertSame(0, (int)$fixtureBalanceAfterReset['reserved_amount'], 'Retired fixture account must have no reserved balance.');
}

$freshDraft = $tournaments->createDraft(
    'tictactoe',
    8,
    'Fresh MVP-21.4 acceptance tournament',
    'test:admin',
    new DateTimeImmutable('2026-09-21T00:14:00Z')
);
$assertSame('draft', $freshDraft['tournament']['state'], 'Reset must allow a fresh official tournament to be created immediately.');
$assertTrue(
    (string)$freshDraft['tournament']['tournament_id'] !== $tournamentId,
    'Fresh tournament must have a new durable identity instead of rewriting the reset tournament.'
);

$productionFixture = new StagingTournamentManualAcceptanceService(
    ['environment'=>'production','base_url'=>'https://example.com'],
    $db,
    $ledger,
    $tournaments,
    static function (): void {}
);
$prodAvailability = $productionFixture->availability($server);
$assertSame(false, $prodAvailability['available'], 'Fixture must be hard-disabled outside staging.');
$assertSame('staging_only', $prodAvailability['reason'], 'Production denial reason must be explicit.');
$prodResetAvailability = $productionFixture->resetAvailability($server);
$assertSame(false, $prodResetAvailability['available'], 'Tournament reset must be hard-disabled outside staging.');
$assertSame('staging_only', $prodResetAvailability['reason'], 'Production reset denial reason must be explicit.');
$assertThrows(
    fn() => $productionFixture->fillToOneManualSeat($server),
    'только в staging',
    'Production must never be allowed to synthesize tournament participants.'
);
$assertThrows(
    fn() => $productionFixture->resetForFreshManualAcceptance($server, 'test:admin'),
    'только в staging',
    'Production must never be allowed to reset an official tournament.'
);

if ($assertions < 98) {
    throw new RuntimeException('MVP-21.3 manual acceptance fixture coverage is incomplete.');
}

fwrite(STDOUT, "Mvp21_3ManualAcceptanceRosterFixtureTest: {$assertions} assertions passed\n");
