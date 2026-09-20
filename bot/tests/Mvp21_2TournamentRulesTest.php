<?php
declare(strict_types=1);

$root = dirname(__DIR__);
require $root . '/database/DatabaseConnectionInterface.php';
require $root . '/database/PdoDatabaseConnection.php';
require $root . '/database/DatabaseMigrationInterface.php';
require $root . '/ledger/LedgerIntegrity.php';
require $root . '/ledger/LedgerWriteService.php';
require $root . '/notifications/NotificationCenterV2Policy.php';
require $root . '/notifications/AdminNotificationEventService.php';
require $root . '/tournaments/TournamentRegistrationService.php';
require $root . '/tournaments/TournamentAdminNotificationBridge.php';

if (!extension_loaded('pdo_sqlite')) {
    throw new RuntimeException('Mvp21_2TournamentRulesTest requires pdo_sqlite.');
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

$rewardJson = json_encode(
    TournamentRegistrationService::canonicalRewardSnapshot(),
    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
);
$db->execute(
    'INSERT INTO mgw_tournaments (
        tournament_id,active_slot,title,game_type,capacity,entry_fee_amount,entry_asset_code,
        reward_snapshot_json,tournament_state,created_by_ref,opened_by_ref,
        created_at_utc,registration_opened_at_utc,updated_at_utc
     ) VALUES (
        :id,:slot,:title,:game,:capacity,:entry,:asset,:reward,:state,:created_by,:opened_by,
        :created_at,:opened_at,:updated_at
     )',
    [
        'id'=>'tour_mvp21_2_backfill',
        'slot'=>TournamentRegistrationService::ACTIVE_SLOT,
        'title'=>'Проба один',
        'game'=>'tictactoe',
        'capacity'=>8,
        'entry'=>50000,
        'asset'=>'mgw_coin',
        'reward'=>$rewardJson,
        'state'=>'registration_open',
        'created_by'=>'test:admin',
        'opened_by'=>'test:admin',
        'created_at'=>'2026-09-20 18:00:00.000000',
        'opened_at'=>'2026-09-20 18:01:00.000000',
        'updated_at'=>'2026-09-20 18:01:00.000000',
    ]
);

(require $root . '/database/migrations/20260920_0050_add_tournament_rules_consent.php')->up($db);

$expectedRules = TournamentRegistrationService::canonicalRulesSnapshot('tictactoe', 8);
$expectedRulesJson = json_encode(
    $expectedRules,
    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
);
$assertSame(
    TournamentRegistrationService::RULES_VERSION,
    (string)$db->fetchValue('SELECT rules_version FROM mgw_tournaments WHERE tournament_id=:id', ['id'=>'tour_mvp21_2_backfill']),
    'Migration must backfill the current rules version into the existing staging tournament.'
);
$assertSame(
    hash('sha256', $expectedRulesJson),
    (string)$db->fetchValue('SELECT rules_sha256 FROM mgw_tournaments WHERE tournament_id=:id', ['id'=>'tour_mvp21_2_backfill']),
    'Backfilled rules hash must equal the canonical immutable rules snapshot.'
);

$ids = [];
for ($i = 1; $i <= 10; $i++) {
    $id = sprintf('MGW-%016d', $i);
    $ids[$i] = $id;
    $db->execute(
        'INSERT INTO mgw_users (mgw_id,status,nickname) VALUES (:id,:status,:nickname)',
        ['id'=>$id,'status'=>'active','nickname'=>'Player ' . $i]
    );
}

