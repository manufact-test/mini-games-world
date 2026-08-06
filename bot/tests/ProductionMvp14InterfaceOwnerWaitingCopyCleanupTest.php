<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$client = file_get_contents($root . '/app/assets/js/games/game-invites-v110.js');
if (!is_string($client)) throw new RuntimeException('Cannot read canonical invite owner.');

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

$waitingStart = strpos($client, 'function showOwnerWaiting(');
$waitingEnd = $waitingStart === false ? false : strpos($client, 'function showOwnerReady(', $waitingStart);
$waitingOwner = $waitingStart === false || $waitingEnd === false
    ? ''
    : substr($client, $waitingStart, $waitingEnd - $waitingStart);

$directStart = strpos($client, 'async function createDirectInvite(');
$directEnd = $directStart === false ? false : strpos($client, 'async function createLinkDraft(', $directStart);
$directOwner = $directStart === false || $directEnd === false
    ? ''
    : substr($client, $directStart, $directEnd - $directStart);

$sharedStart = strpos($client, 'async function confirmSharedInvite(');
$sharedEnd = $sharedStart === false ? false : strpos($client, 'function discardDraft(', $sharedStart);
$sharedOwner = $sharedStart === false || $sharedEnd === false
    ? ''
    : substr($client, $sharedStart, $sharedEnd - $sharedStart);

$assert(
    !str_contains($client, 'Игрок получил приглашение в приложении и сообщение от бота.')
        && !str_contains($client, 'Игрок получил приглашение в приложении.'),
    'The redundant ordinary delivery copy must be removed from the canonical invite owner.'
);
$assert(
    $directOwner !== '' && str_contains($directOwner, 'showOwnerWaiting(currentInvite);'),
    'A direct invitation must open the clean owner waiting sheet without delivery copy.'
);
$assert(
    $sharedOwner !== '' && str_contains($sharedOwner, 'showOwnerWaiting(currentInvite);'),
    'A confirmed shared invitation must use the same clean owner waiting sheet.'
);
$assert(
    $waitingOwner !== ''
        && str_contains($waitingOwner, "function showOwnerWaiting(invite, message = '')")
        && str_contains($waitingOwner, '${message ? `<div class="small-note invite-status-note">${escapeHtml(message)}</div>` : \'\'}'),
    'The waiting note must render only for an explicitly supplied contextual message.'
);
$assert(
    str_contains($client, "showOwnerWaiting(currentInvite, 'Предложение реванша отправлено.');")
        && str_contains($waitingOwner, 'class="btn primary full"'),
    'The rematch context and the accepted primary cancellation control must remain intact.'
);

fwrite(STDOUT, "ProductionMvp14InterfaceOwnerWaitingCopyCleanupTest: {$assertions} assertions passed\n");
