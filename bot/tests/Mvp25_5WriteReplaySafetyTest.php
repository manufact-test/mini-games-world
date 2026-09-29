<?php
declare(strict_types=1);

require_once __DIR__ . '/../database/DatabaseConnectionInterface.php';
require_once __DIR__ . '/../database/DatabaseExceptionClassifier.php';
require_once __DIR__ . '/../database/PdoDatabaseConnection.php';
require_once __DIR__ . '/../database/DatabaseMigrationInterface.php';
require_once __DIR__ . '/../accounts/MgwIdGenerator.php';
require_once __DIR__ . '/../support/SupportTicketService.php';
require_once __DIR__ . '/../social/PlayerReportService.php';

if (!extension_loaded('pdo_sqlite')) {
    fwrite(STDOUT, "SKIP: pdo_sqlite is unavailable\n");
    exit(0);
}

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('PRAGMA foreign_keys = ON');
$db = new PdoDatabaseConnection($pdo);

$db->execute(<<<'SQL'
CREATE TABLE mgw_users (
    mgw_id TEXT NOT NULL PRIMARY KEY,
    status TEXT NOT NULL DEFAULT 'active',
    nickname TEXT NOT NULL,
    updated_at_utc TEXT NOT NULL
)
SQL);
$db->execute('CREATE TABLE mgw_matches (match_id TEXT NOT NULL PRIMARY KEY)');

(require __DIR__ . '/../database/migrations/20260819_0012_create_player_reports.php')->up($db);
(require __DIR__ . '/../database/migrations/20260924_0061_create_support_tickets.php')->up($db);

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

$userA = MgwIdGenerator::generate();
$userB = MgwIdGenerator::generate();
$now = '2026-09-30 00:00:00.000000';
foreach ([[$userA,'ReplayA'],[$userB,'ReplayB']] as [$id,$nickname]) {
    $db->execute(
        'INSERT INTO mgw_users (mgw_id,status,nickname,updated_at_utc)
         VALUES (:id,:status,:nickname,:updated_at)',
        ['id'=>$id,'status'=>'active','nickname'=>$nickname,'updated_at'=>$now]
    );
}

$support = new SupportTicketService($db);
$attachment = [[
    'file_name'=>'proof.txt',
    'mime_type'=>'text/plain',
    'content_base64'=>base64_encode('same replay attachment'),
]];

$created = $support->createTicket(
    $userA,
    'telegram',
    'technical',
    'normal',
    'Replay-safe support ticket',
    'The exact same create request must be idempotent.',
    ['game_id'=>'game-replay-1'],
    $attachment
);
$createdReplay = $support->createTicket(
    $userA,
    'telegram',
    'technical',
    'normal',
    'Replay-safe support ticket',
    'The exact same create request must be idempotent.',
    ['game_id'=>'game-replay-1'],
    $attachment
);
$assertSame($created['ticket_id'], $createdReplay['ticket_id'], 'Exact support create replay must reuse ticket');
$assertSame(true, $createdReplay['_request_replayed'] ?? null, 'Support create replay must be marked for notifier suppression');
$assertSame(1, (int)$db->fetchValue('SELECT COUNT(*) FROM mgw_support_tickets'), 'Support create replay must not insert a second ticket');
$assertSame(1, (int)$db->fetchValue('SELECT COUNT(*) FROM mgw_support_ticket_messages'), 'Support create replay must not insert a second initial message');
$assertSame(1, (int)$db->fetchValue('SELECT COUNT(*) FROM mgw_support_ticket_attachments'), 'Support create replay must not duplicate attachment bytes');

$changedCreate = $support->createTicket(
    $userA,
    'telegram',
    'technical',
    'normal',
    'Replay-safe support ticket changed',
    'The exact same create request must be idempotent.',
    ['game_id'=>'game-replay-1'],
    $attachment
);
$assertTrue($changedCreate['ticket_id'] !== $created['ticket_id'], 'Changed support create payload must create a new ticket');
$assertSame(2, (int)$db->fetchValue('SELECT COUNT(*) FROM mgw_support_tickets'), 'Changed support create must remain a real write');

$reply = $support->replyByUser(
    (string)$created['ticket_number'],
    $userA,
    'Exact duplicate reply body.',
    $attachment
);
$replyReplay = $support->replyByUser(
    (string)$created['ticket_number'],
    $userA,
    'Exact duplicate reply body.',
    $attachment
);
$assertSame((string)$created['ticket_id'], (string)$replyReplay['ticket_id'], 'Reply replay must stay on the same ticket');
$assertSame(true, $replyReplay['_request_replayed'] ?? null, 'Reply replay must be marked for notifier suppression');
$assertSame(
    2,
    (int)$db->fetchValue(
        'SELECT COUNT(*) FROM mgw_support_ticket_messages WHERE ticket_id = :ticket_id',
        ['ticket_id'=>$created['ticket_id']]
    ),
    'Exact reply replay must add only one user reply after the initial message'
);
$assertSame(
    2,
    (int)$db->fetchValue(
        'SELECT COUNT(*) FROM mgw_support_ticket_attachments WHERE ticket_id = :ticket_id',
        ['ticket_id'=>$created['ticket_id']]
    ),
    'Exact reply replay must not duplicate reply attachment bytes'
);

$changedReply = $support->replyByUser(
    (string)$created['ticket_number'],
    $userA,
    'Changed reply body.',
    $attachment
);
$assertSame(false, $changedReply['_request_replayed'] ?? null, 'Changed support reply must remain a real write');
$assertSame(
    3,
    (int)$db->fetchValue(
        'SELECT COUNT(*) FROM mgw_support_ticket_messages WHERE ticket_id = :ticket_id',
        ['ticket_id'=>$created['ticket_id']]
    ),
    'Changed support reply must append one new message'
);

$reports = new PlayerReportService($db);
$report = $reports->submit($userA, $userB, 'spam', 'Exact duplicate report body.');
$reportReplay = $reports->submit($userA, $userB, 'spam', 'Exact duplicate report body.');
$assertSame($report['report_id'], $reportReplay['report_id'], 'Exact report replay must reuse report id');
$assertSame(1, (int)$db->fetchValue('SELECT COUNT(*) FROM mgw_player_reports'), 'Exact report replay must not insert a second report');

$changedReport = $reports->submit($userA, $userB, 'spam', 'Changed report body.');
$assertTrue($changedReport['report_id'] !== $report['report_id'], 'Changed report payload must create a new report');
$assertSame(2, (int)$db->fetchValue('SELECT COUNT(*) FROM mgw_player_reports'), 'Changed report must remain a real write');

fwrite(STDOUT, "Mvp25_5WriteReplaySafetyTest: {$assertions} assertions passed\n");
