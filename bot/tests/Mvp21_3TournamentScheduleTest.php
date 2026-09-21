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
require $root . '/tournaments/TournamentParticipantNotificationBridge.php';

if (!extension_loaded('pdo_sqlite')) {
    throw new RuntimeException('Mvp21_3TournamentScheduleTest requires pdo_sqlite.');
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
(require $root . '/database/migrations/20260920_0051_refresh_tournament_rules_copy.php')->up($db);
(require $root . '/database/migrations/20260921_0052_add_tournament_schedule.php')->up($db);

$ids = [];
for ($i = 1; $i <= 9; $i++) {
    $id = sprintf('MGW-%016d', $i);
    $ids[$i] = $id;
    $db->execute(
        'INSERT INTO mgw_users (mgw_id,status,nickname) VALUES (:id,:status,:nickname)',
        ['id'=>$id,'status'=>'active','nickname'=>'Player ' . $i]
    );
}

$ledgerClock = '2026-09-21 09:00:00.000000';
$ledger = new LedgerWriteService($db, static function () use (&$ledgerClock): string {
    return $ledgerClock;
});
for ($i = 1; $i <= 9; $i++) {
    $ledger->postAvailableDelta([
        'operation_key'=>'mvp21_3:grant:' . $i,
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
$draft = $service->createDraft(
    'tictactoe',
    8,
    'MVP-21.3 Schedule',
    'test:admin',
    new DateTimeImmutable('2026-09-21T09:05:00Z')
);
$tournamentId = (string)$draft['tournament']['tournament_id'];
$opened = $service->openRegistration(
    $tournamentId,
    'test:admin',
    new DateTimeImmutable('2026-09-21T09:06:00Z')
);
$rules = $opened['tournament']['rules'];
$consent = [
    'accepted'=>true,
    'version'=>(string)$rules['version'],
    'language'=>(string)$rules['language'],
    'sha256'=>(string)$rules['sha256'],
];

$last = null;
for ($i = 1; $i <= 8; $i++) {
    $minute = 6 + $i;
    $ledgerClock = sprintf('2026-09-21 09:%02d:00.000000', $minute);
    $last = $service->register(
        $ids[$i],
        'legacy:tg-' . $i,
        new DateTimeImmutable(sprintf('2026-09-21T09:%02d:00Z', $minute)),
        $consent
    );
}
$assertSame('waiting_for_date', $last['tournament']['state'], 'Full accepted roster must reach wait-for-date before scheduling.');
$assertSame(8, $last['tournament']['registered_count'], 'Schedule precondition must preserve all eight participants.');

$assignedAt = new DateTimeImmutable('2026-09-21T10:00:00Z');
$startAt = '2026-09-23T18:30:00Z';
$scheduled = $service->assignFinalDate(
    $tournamentId,
    $startAt,
    'test:admin',
    $assignedAt
);
$assertSame(TournamentRegistrationService::STATE_SCHEDULED, $scheduled['tournament']['state'], 'Final date assignment must move tournament to scheduled.');
$assertSame(true, $scheduled['tournament']['scheduled'], 'Public tournament snapshot must expose scheduled state.');
$assertSame(false, $scheduled['tournament']['waiting_for_date'], 'Scheduled tournament must no longer report wait-for-date.');
$assertSame('2026-09-23 18:30:00.000000', $scheduled['tournament']['scheduled_start_at_utc'], 'Final start must be stored in canonical UTC.');
$assertSame('test:admin', $scheduled['tournament']['scheduled_by_ref'], 'Date assignment must retain admin audit identity.');
$assertSame('2026-09-21 10:00:00.000000', $scheduled['tournament']['scheduled_at_utc'], 'Date assignment must retain server-authored assignment time.');

$participants = $service->registeredParticipantMgwIds($tournamentId);
$assertSame(8, count($participants), 'Participant reminder audience must be exactly the registered roster.');
$assertSame($ids[1], $participants[0], 'Participant audience must retain deterministic registration order.');
$assertSame($ids[8], $participants[7], 'Participant audience must include the final admitted seat.');
$assertTrue(!in_array($ids[9], $participants, true), 'Non-participant must never enter tournament reminder audience.');

$same = $service->assignFinalDate(
    $tournamentId,
    $startAt,
    'test:admin',
    new DateTimeImmutable('2026-09-21T10:05:00Z')
);
$assertSame('2026-09-23 18:30:00.000000', $same['tournament']['scheduled_start_at_utc'], 'Exact retry must be idempotent.');

$assertThrows(
    fn() => $service->assignFinalDate(
        $tournamentId,
        '2026-09-24T18:30:00Z',
        'test:admin',
        new DateTimeImmutable('2026-09-21T10:05:00Z')
    ),
    'Перенос или задержка',
    'MVP-21.3 must reject reschedule/delay.'
);
$assertThrows(
    fn() => $service->assignFinalDate(
        $tournamentId,
        '2026-09-21T09:59:00Z',
        'test:admin',
        new DateTimeImmutable('2026-09-21T10:05:00Z')
    ),
    'должна быть в будущем',
    'Final date must never be assigned in the past.'
);

for ($i = 1; $i <= 8; $i++) {
    $balance = $ledger->getBalance('legacy:tg-' . $i, 'mgw_coin');
    $assertSame(50000, $balance['available_amount'], 'Scheduling must not alter available tournament balance for participant ' . $i . '.');
    $assertSame(50000, $balance['reserved_amount'], 'Scheduling must not consume or release reservation for participant ' . $i . '.');
}

$notificationData = ['users'=>[], 'notifications'=>[]];
for ($i = 1; $i <= 9; $i++) {
    $notificationData['users']['tg-' . $i] = [
        'id'=>'tg-' . $i,
        'mgw_id'=>$ids[$i],
        'mgw_identity_provider'=>'telegram',
    ];
}

$bridge = new TournamentParticipantNotificationBridge();
$notice = $bridge->ensureScheduleNotifications(
    $notificationData,
    $scheduled,
    $participants,
    $assignedAt
);
$assertSame(8, $notice['recipient_count'], 'All eight participants must receive the tournament schedule event.');
$assertSame([], $notice['skipped_past'], 'A start more than 24 hours away must schedule every canonical reminder.');
$assertSame(4, count($notice['created_or_existing']), 'Assignment plus day/hour/15-minute reminders must create four notification events.');
$assertSame(32, count($notificationData['notifications']), 'Four tournament bell events for eight participants must produce 32 canonical notification rows.');

$titles = [];
$scheduledTimes = [];
$recipientIds = [];
foreach ($notificationData['notifications'] as $notification) {
    if (!is_array($notification)) continue;
    $titles[(string)$notification['title']] = true;
    $scheduledTimes[(string)$notification['scheduled_at']] = true;
    $recipientIds[(string)$notification['user_id']] = true;
    $assertSame('tournament', (string)$notification['audience_type'], 'Tournament reminders must use the existing tournament bell audience type.');
    $assertSame('official-tournament:' . $tournamentId, (string)$notification['audience_ref'], 'Tournament reminder audience must remain bound to one tournament.');
}
$assertTrue(isset($titles['Дата турнира назначена']), 'Participants must receive immediate date-assigned notice.');
$assignmentRows = array_values(array_filter(
    $notificationData['notifications'],
    static fn(array $row): bool => (string)($row['title'] ?? '') === 'Дата турнира назначена'
));
$assertSame(8, count($assignmentRows), 'Date assignment must have exactly one immediate row per participant.');
$assertTrue(
    str_contains((string)$assignmentRows[0]['message'], 'ваше местное время')
        && !str_contains((string)$assignmentRows[0]['message'], ' UTC'),
    'Immediate assignment notice must never present the canonical UTC timestamp as the player clock.'
);
$assertTrue(isset($titles['Турнир начнётся через день']), 'Participants must receive 24-hour reminder.');
$assertTrue(isset($titles['Турнир начнётся через час']), 'Participants must receive one-hour reminder.');
$assertTrue(isset($titles['Турнир начнётся через 15 минут']), 'Participants must receive 15-minute reminder.');
$assertTrue(isset($scheduledTimes['2026-09-21T10:00:00+00:00']), 'Assignment event time must be immutable and retry-stable.');
$assertTrue(isset($scheduledTimes['2026-09-22T18:30:00+00:00']), 'Day reminder must be exactly 24 hours before start.');
$assertTrue(isset($scheduledTimes['2026-09-23T17:30:00+00:00']), 'Hour reminder must be exactly one hour before start.');
$assertTrue(isset($scheduledTimes['2026-09-23T18:15:00+00:00']), '15-minute reminder must be exactly 15 minutes before start.');
$assertSame(8, count($recipientIds), 'Only the eight registered participants may have reminder rows.');
$assertTrue(!isset($recipientIds['tg-9']), 'Non-participant must not receive tournament reminders.');

$bridge->ensureScheduleNotifications(
    $notificationData,
    $scheduled,
    $participants,
    new DateTimeImmutable('2026-09-21T10:05:00Z')
);
$assertSame(32, count($notificationData['notifications']), 'Reminder synchronization retry must be idempotent by stable request IDs.');

$dayRows = array_values(array_filter(
    $notificationData['notifications'],
    static fn(array $row): bool => (string)($row['title'] ?? '') === 'Турнир начнётся через день'
));
$assertSame(8, count($dayRows), 'Day reminder must have exactly one row per participant.');
$assertSame(
    false,
    NotificationCenterV2Policy::isDelivered($dayRows[0], new DateTimeImmutable('2026-09-22T18:29:59Z')),
    'Scheduled reminder must remain hidden before its delivery moment.'
);
$assertSame(
    true,
    NotificationCenterV2Policy::isDelivered($dayRows[0], new DateTimeImmutable('2026-09-22T18:30:00Z')),
    'Scheduled reminder must become available exactly at its delivery moment.'
);

if ($assertions < 60) {
    throw new RuntimeException('MVP-21.3 schedule coverage is incomplete.');
}
fwrite(STDOUT, "Mvp21_3TournamentScheduleTest: {$assertions} assertions passed\n");
