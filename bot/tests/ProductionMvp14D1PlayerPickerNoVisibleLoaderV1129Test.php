<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$read = static function (string $path) use ($root): string {
    $content = file_get_contents($root . '/' . $path);
    if (!is_string($content)) throw new RuntimeException('Cannot read ' . $path);
    return $content;
};

$invites = $read('app/assets/js/games/game-invites-v110.js');
$e2e = $read('e2e/staging/d1-bug-b-player-picker-v122.spec.mjs');
$v110 = $read('app/v110.php');
$main = $read('app/assets/js/main-v110.js');
$shell = $read('app/assets/js/main-v110-handoff-shell.js');

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

$start = strpos($invites, 'async function openPlayerPicker(context, sourceButton = null)');
$end = strpos($invites, "\nfunction renderPlayerPicker", $start === false ? 0 : $start);
$assert($start !== false && $end !== false, 'Canonical player-picker function must be present once.');
$picker = substr($invites, $start, $end - $start);

$assert(!str_contains($picker, 'openSheet(`'), 'The picker must not open any intermediate sheet before the server response.');
$assert(!str_contains($picker, 'Загружаем соперников'), 'The visible opponent loader must be absent from the canonical owner.');
$assert(substr_count($picker, 'postJson(OPPONENTS_URL, {})') === 1, 'One user action must perform exactly one fresh opponent request.');
$assert(strpos($picker, 'await postJson(OPPONENTS_URL, {})') < strpos($picker, 'renderPlayerPicker(items, context);'), 'The completed response must precede the first picker render.');
$assert(str_contains($picker, 'playerPickerRequestGeneration') && str_contains($picker, 'requestGeneration !== playerPickerRequestGeneration'), 'Late responses must be ignored by the canonical owner.');
$assert(str_contains($picker, "trigger.disabled = true;") && str_contains($picker, "trigger.setAttribute('aria-busy', 'true');"), 'The existing setup button must own the pending state without opening a loader sheet.');
$assert(!str_contains($picker, 'setTimeout') && !str_contains($picker, 'window.fetch =') && !str_contains($picker, 'Promise.all'), 'The fix must not add timers, fetch interception, retries or parallel owners.');
$assert(str_contains($v110, 'main-v110.js?v=1129') && str_contains($main, 'main-v110-handoff-shell.js?v=1129') && str_contains($shell, 'game-invites-v110.js?v=1129'), 'Ordinary Start must publish the no-loader owner through a fresh v1129 chain.');
$assert(str_contains($e2e, 'setTimeout(resolve, 1500)') && str_contains($e2e, '/Загружаем соперников/i') && str_contains($e2e, 'pickerFrames[0].text'), 'The live test must hold the real response for 1.5 seconds and reject every visible loader frame.');
$assert(str_contains($e2e, 'route.continue()') && !str_contains($e2e, 'route.fulfill('), 'The regression must delay the real endpoint instead of fabricating a response.');
$assert(str_contains($e2e, 'expect(requests).toBe(1);'), 'The live regression must preserve one-request ownership.');

fwrite(STDOUT, "ProductionMvp14D1PlayerPickerNoVisibleLoaderV1129Test: {$assertions} assertions passed\n");
