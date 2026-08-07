<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
require_once $root . '/bot/services/GameInviteService.php';

$reflection = new ReflectionClass(GameInviteService::class);
$service = $reflection->newInstanceWithoutConstructor();
$method = $reflection->getMethod('isNotificationOnlyPendingInvite');
$method->setAccessible(true);

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};
$invoke = static function (?array $invite) use ($method, $service): bool {
    return (bool)$method->invoke($service, $invite);
};

$assert($invoke([
    'status' => 'pending',
    'source' => 'direct',
    'is_owner' => true,
    'is_invitee' => false,
]), 'A normal direct pending invitation must be notification-only for its owner.');

$assert($invoke([
    'status' => 'pending',
    'source' => 'link',
    'is_owner' => true,
    'is_invitee' => false,
]), 'A shared-link pending invitation must be notification-only for its owner.');

$assert(!$invoke([
    'status' => 'pending',
    'source' => 'rematch',
    'is_owner' => true,
    'is_invitee' => false,
]), 'A pending rematch must remain binding for its owner.');

$assert($invoke([
    'status' => 'pending',
    'source' => 'rematch',
    'is_owner' => false,
    'is_invitee' => true,
]), 'The recipient-side pending invitation behavior must remain notification-only.');

$assert(!$invoke([
    'status' => 'accepted',
    'source' => 'direct',
    'is_owner' => true,
    'is_invitee' => false,
]), 'An accepted invitation must remain binding for the owner.');

$assert(!$invoke(null), 'Missing invitation state must not be classified as notification-only.');

fwrite(STDOUT, "ProductionMvp14OwnerPendingNotificationOnlyRuntimeTest: {$assertions} assertions passed\n");
