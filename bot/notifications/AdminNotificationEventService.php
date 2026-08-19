<?php
declare(strict_types=1);

require_once __DIR__ . '/NotificationCenterV2Policy.php';

final class AdminNotificationEventException extends RuntimeException
{
    public function __construct(public string $reason, string $message)
    {
        parent::__construct($message);
    }
}

/**
 * Canonical producer for admin/system/support bell events.
 *
 * It never writes mgw_notifications directly. It appends ordinary notification
 * rows to the existing JSON notification stream; RuntimeNotificationRepository
 * remains the sole JSON -> DB mirror owner.
 */
final class AdminNotificationEventService
{
    public const AUDIENCE_TYPES = ['all', 'one', 'segment', 'platform', 'tournament', 'support'];
    public const SOURCE_TYPES = ['admin', 'system', 'support'];

    public function createEvent(
        array &$db,
        array $input,
        string $createdBy,
        ?DateTimeImmutable $now = null
    ): array {
        $now = ($now ?? new DateTimeImmutable('now', new DateTimeZone('UTC')))
            ->setTimezone(new DateTimeZone('UTC'));
        $createdBy = $this->text($createdBy, 191);
        if ($createdBy === '') $createdBy = 'admin:unknown';

        $sourceType = strtolower($this->text((string)($input['source_type'] ?? 'admin'), 20));
        if (!in_array($sourceType, self::SOURCE_TYPES, true)) {
            throw new AdminNotificationEventException('invalid_source_type', 'Некорректный тип источника уведомления.');
        }

        $audienceType = strtolower($this->text((string)($input['audience_type'] ?? ''), 24));
        if (!in_array($audienceType, self::AUDIENCE_TYPES, true)) {
            throw new AdminNotificationEventException('invalid_audience_type', 'Некорректная аудитория уведомления.');
        }

        $title = $this->text((string)($input['title'] ?? ''), 160);
        $text = $this->text((string)($input['text'] ?? $input['message'] ?? ''), 4000);
        if ($title === '' || $text === '') {
            throw new AdminNotificationEventException('content_required', 'Заголовок и текст уведомления обязательны.');
        }

        $deepLink = trim((string)($input['deep_link'] ?? ''));
        if (!NotificationCenterV2Policy::isSafeDeepLink($deepLink)) {
            throw new AdminNotificationEventException('invalid_deep_link', 'Некорректная внутренняя ссылка уведомления.');
        }

        $scheduledAt = $this->timestamp($input['scheduled_at'] ?? null, $now);
        $expiresAt = $this->nullableTimestamp($input['expires_at'] ?? null);
        if ($expiresAt !== null && new DateTimeImmutable($expiresAt) <= new DateTimeImmutable($scheduledAt)) {
            throw new AdminNotificationEventException('invalid_expiry', 'Срок действия должен быть позже времени доставки.');
        }

        $requestId = $this->requestId((string)($input['request_id'] ?? ''));
        $eventId = 'bell_' . substr(hash('sha256', $requestId), 0, 32);
        $audienceRef = $this->audienceRef($audienceType, $input);
        $recipients = $this->resolveRecipients($db, $audienceType, $audienceRef, $input);
        if ($recipients === []) {
            throw new AdminNotificationEventException('empty_audience', 'Для выбранной аудитории не найдено получателей.');
        }

        $recipientMgwIds = array_map(static fn(array $row): string => (string)$row['mgw_id'], $recipients);
        sort($recipientMgwIds, SORT_STRING);
        $fingerprint = hash('sha256', json_encode([
            'source_type' => $sourceType,
            'audience_type' => $audienceType,
            'audience_ref' => $audienceRef,
            'recipients' => $recipientMgwIds,
            'title' => $title,
            'text' => $text,
            'deep_link' => $deepLink,
            'scheduled_at' => $scheduledAt,
            'expires_at' => $expiresAt,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

        if (!isset($db['notifications']) || !is_array($db['notifications'])) $db['notifications'] = [];
        $existing = array_values(array_filter(
            $db['notifications'],
            static fn($notification): bool => is_array($notification)
                && (string)($notification['notification_event_id'] ?? '') === $eventId
        ));
        if ($existing !== []) {
            foreach ($existing as $notification) {
                if (!hash_equals($fingerprint, (string)($notification['event_fingerprint'] ?? ''))) {
                    throw new AdminNotificationEventException(
                        'request_id_reused',
                        'Этот request_id уже использован для другого уведомления.'
                    );
                }
            }
            return $this->summarizeRows($existing, $now);
        }

        $createdAt = $now->format(DATE_ATOM);
        foreach ($recipients as $recipient) {
            $legacyUserId = (string)$recipient['user_id'];
            $notification = [
                'id' => 'notification_' . bin2hex(random_bytes(12)),
                'event_key' => 'bell_event:' . $eventId . ':' . $legacyUserId,
                'notification_event_id' => $eventId,
                'event_fingerprint' => $fingerprint,
                'user_id' => $legacyUserId,
                'type' => $sourceType . '_message',
                'source_type' => $sourceType,
                'audience_type' => $audienceType,
                'audience_ref' => $audienceRef,
                'title' => $title,
                'message' => $text,
                'text' => $text,
                'tone' => 'info',
                'deep_link' => $deepLink,
                'scheduled_at' => $scheduledAt,
                // Bell delivery means the event becomes available in the canonical
                // notification feed. Android/FCM push is intentionally not owned here.
                'delivered_at' => $scheduledAt,
                'expires_at' => $expiresAt,
                'created_at' => $createdAt,
                'created_by' => $createdBy,
                'read_at' => null,
                'hidden_at' => null,
            ];
            $db['notifications'][] = $notification;
        }

        $rows = array_values(array_filter(
            $db['notifications'],
            static fn($notification): bool => is_array($notification)
                && (string)($notification['notification_event_id'] ?? '') === $eventId
        ));
        return $this->summarizeRows($rows, $now);
    }

    public function history(array $db, int $limit = 50, ?DateTimeImmutable $now = null): array
    {
        $now = ($now ?? new DateTimeImmutable('now', new DateTimeZone('UTC')))
            ->setTimezone(new DateTimeZone('UTC'));
        $grouped = [];
        foreach ($db['notifications'] ?? [] as $notification) {
            if (!is_array($notification)) continue;
            $eventId = trim((string)($notification['notification_event_id'] ?? ''));
            if ($eventId === '') continue;
            $grouped[$eventId][] = $notification;
        }

        $items = [];
        foreach ($grouped as $rows) $items[] = $this->summarizeRows($rows, $now);
        usort($items, static function (array $left, array $right): int {
            $time = strcmp((string)($right['created_at'] ?? ''), (string)($left['created_at'] ?? ''));
            return $time !== 0 ? $time : strcmp((string)($right['event_id'] ?? ''), (string)($left['event_id'] ?? ''));
        });
        return array_slice($items, 0, max(1, min(100, $limit)));
    }

    private function resolveRecipients(array $db, string $audienceType, string $audienceRef, array $input): array
    {
        $users = [];
        foreach ($db['users'] ?? [] as $key => $user) {
            if (!is_array($user)) continue;
            $legacyUserId = $this->text((string)($user['id'] ?? $key), 191);
            $mgwId = $this->text((string)($user['mgw_id'] ?? ''), 24);
            if ($legacyUserId === '' || $mgwId === '') continue;
            $users[$mgwId] = [
                'user_id' => $legacyUserId,
                'mgw_id' => $mgwId,
                'provider' => strtolower($this->text((string)($user['mgw_identity_provider'] ?? ''), 32)),
            ];
        }

        if ($audienceType === 'all') return array_values($users);
        if ($audienceType === 'platform') {
            return array_values(array_filter(
                $users,
                static fn(array $user): bool => (string)$user['provider'] === strtolower($audienceRef)
            ));
        }

        $requested = [];
        if ($audienceType === 'one') {
            $target = $this->text((string)($input['target_mgw_id'] ?? ''), 24);
            if ($target === '') {
                throw new AdminNotificationEventException('target_required', 'Для one-аудитории нужен MGW-ID получателя.');
            }
            $requested = [$target];
        } else {
            $source = $input['recipient_mgw_ids'] ?? [];
            if (is_string($source)) $source = preg_split('/[\s,;]+/', $source, -1, PREG_SPLIT_NO_EMPTY) ?: [];
            if (!is_array($source)) $source = [];
            foreach ($source as $value) {
                $mgwId = $this->text((string)$value, 24);
                if ($mgwId !== '') $requested[$mgwId] = $mgwId;
            }
            $requested = array_values($requested);
            if ($requested === []) {
                throw new AdminNotificationEventException(
                    'recipients_required',
                    'Для segment/tournament/support нужен снимок recipient MGW-ID.'
                );
            }
        }

        $resolved = [];
        $missing = [];
        foreach ($requested as $mgwId) {
            if (!isset($users[$mgwId])) {
                $missing[] = $mgwId;
                continue;
            }
            $resolved[] = $users[$mgwId];
        }
        if ($missing !== []) {
            throw new AdminNotificationEventException(
                'recipient_not_found',
                'Не найдены MGW-ID: ' . implode(', ', $missing)
            );
        }
        return $resolved;
    }

    private function audienceRef(string $audienceType, array $input): string
    {
        if ($audienceType === 'all') return 'all';
        if ($audienceType === 'one') return $this->text((string)($input['target_mgw_id'] ?? ''), 191);
        if ($audienceType === 'platform') {
            $platform = strtolower($this->text((string)($input['platform'] ?? $input['audience_ref'] ?? ''), 32));
            if ($platform === '') {
                throw new AdminNotificationEventException('platform_required', 'Для platform-аудитории нужна платформа.');
            }
            return $platform;
        }

        $ref = $this->text((string)($input['audience_ref'] ?? ''), 191);
        if ($ref === '') {
            throw new AdminNotificationEventException(
                'audience_ref_required',
                'Для segment/tournament/support нужен идентификатор аудитории.'
            );
        }
        return $ref;
    }

    private function summarizeRows(array $rows, DateTimeImmutable $now): array
    {
        $first = $rows[0] ?? [];
        $delivered = 0;
        $read = 0;
        foreach ($rows as $row) {
            if (!is_array($row)) continue;
            if (NotificationCenterV2Policy::isDelivered($row, $now)) $delivered++;
            if (!empty($row['read_at'])) $read++;
        }

        return [
            'event_id' => (string)($first['notification_event_id'] ?? ''),
            'source_type' => (string)($first['source_type'] ?? ''),
            'audience_type' => (string)($first['audience_type'] ?? ''),
            'audience_ref' => (string)($first['audience_ref'] ?? ''),
            'title' => (string)($first['title'] ?? ''),
            'text' => (string)($first['text'] ?? $first['message'] ?? ''),
            'deep_link' => (string)($first['deep_link'] ?? ''),
            'scheduled_at' => NotificationCenterV2Policy::scheduledAt($first),
            'expires_at' => NotificationCenterV2Policy::expiresAt($first),
            'created_at' => (string)($first['created_at'] ?? ''),
            'created_by' => (string)($first['created_by'] ?? ''),
            'recipient_count' => count($rows),
            'delivered_count' => $delivered,
            'read_count' => $read,
            'expired' => NotificationCenterV2Policy::isExpired($first, null, $now),
        ];
    }

    private function requestId(string $value): string
    {
        $value = trim($value);
        if ($value === '') $value = bin2hex(random_bytes(16));
        if (strlen($value) > 120 || preg_match('/^[A-Za-z0-9_.:-]+$/', $value) !== 1) {
            throw new AdminNotificationEventException('invalid_request_id', 'Некорректный request_id уведомления.');
        }
        return $value;
    }

    private function timestamp(mixed $value, DateTimeImmutable $fallback): string
    {
        $value = trim((string)($value ?? ''));
        if ($value === '') return $fallback->format(DATE_ATOM);
        try {
            return (new DateTimeImmutable($value))->setTimezone(new DateTimeZone('UTC'))->format(DATE_ATOM);
        } catch (Throwable) {
            throw new AdminNotificationEventException('invalid_schedule', 'Некорректное время доставки.');
        }
    }

    private function nullableTimestamp(mixed $value): ?string
    {
        $value = trim((string)($value ?? ''));
        if ($value === '') return null;
        try {
            return (new DateTimeImmutable($value))->setTimezone(new DateTimeZone('UTC'))->format(DATE_ATOM);
        } catch (Throwable) {
            throw new AdminNotificationEventException('invalid_expiry', 'Некорректный срок действия уведомления.');
        }
    }

    private function text(string $value, int $maxLength): string
    {
        $value = trim(preg_replace('/[\x00-\x1F\x7F]/u', ' ', $value) ?? '');
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;
        return function_exists('mb_substr') ? mb_substr($value, 0, $maxLength) : substr($value, 0, $maxLength);
    }
}
