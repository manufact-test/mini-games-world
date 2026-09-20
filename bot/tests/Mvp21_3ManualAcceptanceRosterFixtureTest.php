<?php
declare(strict_types=1);

$root = dirname(__DIR__);
require $root . '/database/DatabaseConnectionInterface.php';
require $root . '/database/PdoDatabaseConnection.php';
require $root . '/database/DatabaseMigrationInterface.php';
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
    }
);

$server = ['HTTP_HOST'=>'seashell-okapi-889488.hostingersite.com'];
$availability = $fixture->availability($server);
$assertSame(true, $availability['available'], 'Open empty staging tournament must expose the manual roster fixture.');
$assertSame('ready', $availability['reason'], 'Fixture readiness reason must be explicit.');
$assertSame(7, $availability['target_registered_count'], 'Eight-player manual acceptance must leave the eighth seat live.');
$assertSame(7, $availability['remaining_fixture_slots'], 'Empty tournament must require seven synthetic seats.');

$prepared = $fixture->fillToOneManualSeat($server);
$assertSame('prepared', $prepared['status'], 'Fixture must prepare the live last-seat boundary.');
$assertSame(7, $prepared['created_count'], 'Fixture must synthesize seven participants for an empty eight-seat tournament.');
$assertSame(7, $prepared['registered_count'], 'Prepared tournament must be exactly 7/8.');
$assertSame(8, $prepared['capacity'], 'Fixture must never shrink the canonical tournament capacity.');
$assertSame(1, $prepared['manual_seats_left'], 'Exactly one seat must remain for a real account.');
$assertSame('registration_open', $prepared['snapshot']['tournament']['state'], 'Registration must stay open at 7/8.');
$assertSame(7, count($runtimeUsers), 'Every synthetic participant must also receive a runtime notification identity.');

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
        str_starts_with($mgwId, 'MGW-STG-'),
        'Synthetic fixture identities must stay visibly staging-scoped.'
    );
}

$again = $fixture->fillToOneManualSeat($server);
$assertSame('already_ready', $again['status'], 'Repeated fixture preparation must be idempotent.');
$assertSame(0, $again['created_count'], 'Repeated preparation must not create more participants.');
$assertSame(7, $again['registered_count'], 'Repeated preparation must keep exactly one manual seat.');

$ready = $fixture->availability($server);
$assertSame(false, $ready['available'], 'Fixture button must disable after 7/8 is prepared.');
$assertSame('manual_last_seat_ready', $ready['reason'], 'Admin must be told that the live last seat is ready.');

$manualMgwId = 'MGW-MANUAL-000001';
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
$assertThrows(
    fn() => $productionFixture->fillToOneManualSeat($server),
    'только в staging',
    'Production must never be allowed to synthesize tournament participants.'
);

if ($assertions < 50) {
    throw new RuntimeException('MVP-21.3 manual acceptance fixture coverage is incomplete.');
}

fwrite(STDOUT, "Mvp21_3ManualAcceptanceRosterFixtureTest: {$assertions} assertions passed\n");
