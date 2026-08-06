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

$pickerStart = strpos($client, "document.querySelector('[data-open-player-picker]')");
$pickerEnd = $pickerStart === false ? false : strpos($client, "document.querySelector('[data-create-link-invite]')", $pickerStart);
$pickerOwner = $pickerStart === false || $pickerEnd === false
    ? ''
    : substr($client, $pickerStart, $pickerEnd - $pickerStart);

$directStart = strpos($client, 'async function createDirectInvite(');
$directEnd = $directStart === false ? false : strpos($client, 'async function createLinkDraft(', $directStart);
$directOwner = $directStart === false || $directEnd === false
    ? ''
    : substr($client, $directStart, $directEnd - $directStart);

$assert(
    $pickerOwner !== ''
        && str_contains($pickerOwner, 'openPlayerPicker(currentContext(), event.currentTarget);')
        && !str_contains($pickerOwner, 'cancelWarmShareDraft()'),
    'Opening the player picker must not launch a competing draft-discard write before the authoritative opponents read.'
);
$assert(
    $directOwner !== ''
        && !str_contains(substr($directOwner, 0, (int)strpos($directOwner, 'try {')), 'cancelWarmShareDraft()')
        && str_contains($directOwner, 'scheduleSync(0);')
        && str_contains($directOwner, 'window.setTimeout(cancelWarmShareDraft, 180);'),
    'Direct invite creation must finish its authoritative request before deferred warm-draft cleanup starts.'
);
$assert(
    str_contains($client, "priority:'high'")
        && !str_contains($client, "priority:options.prefetch ? 'low' : 'high'"),
    'A warm share request that becomes user-awaited must not remain browser-low-priority.'
);
$assert(
    str_contains($shell, './games/game-invites-v110.js?v=1135')
        && str_contains($main, './main-v110-handoff-shell.js?v=1135')
        && str_contains($entry, './assets/js/main-v110.js?v=1135')
        && str_contains($entry, "header('X-MGW-Invite-Graph: v1135');"),
    'The exact invite-speed owner must be published through one immutable v1135 graph.'
);
$assert(
    substr_count($client, "postJson(OPPONENTS_URL, {})") === 1
        && !str_contains($client, 'Загружаем соперников'),
    'The accepted one-request ready-first picker architecture must remain unchanged.'
);

fwrite(STDOUT, "ProductionMvp14InterfaceInviteEntrySpeedV1135Test: {$assertions} assertions passed\n");
