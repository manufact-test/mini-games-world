<?php
declare(strict_types=1);

final class SupportTicketException extends RuntimeException
{
    public function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }
}

final class SupportTicketService
{
    public const PLATFORM_LABELS = [
        'telegram' => 'Приложение в Telegram',
        'google_play' => 'Приложение из Google Play',
    ];

    public const CATEGORY_LABELS = [
        'feedback' => 'Обратная связь',
        'idea' => 'Предложение',
        'complaint' => 'Жалоба',
        'technical' => 'Техническая проблема',
        'payment' => 'Платёж / коины',
        'game' => 'Игра / матч',
        'tournament' => 'Турнир',
        'account' => 'Аккаунт',
        'other' => 'Другое',
    ];

    public const STATUS_LABELS = [
        'open' => 'Открыт',
        'in_progress' => 'В работе',
        'waiting_user' => 'Ждём пользователя',
        'resolved' => 'Решён',
        'closed' => 'Закрыт',
    ];

    public const PRIORITY_LABELS = [
        'low' => 'Низкий',
        'normal' => 'Обычный',
        'high' => 'Высокий',
        'critical' => 'Критический',
    ];

    private const MAX_MESSAGE_LENGTH = 4000;
    private const MAX_SUBJECT_LENGTH = 160;
    private const MAX_ATTACHMENTS_PER_MESSAGE = 3;
    private const MAX_ATTACHMENT_BYTES = 2_000_000;
    private const ALLOWED_MIME_TYPES = [
        'image/jpeg',
        'image/png',
        'image/webp',
        'image/gif',
        'application/pdf',
        'text/plain',
    ];

    public function __construct(private DatabaseConnectionInterface $database) {}

    public function createTicket(
        string $requesterMgwId,
        string $platformCode,
        string $categoryCode,
        string $priorityCode,
        string $subject,
        string $message,
        array $related = [],
        array $attachments = []
    ): array {
        $requesterMgwId = $this->requireUser($requesterMgwId);
        $platformCode = $this->enum($platformCode, self::PLATFORM_LABELS, 'invalid_platform', 'Выберите платформу.');
        $categoryCode = $this->enum($categoryCode, self::CATEGORY_LABELS, 'invalid_category', 'Выберите категорию обращения.');
        $priorityCode = $this->enum($priorityCode, self::PRIORITY_LABELS, 'invalid_priority', 'Выберите приоритет обращения.');
        $subject = $this->text($subject, self::MAX_SUBJECT_LENGTH);
        if ($subject === '') $subject = self::CATEGORY_LABELS[$categoryCode];
        $message = $this->requiredMessage($message);
        $preparedAttachments = $this->prepareAttachments($attachments);
        $related = $this->normalizeRelated($related);

        $ticketId = 'ticket_' . bin2hex(random_bytes(16));
        $ticketNumber = $this->newTicketNumber();
        $messageId = 'ticketmsg_' . bin2hex(random_bytes(16));
        $now = $this->timestamp();

        $this->database->transaction(function () use (
            $ticketId,
            $ticketNumber,
            $requesterMgwId,
            $platformCode,
            $categoryCode,
            $priorityCode,
            $subject,
            $message,
            $messageId,
            $preparedAttachments,
            $related,
            $now
        ): void {
            $this->database->execute(
                'INSERT INTO mgw_support_tickets (
                    ticket_id, ticket_number, requester_mgw_id, platform_code, category_code,
                    status_code, priority_code, owner_ref, subject,
                    related_game_id, related_payment_id, related_tournament_id, related_operation_id,
                    critical_alerted_at_utc, normal_summary_alerted_at_utc,
                    created_at_utc, updated_at_utc, last_message_at_utc, resolved_at_utc, closed_at_utc
                 ) VALUES (
                    :ticket_id, :ticket_number, :requester_mgw_id, :platform_code, :category_code,
                    :status_code, :priority_code, NULL, :subject,
                    :related_game_id, :related_payment_id, :related_tournament_id, :related_operation_id,
                    NULL, NULL,
                    :created_at, :updated_at, :last_message_at, NULL, NULL
                 )',
                [
                    'ticket_id' => $ticketId,
                    'ticket_number' => $ticketNumber,
                    'requester_mgw_id' => $requesterMgwId,
                    'platform_code' => $platformCode,
                    'category_code' => $categoryCode,
                    'status_code' => 'open',
                    'priority_code' => $priorityCode,
                    'subject' => $subject,
                    'related_game_id' => $related['game_id'],
                    'related_payment_id' => $related['payment_id'],
                    'related_tournament_id' => $related['tournament_id'],
                    'related_operation_id' => $related['operation_id'],
                    'created_at' => $now,
                    'updated_at' => $now,
                    'last_message_at' => $now,
                ]
            );
            $this->insertMessage($messageId, $ticketId, 'user', $requesterMgwId, $message, $now);
            $this->insertAttachments($ticketId, $messageId, $preparedAttachments, $now);
            $this->insertEvent($ticketId, 'created', $requesterMgwId, null, 'open', [
                'platform' => $platformCode,
                'category' => $categoryCode,
                'priority' => $priorityCode,
            ], $now);
        });

