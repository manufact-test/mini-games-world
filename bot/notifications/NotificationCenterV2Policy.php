<?php
declare(strict_types=1);

/**
 * Presentation/state policy for Notification Center v2.
 *
 * Storage remains owned by NotificationService + RuntimeNotificationRepository.
 * This policy only defines stable event identity, safe internal deep links,
 * explicit expiry/retention semantics and authenticated per-item mutations.
 */
final class NotificationCenterV2Policy
{
    private const SAFE_DEEP_LINKS = [
        'home',
        'profile',
        'store',
        'store:orders',
    ];

    public static function eventId(array $notification): string
    {
        $eventKey = trim((string)($notification['event_key'] ?? ''));
        if ($eventKey !== '') return $eventKey;
        return trim((string)($notification['id'] ?? ''));
    }

    public static function expiresAt(array $notification, ?array $invite = null): ?string
    {
        $value = trim((string)($notification['expires_at'] ?? ''));
        if ($value === '' && is_array($invite)) {
            $value = trim((string)($invite['expires_at'] ?? ''));
        }
        if ($value === '') return null;

        try {
            return (new DateTimeImmutable($value))
                ->setTimezone(new DateTimeZone('UTC'))
                ->format(DATE_ATOM);
        } catch (Throwable) {
            return null;
        }
    }

    public static function isExpired(array $notification, ?array $invite = null, ?DateTimeImmutable $now = null): bool
    {
        $expiresAt = self::expiresAt($notification, $invite);
        if ($expiresAt === null) return false;
        $now ??= new DateTimeImmutable('now', new DateTimeZone('UTC'));
        return new DateTimeImmutable($expiresAt) <= $now;
    }

    public static function deepLink(array $notification): string
    {
        $explicit = trim((string)($notification['deep_link'] ?? ''));
        if (in_array($explicit, self::SAFE_DEEP_LINKS, true)) return $explicit;

        $type = trim((string)($notification['type'] ?? ''));
        if (str_starts_with($type, 'invite_')) return '';

        if (str_starts_with($type, 'shop_order_')) return 'store:orders';
        if (str_starts_with($type, 'payment_')) return 'home';

        return match ($type) {
            'first_game_bonus' => '',
            'weekly_match_bonus',
            'welcome_match_grant',
            'admin_gold_topup' => 'home',
            default => '',
        };
    }

    public static function markOneRead(array &$notifications, string $userId, string $notificationId, ?string $now = null): bool
    {
        return self::mutateOne($notifications, $userId, $notificationId, 'read', $now);
    }

    public static function hideOne(array &$notifications, string $userId, string $notificationId, ?string $now = null): bool
    {
        return self::mutateOne($notifications, $userId, $notificationId, 'hide', $now);
    }

    private static function mutateOne(
        array &$notifications,
        string $userId,
        string $notificationId,
        string $mode,
        ?string $now
    ): bool {
        $userId = trim($userId);
        $notificationId = trim($notificationId);
        if ($userId === '' || $notificationId === '' || strlen($notificationId) > 120) return false;
        if (preg_match('/[\x00-\x1F\x7F]/', $notificationId) === 1) return false;

        $now = self::normalizedNow($now);
        foreach ($notifications as &$notification) {
            if (!is_array($notification)) continue;
            if ((string)($notification['user_id'] ?? '') !== $userId) continue;
            if (!hash_equals((string)($notification['id'] ?? ''), $notificationId)) continue;

            if (empty($notification['read_at'])) $notification['read_at'] = $now;
            if ($mode === 'hide' && empty($notification['hidden_at'])) $notification['hidden_at'] = $now;
            unset($notification);
            return true;
        }
        unset($notification);
        return false;
    }

    private static function normalizedNow(?string $value): string
    {
        if ($value === null || trim($value) === '') {
            return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DATE_ATOM);
        }
        try {
            return (new DateTimeImmutable($value))
                ->setTimezone(new DateTimeZone('UTC'))
                ->format(DATE_ATOM);
        } catch (Throwable) {
            throw new InvalidArgumentException('Notification mutation timestamp is invalid.');
        }
    }
}
