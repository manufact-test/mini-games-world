<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
require_once $root . '/bot/services/GameInviteService.php';

$reflection = new ReflectionClass(GameInviteService::class);
$service = $reflection->newInstanceWithoutConstructor();
$method = $reflection->getMethod('assertAvailableForStart');
$method->setAccessible(true);

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};
$invoke = static function (array $db, array $user, string $token) use ($method, $service): ?Throwable {
    try {
        $method->invoke($service, $db, $user, $token, 'busy');
        return null;
    } catch (Throwable $error) {
        return $error;
    }
};

$token = 'aaaaaaaaaaaaaaaaaaaaaaaa';
$directPending = ['invites' => [[
    'token' => $token,
    'status' => 'pending',
    'source' => 'direct',
    'inviter_id' => 'owner',
    'invitee_id' => 'recipient',
]]];
$assert($invoke($directPending, ['id' => 'owner', 'status' => 'playing'], $token) === null,
    'Normal direct pending owner must remain valid while already playing.');
$assert($invoke($directPending, ['id' => 'owner', 'status' => 'searching'], $token) === null,
    'Normal direct pending owner must remain valid while already searching.');
$assert($invoke($directPending, ['id' => 'recipient', 'status' => 'playing'], $token) instanceof RuntimeException,
    'Invitee must still be free when accepting a pending invitation.');

$linkDraft = ['invites' => [[
    'token' => $token,
    'status' => 'draft',
    'source' => 'link',
    'inviter_id' => 'owner',
    'invitee_id' => null,
]]];
$assert($invoke($linkDraft, ['id' => 'owner', 'status' => 'playing'], $token) === null,
    'Normal link draft owner may remain busy while the already-created share is being opened/confirmed.');

$rematchPending = ['invites' => [[
    'token' => $token,
    'status' => 'pending',
    'source' => 'rematch',
    'inviter_id' => 'owner',
    'invitee_id' => 'recipient',
]]];
$assert($invoke($rematchPending, ['id' => 'owner', 'status' => 'playing'], $token) instanceof RuntimeException,
    'Pending rematch owner must remain strict because rematch acceptance may auto-start.');

$accepted = ['invites' => [[
    'token' => $token,
    'status' => 'awaiting_start',
    'source' => 'direct',
    'inviter_id' => 'owner',
    'invitee_id' => 'recipient',
]]];
$assert($invoke($accepted, ['id' => 'owner', 'status' => 'playing'], $token) instanceof RuntimeException,
    'Actual start after acceptance must still require a free owner.');

fwrite(STDOUT, "ProductionMvp14OwnerPendingBusyAvailabilityRuntimeTest: {$assertions} assertions passed\n");
