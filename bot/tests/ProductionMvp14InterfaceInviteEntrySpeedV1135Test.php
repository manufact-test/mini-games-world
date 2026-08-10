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

$pickerStart = strpos($client, 'async function openPlayerPicker(');
$pickerEnd = $pickerStart === false ? false : strpos($client, 'function playerCard(', $pickerStart);
$pickerOwner = $pickerStart === false || $pickerEnd === false ? '' : substr($client, $pickerStart, $pickerEnd - $pickerStart);

$directStart = strpos($client, 'async function createDirectInvite(');
$directEnd = $directStart === false ? false : strpos($client, 'async function createLinkDraft(', $directStart);
$directOwner = $directStart === false || $directEnd === false ? '' : substr($client, $directStart, $directEnd - $directStart);

$assert(
    substr_count($client, "postJson(OPPONENTS_URL, {})") === 1
        && str_contains($pickerOwner, 'showPlayerPickerLoading(context, requestGeneration);')
        && str_contains($pickerOwner, 'renderPlayerPicker(items, context, requestGeneration);'),
    'Player picker must paint immediately but keep exactly one authoritative opponents request.'
);
$assert(
    str_contains($client, 'data-player-picker-results aria-busy="true"')
        && str_contains($client, 'Загружаем игроков')
        && str_contains($client, "surface.results.setAttribute('aria-busy', 'false');"),
    'Picker must expose one stable loading surface and populate that same surface after the authoritative response.'
);
$assert(
    $directOwner !== ''
        && str_contains($directOwner, 'showDirectInvitePending(context, opponentName, requestGeneration);')
        && str_contains($directOwner, 'finalizeDirectInvitePendingSurface(currentInvite, requestGeneration);')
        && str_contains($client, 'data-direct-invite-cancel-reserved'),
    'Direct invite must paint immediately and upgrade its reserved Cancel control in place after token creation.'
);
$assert(
    str_contains($client, 'let inviteUiTransitionGeneration = 0;')
        && str_contains($client, 'const syncUiTransitionGeneration = inviteUiTransitionGeneration;')
        && str_contains($client, 'if (syncUiTransitionGeneration !== inviteUiTransitionGeneration) return result;'),
    'A sync started before an explicit invite action must not regain UI ownership after that action begins.'
);
$assert(
    str_contains($client, 'reconcileInviteeWaiting(currentInvite)')
        && str_contains($client, 'function reconcileInviteeWaiting(invite)'),
    'Authoritative Accept completion must reconcile the optimistic waiting sheet in place when it is still current.'
);
$assert(
    str_contains($client, "priority:'high'")
        && !str_contains($client, "priority:options.prefetch ? 'low' : 'high'"),
    'User-awaited invite requests must retain the accepted high browser priority.'
);
$assert(
    str_contains($shell, './games/game-invites-v110.js?v=1136')
        && str_contains($main, './main-v110-handoff-shell.js?v=1136')
        && str_contains($entry, './assets/js/main-v110.js?v=1136')
        && str_contains($entry, "header('X-MGW-Invite-Graph: v1136');"),
    'The popup-stability owner must be published through one immutable v1136 graph.'
);
$assert(
    !str_contains($client, 'retry')
        && !str_contains($pickerOwner, 'setTimeout(')
        && !str_contains($pickerOwner, 'setInterval('),
    'Popup stability must not be implemented with retries, sleeps, or picker timing patches.'
);

fwrite(STDOUT, "ProductionMvp14InterfaceInviteEntrySpeedV1135Test: {$assertions} assertions passed (v1136 successor contract)\n");
