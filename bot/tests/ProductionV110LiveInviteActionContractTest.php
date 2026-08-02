<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$service = file_get_contents($root . '/bot/services/GameInviteService.php');
$screen = file_get_contents($root . '/app/assets/js/screens/notifications-screen-v110.js');
if (!is_string($service) || !is_string($screen)) {
    throw new RuntimeException('Cannot read v110 live invite sources.');
}

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

$assert(
    str_contains($service, 'private function inviteEventsForUser(array $db, string $userId): array')
        && str_contains($service, "'actions' => \$this->liveInviteActions(\$invite, \$userId)")
        && str_contains($service, 'private function liveInviteActions(?array $invite, string $userId): array'),
    'The live invite event must be assembled by the service with authoritative actions.'
);

$assert(
    str_contains($service, "if (\$status === 'pending' && \$isInvitee) return ['accept', 'decline'];")
        && str_contains($service, "if (\$status === 'awaiting_start' && \$isOwner) return ['start', 'cancel'];")
        && str_contains($service, "if (\$status === 'awaiting_start' && \$isInvitee) return ['cancel'];"),
    'Live actions must follow the stored invite state and participant role.'
);

$assert(
    str_contains($screen, 'const seed = toastItem ? [cloneItem(toastItem)] : currentItems();')
        && str_contains($screen, 'if (immediate.length) renderNotifications(immediate);')
        && str_contains($screen, 'const actions = Array.isArray(item?.actions) ? item.actions : [];'),
    'The v110 toast must render those server actions before its background refresh.'
);

fwrite(STDOUT, "ProductionV110LiveInviteActionContractTest: {$assertions} assertions passed\n");
