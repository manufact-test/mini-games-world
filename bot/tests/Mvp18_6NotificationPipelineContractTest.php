<?php
declare(strict_types=1);

if (!function_exists('now_iso')) {
    function now_iso(): string { return '2026-08-19T20:00:00+00:00'; }
}

require_once dirname(__DIR__) . '/notifications/NotificationCenterV2Policy.php';
require_once dirname(__DIR__) . '/notifications/AdminNotificationEventService.php';
require_once dirname(__DIR__) . '/services/NotificationService.php';

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
$assertThrowsReason = static function (string $reason, callable $callback, string $message) use (&$assertions): void {
    $assertions++;
    try {
        $callback();
    } catch (AdminNotificationEventException $error) {
        if ($error->reason === $reason) return;
        throw new RuntimeException($message . ': expected reason ' . $reason . ', got ' . $error->reason);
    }
    throw new RuntimeException($message . ': exception was not thrown');
};

$users = [
    '101' => ['id'=>'101', 'mgw_id'=>'MGW000000000000000000001', 'mgw_identity_provider'=>'telegram'],
    '102' => ['id'=>'102', 'mgw_id'=>'MGW000000000000000000002', 'mgw_identity_provider'=>'telegram'],
    '103' => ['id'=>'103', 'mgw_id'=>'MGW000000000000000000003', 'mgw_identity_provider'=>'google'],
];
$now = new DateTimeImmutable('2026-08-19T20:00:00Z');
$service = new AdminNotificationEventService();

$base = static fn(): array => ['users'=>$users, 'notifications'=>[]];
$event = static fn(string $request, string $audience): array => [
    'request_id'=>$request,
    'source_type'=>'admin',
    'audience_type'=>$audience,
    'title'=>'Системное сообщение',
    'text'=>'Проверка единого bell pipeline.',
    'deep_link'=>'home',
];

$db = $base();
$all = $service->createEvent($db, $event('req-all-000001', 'all'), 'telegram:1', $now);
$assertSame(3, $all['recipient_count'], 'all must resolve every current canonical MGW runtime account');
$assertSame(3, $all['delivered_count'], 'immediate all event must be delivered in bell');
$assertSame(3, count($db['notifications']), 'all must materialize one canonical row per recipient');
$assertSame('home', $db['notifications'][0]['deep_link'], 'safe internal deep link must stay on the canonical row');
$assertSame('Проверка единого bell pipeline.', $db['notifications'][0]['text'], 'title/text contract must persist text explicitly');

$again = $service->createEvent($db, $event('req-all-000001', 'all'), 'telegram:1', $now);
$assertSame($all['event_id'], $again['event_id'], 'same request_id must be idempotent');
$assertSame(3, count($db['notifications']), 'idempotent retry must not duplicate recipient rows');
$changed = $event('req-all-000001', 'all');
$changed['title'] = 'Другой заголовок';
$assertThrowsReason('request_id_reused', fn() => $service->createEvent($db, $changed, 'telegram:1', $now), 'request_id reuse with different immutable event must fail closed');

$db = $base();
$oneEvent = $event('req-one-000001', 'one');
$oneEvent['target_mgw_id'] = 'MGW000000000000000000002';
$one = $service->createEvent($db, $oneEvent, 'telegram:1', $now);
$assertSame(1, $one['recipient_count'], 'one must target exactly one canonical MGW-ID');
$assertSame('102', $db['notifications'][0]['user_id'], 'one must map MGW-ID to existing runtime legacy recipient for current bell owner');

$db = $base();
$platformEvent = $event('req-platform-000001', 'platform');
$platformEvent['platform'] = 'telegram';
$platformResult = $service->createEvent($db, $platformEvent, 'telegram:1', $now);
$assertSame(2, $platformResult['recipient_count'], 'platform must resolve current verified identity provider');

$db = $base();
$segmentEvent = $event('req-segment-000001', 'segment');
$segmentEvent['audience_ref'] = 'segment:beta';
$segmentEvent['recipient_mgw_ids'] = ['MGW000000000000000000001', 'MGW000000000000000000003'];
$segment = $service->createEvent($db, $segmentEvent, 'telegram:1', $now);
$assertSame(2, $segment['recipient_count'], 'segment must use an explicit canonical recipient snapshot');
$assertSame('segment:beta', $segment['audience_ref'], 'segment identity must be retained for audit');

$db = $base();
$tournamentEvent = $event('req-tournament-000001', 'tournament');
$tournamentEvent['source_type'] = 'system';
$tournamentEvent['audience_ref'] = 'tournament:T-42';
$tournamentEvent['recipient_mgw_ids'] = ['MGW000000000000000000002', 'MGW000000000000000000003'];
$tournament = $service->createEvent($db, $tournamentEvent, 'telegram:1', $now);
$assertSame(2, $tournament['recipient_count'], 'tournament must accept the future tournament owner recipient snapshot without creating tournament lifecycle');
$assertSame('system_message', $db['notifications'][0]['type'], 'system event must remain a normal bell notification type');

