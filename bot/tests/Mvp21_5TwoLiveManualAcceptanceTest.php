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
require $root . '/tournaments/TournamentHallService.php';

if (!extension_loaded('pdo_sqlite')) {
    throw new RuntimeException('Mvp21_5TwoLiveManualAcceptanceTest requires pdo_sqlite.');
}

$assertions = 0;
$assertSame = static function (mixed $expected, mixed $actual, string $message) use (&$assertions): void {
    $assertions++;
    if ($expected !== $actual) {
        throw new RuntimeException($message . ': expected ' . var_export($expected, true)
            . ', got ' . var_export($actual, true));
    }
};
$assertTrue = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('PRAGMA foreign_keys = ON');
$db = new PdoDatabaseConnection($pdo);

foreach ([
    '20260716_0002_create_accounts_identities_sessions.php',
    '20260717_0005_create_balances_ledger_reservations.php',
    '20260718_0007_create_account_ownership.php',
    '20260816_0009_add_canonical_profile_identity.php',
    '20260920_0049_create_official_tournaments.php',
    '20260920_0050_add_tournament_rules_consent.php',
    '20260920_0051_refresh_tournament_rules_copy.php',
    '20260921_0052_add_tournament_schedule.php',
    '20260921_0053_create_tournament_hall_bracket.php',
] as $migration) {
    (require $root . '/database/migrations/' . $migration)->up($db);
}

$ledger = new LedgerWriteService($db);
$tournaments = new TournamentRegistrationService($db, $ledger);
$ownership = new RuntimeAccountOwnershipService($db);
$config = [
    'environment'=>'staging',
    'base_url'=>'https://seashell-okapi-889488.hostingersite.com',
    'external_payments_enabled'=>false,
    'payment_mode'=>'test',
    'telegram_stars_mode'=>'test',
    'google_play_billing_mode'=>'test',
];
$server = ['HTTP_HOST'=>'seashell-okapi-889488.hostingersite.com'];

$draft = $tournaments->createDraft(
    'tictactoe',
    8,
    'Two live manual acceptance',
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

$availability = $fixture->availability($server);
$assertSame(true, (bool)$availability['modes']['2']['available'], 'Two-live mode must be available on an empty 8-player staging tournament.');
$assertSame(6, (int)$availability['modes']['2']['target_registered_count'], 'Two-live mode must target 6/8.');
$assertSame(6, (int)$availability['modes']['2']['remaining_fixture_slots'], 'Two-live mode must add six fixture registrations.');

$prepared = $fixture->fillToManualSeats($server, 2);
$assertSame('prepared', (string)$prepared['status'], 'Two-live fixture preparation must succeed.');
$assertSame(6, (int)$prepared['created_count'], 'Two-live mode must create six fixtures.');
$assertSame(6, (int)$prepared['registered_count'], 'Two-live mode must stop at 6/8.');
$assertSame(2, (int)$prepared['manual_seats_left'], 'Exactly two live seats must remain.');
$assertSame(6, count($runtimeUsers), 'All six fixture users must exist in runtime parity.');

$live = [];
for ($i = 1; $i <= 2; $i++) {
    $mgwId = $i === 1 ? 'MGW-AAAABBBBCCCC0001' : 'MGW-AAAABBBBCCCC0002';
    $legacyId = 'manual_live_' . $i;
    $accountRef = 'legacy:' . $legacyId;
    $timestamp = sprintf('2026-09-21 00:%02d:00.000000', 10 + $i);

    $db->execute(
        'INSERT INTO mgw_users (
            mgw_id,status,nickname,display_name,username,
            avatar_provider,avatar_external_ref,avatar_storage_key,avatar_mime_type,
            avatar_width,avatar_height,equipped_avatar_item_id,preferred_locale,
            created_at_utc,updated_at_utc,last_seen_at_utc
         ) VALUES (
            :mgw_id,:status,:nickname,:display_name,NULL,
            NULL,NULL,NULL,NULL,
            NULL,NULL,:avatar_item_id,NULL,
            :created_at_utc,:updated_at_utc,:last_seen_at_utc
         )',
        [
            'mgw_id'=>$mgwId,
            'status'=>'active',
            'nickname'=>'ManualLive' . $i,
            'display_name'=>'Manual Live ' . $i,
            'avatar_item_id'=>'starter-default-01',
            'created_at_utc'=>$timestamp,
            'updated_at_utc'=>$timestamp,
            'last_seen_at_utc'=>$timestamp,
        ]
    );
    $ownership->ensure('development', $legacyId, $mgwId);
    $ledger->postAvailableDelta([
        'operation_key'=>'mvp21-5-two-live-grant-' . $i,
        'account_ref'=>$accountRef,
        'mgw_id'=>$mgwId,
        'legacy_user_id'=>$legacyId,
        'asset_code'=>TournamentRegistrationService::ENTRY_ASSET,
        'available_delta'=>TournamentRegistrationService::ENTRY_FEE,
        'category'=>'test_grant',
        'source_type'=>'test',
        'source_ref'=>$tournamentId,
    ]);
    $tournaments->register(
        $mgwId,
        $accountRef,
        new DateTimeImmutable(sprintf('2026-09-21T00:%02d:00Z', 12 + $i)),
        $consent
    );
    $live[$i] = [
        'mgw_id'=>$mgwId,
        'legacy_user_id'=>$legacyId,
        'account_ref'=>$accountRef,
    ];
}

