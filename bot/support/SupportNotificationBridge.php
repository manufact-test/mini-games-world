<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/notifications/AdminNotificationEventService.php';

final class SupportNotificationBridge
{
    public function __construct(private array $config) {}

    public function notifyUserAboutAdminReply(array $ticket, string $actorRef): array
    {
        $ticketNumber = trim((string)($ticket['ticket_number'] ?? ''));
        $requesterMgwId = trim((string)($ticket['requester_mgw_id'] ?? ''));
        if ($ticketNumber === '' || $requesterMgwId === '') {
            throw new RuntimeException('Support reply notification has no ticket recipient.');
        }

        $messageId = '';
        $messages = is_array($ticket['messages'] ?? null) ? $ticket['messages'] : [];
        for ($index = count($messages) - 1; $index >= 0; $index--) {
            $message = $messages[$index] ?? null;
            if (!is_array($message) || (string)($message['actor_type'] ?? '') !== 'admin') continue;
            $messageId = trim((string)($message['message_id'] ?? ''));
            if ($messageId !== '') break;
        }
        if ($messageId === '') {
            throw new RuntimeException('Support reply notification has no admin message ID.');
        }

        $storage = StorageFactory::createJson((string)($this->config['data_dir'] ?? dirname(__DIR__) . '/data'));
        $service = new AdminNotificationEventService();
        $event = [
            'request_id' => 'support-admin-reply:' . $messageId,
            'source_type' => 'support',
            'audience_type' => 'support',
            'audience_ref' => 'ticket:' . $ticketNumber,
            'recipient_mgw_ids' => [$requesterMgwId],
            'title' => 'Ответ поддержки',
            'text' => 'По обращению ' . $ticketNumber . ' пришёл новый ответ.',
            'deep_link' => 'support:ticket:' . $ticketNumber,
        ];

        return $storage->transaction(
            static fn(array &$data): array => $service->createEvent($data, $event, $actorRef)
        );
    }
}
