<?php
declare(strict_types=1);

$root = dirname(__DIR__);
require $root . '/database/DatabaseConnectionInterface.php';
require $root . '/database/PdoDatabaseConnection.php';
require $root . '/database/DatabaseMigrationInterface.php';
require $root . '/ledger/LedgerIntegrity.php';
require $root . '/ledger/LedgerWriteService.php';
require $root . '/tournaments/TournamentRegistrationService.php';

if (!extension_loaded('pdo_sqlite')) {
    throw new RuntimeException('Mvp21_1TournamentRegistrationTest requires pdo_sqlite.');
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

$db->execute('CREATE TABLE mgw_users (
    mgw_id TEXT NOT NULL PRIMARY KEY,
    status TEXT NOT NULL,
    nickname TEXT NULL
)');

(require $root . '/database/migrations/20260717_0005_create_balances_ledger_reservations.php')->up($db);
(require $root . '/database/migrations/20260920_0049_create_official_tournaments.php')->up($db);
(require $root . '/database/migrations/20260920_0050_add_tournament_rules_consent.php')->up($db);

$ids = [];
for ($i = 1; $i <= 10; $i++) {
    $id = sprintf('MGW-%016d', $i);
    $ids[$i] = $id;
    $db->execute(
        'INSERT INTO mgw_users (mgw_id,status,nickname) VALUES (:id,:status,:nickname)',
        ['id'=>$id,'status'=>'active','nickname'=>'Player ' . $i]
    );
}

$clock = '2026-09-20 13:00:00.000000';
$ledger = new LedgerWriteService($db, static function () use (&$clock): string {
    return $clock;
});

for ($i = 1; $i <= 10; $i++) {
    $ledger->postAvailableDelta([
        'operation_key'=>'mvp21:grant:' . $i,
        'account_ref'=>'legacy:tg-' . $i,
        'mgw_id'=>$ids[$i],
        'legacy_user_id'=>'tg-' . $i,
        'asset_code'=>'mgw_coin',
        'available_delta'=>100000,
        'category'=>'test_grant',
        'source_type'=>'test',
    ]);
}

$service = new TournamentRegistrationService($db, $ledger);
$consentFor = static function (array $snapshot): array {
    $rules = $snapshot['tournament']['rules'] ?? [];
    return [
        'accepted'=>true,
        'version'=>(string)($rules['version'] ?? ''),
        'language'=>(string)($rules['language'] ?? ''),
        'sha256'=>(string)($rules['sha256'] ?? ''),
    ];
};

$draft = $service->createDraft(
    'tictactoe',
    8,
    'Осенний официальный турнир',
    'telegram:admin',
    new DateTimeImmutable('2026-09-20T13:01:00Z')
);
$tournamentId = (string)$draft['tournament']['tournament_id'];
$assertSame('draft', $draft['tournament']['state'], 'New official tournament must start as draft.');
$assertSame(8, $draft['tournament']['capacity'], 'Draft must preserve the selected capacity.');
$assertSame(50000, $draft['tournament']['entry_fee']['amount'], 'Canonical tournament entry must be 50,000.');
$assertSame('mgw_coin', $draft['tournament']['entry_fee']['asset_code'], 'Tournament entry must use canonical mgw_coin.');
$assertSame(200000, $draft['tournament']['reward_snapshot']['placements']['1']['total'], 'First-place reward must be snapshotted at draft creation.');
$assertSame(true, $draft['tournament']['reward_snapshot']['placements']['1']['golden_ticket'], 'First-place snapshot must contain Golden Ticket.');
$assertSame(false, $draft['tournament']['reward_snapshot']['golden_ticket']['transferable'], 'Golden Ticket must remain non-transferable.');

$assertThrows(
    fn() => $service->createDraft('chess', 16, 'Second official', 'telegram:admin'),
    'одновременно',
    'A second live official tournament must be rejected.'
);

$opened = $service->openRegistration(
    $tournamentId,
    'telegram:admin',
    new DateTimeImmutable('2026-09-20T13:02:00Z')
);
$assertSame('registration_open', $opened['tournament']['state'], 'Admin must explicitly open registration.');
$rulesConsent = $consentFor($opened);

$user1Account = 'legacy:tg-1';
$registered = $service->register(
    $ids[1],
    $user1Account,
    new DateTimeImmutable('2026-09-20T13:03:00Z'),
    $rulesConsent
);
$assertSame('registered', $registered['registration']['state'], 'Player must become registered.');
$assertSame(1, $registered['registration']['attempt_no'], 'First registration must use attempt 1.');
$assertSame(50000, $registered['balance']['available_amount'], 'Registration must move entry out of available balance.');
$assertSame(50000, $registered['balance']['reserved_amount'], 'Registration must reserve entry instead of spending it.');
$assertSame(1, $registered['tournament']['registered_count'], 'First registration must occupy exactly one place.');
$assertSame(1, (int)$db->fetchValue(
    "SELECT COUNT(*) FROM mgw_reservations WHERE source_type='official_tournament' AND status='active'"
), 'Registration must create one active canonical reservation.');

$duplicate = $service->register(
    $ids[1],
    $user1Account,
    new DateTimeImmutable('2026-09-20T13:03:30Z'),
    $rulesConsent
);
$assertSame(1, $duplicate['tournament']['registered_count'], 'Duplicate register must not occupy a second place.');
$assertSame(1, (int)$db->fetchValue(
    "SELECT COUNT(*) FROM mgw_tournament_registrations WHERE tournament_id=:id AND mgw_id=:mgw",
    ['id'=>$tournamentId,'mgw'=>$ids[1]]
), 'Duplicate register must not create another registration attempt while active.');

$left = $service->leave(
    $ids[1],
    $user1Account,
    new DateTimeImmutable('2026-09-20T13:04:00Z')
);
$assertSame('withdrawn', $left['registration']['state'], 'Player may leave before the tournament is full.');
$assertSame(100000, $left['balance']['available_amount'], 'Leaving before full must release the entire entry.');
$assertSame(0, $left['balance']['reserved_amount'], 'Leaving before full must clear reserved balance.');
$assertSame(0, $left['tournament']['registered_count'], 'Leaving before full must free the place.');
$assertSame('released', (string)$db->fetchValue(
    'SELECT status FROM mgw_reservations WHERE reservation_id=:id',
    ['id'=>$registered['registration']['reservation_id']]
), 'Leaving must release the same canonical reservation.');

$reregistered = $service->register(
    $ids[1],
    $user1Account,
    new DateTimeImmutable('2026-09-20T13:05:00Z'),
    $rulesConsent
);
$assertSame(2, $reregistered['registration']['attempt_no'], 'Re-registration after withdrawal must create attempt 2.');
$assertSame(2, (int)$db->fetchValue(
    'SELECT COUNT(*) FROM mgw_tournament_registrations WHERE tournament_id=:id AND mgw_id=:mgw',
    ['id'=>$tournamentId,'mgw'=>$ids[1]]
), 'Withdrawn attempt must remain durable audit instead of being overwritten.');
$assertSame(1, (int)$db->fetchValue(
    "SELECT COUNT(*) FROM mgw_tournament_registrations
     WHERE tournament_id=:id AND mgw_id=:mgw AND registration_state='withdrawn'",
    ['id'=>$tournamentId,'mgw'=>$ids[1]]
), 'Prior withdrawn attempt must remain recorded.');

for ($i = 2; $i <= 8; $i++) {
    $service->register(
        $ids[$i],
        'legacy:tg-' . $i,
        new DateTimeImmutable('2026-09-20T13:' . sprintf('%02d', 5 + $i) . ':00Z'),
        $rulesConsent
    );
}

$full = $service->snapshot($ids[8], 'legacy:tg-8');
$assertSame(8, $full['tournament']['registered_count'], 'Eight-player tournament must stop at eight active registrations.');
$assertSame(true, $full['tournament']['is_full'], 'Capacity must be reported as full.');
$assertSame(0, $full['tournament']['remaining_count'], 'Full tournament must expose zero remaining places.');
$assertSame('waiting_for_date', $full['tournament']['state'], 'MVP-21.2 must auto-close a full tournament after all registered players accepted rules.');

$assertThrows(
    fn() => $service->register($ids[9], 'legacy:tg-9', new DateTimeImmutable('2026-09-20T13:20:00Z'), $rulesConsent),
    'закрыта',
    'The ninth player must lose the concurrent-last-place boundary after auto-close.'
);
$balance9 = $ledger->getBalance('legacy:tg-9', 'mgw_coin');
$assertSame(100000, $balance9['available_amount'], 'Rejected last-place contender must not lose available coins.');
$assertSame(0, $balance9['reserved_amount'], 'Rejected last-place contender must not create a reservation.');
$assertSame(0, (int)$db->fetchValue(
    'SELECT COUNT(*) FROM mgw_tournament_registrations WHERE tournament_id=:id AND mgw_id=:mgw',
    ['id'=>$tournamentId,'mgw'=>$ids[9]]
), 'Rejected last-place contender must not create a registration row.');

$assertThrows(
    fn() => $service->leave($ids[1], $user1Account, new DateTimeImmutable('2026-09-20T13:21:00Z')),
    'текущего состояния',
    'Once full, registration must be locked against voluntary leave.'
);
$assertSame(8, (int)$db->fetchValue(
    "SELECT COUNT(*) FROM mgw_tournament_registrations
     WHERE tournament_id=:id AND registration_state='registered'",
    ['id'=>$tournamentId]
), 'Failed leave after full must not free a place.');
$assertSame(0, (int)$db->fetchValue(
    "SELECT COUNT(*) FROM mgw_reservations
     WHERE source_type='official_tournament' AND status='consumed'"
), 'MVP-21.1 must reserve entry and never consume it.');

$storedSnapshot = json_decode((string)$db->fetchValue(
    'SELECT reward_snapshot_json FROM mgw_tournaments WHERE tournament_id=:id',
    ['id'=>$tournamentId]
), true);
$assertSame(
    TournamentRegistrationService::canonicalRewardSnapshot(),
    $storedSnapshot,
    'Tournament must persist the immutable canonical reward snapshot.'
);

$assertTrue($assertions >= 30, 'MVP-21.1 model coverage must be substantial.');
fwrite(STDOUT, "Mvp21_1TournamentRegistrationTest: {$assertions} assertions passed\n");
