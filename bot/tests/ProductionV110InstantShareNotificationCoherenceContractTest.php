<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$read = static function (string $path) use ($root): string {
    $content = file_get_contents($root . '/' . $path);
    if (!is_string($content)) throw new RuntimeException('Cannot read R7 source: ' . $path);
    return $content;
};

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

$entry = $read('app/v110.php');
$owner = $read('app/assets/js/production-v110-invite-share-notification-owner.js');
$clean = $read('app/assets/js/production-clean-entry-v110.js');

$assert(
    str_contains($entry, 'production-v110-invite-share-notification-owner.js?v=1112'),
    'The no-store v110 entrypoint must load the fresh share/notification owner.'
);
$assert(
    str_contains($owner, "window.addEventListener('pointerdown', rememberShareIntent, true)")
        && str_contains($owner, "window.addEventListener('click', ownInviteShareClick, true)")
        && str_contains($owner, "origin.closest('[data-create-link-invite]')")
        && str_contains($owner, 'event.stopImmediatePropagation();'),
    'One window-capture owner must fully own the link-share button before retained target listeners.'
);
$assert(
    str_contains($owner, 'prepareMessage:false')
        && str_contains($owner, 'runtime.serial = runtime.serial')
        && str_contains($owner, 'void warmDraft(defaultContext(gameType))'),
    'Link drafts must be prewarmed serially without waiting for Telegram prepared-message creation.'
);
$assert(
    str_contains($owner, 'https://t.me/share/url?url=')
        && str_contains($owner, 'telegram.openTelegramLink(url)')
        && !str_contains($owner, '.shareMessage(')
        && !str_contains($owner, 'Ждём результата отправки')
        && !str_contains($owner, 'Отправка отменена'),
    'Share cancellation must return to the unchanged setup immediately and remain silent.'
);
$assert(
    str_contains($owner, "window.addEventListener('mgw:notification-sync', rememberAlreadyPresentedInviteToast, true)")
        && str_contains($owner, 'function rememberAlreadyPresentedInviteToast(event){')
        && str_contains($owner, "#sheet [data-invite-sheet][data-invite-token]")
        && str_contains($owner, 'new MutationObserver(suppressMatchingInviteToast)')
        && str_contains($owner, 'mgw-invite-toast-suppressed'),
    'An invite already visible in its canonical sheet must suppress only the later duplicate blue toast.'
);
$assert(
    str_contains($owner, 'Let the canonical notification owner receive this event')
        && !str_contains($owner, "event.stopImmediatePropagation();\n  rememberAnnouncedId"),
    'Duplicate-toast suppression must not swallow the canonical notification state update.'
);
$assert(
    !str_contains($clean, 'initV109ShareSpeed')
        && !str_contains($clean, 'initV109ShareFallbackGuard')
        && !str_contains($clean, 'initV99InvitePickerHold'),
    'Historical competing share and picker owners must remain outside the active graph.'
);

fwrite(STDOUT, "ProductionV110InstantShareNotificationCoherenceContractTest: {$assertions} assertions passed\n");
