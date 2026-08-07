<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
require_once $root . '/bot/services/GameInviteService.php';

$reflection = new ReflectionClass(GameInviteService::class);
$service = $reflection->newInstanceWithoutConstructor();
$method = $reflection->getMethod('effectiveReadyDeadlineTs');
$method->setAccessible(true);

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};
$invoke = static function (array $invite) use ($method, $service): int {
    return (int)$method->invoke($service, $invite);
};

$now = time();
$ready = $now + 90;
$longExpiry = $now + 600;
$shortExpiry = $now + 30;

$assert($invoke([
    'source' => 'direct',
    'ready_deadline_at' => gmdate('c', $ready),
    'expires_at' => gmdate('c', $longExpiry),
]) === $longExpiry, 'Normal direct acceptance must retain the later original invitation deadline.');

$assert($invoke([
    'source' => 'link',
    'ready_deadline_at' => gmdate('c', $ready),
    'expires_at' => gmdate('c', $shortExpiry),
]) === $ready, 'Existing 90-second ready minimum must remain when the original link expiry is sooner.');

$assert($invoke([
    'source' => 'rematch',
    'ready_deadline_at' => gmdate('c', $ready),
    'expires_at' => gmdate('c', $longExpiry),
]) === $ready, 'Rematch must retain the existing strict ready deadline.');

fwrite(STDOUT, "ProductionMvp14OwnerPendingReadyDeadlineRuntimeTest: {$assertions} assertions passed\n");
