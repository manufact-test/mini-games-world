<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
require_once $root . '/bot/services/ChessRuntimeService.php';

$reflection = new ReflectionClass(ChessRuntimeService::class);
$runtime = $reflection->newInstanceWithoutConstructor();
$guard = $reflection->getMethod('assertNoOpenInviteBeforeSearch');
$guard->setAccessible(true);

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};
$invoke = static function (array &$db, string $userId) use ($guard, $runtime): ?Throwable {
    try {
        $user = ['id' => $userId];
        $guard->invokeArgs($runtime, [&$db, $user]);
        return null;
    } catch (Throwable $error) {
        return $error;
    }
};

$future = gmdate('c', time() + 300);
$past = gmdate('c', time() - 300);

$directPending = ['invites' => [[
    'status' => 'pending',
    'source' => 'direct',
    'inviter_id' => 'owner',
    'invitee_id' => 'recipient',
    'expires_at' => $future,
]]];
$assert($invoke($directPending, 'recipient') === null,
    'Recipient of a pending direct invitation must be allowed to start unrelated matchmaking.');
$assert($invoke($directPending, 'owner') === null,
    'Owner of a pending direct invitation must be allowed to start unrelated matchmaking.');

$linkPending = ['invites' => [[
    'status' => 'pending',
    'source' => 'link',
    'inviter_id' => 'owner',
    'invitee_id' => 'recipient',
    'expires_at' => $future,
]]];
$assert($invoke($linkPending, 'owner') === null,
    'Owner of a pending link invitation must be allowed to start unrelated matchmaking.');

$rematchPending = ['invites' => [[
    'status' => 'pending',
    'source' => 'rematch',
    'inviter_id' => 'owner',
    'invitee_id' => 'recipient',
    'expires_at' => $future,
]]];
$assert($invoke($rematchPending, 'recipient') === null,
    'Recipient of a pending rematch may still treat it as notification-only before accepting.');
$assert($invoke($rematchPending, 'owner') instanceof RuntimeException,
    'Owner of a pending rematch must remain blocked because rematch acceptance may auto-start.');

$accepted = ['invites' => [[
    'status' => 'awaiting_start',
    'source' => 'direct',
    'inviter_id' => 'owner',
    'invitee_id' => 'recipient',
    'ready_deadline_at' => $future,
]]];
$assert($invoke($accepted, 'owner') instanceof RuntimeException,
    'Owner of an accepted invitation must remain blocked.');
$assert($invoke($accepted, 'recipient') instanceof RuntimeException,
    'Recipient of an accepted invitation must remain blocked.');

$expired = ['invites' => [[
    'status' => 'pending',
    'source' => 'rematch',
    'inviter_id' => 'owner',
    'invitee_id' => 'recipient',
    'expires_at' => $past,
]]];
$assert($invoke($expired, 'owner') === null,
    'Expired pending invitation must not block matchmaking.');

fwrite(STDOUT, "ProductionV110PendingInviteSearchNonBlockingRuntimeTest: {$assertions} assertions passed\n");