$db = $base();
$supportEvent = $event('req-support-000001', 'support');
$supportEvent['source_type'] = 'support';
$supportEvent['audience_ref'] = 'case:RPT-42';
$supportEvent['recipient_mgw_ids'] = ['MGW000000000000000000001'];
$support = $service->createEvent($db, $supportEvent, 'telegram:1', $now);
$assertSame(1, $support['recipient_count'], 'support must accept an explicit case recipient snapshot');
$assertSame('support_message', $db['notifications'][0]['type'], 'support must use the same bell pipeline, not a support-specific store');

$db = $base();
$futureEvent = $event('req-future-000001', 'one');
$futureEvent['target_mgw_id'] = 'MGW000000000000000000001';
$futureEvent['scheduled_at'] = '2026-08-19T21:00:00Z';
$futureEvent['expires_at'] = '2026-08-19T22:00:00Z';
$future = $service->createEvent($db, $futureEvent, 'telegram:1', $now);
$row = $db['notifications'][0];
$assertSame(0, $future['delivered_count'], 'future schedule must not be delivered early');
$assertFalse(NotificationCenterV2Policy::isDelivered($row, $now), 'scheduled event must stay outside bell before delivery time');
$assertTrue(NotificationCenterV2Policy::isDelivered($row, new DateTimeImmutable('2026-08-19T21:00:01Z')), 'scheduled event must become delivered when its bell time arrives');
$assertFalse(NotificationCenterV2Policy::markOneRead($db['notifications'], '101', $row['id'], '2026-08-19T20:30:00Z'), 'future event cannot be marked read before delivery');
$assertTrue(NotificationCenterV2Policy::markOneRead($db['notifications'], '101', $row['id'], '2026-08-19T21:01:00Z'), 'delivered active event can be marked read');
$assertTrue(NotificationCenterV2Policy::isExpired($row, null, new DateTimeImmutable('2026-08-19T22:00:00Z')), 'expiry must close the event at the exact boundary');

$db = $base();
$currentEvent = $event('req-active-000001', 'one');
$currentEvent['target_mgw_id'] = 'MGW000000000000000000001';
$service->createEvent($db, $currentEvent, 'telegram:1', $now);
$futureEvent['request_id'] = 'req-future-000002';
$service->createEvent($db, $futureEvent, 'telegram:1', $now);
$notifications = new NotificationService();
$visible = $notifications->userNotifications($db, '101', 30);
$assertSame(1, count($visible), 'NotificationService must filter future scheduled rows before applying feed limit');
$assertTrue($visible[0]['delivered'], 'visible canonical event must expose delivered state');
$notifications->markAllRead($db, '101');
$activeRows = array_values(array_filter($db['notifications'], static fn(array $item): bool => ($item['scheduled_at'] ?? '') === '2026-08-19T20:00:00+00:00'));
$futureRows = array_values(array_filter($db['notifications'], static fn(array $item): bool => ($item['scheduled_at'] ?? '') === '2026-08-19T21:00:00+00:00'));
$assertTrue(!empty($activeRows[0]['read_at']), 'mark-all must read currently delivered bell events');
$assertSame(null, $futureRows[0]['read_at'], 'mark-all must not pre-read future scheduled bell events');

$badLink = $event('req-bad-link-000001', 'all');
$badLink['deep_link'] = 'https://example.com';
$db = $base();
$assertThrowsReason('invalid_deep_link', fn() => $service->createEvent($db, $badLink, 'telegram:1', $now), 'external deep links must remain forbidden');

$missing = $event('req-missing-000001', 'one');
$missing['target_mgw_id'] = 'MGW_DOES_NOT_EXIST';
$db = $base();
$assertThrowsReason('recipient_not_found', fn() => $service->createEvent($db, $missing, 'telegram:1', $now), 'unknown canonical recipient must fail instead of silently dropping delivery');

$repositorySource = file_get_contents(dirname(__DIR__) . '/notifications/RuntimeNotificationRepository.php') ?: '';
foreach (['notification_event_id', 'source_type', 'audience_type', 'audience_ref', 'text', 'deep_link', 'scheduled_at', 'delivered_at', 'expires_at', 'created_by'] as $field) {
    $assertTrue(str_contains($repositorySource, "'{$field}'"), "DB parity normalizer must preserve {$field}");
}
$producerSource = file_get_contents(dirname(__DIR__) . '/notifications/AdminNotificationEventService.php') ?: '';
$endpointSource = file_get_contents(dirname(__DIR__) . '/admin-notifications.php') ?: '';
$assertFalse(str_contains($producerSource, 'INSERT INTO mgw_notifications'), 'event producer must never create a parallel direct DB write path');
$assertFalse(str_contains($endpointSource, 'INSERT INTO mgw_notifications'), 'admin endpoint must never bypass canonical JSON -> DB notification mirror');
$assertFalse(str_contains($producerSource, 'FirebaseMessaging'), 'Android push implementation is outside MVP-18.6');
$assertFalse(str_contains($endpointSource, 'fcm_token'), 'FCM token ownership is outside MVP-18.6');

fwrite(STDOUT, "Mvp18_6NotificationPipelineContractTest: {$assertions} assertions passed\n");