$clock = '2026-09-20 18:02:00.000000';
$ledger = new LedgerWriteService($db, static function () use (&$clock): string {
    return $clock;
});
for ($i = 1; $i <= 10; $i++) {
    $ledger->postAvailableDelta([
        'operation_key'=>'mvp21_2:grant:' . $i,
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
$before = $service->snapshot($ids[1], 'legacy:tg-1');
$rules = $before['tournament']['rules'];
$assertSame(TournamentRegistrationService::RULES_VERSION, $rules['version'], 'Player snapshot must expose rules version.');
$assertSame('ru', $rules['language'], 'Current supported rules language must be durable.');
$assertSame($expectedRules, $rules['snapshot'], 'Player snapshot must expose the exact immutable rules text.');
$assertTrue(preg_match('/^[a-f0-9]{64}$/', (string)$rules['sha256']) === 1, 'Rules snapshot must expose SHA-256 identity.');

$assertThrows(
    fn() => $service->register($ids[1], 'legacy:tg-1', new DateTimeImmutable('2026-09-20T18:03:00Z')),
    'подтвердите согласие',
    'Server must reject registration without explicit rules consent.'
);
$balance1 = $ledger->getBalance('legacy:tg-1', 'mgw_coin');
$assertSame(100000, $balance1['available_amount'], 'Missing consent must fail before reserving coins.');
$assertSame(0, $balance1['reserved_amount'], 'Missing consent must not create a hold.');

$badConsent = [
    'accepted'=>true,
    'version'=>(string)$rules['version'],
    'language'=>(string)$rules['language'],
    'sha256'=>str_repeat('0', 64),
];
$assertThrows(
    fn() => $service->register($ids[1], 'legacy:tg-1', new DateTimeImmutable('2026-09-20T18:03:30Z'), $badConsent),
    'обновились',
    'Stale or modified rules identity must fail closed.'
);

$consent = [
    'accepted'=>true,
    'version'=>(string)$rules['version'],
    'language'=>(string)$rules['language'],
    'sha256'=>(string)$rules['sha256'],
];
$clock = '2026-09-20 18:04:00.000000';
$registered = $service->register(
    $ids[1],
    'legacy:tg-1',
    new DateTimeImmutable('2026-09-20T18:04:00Z'),
    $consent
);
$assertSame('registered', $registered['registration']['state'], 'Consent-backed registration must succeed.');
$assertSame(true, $registered['registration']['rules_consent']['accepted'], 'Registration must expose accepted consent.');
$assertSame($rules['version'], $registered['registration']['rules_consent']['version'], 'Registration must freeze accepted rules version.');
$assertSame($rules['language'], $registered['registration']['rules_consent']['language'], 'Registration must freeze accepted rules language.');
$assertSame($rules['sha256'], $registered['registration']['rules_consent']['sha256'], 'Registration must freeze accepted rules hash.');
$assertSame('2026-09-20 18:04:00.000000', $registered['registration']['rules_consent']['accepted_at_utc'], 'Consent time must be server-authored.');
$assertSame(50000, $registered['balance']['available_amount'], 'Rules consent must not change the canonical 50,000 reservation amount.');
$assertSame(50000, $registered['balance']['reserved_amount'], 'Tournament entry remains reserved, not spent.');

$duplicate = $service->register($ids[1], 'legacy:tg-1', null, $consent);
$assertSame(1, $duplicate['tournament']['registered_count'], 'Duplicate consent/register must remain idempotent.');
$assertSame(1, (int)$db->fetchValue(
    "SELECT COUNT(*) FROM mgw_reservations WHERE account_ref='legacy:tg-1' AND status='active'"
), 'Duplicate registration must preserve one canonical reservation.');

$left = $service->leave($ids[1], 'legacy:tg-1', new DateTimeImmutable('2026-09-20T18:05:00Z'));
$assertSame('withdrawn', $left['registration']['state'], 'Player may still leave before tournament fills.');
$assertSame(100000, $left['balance']['available_amount'], 'Pre-full leave must restore all 50,000.');
$assertSame(0, $left['balance']['reserved_amount'], 'Pre-full leave must release hold.');

$service->register($ids[1], 'legacy:tg-1', new DateTimeImmutable('2026-09-20T18:06:00Z'), $consent);
$last = null;
for ($i = 2; $i <= 8; $i++) {
    $clock = '2026-09-20 18:' . sprintf('%02d', 6 + $i) . ':00.000000';
    $last = $service->register(
        $ids[$i],
        'legacy:tg-' . $i,
        new DateTimeImmutable('2026-09-20T18:' . sprintf('%02d', 6 + $i) . ':00Z'),
        $consent
    );
}

$assertSame('waiting_for_date', $last['tournament']['state'], 'Last valid seat must atomically close registration into wait-for-date.');
$assertSame(true, $last['tournament']['waiting_for_date'], 'Public tournament must expose wait-for-date state.');
$assertSame('full', $last['tournament']['registration_closed_reason'], 'Automatic close reason must be full.');
$assertSame(true, $last['transition']['registration_closed_now'], 'Exact last-seat write must expose one close transition.');
$assertSame(8, $last['tournament']['registered_count'], 'Full tournament must contain exactly capacity registrations.');
$assertSame(8, (int)$db->fetchValue(
    "SELECT COUNT(*) FROM mgw_tournament_registrations
     WHERE tournament_id='tour_mvp21_2_backfill'
       AND registration_state='registered'
       AND rules_accepted_at_utc IS NOT NULL"
), 'Every locked participant must have durable rules consent.');

$assertThrows(
    fn() => $service->register($ids[9], 'legacy:tg-9', null, $consent),
    'закрыта',
    'Ninth player must be rejected after atomic auto-close.'
);
$balance9 = $ledger->getBalance('legacy:tg-9', 'mgw_coin');
$assertSame(100000, $balance9['available_amount'], 'Rejected ninth player must not be debited.');
$assertSame(0, $balance9['reserved_amount'], 'Rejected ninth player must not get a reservation.');

$lastRetry = $service->register($ids[8], 'legacy:tg-8', null, $consent);
$assertSame('registered', $lastRetry['registration']['state'], 'Lost-response retry by accepted last player must remain idempotent after close.');
$assertSame(8, $lastRetry['tournament']['registered_count'], 'Last-player retry must not create a ninth seat.');

$assertThrows(
    fn() => $service->leave($ids[1], 'legacy:tg-1'),
    'текущего состояния',
    'Wait-for-date state must lock voluntary registration leave.'
);

$storedRulesBefore = (string)$db->fetchValue(
    'SELECT rules_snapshot_json FROM mgw_tournaments WHERE tournament_id=:id',
    ['id'=>'tour_mvp21_2_backfill']
);
$assertSame($expectedRulesJson, $storedRulesBefore, 'Existing tournament rules must stay byte-stable after registrations and close.');

$notificationData = [
    'users'=>[
        '9001'=>['id'=>'9001','mgw_id'=>$ids[10],'mgw_identity_provider'=>'telegram'],
    ],
    'notifications'=>[],
];
$bridge = new TournamentAdminNotificationBridge();
$notice = $bridge->emitRegistrationFull(
    $notificationData,
    ['9001'],
    $last,
    new DateTimeImmutable('2026-09-20T18:20:00Z')
);
$assertSame(1, $notice['recipient_count'], 'Full tournament must create one canonical admin bell event for one admin.');
$assertSame('Состав турнира набран', $notice['title'], 'Admin alert must identify the full-tournament event.');
$assertSame(1, count($notificationData['notifications']), 'Admin alert must use the existing notification stream.');
$bridge->emitRegistrationFull(
    $notificationData,
    ['9001'],
    $last,
    new DateTimeImmutable('2026-09-20T18:20:30Z')
);
$assertSame(1, count($notificationData['notifications']), 'Repeated full-tournament alert must be idempotent by request identity.');

$assertTrue($assertions >= 35, 'MVP-21.2 model coverage must remain substantial.');
fwrite(STDOUT, "Mvp21_2TournamentRulesTest: {$assertions} assertions passed\n");
