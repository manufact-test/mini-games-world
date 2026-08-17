<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/notifications/NotificationCenterV2Policy.php';

$assertions = 0;
$assertSame = static function (mixed $expected, mixed $actual, string $message) use (&$assertions): void {
    $assertions++;
    if ($expected !== $actual) {
        throw new RuntimeException($message . ': expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
};
$assertTrue = static function (bool $value, string $message) use (&$assertions): void {
    $assertions++;
    if (!$value) throw new RuntimeException($message);
};
$assertFalse = static function (bool $value, string $message) use (&$assertions): void {
    $assertions++;
    if ($value) throw new RuntimeException($message);
};

$assertSame('event:shop:42', NotificationCenterV2Policy::eventId([
    'id' => 'notification_42',
    'event_key' => 'event:shop:42',
]), 'event_key must own stable event identity');
$assertSame('notification_42', NotificationCenterV2Policy::eventId([
    'id' => 'notification_42',
]), 'legacy notification ID must remain a safe fallback event identity');

$assertSame('store:orders', NotificationCenterV2Policy::deepLink([
    'type' => 'shop_order_done',
]), 'shop events must deep-link to order history');
$assertSame('home', NotificationCenterV2Policy::deepLink([
    'type' => 'weekly_match_bonus',
]), 'weekly bonus must deep-link to home');
$assertSame('profile', NotificationCenterV2Policy::deepLink([
    'type' => 'first_game_bonus',
]), 'first-game bonus must deep-link to Profile');
$assertSame('', NotificationCenterV2Policy::deepLink([
    'type' => 'invite_received',
    'deep_link' => 'https://example.com',
]), 'arbitrary/external links must never be projected');
$assertSame('profile', NotificationCenterV2Policy::deepLink([
    'type' => 'custom',
    'deep_link' => 'profile',
]), 'explicit safe internal deep link must be preserved');

$now = new DateTimeImmutable('2026-08-17T16:00:00Z');
$assertTrue(NotificationCenterV2Policy::isExpired([
    'expires_at' => '2026-08-17T15:59:59Z',
], null, $now), 'expired event must leave the active feed');
$assertFalse(NotificationCenterV2Policy::isExpired([
    'expires_at' => '2026-08-17T16:00:01Z',
], null, $now), 'future-expiry event must remain active');
$assertFalse(NotificationCenterV2Policy::isExpired([], null, $now), 'no expiry means no invented retention timeout');
$assertTrue(NotificationCenterV2Policy::isExpired([], [
    'expires_at' => '2026-08-17T15:00:00Z',
], $now), 'invite expiry must also control active invite notification retention');

$notifications = [
    ['id'=>'n1', 'user_id'=>'100', 'read_at'=>null, 'hidden_at'=>null],
    ['id'=>'n2', 'user_id'=>'200', 'read_at'=>null, 'hidden_at'=>null],
];
$assertTrue(NotificationCenterV2Policy::markOneRead($notifications, '100', 'n1', '2026-08-17T16:01:00Z'), 'owned notification must be markable read');
$assertSame('2026-08-17T16:01:00+00:00', $notifications[0]['read_at'], 'mark-one must persist deterministic read timestamp');
$assertSame(null, $notifications[1]['read_at'], 'mark-one must not mutate another user notification');
$assertFalse(NotificationCenterV2Policy::hideOne($notifications, '100', 'n2', '2026-08-17T16:02:00Z'), 'delete-one must not cross user ownership');
$assertSame(null, $notifications[1]['hidden_at'], 'foreign notification must remain visible to its owner');
$assertTrue(NotificationCenterV2Policy::hideOne($notifications, '100', 'n1', '2026-08-17T16:02:00Z'), 'owned notification must be soft-deletable');
$assertSame('2026-08-17T16:02:00+00:00', $notifications[0]['hidden_at'], 'delete-one must use hidden_at retention state');

fwrite(STDOUT, "NotificationCenterV2PolicyTest: {$assertions} assertions passed\n");
