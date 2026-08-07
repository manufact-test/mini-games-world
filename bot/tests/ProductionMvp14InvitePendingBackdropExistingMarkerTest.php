<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$client = file_get_contents($root . '/app/assets/js/games/game-invites-v110.js');
$inviteCss = file_get_contents($root . '/app/assets/css/components/game-invites.css');

if (!is_string($client) || !is_string($inviteCss)) {
    throw new RuntimeException('Cannot read invite client/CSS sources.');
}

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

$pendingStart = strpos($client, 'function showDirectInvitePending(');
$pendingEnd = $pendingStart === false ? false : strpos($client, 'function showIncomingInvite(', $pendingStart);
$pending = $pendingStart === false || $pendingEnd === false
    ? ''
    : substr($client, $pendingStart, $pendingEnd - $pendingStart);

$assert(
    $pending !== '' && str_contains($pending, '<span data-invite-sheet hidden></span>'),
    'The pre-token direct-invite frame must opt into the existing invite backdrop immediately.'
);
$assert(
    !str_contains($pending, 'data-invite-token=')
        && !str_contains($pending, 'data-invite-state=')
        && !str_contains($pending, 'data-invite-action="cancel"'),
    'The visual marker must not pretend to own a token, state transition, or cancellation action.'
);
$assert(
    str_contains($inviteCss, '.overlay:has([data-invite-sheet])')
        && str_contains($inviteCss, 'background:rgba(3,6,12,.84)')
        && str_contains($inviteCss, 'backdrop-filter:blur(6px)'),
    'The marker must reuse the already accepted invite backdrop rule.'
);

fwrite(STDOUT, "ProductionMvp14InvitePendingBackdropExistingMarkerTest: {$assertions} assertions passed\n");
