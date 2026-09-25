<?php
declare(strict_types=1);

require_once __DIR__ . '/../database/DatabaseConnectionInterface.php';
require_once __DIR__ . '/../database/DatabaseExceptionClassifier.php';
require_once __DIR__ . '/../database/PdoDatabaseConnection.php';
require_once __DIR__ . '/../database/DatabaseMigrationInterface.php';
require_once __DIR__ . '/../accounts/MgwIdGenerator.php';
require_once __DIR__ . '/../support/SupportTicketService.php';

$dsn = trim((string)getenv('MGW_SUPPORT_MYSQL_DSN'));
$user = (string)getenv('MGW_SUPPORT_MYSQL_USER');
$pass = (string)getenv('MGW_SUPPORT_MYSQL_PASS');
if ($dsn === '') {
    fwrite(STDOUT, "SupportTicketMySqlIntegrationTest skipped: no DSN.\n");
    return;
}

$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};

$pdo = new PDO($dsn, $user, $pass, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
]);
$database = new PdoDatabaseConnection($pdo);

foreach ([
    'mgw_support_ticket_events',
    'mgw_support_ticket_attachments',
    'mgw_support_ticket_messages',
    'mgw_support_tickets',
    'mgw_users',
] as $table) {
    $database->execute('DROP TABLE IF EXISTS ' . $table);
}

$database->execute(<<<'SQL'
CREATE TABLE mgw_users (
    mgw_id VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

$migration = require __DIR__ . '/../database/migrations/20260924_0061_create_support_tickets.php';
$migration->up($database);

$mgwId = MgwIdGenerator::generate();
$database->execute('INSERT INTO mgw_users (mgw_id) VALUES (:id)', ['id' => $mgwId]);
$service = new SupportTicketService($database);

$ticketNumbers = [];
for ($index = 0; $index < 100; $index++) {
    $ticket = $service->createTicket(
        $mgwId,
        $index % 2 === 0 ? 'telegram' : 'google_play',
        $index % 4 === 0 ? 'technical' : 'feedback',
        $index === 0 ? 'critical' : 'normal',
        'MySQL support ticket ' . $index,
        'Thread root ' . $index,
        ['tournament_id' => 'tournament_' . $index],
        []
    );
    $number = (string)$ticket['ticket_number'];
    $assert($number !== '' && !isset($ticketNumbers[$number]), 'MySQL ticket numbers must stay unique.');
    $ticketNumbers[$number] = true;
}

$queue = $service->adminQueue([], 100);
$assert(count($queue) === 100, 'MySQL queue must keep 100 tickets isolated.');

$target = null;
foreach ($queue as $row) {
    if ((string)$row['priority'] === 'normal') {
        $target = (string)$row['ticket_number'];
        break;
    }
}
$assert(is_string($target) && $target !== '', 'Normal support ticket fixture is required.');

$userReply = $service->replyByUser(
    $target,
    $mgwId,
    'User follow-up with near-limit attachment.',
    [[
        'file_name' => 'near-limit.jpg',
        'mime_type' => 'image/jpeg',
        'content_base64' => base64_encode(str_repeat('A', 1900000)),
    ]]
);
$assert(count($userReply['messages']) === 2, 'User reply must persist as a second isolated message.');
$userReplyAttachment = $userReply['messages'][1]['attachments'][0] ?? null;
$assert(is_array($userReplyAttachment), 'User reply attachment metadata must persist.');
$userReplyDownload = $service->attachmentForUser((string)$userReplyAttachment['attachment_id'], $mgwId);
$assert((int)$userReplyDownload['size_bytes'] === 1900000, 'Near-limit user attachment must round-trip on MySQL.');

$actor = 'telegram:mysql-ci-admin';
$service->assignOwner($target, $actor, $actor);
$service->setStatus($target, 'in_progress', $actor);
$service->setPriority($target, 'high', $actor);
$service->replyByAdmin($target, $actor, 'MySQL admin reply.');
$service->updateRelated($target, [
    'game_id' => str_repeat('g', 96),
    'payment_id' => str_repeat('p', 96),
    'tournament_id' => str_repeat('t', 64),
    'operation_id' => str_repeat('o', 191),
], $actor);
$detail = $service->adminTicket($target);

$assert($detail['owner_ref'] === $actor, 'MySQL owner must persist.');
$assert($detail['related']['operation_id'] === str_repeat('o', 191), 'Long related IDs must persist without scalar audit overflow.');
$assert($detail['status'] === 'waiting_user', 'MySQL admin reply must persist waiting-user status.');
$assert($detail['priority'] === 'high', 'MySQL priority must persist.');
$assert(count($detail['messages']) === 3, 'MySQL ticket thread must stay isolated across user/admin replies.');
$assert(count($detail['history']) >= 5, 'MySQL owner/status/priority/reply history must persist.');

foreach ([
    'mgw_support_ticket_events',
    'mgw_support_ticket_attachments',
    'mgw_support_ticket_messages',
    'mgw_support_tickets',
    'mgw_users',
] as $table) {
    $database->execute('DROP TABLE IF EXISTS ' . $table);
}

fwrite(STDOUT, "MVP-22.1 MySQL 8.4 support integration OK: 100 tickets + owner/status/history/thread.\n");
