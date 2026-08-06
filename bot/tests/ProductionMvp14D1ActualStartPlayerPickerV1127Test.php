<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$read = static function (string $path) use ($root): string {
    $content = file_get_contents($root . '/' . $path);
    if (!is_string($content)) throw new RuntimeException('Cannot read ' . $path);
    return $content;
};

$v110 = $read('app/v110.php');
$main = $read('app/assets/js/main-v110.js');
$shell = $read('app/assets/js/main-v110-handoff-shell.js');
$invites = $read('app/assets/js/games/game-invites-v110.js');
$e2e = $read('e2e/staging/d1-bug-b-player-picker-v122.spec.mjs');

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

$assert(
    str_contains($v110, "./assets/js/main-v110.js?v=1133")
        && str_contains($main, "./main-v110-handoff-shell.js?v=1133")
        && str_contains($shell, "./games/game-invites-v110.js?v=1133"),
    'Ordinary Telegram Start must publish the fresh outer v1132 graph while retaining the canonical inner player-picker owner v1130.'
);

$assert(
    substr_count($shell, "game-invites-v110.js?v=1130") === 1
        && substr_count($shell, 'initGameInvites();') === 1
        && substr_count($invites, 'async function openPlayerPicker(context, sourceButton = null)') === 1
        && substr_count($invites, 'const OPPONENTS_URL = `${window.location.origin}/bot/invite-opponents.php`;') === 1,
    'The actual Start graph must keep exactly one player-picker module, initializer, UI owner and endpoint owner.'
);

$assert(
    !str_contains($invites, 'Загружаем соперников')
        && str_contains($invites, 'const result = await postJson(OPPONENTS_URL, {});')
        && str_contains($invites, 'playerPickerRequestGeneration')
        && str_contains($invites, "trigger.setAttribute('aria-busy', 'true');")
        && str_contains($invites, 'renderPlayerPicker(items, context);')
        && strpos($invites, 'const result = await postJson(OPPONENTS_URL, {});') < strpos($invites, 'renderPlayerPicker(items, context);')
        && !str_contains($invites, 'renderPlayerPicker([], context);'),
    'The canonical owner must keep the setup sheet visible and open the picker only from the completed authoritative response.'
);

$assert(
    !str_contains($shell, 'opponents-native-fetch')
        && !str_contains($shell, 'opponents-empty-cache-guard')
        && !str_contains($shell, 'opponents-authoritative-confirm')
        && !str_contains($shell, 'opponents-fresh-user-action')
        && !str_contains($shell, 'window.fetch =')
        && !str_contains($invites, 'window.fetch ='),
    'The fix must not restore retry wrappers, global fetch interception or a second player-picker owner.'
);

$assert(
    str_contains($e2e, 'const APP_ROUTE = `${STAGING_ORIGIN}/app/v110.php?v=1123`;')
        && str_contains($e2e, "url.searchParams.get('v') === '1133'")
        && str_contains($e2e, 'requestAnimationFrame(capture);')
        && str_contains($e2e, 'FALSE_EMPTY_PATTERN')
        && str_contains($e2e, 'setTimeout(resolve, 1500)')
        && str_contains($e2e, 'pickerFrames[0].text')
        && str_contains($e2e, 'expect(requests).toBe(1);'),
    'The live regression must exercise ordinary Start, verify the retained inner owner URL and inspect every visible frame on one fresh request.'
);

$assert(
    str_contains($e2e, "runActualStartPicker(browser, false)")
        && str_contains($e2e, "runActualStartPicker(browser, true)"),
    'Desktop and mobile Chromium must be validated independently.'
);

fwrite(STDOUT, "ProductionMvp14D1ActualStartPlayerPickerV1130Test: {$assertions} assertions passed\n");
