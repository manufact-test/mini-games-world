<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/notifications/AdminNotificationEventService.php';
require_once dirname(__DIR__) . '/services/NotificationService.php';

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

        $legacyUserId = $this->canonicalLegacyUserId($requesterMgwId);
        $storage = StorageFactory::createJson((string)($this->config['data_dir'] ?? dirname(__DIR__) . '/data'));
        $service = new AdminNotificationEventService();
        $event = [
            'request_id' => 'support-admin-reply:' . $messageId,
            'source_type' => 'support',
            'audience_type' => 'support',
            'audience_ref' => 'ticket:' . $ticketNumber,
            'recipient_mgw_ids' => [$requesterMgwId],
            'recipient_legacy_user_ids' => [$legacyUserId],
            'title' => 'Ответ поддержки',
            'text' => 'По обращению ' . $ticketNumber . ' пришёл новый ответ.',
            'deep_link' => 'support:ticket:' . $ticketNumber,
        ];

        return $storage->transaction(function (array &$data) use (
            $service,
            $event,
            $actorRef,
            $legacyUserId,
            $ticketNumber
        ): array {
            $published = $service->createEvent($data, $event, $actorRef);
            $eventId = trim((string)($published['event_id'] ?? ''));
            if ($eventId === '') {
                throw new RuntimeException('Support reply notification has no canonical event ID.');
            }

            $visible = (new NotificationService())->userNotifications($data, $legacyUserId, 100);
            $matched = false;
            foreach ($visible as $notification) {
                if (!is_array($notification)) continue;
                if ((string)($notification['notification_event_id'] ?? '') !== $eventId) continue;
                if ((string)($notification['type'] ?? '') !== 'support_message') continue;
                if ((string)($notification['deep_link'] ?? '') !== 'support:ticket:' . $ticketNumber) continue;
                $matched = true;
                break;
            }
            if (!$matched) {
                throw new RuntimeException('Support reply event is not visible in the recipient bell feed.');
            }

            $published['feed_verified'] = true;
            return $published;
        });
    }

    private function canonicalLegacyUserId(string $requesterMgwId): string
    {
        $databaseConfig = DatabaseConfig::fromApplicationConfig($this->config);
        if (!$databaseConfig->enabled()) {
            throw new RuntimeException('Support reply notification requires canonical account ownership.');
        }

        $database = PdoConnectionFactory::create($databaseConfig);
        $rows = $database->fetchAll(
            'SELECT legacy_user_id
             FROM mgw_account_ownership
             WHERE mgw_id = :mgw_id AND ownership_status = :status',
            [
                'mgw_id' => $requesterMgwId,
                'status' => 'active',
            ]
        );
        if (count($rows) !== 1) {
            throw new RuntimeException('Support reply recipient ownership is unavailable or ambiguous.');
        }

        $legacyUserId = trim((string)($rows[0]['legacy_user_id'] ?? ''));
        if ($legacyUserId === '') {
            throw new RuntimeException('Support reply recipient transport identity is unavailable.');
        }
        return $legacyUserId;
    }
}