        return $this->ticketForUser($ticketNumber, $requesterMgwId);
    }

    public function userSnapshot(string $requesterMgwId, int $limit = 30): array
    {
        $requesterMgwId = $this->requireUser($requesterMgwId);
        $limit = max(1, min(100, $limit));
        $rows = $this->database->fetchAll(
            'SELECT * FROM mgw_support_tickets
             WHERE requester_mgw_id = :mgw_id
             ORDER BY updated_at_utc DESC, ticket_number DESC
             LIMIT ' . $limit,
            ['mgw_id' => $requesterMgwId]
        );
        return array_map(fn(array $row): array => $this->ticketSummary($row), $rows);
    }

    public function ticketForUser(string $ticketRef, string $requesterMgwId): array
    {
        $requesterMgwId = $this->requireUser($requesterMgwId);
        $ticket = $this->findTicket($ticketRef);
        if ((string)$ticket['requester_mgw_id'] !== $requesterMgwId) {
            throw new SupportTicketException('ticket_not_found', 'Обращение не найдено.');
        }
        return $this->hydrateTicket($ticket, false);
    }

    public function replyByUser(
        string $ticketRef,
        string $requesterMgwId,
        string $message,
        array $attachments = []
    ): array {
        $requesterMgwId = $this->requireUser($requesterMgwId);
        $ticket = $this->findTicket($ticketRef);
        if ((string)$ticket['requester_mgw_id'] !== $requesterMgwId) {
            throw new SupportTicketException('ticket_not_found', 'Обращение не найдено.');
        }
        if ((string)$ticket['status_code'] === 'closed') {
            throw new SupportTicketException('ticket_closed', 'Закрытое обращение нельзя продолжить. Создайте новое.');
        }

        $message = $this->requiredMessage($message);
        $preparedAttachments = $this->prepareAttachments($attachments);
        $messageId = 'ticketmsg_' . bin2hex(random_bytes(16));
        $now = $this->timestamp();
        $previousStatus = (string)$ticket['status_code'];
        $nextStatus = in_array($previousStatus, ['waiting_user', 'resolved'], true) ? 'open' : $previousStatus;

        $this->database->transaction(function () use (
            $ticket,
            $requesterMgwId,
            $message,
            $messageId,
            $preparedAttachments,
            $now,
            $previousStatus,
            $nextStatus
        ): void {
            $this->insertMessage($messageId, (string)$ticket['ticket_id'], 'user', $requesterMgwId, $message, $now);
            $this->insertAttachments((string)$ticket['ticket_id'], $messageId, $preparedAttachments, $now);
            $this->database->execute(
                'UPDATE mgw_support_tickets
                 SET status_code = :status, updated_at_utc = :updated_at, last_message_at_utc = :last_message,
                     resolved_at_utc = NULL
                 WHERE ticket_id = :ticket_id',
                [
                    'status' => $nextStatus,
                    'updated_at' => $now,
                    'last_message' => $now,
                    'ticket_id' => (string)$ticket['ticket_id'],
                ]
            );
            $this->insertEvent((string)$ticket['ticket_id'], 'user_reply', $requesterMgwId, null, null, [
                'attachment_count' => count($preparedAttachments),
            ], $now);
            if ($nextStatus !== $previousStatus) {
                $this->insertEvent((string)$ticket['ticket_id'], 'status_changed', $requesterMgwId, $previousStatus, $nextStatus, [], $now);
            }
        });

        return $this->ticketForUser((string)$ticket['ticket_number'], $requesterMgwId);
    }

    public function adminQueue(array $filters = [], int $limit = 100): array
    {
        $limit = max(1, min(200, $limit));
        $where = [];
        $params = [];

        foreach (['status' => self::STATUS_LABELS, 'priority' => self::PRIORITY_LABELS, 'category' => self::CATEGORY_LABELS, 'platform' => self::PLATFORM_LABELS] as $key => $allowed) {
            $value = strtolower(trim((string)($filters[$key] ?? '')));
            if ($value === '') continue;
            if (!isset($allowed[$value])) {
                throw new SupportTicketException('invalid_filter', 'Некорректный фильтр очереди.');
            }
            $column = match ($key) {
                'status' => 'status_code',
                'priority' => 'priority_code',
                'category' => 'category_code',
                default => 'platform_code',
            };
            $where[] = $column . ' = :' . $key;
            $params[$key] = $value;
        }

        $owner = $this->text((string)($filters['owner_ref'] ?? ''), 191);
        if ($owner !== '') {
            $where[] = 'owner_ref = :owner_ref';
            $params['owner_ref'] = $owner;
        }

        $query = $this->text((string)($filters['query'] ?? ''), 120);
        if ($query !== '') {
            $where[] = '(ticket_number LIKE :query_number OR requester_mgw_id LIKE :query_requester OR subject LIKE :query_subject)';
            $needle = '%' . $query . '%';
            $params['query_number'] = $needle;
            $params['query_requester'] = $needle;
            $params['query_subject'] = $needle;
        }

        $sql = 'SELECT * FROM mgw_support_tickets';
        if ($where !== []) $sql .= ' WHERE ' . implode(' AND ', $where);
        $sql .= " ORDER BY
            CASE priority_code WHEN 'critical' THEN 0 WHEN 'high' THEN 1 WHEN 'normal' THEN 2 ELSE 3 END,
            CASE status_code WHEN 'open' THEN 0 WHEN 'in_progress' THEN 1 WHEN 'waiting_user' THEN 2 WHEN 'resolved' THEN 3 ELSE 4 END,
            updated_at_utc DESC, ticket_number DESC
            LIMIT " . $limit;

        return array_map(fn(array $row): array => $this->ticketSummary($row), $this->database->fetchAll($sql, $params));
    }

    public function adminTicket(string $ticketRef): array
    {
        return $this->hydrateTicket($this->findTicket($ticketRef), true);
    }

    public function setStatus(string $ticketRef, string $status, string $actorRef): array
    {
        $status = $this->enum($status, self::STATUS_LABELS, 'invalid_status', 'Некорректный статус обращения.');
        return $this->changeScalar($ticketRef, 'status_code', $status, 'status_changed', $actorRef);
    }

    public function setPriority(string $ticketRef, string $priority, string $actorRef): array
    {
        $priority = $this->enum($priority, self::PRIORITY_LABELS, 'invalid_priority', 'Некорректный приоритет обращения.');
        return $this->changeScalar($ticketRef, 'priority_code', $priority, 'priority_changed', $actorRef);
    }

    public function assignOwner(string $ticketRef, ?string $ownerRef, string $actorRef): array
    {
        $ownerRef = $this->text((string)($ownerRef ?? ''), 191);
        return $this->changeScalar($ticketRef, 'owner_ref', $ownerRef !== '' ? $ownerRef : null, 'owner_changed', $actorRef);
    }

    public function updateRelated(string $ticketRef, array $related, string $actorRef): array
    {
        $ticket = $this->findTicket($ticketRef);
        $related = $this->normalizeRelated($related);
        $now = $this->timestamp();
        $before = [
            'game_id' => $ticket['related_game_id'] ?? null,
            'payment_id' => $ticket['related_payment_id'] ?? null,
            'tournament_id' => $ticket['related_tournament_id'] ?? null,
            'operation_id' => $ticket['related_operation_id'] ?? null,
        ];
        $after = $related;

        $this->database->transaction(function () use ($ticket, $related, $actorRef, $before, $after, $now): void {
            $this->database->execute(
                'UPDATE mgw_support_tickets
                 SET related_game_id = :game_id, related_payment_id = :payment_id,
                     related_tournament_id = :tournament_id, related_operation_id = :operation_id,
                     updated_at_utc = :updated_at
                 WHERE ticket_id = :ticket_id',
                [
                    'game_id' => $related['game_id'],
                    'payment_id' => $related['payment_id'],
                    'tournament_id' => $related['tournament_id'],
                    'operation_id' => $related['operation_id'],
                    'updated_at' => $now,
                    'ticket_id' => (string)$ticket['ticket_id'],
                ]
            );
            $this->insertEvent((string)$ticket['ticket_id'], 'related_ids_changed', $actorRef, null, null, [
                'before' => $before,
                'after' => $after,
            ], $now);
        });
        return $this->adminTicket((string)$ticket['ticket_number']);
    }

    public function replyByAdmin(
        string $ticketRef,
        string $actorRef,
        string $message,
        array $attachments = []
    ): array {
        $ticket = $this->findTicket($ticketRef);
        if ((string)$ticket['status_code'] === 'closed') {
            throw new SupportTicketException('ticket_closed', 'Сначала откройте обращение заново.');
        }
        $message = $this->requiredMessage($message);
        $preparedAttachments = $this->prepareAttachments($attachments);
        $messageId = 'ticketmsg_' . bin2hex(random_bytes(16));
        $now = $this->timestamp();
        $previousStatus = (string)$ticket['status_code'];
        $nextStatus = $previousStatus === 'open' ? 'in_progress' : $previousStatus;

        $this->database->transaction(function () use (
            $ticket, $actorRef, $message, $preparedAttachments, $messageId, $now, $previousStatus, $nextStatus
        ): void {
            $this->insertMessage($messageId, (string)$ticket['ticket_id'], 'admin', $actorRef, $message, $now);
            $this->insertAttachments((string)$ticket['ticket_id'], $messageId, $preparedAttachments, $now);
            $this->database->execute(
                'UPDATE mgw_support_tickets
                 SET status_code = :status, updated_at_utc = :updated_at, last_message_at_utc = :last_message
                 WHERE ticket_id = :ticket_id',
                [
                    'status' => $nextStatus,
                    'updated_at' => $now,
                    'last_message' => $now,
                    'ticket_id' => (string)$ticket['ticket_id'],
                ]
            );
            $this->insertEvent((string)$ticket['ticket_id'], 'admin_reply', $actorRef, null, null, [
                'attachment_count' => count($preparedAttachments),
            ], $now);
            if ($previousStatus !== $nextStatus) {
                $this->insertEvent((string)$ticket['ticket_id'], 'status_changed', $actorRef, $previousStatus, $nextStatus, [], $now);
            }
        });
        return $this->adminTicket((string)$ticket['ticket_number']);
    }

    public function attachmentForUser(string $attachmentId, string $requesterMgwId): array
    {
        $requesterMgwId = $this->requireUser($requesterMgwId);
        return $this->attachment($attachmentId, $requesterMgwId);
    }

    public function attachmentForAdmin(string $attachmentId): array
    {
        return $this->attachment($attachmentId, null);
    }

    public function markTelegramAlerted(string $ticketRef, string $kind): void
    {
        $ticket = $this->findTicket($ticketRef);
        $column = $kind === 'critical' ? 'critical_alerted_at_utc' : 'normal_summary_alerted_at_utc';
        $this->database->execute(
            'UPDATE mgw_support_tickets SET ' . $column . ' = :at WHERE ticket_id = :ticket_id',
            ['at' => $this->timestamp(), 'ticket_id' => (string)$ticket['ticket_id']]
        );
    }

    public function queueMetrics(): array
    {
        $rows = $this->database->fetchAll(
            "SELECT priority_code, status_code, COUNT(*) AS total
             FROM mgw_support_tickets
             GROUP BY priority_code, status_code"
        );
        $metrics = ['open_total' => 0, 'critical_open' => 0, 'unowned_open' => 0];
        foreach ($rows as $row) {
            $status = (string)($row['status_code'] ?? '');
            $priority = (string)($row['priority_code'] ?? '');
            $count = (int)($row['total'] ?? 0);
            if (!in_array($status, ['resolved', 'closed'], true)) {
                $metrics['open_total'] += $count;
                if ($priority === 'critical') $metrics['critical_open'] += $count;
            }
        }
        $metrics['unowned_open'] = (int)($this->database->fetchValue(
            "SELECT COUNT(*) FROM mgw_support_tickets
             WHERE owner_ref IS NULL AND status_code NOT IN ('resolved','closed')"
        ) ?? 0);
        return $metrics;
    }

    private function changeScalar(
        string $ticketRef,
        string $column,
        mixed $value,
        string $eventType,
        string $actorRef
    ): array {
        $allowedColumns = ['status_code', 'priority_code', 'owner_ref'];
        if (!in_array($column, $allowedColumns, true)) throw new LogicException('Invalid support scalar column.');
        $ticket = $this->findTicket($ticketRef);
        $previous = $ticket[$column] ?? null;
        if ((string)($previous ?? '') === (string)($value ?? '')) return $this->adminTicket((string)$ticket['ticket_number']);
        $now = $this->timestamp();

        $this->database->transaction(function () use ($ticket, $column, $value, $eventType, $actorRef, $previous, $now): void {
            $extra = '';
            $params = [
                'value' => $value,
                'updated_at' => $now,
                'ticket_id' => (string)$ticket['ticket_id'],
            ];
            if ($column === 'status_code') {
                $extra = ", resolved_at_utc = CASE WHEN :status_for_resolved = 'resolved' THEN :resolved_at WHEN :status_for_clear_resolved IN ('open','in_progress','waiting_user') THEN NULL ELSE resolved_at_utc END,
                           closed_at_utc = CASE WHEN :status_for_closed = 'closed' THEN :closed_at WHEN :status_for_clear_closed <> 'closed' THEN NULL ELSE closed_at_utc END";
                $params['status_for_resolved'] = $value;
                $params['resolved_at'] = $now;
                $params['status_for_clear_resolved'] = $value;
                $params['status_for_closed'] = $value;
                $params['closed_at'] = $now;
                $params['status_for_clear_closed'] = $value;
            }
            $this->database->execute(
                'UPDATE mgw_support_tickets SET ' . $column . ' = :value, updated_at_utc = :updated_at' . $extra . ' WHERE ticket_id = :ticket_id',
                $params
            );
            $this->insertEvent(
                (string)$ticket['ticket_id'],
                $eventType,
                $actorRef,
                $previous !== null ? (string)$previous : null,
                $value !== null ? (string)$value : null,
                [],
                $now
            );
        });

        return $this->adminTicket((string)$ticket['ticket_number']);
    }

    private function hydrateTicket(array $ticket, bool $includeHistory): array
    {
        $result = $this->ticketSummary($ticket);
        $messages = $this->database->fetchAll(
            'SELECT message_id, ticket_id, actor_type, actor_ref, body, created_at_utc
             FROM mgw_support_ticket_messages
             WHERE ticket_id = :ticket_id
             ORDER BY created_at_utc ASC, message_id ASC',
            ['ticket_id' => (string)$ticket['ticket_id']]
        );
        $attachments = $this->database->fetchAll(
            'SELECT attachment_id, message_id, file_name, mime_type, size_bytes, created_at_utc
             FROM mgw_support_ticket_attachments
             WHERE ticket_id = :ticket_id
             ORDER BY created_at_utc ASC, attachment_id ASC',
            ['ticket_id' => (string)$ticket['ticket_id']]
        );
        $byMessage = [];
        foreach ($attachments as $attachment) {
            $byMessage[(string)$attachment['message_id']][] = $attachment;
        }
        foreach ($messages as &$message) {
            $message['attachments'] = $byMessage[(string)$message['message_id']] ?? [];
        }
        unset($message);
        $result['messages'] = $messages;

        if ($includeHistory) {
            $result['history'] = $this->database->fetchAll(
                'SELECT event_id, event_type, actor_ref, previous_value, next_value, details_json, created_at_utc
                 FROM mgw_support_ticket_events
                 WHERE ticket_id = :ticket_id
                 ORDER BY created_at_utc ASC, event_id ASC',
                ['ticket_id' => (string)$ticket['ticket_id']]
            );
        }

        return $result;
    }

    private function ticketSummary(array $row): array
    {
        $platform = (string)($row['platform_code'] ?? '');
        $category = (string)($row['category_code'] ?? '');
        $status = (string)($row['status_code'] ?? '');
        $priority = (string)($row['priority_code'] ?? '');
        return [
            'ticket_id' => (string)($row['ticket_id'] ?? ''),
            'ticket_number' => (string)($row['ticket_number'] ?? ''),
            'requester_mgw_id' => (string)($row['requester_mgw_id'] ?? ''),
            'platform' => $platform,
            'platform_label' => self::PLATFORM_LABELS[$platform] ?? $platform,
            'category' => $category,
            'category_label' => self::CATEGORY_LABELS[$category] ?? $category,
            'status' => $status,
            'status_label' => self::STATUS_LABELS[$status] ?? $status,
            'priority' => $priority,
            'priority_label' => self::PRIORITY_LABELS[$priority] ?? $priority,
            'owner_ref' => $row['owner_ref'] !== null ? (string)$row['owner_ref'] : null,
            'subject' => (string)($row['subject'] ?? ''),
            'related' => [
                'game_id' => $row['related_game_id'] !== null ? (string)$row['related_game_id'] : null,
                'payment_id' => $row['related_payment_id'] !== null ? (string)$row['related_payment_id'] : null,
                'tournament_id' => $row['related_tournament_id'] !== null ? (string)$row['related_tournament_id'] : null,
                'operation_id' => $row['related_operation_id'] !== null ? (string)$row['related_operation_id'] : null,
            ],
            'created_at' => (string)($row['created_at_utc'] ?? ''),
            'updated_at' => (string)($row['updated_at_utc'] ?? ''),
            'last_message_at' => (string)($row['last_message_at_utc'] ?? ''),
            'resolved_at' => $row['resolved_at_utc'] !== null ? (string)$row['resolved_at_utc'] : null,
            'closed_at' => $row['closed_at_utc'] !== null ? (string)$row['closed_at_utc'] : null,
            'telegram_alert' => [
                'critical_at' => $row['critical_alerted_at_utc'] !== null ? (string)$row['critical_alerted_at_utc'] : null,
                'summary_at' => $row['normal_summary_alerted_at_utc'] !== null ? (string)$row['normal_summary_alerted_at_utc'] : null,
            ],
        ];
    }

    private function findTicket(string $ticketRef): array
    {
        $ticketRef = $this->text($ticketRef, 64);
        if ($ticketRef === '') throw new SupportTicketException('ticket_required', 'Укажите номер обращения.');
        $rows = $this->database->fetchAll(
            'SELECT * FROM mgw_support_tickets WHERE ticket_id = :ticket_id_ref OR ticket_number = :ticket_number_ref LIMIT 1',
            ['ticket_id_ref' => $ticketRef, 'ticket_number_ref' => $ticketRef]
        );
        if ($rows === []) throw new SupportTicketException('ticket_not_found', 'Обращение не найдено.');
        return $rows[0];
    }

    private function attachment(string $attachmentId, ?string $requesterMgwId): array
    {
        $attachmentId = $this->text($attachmentId, 64);
        $rows = $this->database->fetchAll(
            'SELECT a.attachment_id, a.ticket_id, a.message_id, a.file_name, a.mime_type, a.size_bytes,
                    a.content_base64, a.created_at_utc, t.requester_mgw_id
             FROM mgw_support_ticket_attachments a
             INNER JOIN mgw_support_tickets t ON t.ticket_id = a.ticket_id
             WHERE a.attachment_id = :attachment_id LIMIT 1',
            ['attachment_id' => $attachmentId]
        );
        if ($rows === []) throw new SupportTicketException('attachment_not_found', 'Вложение не найдено.');
        $row = $rows[0];
        if ($requesterMgwId !== null && (string)$row['requester_mgw_id'] !== $requesterMgwId) {
            throw new SupportTicketException('attachment_not_found', 'Вложение не найдено.');
        }
        return [
            'attachment_id' => (string)$row['attachment_id'],
            'ticket_id' => (string)$row['ticket_id'],
            'message_id' => (string)$row['message_id'],
            'file_name' => (string)$row['file_name'],
            'mime_type' => (string)$row['mime_type'],
            'size_bytes' => (int)$row['size_bytes'],
            'content_base64' => (string)$row['content_base64'],
            'created_at' => (string)$row['created_at_utc'],
        ];
    }

    private function insertMessage(string $messageId, string $ticketId, string $actorType, string $actorRef, string $body, string $now): void
    {
        $this->database->execute(
            'INSERT INTO mgw_support_ticket_messages (
                message_id, ticket_id, actor_type, actor_ref, body, created_at_utc
             ) VALUES (:message_id, :ticket_id, :actor_type, :actor_ref, :body, :created_at)',
            [
                'message_id' => $messageId,
                'ticket_id' => $ticketId,
                'actor_type' => $actorType,
                'actor_ref' => $this->text($actorRef, 191),
                'body' => $body,
                'created_at' => $now,
            ]
        );
    }

    private function insertAttachments(string $ticketId, string $messageId, array $attachments, string $now): void
    {
        foreach ($attachments as $attachment) {
            $this->database->execute(
                'INSERT INTO mgw_support_ticket_attachments (
                    attachment_id, ticket_id, message_id, file_name, mime_type,
                    size_bytes, content_base64, created_at_utc
                 ) VALUES (
                    :attachment_id, :ticket_id, :message_id, :file_name, :mime_type,
                    :size_bytes, :content_base64, :created_at
                 )',
                [
                    'attachment_id' => 'ticketatt_' . bin2hex(random_bytes(16)),
                    'ticket_id' => $ticketId,
                    'message_id' => $messageId,
                    'file_name' => $attachment['file_name'],
                    'mime_type' => $attachment['mime_type'],
                    'size_bytes' => $attachment['size_bytes'],
                    'content_base64' => $attachment['content_base64'],
                    'created_at' => $now,
                ]
            );
        }
    }

    private function insertEvent(
        string $ticketId,
        string $eventType,
        string $actorRef,
        ?string $previous,
        ?string $next,
        array $details,
        string $now
    ): void {
        $this->database->execute(
            'INSERT INTO mgw_support_ticket_events (
                event_id, ticket_id, event_type, actor_ref, previous_value, next_value, details_json, created_at_utc
             ) VALUES (
                :event_id, :ticket_id, :event_type, :actor_ref, :previous_value, :next_value, :details_json, :created_at
             )',
            [
                'event_id' => 'ticketevt_' . bin2hex(random_bytes(16)),
                'ticket_id' => $ticketId,
                'event_type' => $eventType,
                'actor_ref' => $this->text($actorRef, 191),
                'previous_value' => $previous,
                'next_value' => $next,
                'details_json' => $details !== [] ? json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) : null,
                'created_at' => $now,
            ]
        );
    }

    private function prepareAttachments(array $attachments): array
    {
        if (count($attachments) > self::MAX_ATTACHMENTS_PER_MESSAGE) {
            throw new SupportTicketException('too_many_attachments', 'Можно приложить не более 3 файлов к одному сообщению.');
        }
        $prepared = [];
        foreach ($attachments as $attachment) {
            if (!is_array($attachment)) throw new SupportTicketException('invalid_attachment', 'Некорректное вложение.');
            $fileName = $this->text((string)($attachment['file_name'] ?? $attachment['name'] ?? ''), 180);
            $mimeType = strtolower($this->text((string)($attachment['mime_type'] ?? $attachment['type'] ?? ''), 96));
            $base64 = preg_replace('/^data:[^;]+;base64,/i', '', trim((string)($attachment['content_base64'] ?? $attachment['data'] ?? ''))) ?? '';
            if ($fileName === '' || !in_array($mimeType, self::ALLOWED_MIME_TYPES, true) || $base64 === '') {
                throw new SupportTicketException('invalid_attachment', 'Поддерживаются изображения, PDF и TXT.');
            }
            $binary = base64_decode($base64, true);
            if ($binary === false) throw new SupportTicketException('invalid_attachment', 'Не удалось прочитать вложение.');
            $size = strlen($binary);
            if ($size < 1 || $size > self::MAX_ATTACHMENT_BYTES) {
                throw new SupportTicketException('attachment_too_large', 'Размер одного вложения не должен превышать 2 МБ.');
            }
            $prepared[] = [
                'file_name' => $fileName,
                'mime_type' => $mimeType,
                'size_bytes' => $size,
                'content_base64' => base64_encode($binary),
            ];
        }
        return $prepared;
    }

    private function normalizeRelated(array $related): array
    {
        return [
            'game_id' => $this->nullableRef($related['game_id'] ?? $related['related_game_id'] ?? null, 96),
            'payment_id' => $this->nullableRef($related['payment_id'] ?? $related['related_payment_id'] ?? null, 96),
            'tournament_id' => $this->nullableRef($related['tournament_id'] ?? $related['related_tournament_id'] ?? null, 64),
            'operation_id' => $this->nullableRef($related['operation_id'] ?? $related['related_operation_id'] ?? null, 191),
        ];
    }

    private function nullableRef(mixed $value, int $max): ?string
    {
        $value = $this->text((string)($value ?? ''), $max);
        return $value !== '' ? $value : null;
    }

    private function requireUser(string $mgwId): string
    {
        $mgwId = trim($mgwId);
        if (!MgwIdGenerator::isValid($mgwId)) {
            throw new SupportTicketException('user_unavailable', 'Профиль MGW недоступен.');
        }
        $exists = $this->database->fetchValue('SELECT mgw_id FROM mgw_users WHERE mgw_id = :mgw_id LIMIT 1', ['mgw_id' => $mgwId]);
        if (!is_string($exists) || $exists === '') {
            throw new SupportTicketException('user_unavailable', 'Профиль MGW недоступен.');
        }
        return $mgwId;
    }

    private function enum(string $value, array $allowed, string $reason, string $message): string
    {
        $value = strtolower(trim($value));
        if (!isset($allowed[$value])) throw new SupportTicketException($reason, $message);
        return $value;
    }

    private function requiredMessage(string $message): string
    {
        $message = $this->textPreserveLines($message, self::MAX_MESSAGE_LENGTH);
        if ($message === '') throw new SupportTicketException('message_required', 'Напишите сообщение.');
        return $message;
    }

    private function newTicketNumber(): string
    {
        for ($attempt = 0; $attempt < 8; $attempt++) {
            $number = 'SUP-' . gmdate('ymd') . '-' . strtoupper(bin2hex(random_bytes(4)));
            $exists = $this->database->fetchValue('SELECT 1 FROM mgw_support_tickets WHERE ticket_number = :number LIMIT 1', ['number' => $number]);
            if ($exists === null) return $number;
        }
        throw new RuntimeException('Unable to allocate support ticket number.');
    }

    private function timestamp(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');
    }

    private function text(string $value, int $maxLength): string
    {
        $value = trim(preg_replace('/[\x00-\x1F\x7F]/u', ' ', $value) ?? '');
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;
        return function_exists('mb_substr') ? mb_substr($value, 0, $maxLength) : substr($value, 0, $maxLength);
    }

    private function textPreserveLines(string $value, int $maxLength): string
    {
        $value = str_replace(["\r\n", "\r"], "\n", $value);
        $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $value) ?? '';
        $value = trim($value);
        return function_exists('mb_substr') ? mb_substr($value, 0, $maxLength) : substr($value, 0, $maxLength);
    }
}
