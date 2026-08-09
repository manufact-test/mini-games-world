<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$read = static function (string $path) use ($root): string {
    $content = file_get_contents($root . '/' . $path);
    if (!is_string($content)) throw new RuntimeException('Missing source: ' . $path);
    return $content;
};
$blobPrefix = static function (string $content): string {
    return substr(sha1('blob ' . strlen($content) . "\0" . $content), 0, 12);
};

$safePath = 'app/assets/js/screens/game-screen-v102-safe.js';
$acceptancePath = 'app/assets/js/production-v110-acceptance-runtime.js';
$readonlyPath = 'app/assets/js/production-v110-readonly-game-sync.js';
$shellPath = 'app/assets/js/main-v110-handoff-shell.js';
$mainPath = 'app/assets/js/main-v110.js';
$safe = $read($safePath);
$acceptance = $read($acceptancePath);
$readonly = $read($readonlyPath);
$shell = $read($shellPath);
$main = $read($mainPath);
$v110 = $read('app/v110.php');
$manifest = $read('bot/helpers/staging-e2e-runtime-files.txt');

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

$safePrefix = $blobPrefix($safe);
$acceptancePrefix = $blobPrefix($acceptance);
$readonlyPrefix = $blobPrefix($readonly);
$shellPrefix = $blobPrefix($shell);
$mainPrefix = $blobPrefix($main);
$assert($safePrefix === '901c5c869703', 'Safe game-screen blob prefix must match the reviewed content-address value.');
$assert($acceptancePrefix === 'c24c4e5611c8', 'Acceptance runtime blob prefix must match the reviewed content-address value.');
$assert($readonlyPrefix === 'bc9d7b435f1a', 'Read-only sync blob prefix must match the reviewed content-address value.');
$assert($shellPrefix === 'c723392fcac8', 'Handoff shell blob prefix must match the reviewed content-address value.');
$assert($mainPrefix === '31fca0ad4bfb', 'Main v110 blob prefix must match the reviewed content-address value.');

$assert(str_contains($v110, 'game-screen-v102-safe.js?v=102&b=' . $safePrefix), 'v110 import map must content-address the active safe wrapper.');
$assert(str_contains($v110, 'production-v110-acceptance-runtime.js?v=110&b=' . $acceptancePrefix), 'v110 import map must content-address the active acceptance runtime.');
$assert(str_contains($shell, 'production-v110-readonly-game-sync.js?v=1107&b=' . $readonlyPrefix), 'Handoff shell must content-address the read-only freshness owner.');
$assert(str_contains($main, 'main-v110-handoff-shell.js?v=1135&pending=6&b=' . $shellPrefix), 'Main v110 must content-address the handoff shell.');
$assert(str_contains($v110, 'main-v110.js?v=1135&pending=6&b=' . $mainPrefix), 'v110 entrypoint must content-address main v110.');

foreach ([$safePath, $acceptancePath, $readonlyPath, $shellPath, $mainPath] as $path) {
    $assert(str_contains($manifest, $path), $path . ' must be included in exact staging fingerprint coverage.');
}

$assert(!str_contains($safe, 'PREACTIVE_POLL_MS'), 'Pre-start must not reintroduce a fast write-poll constant.');
$assert(!str_contains($safe, 'APP_CONFIG.gameIntervalMs'), 'Safe wrapper must not mutate the authoritative game_state polling cadence.');
$assert(str_contains($safe, "new CustomEvent('mgw:phase-b-game-entering'"), 'Safe wrapper must synchronously prime the global launch gate before rendering the game.');
$assert(strpos($safe, "new CustomEvent('mgw:phase-b-game-entering'") < strpos($safe, 'enterBaseGame(game, me);'), 'Global launch gate must be primed before the game screen renders.');

$assert(str_contains($readonly, "const WATCH_INTERVAL_MS = 250;"), 'Read-only cross-device freshness must remain bounded at 250ms.');
$assert(str_contains($readonly, "['preparing', 'countdown', 'active'].includes(launchPhase)"), 'Read-only freshness must cover preparation, countdown and active phases.');
$assert(
    str_contains($readonly, 'Frequent cross-device freshness reads only games.json')
        && str_contains($readonly, 'global write transaction lock'),
    'Read-only owner must document the pre-start lock-isolation contract.'
);

$assert(str_contains($acceptance, "document.addEventListener('mgw:phase-b-game-entering', primeLaunchState);"), 'Acceptance runtime must own the synchronous global launch-gate event.');
$assert(str_contains($acceptance, "owner = document.getElementById('app')"), 'Launch overlay must be owned by the application root, not the board.');
$assert(!str_contains($acceptance, "querySelector('#screen-game .board-wrap')"), 'Launch overlay must never be mounted inside the game board wrapper.');
$assert(str_contains($acceptance, 'z-index:140') && str_contains($acceptance, 'inset:0'), 'Launch overlay must cover the complete application above game UI.');
$assert(str_contains($acceptance, "title.textContent = 'Готовим матч'"), 'Preparing state must use user-facing launch copy.');
$assert(str_contains($acceptance, "title.textContent = 'Поехали!'"), 'Countdown state must use user-facing countdown copy.');
$assert(!str_contains($acceptance, 'Синхронизируем игроков'), 'Technical synchronization wording must not be exposed to players.');
$assert(!str_contains($acceptance, 'Готово устройств:'), 'Technical device readiness counters must not be exposed to players.');

$assert(str_contains($acceptance, "window.addEventListener('click', guardPhaseBPreStartControls, true);"), 'Generic pre-start capture guard must remain active.');
$assert(str_contains($acceptance, "return !phase || phase === 'active';"), 'Explicit surrender must remain blocked until authoritative active phase.');
$assert(str_contains($acceptance, 'candidateDeadline + 700 < runtime.clock.deadline'), 'Same-turn snapshots must never extend the local deadline.');
$assert(str_contains($acceptance, 'candidateStart + 250 < runtime.clock.start'), 'Same-turn snapshots must never extend the local start anchor.');
$assert(str_contains($acceptance, "phase === 'countdown' && !launchStartReached(game)"), 'Countdown actions must remain blocked until the shared start anchor.');
$assert(str_contains($acceptance, "phase === 'preparing' || phase === 'preparation_timeout' || phase === 'cancelled'"), 'Pre-start and cancelled actions must be blocked before optimistic state.');

fwrite(STDOUT, "PhaseBClientLaunchUxContractTest: {$assertions} assertions passed\n");