$full = $tournaments->snapshot();
$assertSame(8, (int)$full['tournament']['registered_count'], 'Two live registrations must complete the 8/8 roster.');
$assertSame(TournamentRegistrationService::STATE_WAITING_FOR_DATE, (string)$full['tournament']['state'], 'Second live registration must close registration canonically.');

$tournaments->assignFinalDate(
    $tournamentId,
    '2026-09-21T01:00:00Z',
    'test:admin',
    new DateTimeImmutable('2026-09-21T00:20:00Z')
);

$presence = [
    $live[1]['legacy_user_id']=>['state'=>'foreground','last_foreground_at'=>0],
    $live[2]['legacy_user_id']=>['state'=>'foreground','last_foreground_at'=>0],
];
$hall = new TournamentHallService(
    $db,
    static function (string $legacyUserId) use (&$presence): array {
        return $presence[$legacyUserId] ?? ['state'=>'unknown','last_foreground_at'=>0];
    },
    static fn(int $min, int $max): int => $min
);

foreach ([1,2] as $i) {
    $hall->enter(
        $live[$i]['mgw_id'],
        $live[$i]['account_ref'],
        $live[$i]['legacy_user_id'],
        new DateTimeImmutable('2026-09-21T00:45:00Z')
    );
    $hall->heartbeat(
        $live[$i]['mgw_id'],
        $live[$i]['account_ref'],
        $live[$i]['legacy_user_id'],
        new DateTimeImmutable('2026-09-21T00:59:57Z')
    );
}

$started = $hall->heartbeat(
    $live[1]['mgw_id'],
    $live[1]['account_ref'],
    $live[1]['legacy_user_id'],
    new DateTimeImmutable('2026-09-21T01:00:00Z')
);

$assertTrue(is_array($started['bracket']), 'Two-live acceptance must generate a bracket at T0.');
$liveSeeds = array_values(array_filter(
    $started['bracket']['seeds'],
    static fn(array $seed): bool => in_array($seed['mgw_id'], [
        $live[1]['mgw_id'],
        $live[2]['mgw_id'],
    ], true)
));
$assertSame(2, count($liveSeeds), 'Both live accounts must remain in the generated bracket.');
$assertSame((int)$liveSeeds[0]['pair_no'], (int)$liveSeeds[1]['pair_no'], 'The two live accounts must be paired together in staging two-live mode.');
$assertSame(true, (bool)$liveSeeds[0]['present_at_start'], 'First live account must be present at start.');
$assertSame(true, (bool)$liveSeeds[1]['present_at_start'], 'Second live account must be present at start.');
$assertSame(false, (bool)$liveSeeds[0]['technical_loss'], 'First live account must not receive technical loss.');
$assertSame(false, (bool)$liveSeeds[1]['technical_loss'], 'Second live account must not receive technical loss.');

$fixtureSeeds = array_values(array_filter(
    $started['bracket']['seeds'],
    static fn(array $seed): bool => !in_array($seed['mgw_id'], [
        $live[1]['mgw_id'],
        $live[2]['mgw_id'],
    ], true)
));
$assertSame(6, count($fixtureSeeds), 'Exactly six synthetic fixture seeds must remain.');
foreach ($fixtureSeeds as $seed) {
    $assertSame(true, (bool)$seed['technical_loss'], 'Synthetic fixture users remain absent unless a later acceptance helper explicitly simulates presence.');
}

if ($assertions < 24) {
    throw new RuntimeException('Two-live manual acceptance coverage is incomplete: ' . $assertions);
}

echo "MVP-21.5 two-live manual acceptance assertions passed: {$assertions}\n";
