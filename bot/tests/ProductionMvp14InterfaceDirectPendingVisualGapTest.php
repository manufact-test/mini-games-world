<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$read = static function (string $path) use ($root): string {
    $content = file_get_contents($root . '/' . $path);
    if (!is_string($content)) throw new RuntimeException('Cannot read ' . $path);
    return $content;
};

$client = $read('app/assets/js/games/game-invites-v110.js');
$shell = $read('app/assets/js/main-v110-handoff-shell.js');
$main = $read('app/assets/js/main-v110.js');
$entry = $read('app/v110.php');

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

$pendingStart = strpos($client, 'function showDirectInvitePending(');
$pendingEnd = $pendingStart === false ? false : strpos($client, 'function showIncomingInvite(', $pendingStart);
$pendingOwner = $pendingStart === false || $pendingEnd === false
    ? ''
    : substr($client, $pendingStart, $pendingEnd - $pendingStart);

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

$assert(
    $pendingOwner !== ''
        && !str_contains($pendingOwner, 'Доставляем приглашение игроку')
        && !str_contains($pendingOwner, 'получит его в приложении'),
    'The direct-invite pending frame must not expose the visible delivery/loading copy.'
);
$assert(
    str_contains($pendingOwner, 'class="btn primary full"')
        && str_contains($pendingOwner, '>Отменить приглашение</button>')
        && str_contains($pendingOwner, 'aria-disabled="true"')
        && str_contains($pendingOwner, ' disabled')
        && !str_contains($pendingOwner, 'data-invite-action="cancel"'),
    'The pending frame must reserve the final purple cancellation control without owning cancellation before a token exists.'
);
$assert(
    $waitingOwner !== ''
        && str_contains($waitingOwner, 'class="btn primary full"')
        && str_contains($waitingOwner, 'data-invite-action="cancel"')
        && str_contains($waitingOwner, '>Отменить приглашение</button>'),
    'The authoritative post-response owner cancellation control must remain unchanged.'
);
$assert(
    $directOwner !== ''
        && substr_count($directOwner, "inviteRequest('create_direct'") === 1
        && str_contains($directOwner, 'showDirectInvitePending(context, opponentName);')
        && str_contains($directOwner, 'showOwnerWaiting(currentInvite);'),
    'Direct invitation creation must retain one authoritative server request and the accepted final owner transition.'
);
$assert(
    str_contains($shell, './games/game-invites-v110.js?v=1135&pending=4')
        && str_contains($main, './main-v110-handoff-shell.js?v=1135&pending=4')
        && str_contains($entry, './assets/js/main-v110.js?v=1135&pending=4'),
    'The passive owner-pending update must publish the canonical invite owner through fresh pending=4 browser paths.'
);

fwrite(STDOUT, "ProductionMvp14InterfaceDirectPendingVisualGapTest: {$assertions} assertions passed\n");
