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
require $root . '/tournaments/TournamentHallService.php';

$mysqlHost = trim((string)(getenv('MGW_TEST_MYSQL_HOST') ?: ''));
$useMysql = $mysqlHost !== '';
if ($useMysql && !extension_loaded('pdo_mysql')) {
    throw new RuntimeException('Mvp21_4TournamentHallBracketTest requires pdo_mysql for MySQL mode.');
}
if (!$useMysql && !extension_loaded('pdo_sqlite')) {
    throw new RuntimeException('Mvp21_4TournamentHallBracketTest requires pdo_sqlite.');
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

if ($useMysql) {
    $mysqlPort = (int)(getenv('MGW_TEST_MYSQL_PORT') ?: 3306);
    $mysqlDatabase = trim((string)(getenv('MGW_TEST_MYSQL_DATABASE') ?: 'mgw_test'));
    $mysqlUser = (string)(getenv('MGW_TEST_MYSQL_USER') ?: 'root');
    $mysqlPassword = (string)(getenv('MGW_TEST_MYSQL_PASSWORD') ?: 'root');
    $pdo = new PDO(
        "mysql:host={$mysqlHost};port={$mysqlPort};dbname={$mysqlDatabase};charset=utf8mb4",
        $mysqlUser,
        $mysqlPassword,
        [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]
    );
} else {
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec('PRAGMA foreign_keys = ON');
}
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

$draft = $tournaments->createDraft(
    'tictactoe',
    8,
    'MVP-21.4 Hall test',
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

$players = [];
for ($i = 1; $i <= 9; $i++) {
    $mgwId = 'MGW-' . strtoupper(str_pad(dechex($i), 16, '0', STR_PAD_LEFT));
    $legacyId = 'hall_player_' . $i;
    $accountRef = 'legacy:' . $legacyId;
    $now = sprintf('2026-09-21 00:%02d:00.000000', min(59, $i + 1));

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
            'nickname'=>'HallPlayer' . $i,
            'display_name'=>'Hall Player ' . $i,
            'avatar_item_id'=>'starter-default-01',
            'created_at_utc'=>$now,
            'updated_at_utc'=>$now,
            'last_seen_at_utc'=>$now,
        ]
    );
    $owned = $ownership->ensure('development', $legacyId, $mgwId);
    $assertSame($accountRef, (string)$owned['account_ref'], 'Ownership must retain canonical legacy account ref.');

    $ledger->postAvailableDelta([
        'operation_key'=>'mvp21-4-hall-grant-' . $i,
        'account_ref'=>$accountRef,
        'mgw_id'=>$mgwId,
        'legacy_user_id'=>$legacyId,
        'asset_code'=>TournamentRegistrationService::ENTRY_ASSET,
        'available_delta'=>TournamentRegistrationService::ENTRY_FEE,
        'category'=>'test_grant',
        'source_type'=>'test',
        'source_ref'=>$tournamentId,
    ]);

    $players[$i] = [
        'mgw_id'=>$mgwId,
        'legacy_user_id'=>$legacyId,
        'account_ref'=>$accountRef,
    ];
    if ($i <= 8) {
        $tournaments->register(
            $mgwId,
            $accountRef,
            new DateTimeImmutable(sprintf('2026-09-21T00:%02d:30Z', 10 + $i)),
            $consent
        );
    }
}

$full = $tournaments->snapshot();
$assertSame('waiting_for_date', $full['tournament']['state'], 'Full accepted roster must reach waiting_for_date.');
$scheduled = $tournaments->assignFinalDate(
    $tournamentId,
    '2026-09-21T01:00:00Z',
    'test:admin',
    new DateTimeImmutable('2026-09-21T00:30:00Z')
);
$assertSame('scheduled', $scheduled['tournament']['state'], 'Hall test requires scheduled tournament.');

$presence = [];
$randomCalls = 0;
$hall = new TournamentHallService(
    $db,
    static function (string $legacyUserId) use (&$presence): array {
        return $presence[$legacyUserId] ?? ['state'=>'unknown','last_foreground_at'=>0];
    },
    static function (int $min, int $max) use (&$randomCalls): int {
        $randomCalls++;
        return $min;
    }
);

