<?php
declare(strict_types=1);

if (!function_exists('now_iso')) {
    function now_iso(): string { return gmdate('c'); }
}
if (!function_exists('make_id')) {
    function make_id(string $prefix = 'id'): string {
        static $sequence = 0;
        $sequence++;
        return $prefix . '_invite_hydration_' . $sequence;
    }
}

$root = dirname(__DIR__);
require_once $root . '/services/GameInviteService.php';

$assertions = 0;
$assertSame = static function (mixed $expected, mixed $actual, string $message) use (&$assertions): void {
    $assertions++;
    if ($expected !== $actual) {
        throw new RuntimeException($message . ': expected ' . var_export($expected, true)
            . ', got ' . var_export($actual, true));
    }
};
$assertTrue = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

$reflection = new ReflectionClass(GameInviteService::class);
$service = $reflection->newInstanceWithoutConstructor();
$invokePrivate = static function (string $method, array $arguments = []) use ($reflection, $service): mixed {
    $target = $reflection->getMethod($method);
    $target->setAccessible(true);
    return $target->invokeArgs($service, $arguments);
};

$assertSame(120, $reflection->getConstant('INVITE_TTL_SEC'), 'Pending invite TTL must be exactly 120 seconds');
$assertSame(900, $reflection->getConstant('DRAFT_TTL_SEC'), 'Technical prepared-share draft TTL must retain its non-product buffer');
$assertSame(90, $reflection->getConstant('READY_TTL_SEC'), 'Accepted ready TTL must be exactly 90 seconds');

$now = time();
$pendingExpiry = gmdate('c', $now + 120);
$pending = [
    'id' => 'invite-normal-launch',
    'token' => str_repeat('a', 24),
    'status' => 'pending',
    'source' => 'direct',
    'inviter_id' => '1001',
    'inviter_name' => 'Sender',
    'invitee_id' => '1002',
    'invitee_name' => 'Recipient',
    'game_type' => 'tictactoe',
    'game_title' => 'Крестики-нолики',
    'room' => 'match',
    'bet' => 100,
    'board_size' => 3,
    'board_columns' => 3,
    'board_rows' => 3,
    'created_at' => gmdate('c', $now),
    'updated_at' => gmdate('c', $now),
    'expires_at' => $pendingExpiry,
    'ready_deadline_at' => null,
];
$pendingPublic = $invokePrivate('publicInvite', [$pending, '1001']);
$assertSame('', $pendingPublic['ready_deadline_at'], 'Pending expiry must not masquerade as ready deadline');
$assertSame($pendingExpiry, $pendingPublic['countdown_deadline_at'], 'Pending countdown must use pending expiry');
$assertTrue(
    (int)$pendingPublic['waiting_seconds'] >= 118 && (int)$pendingPublic['waiting_seconds'] <= 120,
    'Pending public countdown must begin at the canonical 120-second window'
);

$readyDeadline = gmdate('c', $now + 90);
$accepted = $pending;
$accepted['status'] = 'awaiting_start';
$accepted['accepted_at'] = gmdate('c', $now);
$accepted['ready_deadline_at'] = $readyDeadline;
$accepted['expires_at'] = gmdate('c', $now + 600);
$acceptedPublic = $invokePrivate('publicInvite', [$accepted, '1001']);
$assertSame('accepted', $acceptedPublic['status'], 'Awaiting-start storage state must remain public accepted state');
$assertSame($readyDeadline, $acceptedPublic['ready_deadline_at'], 'Ready deadline must remain the exact accepted deadline');
$assertSame($readyDeadline, $acceptedPublic['countdown_deadline_at'], 'Accepted countdown must use ready deadline');
$assertTrue(
    (int)$acceptedPublic['waiting_seconds'] >= 88 && (int)$acceptedPublic['waiting_seconds'] <= 90,
    'Accepted public countdown must begin at the canonical 90-second window'
);
$assertSame(
    strtotime($readyDeadline),
    $invokePrivate('effectiveReadyDeadlineTs', [$accepted]),
    'Original pending expiry must never extend accepted ready window'
);

$directDb = [
    'invites' => [
        [
            'source' => 'link', 'status' => 'pending',
            'inviter_id' => '1001', 'invitee_id' => '1002',
        ],
        [
            'source' => 'direct', 'status' => 'pending',
            'inviter_id' => '1001', 'invitee_id' => '1002',
        ],
    ],
];
$assertSame(1, $invokePrivate('findOpenDirectIndex', [$directDb, '1001', '1002']), 'Pending direct pair must have one authoritative dedupe owner');
$assertSame(null, $invokePrivate('findOpenDirectIndex', [$directDb, '1002', '1001']), 'Reverse direction must not be confused with the existing outgoing request');

// A direct invite is already bound to its recipient before any Telegram deep-link
// is opened. A normal Mini App launch must therefore be able to repair a missing
// received card from canonical invite state without creating a second invite owner.
$hydrationDb = ['invites' => [$pending], 'notifications' => []];
$assertSame(
    1,
    $service->hydratePendingReceivedNotifications($hydrationDb, '1002'),
    'A still-valid pending direct invite must hydrate one missing received notification'
);
$assertSame(1, count($hydrationDb['notifications']), 'Normal-launch hydration must create exactly one notification card');
$hydrated = $hydrationDb['notifications'][0];
$assertSame('1002', $hydrated['user_id'], 'Hydrated invite notification must belong to the bound recipient');
$assertSame('invite_received', $hydrated['type'], 'Hydrated direct invite must use the canonical received type');
$assertSame($pending['token'], $hydrated['invite_token'], 'Hydrated notification must retain the canonical invite token');
$assertSame(
    0,
    $service->hydratePendingReceivedNotifications($hydrationDb, '1002'),
    'Repeated normal-launch hydration must be idempotent'
);
$assertSame(1, count($hydrationDb['notifications']), 'Repeated hydration must never duplicate the received card');

