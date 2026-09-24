<?php
declare(strict_types=1);

require_once __DIR__ . '/../database/DatabaseConnectionInterface.php';
require_once __DIR__ . '/../database/DatabaseExceptionClassifier.php';
require_once __DIR__ . '/../database/PdoDatabaseConnection.php';
require_once __DIR__ . '/../database/DatabaseMigrationInterface.php';
require_once __DIR__ . '/../accounts/MgwIdGenerator.php';
require_once __DIR__ . '/../support/SupportTicketService.php';

function support_assert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$pdo = new PDO('sqlite::memory:');
$database = new PdoDatabaseConnection($pdo);
$database->execute('PRAGMA foreign_keys = ON');
$database->execute('CREATE TABLE mgw_users (mgw_id TEXT NOT NULL PRIMARY KEY)');

$migration = require __DIR__ . '/../database/migrations/20260924_0061_create_support_tickets.php';
$migration->up($database);

$userA = MgwIdGenerator::generate();
$userB = MgwIdGenerator::generate();
$database->execute('INSERT INTO mgw_users (mgw_id) VALUES (:id)', ['id' => $userA]);
$database->execute('INSERT INTO mgw_users (mgw_id) VALUES (:id)', ['id' => $userB]);

$service = new SupportTicketService($database);
$numbers = [];
for ($index = 0; $index < 100; $index++) {
    $ticket = $service->createTicket(
        $userA,
        $index % 2 === 0 ? 'telegram' : 'google_play',
        $index % 3 === 0 ? 'technical' : 'feedback',
        $index === 99 ? 'critical' : 'normal',
        'Isolation ticket ' . $index,
        'Thread root message ' . $index,
        ['game_id' => 'game_' . $index],
        []
    );
    $number = (string)$ticket['ticket_number'];
    support_assert($number !== '', 'ticket number must exist');
    support_assert(!isset($numbers[$number]), 'ticket numbers must be unique');
    $numbers[$number] = true;
    support_assert(count($ticket['messages']) === 1, 'new ticket must own exactly one initial message');
}

$queue = $service->adminQueue([], 100);
support_assert(count($queue) === 100, '100 support tickets must remain independently visible');

$normalQueue = array_values(array_filter($queue, static fn(array $row): bool => (string)($row['priority'] ?? '') === 'normal'));
support_assert(count($normalQueue) >= 2, 'fixture must contain at least two normal-priority tickets');
$targetNumber = (string)$normalQueue[0]['ticket_number'];
$otherNumber = (string)$normalQueue[1]['ticket_number'];
$actor = 'telegram:admin-test';

$service->assignOwner($targetNumber, $actor, $actor);
$service->setStatus($targetNumber, 'in_progress', $actor);
$service->setPriority($targetNumber, 'critical', $actor);
$updated = $service->replyByAdmin(
    $targetNumber,
    $actor,
    'Admin reply isolated to one ticket.',
    [[
        'file_name' => 'proof.txt',
        'mime_type' => 'text/plain',
        'content_base64' => base64_encode('support-proof'),
    ]]
);

support_assert($updated['owner_ref'] === $actor, 'owner must persist');
support_assert($updated['status'] === 'in_progress', 'status must persist');
support_assert($updated['priority'] === 'critical', 'priority must persist');
support_assert(count($updated['messages']) === 2, 'target thread must contain isolated admin reply');
support_assert(count($updated['history']) >= 5, 'owner/status/priority/reply must be durable history');

$other = $service->adminTicket($otherNumber);
support_assert(count($other['messages']) === 1, 'another ticket thread must not receive target reply');
support_assert($other['owner_ref'] === null, 'another ticket owner must stay isolated');

$attachment = null;
foreach ($updated['messages'] as $messageRow) {
    if ((string)($messageRow['actor_type'] ?? '') !== 'admin') continue;
    if (!empty($messageRow['attachments'][0]) && is_array($messageRow['attachments'][0])) {
        $attachment = $messageRow['attachments'][0];
        break;
    }
}
support_assert(is_array($attachment), 'admin reply attachment metadata must exist');
$download = $service->attachmentForUser((string)$attachment['attachment_id'], $userA);
support_assert(base64_decode((string)$download['content_base64'], true) === 'support-proof', 'attachment bytes must round-trip');

$crossUserDenied = false;
try {
    $service->ticketForUser($targetNumber, $userB);
} catch (SupportTicketException $error) {
    $crossUserDenied = $error->reason === 'ticket_not_found';
}
support_assert($crossUserDenied, 'cross-user ticket read must fail closed');

$crossAttachmentDenied = false;
try {
    $service->attachmentForUser((string)$attachment['attachment_id'], $userB);
} catch (SupportTicketException $error) {
    $crossAttachmentDenied = $error->reason === 'attachment_not_found';
}
support_assert($crossAttachmentDenied, 'cross-user attachment read must fail closed');

$metrics = $service->queueMetrics();
support_assert((int)$metrics['open_total'] === 100, 'all 100 non-terminal tickets must remain counted');
support_assert((int)$metrics['critical_open'] >= 1, 'critical priority must remain represented');

echo "MVP-22.1 SupportTicketServiceTest OK: 100 isolated tickets + owner/status/history/thread/attachment boundaries.\n";