$before = $hall->status(
    $players[1]['mgw_id'],
    $players[1]['account_ref'],
    $players[1]['legacy_user_id'],
    new DateTimeImmutable('2026-09-21T00:44:59Z')
);
$assertSame(false, $before['hall']['open'], 'Hall must stay closed until exactly T-15 minutes.');
$assertSame(false, $before['hall']['entered'], 'Closed Hall must not imply participant entry.');
$assertSame(null, $before['bracket'], 'Bracket must not exist before scheduled start.');
$assertSame(8, count($before['hall']['roster']), 'Participant Hall must expose the exact registered roster.');
$assertThrows(
    fn() => $hall->enter(
        $players[1]['mgw_id'],
        $players[1]['account_ref'],
        $players[1]['legacy_user_id'],
        new DateTimeImmutable('2026-09-21T00:44:59Z')
    ),
    '15 минут',
    'Hall entry before T-15 must be rejected.'
);

$presence[$players[1]['legacy_user_id']] = ['state'=>'foreground','last_foreground_at'=>0];
$atOpen = $hall->enter(
    $players[1]['mgw_id'],
    $players[1]['account_ref'],
    $players[1]['legacy_user_id'],
    new DateTimeImmutable('2026-09-21T00:45:00Z')
);
$assertSame(true, $atOpen['hall']['open'], 'Hall must open exactly at T-15.');
$assertSame(true, $atOpen['hall']['entered'], 'Participant entry must become durable at T-15.');
$assertSame(null, $atOpen['bracket'], 'Entering Hall must not create the bracket early.');

$backgroundIndex = 2;
$presence[$players[$backgroundIndex]['legacy_user_id']] = ['state'=>'background','last_foreground_at'=>0];
$backgroundEntry = $hall->enter(
    $players[$backgroundIndex]['mgw_id'],
    $players[$backgroundIndex]['account_ref'],
    $players[$backgroundIndex]['legacy_user_id'],
    new DateTimeImmutable('2026-09-21T00:50:00Z')
);
$assertSame(true, $backgroundEntry['hall']['entered'], 'Registered participant may enter Hall while app presence is background.');
$backgroundRoster = array_values(array_filter(
    $backgroundEntry['hall']['roster'],
    fn(array $row): bool => $row['mgw_id'] === $players[$backgroundIndex]['mgw_id']
));
$assertSame(1, count($backgroundRoster), 'Background Hall participant must stay in roster.');
$assertSame(false, $backgroundRoster[0]['present'], 'Background generic presence must not be promoted to Hall presence.');

$presentIndexes = [1,3,5,7];
foreach ($presentIndexes as $index) {
    $presence[$players[$index]['legacy_user_id']] = ['state'=>'foreground','last_foreground_at'=>0];
    if ($index !== 1) {
        $hall->enter(
            $players[$index]['mgw_id'],
            $players[$index]['account_ref'],
            $players[$index]['legacy_user_id'],
            new DateTimeImmutable('2026-09-21T00:52:00Z')
        );
    }
    $hall->heartbeat(
        $players[$index]['mgw_id'],
        $players[$index]['account_ref'],
        $players[$index]['legacy_user_id'],
        new DateTimeImmutable('2026-09-21T00:59:57Z')
    );
}

$preStart = $hall->status(
    $players[1]['mgw_id'],
    $players[1]['account_ref'],
    $players[1]['legacy_user_id'],
    new DateTimeImmutable('2026-09-21T00:59:59Z')
);
$presentNow = array_values(array_filter(
    $preStart['hall']['roster'],
    static fn(array $row): bool => $row['present'] === true
));
$assertSame(4, count($presentNow), 'Hall roster must show four fresh foreground participants before start.');
$assertSame(null, $preStart['bracket'], 'Bracket must remain absent one second before start.');

$readOnlyAtStart = $hall->status(
    $players[1]['mgw_id'],
    $players[1]['account_ref'],
    $players[1]['legacy_user_id'],
    new DateTimeImmutable('2026-09-21T01:00:00Z')
);
$assertSame(true, $readOnlyAtStart['hall']['started'], 'Hall status must cross the start boundary at the exact scheduled instant.');
$assertSame(null, $readOnlyAtStart['bracket'], 'Read-only Hall status must never create the bracket by itself.');