$hiddenDb = $hydrationDb;
$hiddenDb['notifications'][0]['read_at'] = gmdate('c', $now);
$hiddenDb['notifications'][0]['hidden_at'] = gmdate('c', $now);
$hiddenAt = $hiddenDb['notifications'][0]['hidden_at'];
$assertSame(
    0,
    $service->hydratePendingReceivedNotifications($hiddenDb, '1002'),
    'A deliberately consumed invite card must remain authoritative'
);
$assertSame(1, count($hiddenDb['notifications']), 'Consumed invite hydration must not create a replacement card');
$assertSame($hiddenAt, $hiddenDb['notifications'][0]['hidden_at'], 'Consumed invite hydration must not resurrect the hidden row');

$expiredPending = $pending;
$expiredPending['id'] = 'invite-expired';
$expiredPending['token'] = str_repeat('b', 24);
$expiredPending['expires_at'] = gmdate('c', $now - 1);
$expiredDb = ['invites' => [$expiredPending], 'notifications' => []];
$assertSame(
    0,
    $service->hydratePendingReceivedNotifications($expiredDb, '1002'),
    'Expired pending invites must never hydrate on a later normal launch'
);
$assertSame([], $expiredDb['notifications'], 'Expired pending invite must not create a notification');

$acceptedDb = ['invites' => [$accepted], 'notifications' => []];
$assertSame(
    0,
    $service->hydratePendingReceivedNotifications($acceptedDb, '1002'),
    'Accepted invites must not be recreated as pending received cards'
);

$otherRecipient = $pending;
$otherRecipient['id'] = 'invite-other-recipient';
$otherRecipient['token'] = str_repeat('c', 24);
$otherRecipient['invitee_id'] = '1003';
$otherDb = ['invites' => [$otherRecipient], 'notifications' => []];
$assertSame(
    0,
    $service->hydratePendingReceivedNotifications($otherDb, '1002'),
    'Normal launch must never hydrate another player\'s pending invite'
);

$creationSource = file_get_contents($root . '/services/invites/GameInviteCreationTrait.php');
$validationSource = file_get_contents($root . '/services/invites/GameInviteValidationTrait.php');
$storageSource = file_get_contents($root . '/services/invites/GameInviteStorageTrait.php');
$endpointSource = file_get_contents($root . '/invites.php');
$notificationEndpointSource = file_get_contents($root . '/notifications.php');
$clientSource = file_get_contents(dirname($root) . '/app/assets/js/games/game-invites-v110.js');
foreach ([$creationSource, $validationSource, $storageSource, $endpointSource, $notificationEndpointSource, $clientSource] as $source) {
    if (!is_string($source)) throw new RuntimeException('Invite v2 contract source is unavailable.');
}

$assertTrue(str_contains($creationSource, 'if ($sameContext) return $this->publicInvite($existing, $userId);'), 'Exact repeated direct invite must return the existing pending invite');
$assertTrue(str_contains($creationSource, "Этому игроку уже отправлено другое приглашение."), 'Conflicting second direct invite must be rejected instead of duplicated');
$assertTrue(str_contains($creationSource, "['expires_at'] = gmdate('c', time() + self::INVITE_TTL_SEC)"), 'Draft-to-pending transitions must restart the 120-second window');
$assertTrue(!str_contains($storageSource, '$inviteExpiry > $deadline'), 'Accepted ready window must not be extended by legacy pending expiry');
$assertTrue(str_contains($storageSource, 'public function hydratePendingReceivedNotifications'), 'Invite owner must expose bounded normal-launch notification hydration');
$assertTrue(str_contains($notificationEndpointSource, 'hydratePendingReceivedNotifications($snapshot, $userId)'), 'Notification Center must detect missing pending invite cards from a read-only snapshot');
$assertTrue(str_contains($notificationEndpointSource, 'hydratePendingReceivedNotifications($data, $userId)'), 'Notification Center must persist the missing pending invite card before listing');
$assertTrue(str_contains($validationSource, '$activeGame !== null || $queued'), 'Busy/searching availability must remain an explicit blocker');
$assertTrue(!str_contains($endpointSource, 'leaveSearch('), 'Invite endpoint must not auto-cancel public matchmaking search');
$assertTrue(str_contains($clientSource, 'data-invite-countdown'), 'Invite modal must expose the canonical countdown surface');
$assertTrue(str_contains($clientSource, "mountInviteCountdown(invite, 'Ждём запуск матча')"), 'Accepted invite must re-arm countdown from the server response');
$assertTrue(str_contains($clientSource, 'clearInviteCountdown();'), 'Invite modal lifecycle must clean up its presentation timer');
$assertTrue(str_contains($clientSource, 'scheduleSync(0);'), 'Countdown reaching zero must hand authority back to server sync');

fwrite(STDOUT, 'PASS: MVP-18.3 invite v2 contract (' . $assertions . " assertions)\n");
