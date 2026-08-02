<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$read = static function (string $path) use ($root): string {
    $content = file_get_contents($root . '/' . $path);
    if (!is_string($content)) throw new RuntimeException('Cannot read ' . $path);
    return $content;
};
$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

$notifications = $read('app/assets/js/screens/notifications-screen-v110r12.js');
$service = $read('bot/services/GameInviteService.php');

$assert(
    str_contains($service, "'invite_status' => \$this->liveInviteStatus(\$invite)")
        && str_contains($service, "'invite_is_owner' => is_array(\$invite)")
        && str_contains($service, "'actions' => \$this->liveInviteActions(\$invite, \$userId)")
        && str_contains($service, "return \$status === 'awaiting_start' ? 'accepted' : \$status;"),
    'The live invitation event must carry status, role and legal actions before the toast is shown.'
);
$assert(
    str_contains($notifications, 'element.__mgwNotificationItem = cloneItem(item);')
        && str_contains($notifications, 'function toastSnapshot(')
        && str_contains($notifications, 'pressedToastItem = toastSnapshot(element);')
        && str_contains($notifications, 'pressedToastItem || toastSnapshot() || newestItem()'),
    'Mobile pointer and click paths must open from one exact immutable toast snapshot.'
);
$paintPosition = strpos($notifications, "if (source === 'toast') await waitForFirstSheetPaint(generation);");
$refreshPosition = strpos($notifications, 'await refreshOpenSheet(generation);', $paintPosition ?: 0);
$assert(
    $paintPosition !== false
        && $refreshPosition !== false
        && $paintPosition < $refreshPosition
        && str_contains($notifications, 'window.requestAnimationFrame(resolve)')
        && str_contains($notifications, 'if (!isCurrentSheet(generation)) return;'),
    'The exact toast card must receive a real browser paint before the background notification refresh may repaint the sheet.'
);
$assert(
    str_contains($notifications, 'for (const item of immediate) pinItem(item);')
        && str_contains($notifications, 'return mergeNotificationItems(pinned, currentItems());')
        && str_contains($notifications, 'rememberLocalAuthority(item);'),
    'The first-frame item must stay pinned and locally authoritative throughout server reconciliation.'
);
$assert(
    str_contains($notifications, 'item.actions = completeInviteActions(item);')
        && str_contains($notifications, "status === 'pending'")
        && str_contains($notifications, "return ['accept','decline'];")
        && str_contains($notifications, "status === 'accepted'")
        && str_contains($notifications, "['start','cancel']"),
    'A complete button set must be reconstructed from authoritative invite status if an older cached item lacks actions.'
);
$assert(
    str_contains($notifications, 'element.__mgwNotificationItem = null;')
        && str_contains($notifications, "element.classList.remove('show','dragging');"),
    'The DOM snapshot must be cleared only when the toast is dismissed after its exact item was captured.'
);

fwrite(STDOUT, "ProductionV110MobileNotificationFirstPaintContractTest: {$assertions} assertions passed\n");
