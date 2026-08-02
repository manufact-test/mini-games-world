<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
require_once $root . '/bot/core/bootstrap.php';

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

$receivedPending = ['invites' => [[
    'status' => 'pending',
    'inviter_id' => 'owner',
    'invitee_id' => 'recipient',
    'expires_at' => $future,
]]];
$assert($invoke($receivedPending, 'recipient') === null,
    'Recipient of a pending invitation must be allowed to start unrelated matchmaking.');

$ownerPending = $receivedPending;
$ownerError = $invoke($ownerPending, 'owner');
$assert($ownerError instanceof RuntimeException,
    'Owner of a pending invitation must remain blocked from conflicting matchmaking.');

$accepted = ['invites' => [[
    'status' => 'awaiting_start',
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
    'inviter_id' => 'owner',
    'invitee_id' => 'recipient',
    'expires_at' => $past,
]]];
$assert($invoke($expired, 'owner') === null,
    'Expired pending invitation must not block matchmaking.');

fwrite(STDOUT, "ProductionV110PendingInviteSearchNonBlockingRuntimeTest: {$assertions} assertions passed\n");
