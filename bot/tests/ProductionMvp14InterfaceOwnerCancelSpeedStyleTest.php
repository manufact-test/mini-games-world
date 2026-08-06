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

$actionStart = strpos($client, 'async function performInviteAction(');
$actionEnd = $actionStart === false ? false : strpos($client, 'function terminalActionContext(', $actionStart);
$actionOwner = $actionStart === false || $actionEnd === false
    ? ''
    : substr($client, $actionStart, $actionEnd - $actionStart);

$waitingStart = strpos($client, 'function showOwnerWaiting(');
$waitingEnd = $waitingStart === false ? false : strpos($client, 'function showOwnerReady(', $waitingStart);
$waitingOwner = $waitingStart === false || $waitingEnd === false
    ? ''
    : substr($client, $waitingStart, $waitingEnd - $waitingStart);

$assert(
    $waitingOwner !== ''
        && str_contains($waitingOwner, 'class="btn primary full"')
        && str_contains($waitingOwner, 'data-invite-action="cancel"')
        && str_contains($waitingOwner, '>Отменить приглашение</button>'),
    'The owner waiting cancellation control must use the visible primary purple button style.'
);
$assert(
    $actionOwner !== ''
        && str_contains($actionOwner, "const optimisticOwnerCancel = action === 'cancel'")
        && str_contains($actionOwner, 'Boolean(rollbackInvite?.is_owner)')
        && str_contains($actionOwner, '!terminalContext.notificationSurface'),
    'Immediate cancellation feedback must be limited to the owner sheet and excluded from notification cards.'
);
$assert(
    strpos($actionOwner, 'closeSheet();') < strpos($actionOwner, 'const result = await inviteRequest(action, { token });')
        && strpos($actionOwner, "showScreen('home');") < strpos($actionOwner, 'const result = await inviteRequest(action, { token });'),
    'The owner sheet must close and return home before the network round trip completes.'
);
$assert(
    substr_count($actionOwner, 'await inviteRequest(action, { token });') === 1
        && str_contains($actionOwner, 'currentInvite = result.invite || currentInvite;'),
    'The server request must remain the single authoritative cancellation owner.'
);
$assert(
    str_contains($actionOwner, 'currentInvite = rollbackInvite;')
        && str_contains($actionOwner, 'if (rollbackHtml) openSheet(rollbackHtml);')
        && str_contains($actionOwner, "toast(error.message || 'Не удалось выполнить действие.');"),
    'A failed authoritative cancellation must restore the exact previous sheet and state.'
);

fwrite(STDOUT, "ProductionMvp14InterfaceOwnerCancelSpeedStyleTest: {$assertions} assertions passed\n");