$started = $hall->heartbeat(
    $players[1]['mgw_id'],
    $players[1]['account_ref'],
    $players[1]['legacy_user_id'],
    new DateTimeImmutable('2026-09-21T01:00:00Z')
);
$assertTrue(is_array($started['bracket']), 'Participant Hall heartbeat must materialize the bracket at the start boundary.');
$assertSame(TournamentHallService::BRACKET_VERSION, $started['bracket']['version'], 'Bracket version must be explicit and durable.');
$assertSame('2026-09-21 01:00:00.000000', $started['bracket']['effective_at_utc'], 'Bracket effective time must equal exact scheduled start.');
$assertSame('2026-09-21 01:00:00.000000', $started['bracket']['generated_at_utc'], 'Exact-boundary request must record exact bracket generation time.');
$assertSame(8, count($started['bracket']['seeds']), 'Every registered participant must remain in the bracket.');

$seedIds = array_map(static fn(array $seed): string => $seed['mgw_id'], $started['bracket']['seeds']);
$assertSame(8, count(array_unique($seedIds)), 'Random bracket must contain every participant exactly once.');
$assertTrue($seedIds !== array_map(fn(int $i): string => $players[$i]['mgw_id'], range(1, 8)), 'Deterministic test randomizer must prove bracket reordering occurs.');

$technical = array_values(array_filter(
    $started['bracket']['seeds'],
    static fn(array $seed): bool => $seed['technical_loss'] === true
));
$assertSame(4, count($technical), 'Every absent participant must remain in bracket with technical loss.');
$technicalIds = array_map(static fn(array $seed): string => $seed['mgw_id'], $technical);
sort($technicalIds);
$expectedTechnicalIds = array_map(fn(int $i): string => $players[$i]['mgw_id'], [2,4,6,8]);
sort($expectedTechnicalIds);
$assertSame($expectedTechnicalIds, $technicalIds, 'Only participants absent at the exact start boundary may receive technical loss.');

foreach ($started['bracket']['seeds'] as $seed) {
    $assertSame(
        !$seed['technical_loss'],
        $seed['present_at_start'],
        'Technical-loss flag must be the exact inverse of presence-at-start in MVP-21.4.'
    );
    $assertTrue($seed['pair_no'] >= 1 && $seed['pair_no'] <= 4, 'Eight-player bracket must create four first-round pairs.');
}

$generatedBefore = $started['bracket'];
$presence[$players[2]['legacy_user_id']] = ['state'=>'foreground','last_foreground_at'=>0];
$late = $hall->enter(
    $players[2]['mgw_id'],
    $players[2]['account_ref'],
    $players[2]['legacy_user_id'],
    new DateTimeImmutable('2026-09-21T01:00:01Z')
);
$lateSeed = array_values(array_filter(
    $late['bracket']['seeds'],
    fn(array $seed): bool => $seed['mgw_id'] === $players[2]['mgw_id']
));
$assertSame(1, count($lateSeed), 'Late participant must remain in the already generated bracket.');
$assertSame(true, $lateSeed[0]['technical_loss'], 'Late Hall entry must never erase the start-boundary technical loss.');
$assertSame(
    $generatedBefore['seeds'],
    $late['bracket']['seeds'],
    'Repeated/late Hall calls must never reshuffle the immutable bracket.'
);
$assertSame(
    $generatedBefore['generated_at_utc'],
    $late['bracket']['generated_at_utc'],
    'Repeated Hall calls must preserve the first bracket generation timestamp.'
);

$assertThrows(
    fn() => $hall->status(
        $players[9]['mgw_id'],
        $players[9]['account_ref'],
        $players[9]['legacy_user_id'],
        new DateTimeImmutable('2026-09-21T00:50:00Z')
    ),
    'только зарегистрированным участникам',
    'Spectator/nonparticipant access must remain excluded from MVP-21.4.'
);

$storedSeeds = (int)$db->fetchValue(
    'SELECT COUNT(*) FROM mgw_tournament_bracket_seeds WHERE tournament_id=:tournament_id',
    ['tournament_id'=>$tournamentId]
);
$assertSame(8, $storedSeeds, 'Exactly eight durable bracket seeds must exist after repeated Hall calls.');
$assertTrue($randomCalls > 0, 'Bracket generation must exercise the randomizer.');

if ($assertions < 46) {
    throw new RuntimeException('MVP-21.4 Hall/bracket test is too shallow: ' . $assertions);
}

echo 'MVP-21.4 Tournament Hall + bracket (' . ($useMysql ? 'mysql' : 'sqlite') . ') assertions passed: ' . $assertions . PHP_EOL;
