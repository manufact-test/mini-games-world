<?php
declare(strict_types=1);

/**
 * Presentation/state policy for Notification Center v2.
 *
 * Storage remains owned by NotificationService + RuntimeNotificationRepository.
 * This policy only defines stable event identity, safe internal deep links,
 * delivery scheduling, explicit expiry/retention semantics and authenticated
 * per-item mutations.
 */
final class NotificationCenterV2Policy
{
    private const SAFE_DEEP_LINKS = [
        'home',
        'profile',
        'store',
        'store:orders',
        'friends:requests',
    ];

    public static function eventId(array $notification): string
    {
        $eventKey = trim((string)($notification['event_key'] ?? ''));
        if ($eventKey !== '') return $eventKey;
        return trim((string)($notification['id'] ?? ''));
    }

    public static function isSafeDeepLink(string $deepLink): bool
    {
        $deepLink = trim($deepLink);
        return $deepLink === '' || in_array($deepLink, self::SAFE_DEEP_LINKS, true);
    }

    public static function scheduledAt(array $notification): ?string
    {
        return self::normalizedTimestamp($notification['scheduled_at'] ?? null);
    }

    public static function deliveredAt(array $notification): ?string
    {
        $deliveredAt = self::normalizedTimestamp($notification['delivered_at'] ?? null);
        if ($deliveredAt !== null) return $deliveredAt;

        $scheduledAt = self::scheduledAt($notification);
        if ($scheduledAt !== null) return $scheduledAt;

        return self::normalizedTimestamp($notification['created_at'] ?? null);
    }

    public static function isDelivered(array $notification, ?DateTimeImmutable $now = null): bool
    {
        $deliveredAt = self::deliveredAt($notification);
        if ($deliveredAt === null) return true;
        $now ??= new DateTimeImmutable('now', new DateTimeZone('UTC'));
        return new DateTimeImmutable($deliveredAt) <= $now;
    }

    public static function expiresAt(array $notification, ?array $invite = null): ?string
    {
        $value = self::normalizedTimestamp($notification['expires_at'] ?? null);
        if ($value === null && is_array($invite)) {
            $value = self::normalizedTimestamp($invite['expires_at'] ?? null);
        }
        return $value;
    }

    public static function isExpired(array $notification, ?array $invite = null, ?DateTimeImmutable $now = null): bool
    {
        $expiresAt = self::expiresAt($notification, $invite);
        if ($expiresAt === null) return false;
        $now ??= new DateTimeImmutable('now', new DateTimeZone('UTC'));
        return new DateTimeImmutable($expiresAt) <= $now;
    }

    public static function isActive(array $notification, ?array $invite = null, ?DateTimeImmutable $now = null): bool
    {
        $now ??= new DateTimeImmutable('now', new DateTimeZone('UTC'));
        return self::isDelivered($notification, $now) && !self::isExpired($notification, $invite, $now);
    }

    public static function deepLink(array $notification): string
    {
        $type = trim((string)($notification['type'] ?? ''));

        // Invite cards own their actions inside Notification Center and should not
        // inherit an unrelated historical navigation target.
        if (str_starts_with($type, 'invite_')) return '';

        // Informational game-bonus events have no dedicated destination. Ignore
        // legacy explicit profile/home links so old stored cards do not expose a
        // meaningless `Открыть` button after the v2 policy is deployed.
        if (in_array($type, ['first_game_bonus', 'weekly_match_bonus'], true)) return '';

        $explicit = trim((string)($notification['deep_link'] ?? ''));
        if (in_array($explicit, self::SAFE_DEEP_LINKS, true)) return $explicit;

        if (str_starts_with($type, 'shop_order_')) return 'store:orders';
        if (str_starts_with($type, 'payment_')) return 'home';

        return match ($type) {
            'friend_request' => 'friends:requests',
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
        $instant = new DateTimeImmutable($now);
        foreach ($notifications as &$notification) {
            if (!is_array($notification)) continue;
            if ((string)($notification['user_id'] ?? '') !== $userId) continue;
            if (!hash_equals((string)($notification['id'] ?? ''), $notificationId)) continue;
            if (!self::isActive($notification, null, $instant)) {
                unset($notification);
                return false;
            }

            if (empty($notification['read_at'])) $notification['read_at'] = $now;
            if ($mode === 'hide' && empty($notification['hidden_at'])) $notification['hidden_at'] = $now;
            unset($notification);
            return true;
        }
        unset($notification);
        return false;
    }

    private static function normalizedTimestamp(mixed $value): ?string
    {
        $value = trim((string)($value ?? ''));
        if ($value === '') return null;
        try {
            return (new DateTimeImmutable($value))
                ->setTimezone(new DateTimeZone('UTC'))
                ->format(DATE_ATOM);
        } catch (Throwable) {
            return null;
        }
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
