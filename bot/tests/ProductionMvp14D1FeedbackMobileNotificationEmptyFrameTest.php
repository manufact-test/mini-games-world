<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$entry = file_get_contents($root . '/app/v114.php');
$guard = file_get_contents($root . '/app/assets/js/screens/notification-empty-frame-guard-v115.js');
if (!is_string($entry) || !is_string($guard)) {
    throw new RuntimeException('Missing mobile notification empty-frame sources.');
}

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

$assert(
    str_contains($entry, 'notification-empty-frame-guard-v115.js?v=115')
        && substr_count($entry, 'notification-empty-frame-guard-v115.js?v=115') === 1,
    'The staging entry must load exactly one fresh empty-frame guard.'
);

$assert(
    str_contains($guard, "target?.closest('#notificationsOpen, #notificationToast')")
        && str_contains($guard, 'guardUntil = performance.now() + GUARD_MS;')
        && str_contains($guard, "new MutationObserver(guardTransientEmptyFrame)"),
    'The guard must cover both bell and blue-toast openings and observe only rendered sheet changes.'
);

$assert(
    str_contains($guard, "'Пока уведомлений нет'")
        && str_contains($guard, "empty.innerHTML = '<div>🔔</div><strong>Загружаем…</strong>'")
        && str_contains($guard, 'empty.innerHTML = originalHtml;'),
    'A transient empty frame must stay loading while a genuine confirmed empty state remains available.'
);

$assert(
    !str_contains($guard, 'stopImmediatePropagation')
        && !str_contains($guard, 'preventDefault')
        && !str_contains($guard, 'api.notifications')
        && !str_contains($guard, 'data-invite-action'),
    'This isolated visual guard must not own bell clicks, network reads or invitation actions.'
);

$assert(
    str_contains($guard, "document.addEventListener('DOMContentLoaded', initNotificationEmptyFrameGuard, { once:true })")
        && str_contains($guard, 'if (observer) return;'),
    'The guard must initialize once even when loaded before the sheet DOM.'
);

fwrite(STDOUT, "ProductionMvp14D1FeedbackMobileNotificationEmptyFrameTest: {$assertions} assertions passed\n");
