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
$versionManifest = $read('app/runtime/client/version-manifest.php');

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
$assert(strlen($safePrefix) === 12, 'Safe game-screen must expose a valid computed content-address prefix.');
$assert(strlen($acceptancePrefix) === 12, 'Acceptance runtime must keep a valid content fingerprint after the reviewed launch-owner extension.');
$assert(strlen($readonlyPrefix) === 12, 'Read-only sync must expose a valid computed content-address prefix.');
$assert(strlen($shellPrefix) === 12, 'Handoff shell must expose a valid computed content-address prefix.');
$assert(strlen($mainPrefix) === 12, 'Main v110 must expose a valid computed content-address prefix.');

$assert(
    str_contains($v110, "runtime/client/version-manifest.php")
        && str_contains($v110, 'type=\\"importmap\\"'),
    'v110 entrypoint must build its active import graph from the canonical version manifest.'
);
$assert(
    str_contains($shell, "./screens/game-screen-v102-safe.js?v=102")
        && str_contains($versionManifest, "'./assets/js/screens/game-screen-v102-safe.js?v=102' => './assets/js/screens/game-screen-v102-safe.js?v=105"),
    'Safe game-screen import key must resolve through the canonical version manifest.'
);
$assert(
    str_contains($versionManifest, "'./assets/js/production-v110-acceptance-runtime.js?v=110' => './assets/js/production-v110-acceptance-runtime.js?v=132")
        && str_contains($versionManifest, 'mvp21_5=countdown-10-fresh60-v2'),
    'The active v110 graph must resolve the reviewed MVP-21.5 acceptance runtime through the canonical version manifest.'
);
$assert(
    str_contains($shell, "./production-v110-readonly-game-sync.js?v=1107&b=bc9d7b435f1a")
        && str_contains($versionManifest, "'./assets/js/production-v110-readonly-game-sync.js?v=1107&b=bc9d7b435f1a' => './assets/js/production-v110-readonly-game-sync.js?v=1117"),
    'Read-only freshness import key must resolve through the canonical version manifest.'
);
$assert(
    str_contains($main, "./main-v110-handoff-shell.js?v=1137&ux=1&sk=3&icons=c1efd5af&render=5")
        && str_contains($versionManifest, "'./assets/js/main-v110-handoff-shell.js?v=1137&ux=1&sk=3&icons=c1efd5af&render=5' => './assets/js/main-v110-handoff-shell.js?v=1156"),
    'Main v110 shell import key must resolve through the canonical version manifest.'
);

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
    str_contains($readonly, '/bot/game-watch.php')
        && !str_contains($readonly, '/bot/api.php')
        && str_contains($readonly, 'adoptClockProjection(game);')
        && str_contains($readonly, 'if (actionIsBusy(currentItem))'),
    'Read-only owner must use the dedicated watch endpoint and project authoritative clock snapshots without action writes.'
);

$assert(str_contains($acceptance, "document.addEventListener('mgw:phase-b-game-entering', primeLaunchState);"), 'Acceptance runtime must own the synchronous global launch-gate event.');
$assert(str_contains($acceptance, "owner = document.getElementById('app')"), 'Launch overlay must be owned by the application root, not the board.');
$assert(!str_contains($acceptance, "querySelector('#screen-game .board-wrap')"), 'Launch overlay must never be mounted inside the game board wrapper.');
$assert(str_contains($acceptance, 'z-index:10000') && str_contains($acceptance, 'inset:0'), 'Launch overlay must cover the complete application above game UI.');
$assert(str_contains($acceptance, "title.textContent = 'Матч скоро начнётся'"), 'Preparing/countdown state must use user-facing launch copy.');
$assert(str_contains($acceptance, "title.textContent = 'Всё готово'"), 'Final launch handoff must use user-facing ready copy.');
$assert(str_contains($acceptance, 'launchCountdownSeconds(game)')
    && str_contains($acceptance, 'game?.launch_countdown_sec ?? 3')
    && str_contains($acceptance, 'String(total - index)'),
    'Launch presentation must preserve three seconds by default while rendering an authoritative N-to-1 countdown when supplied.');
$assert(!str_contains($acceptance, 'Синхронизируем игроков'), 'Technical synchronization wording must not be exposed to players.');
$assert(!str_contains($acceptance, 'Готово устройств:'), 'Technical device readiness counters must not be exposed to players.');

$assert(str_contains($acceptance, "window.addEventListener('click', guardPhaseBPreStartControls, true);"), 'Generic pre-start capture guard must remain active.');
$assert(str_contains($acceptance, "return !phase || phase === 'active';"), 'Explicit surrender must remain blocked until authoritative active phase.');
$assert(
    str_contains($acceptance, 'if (!runtime.clock || runtime.clock.signature !== signature)')
        && str_contains($acceptance, 'immutable local projection of the authoritative server')
        && !str_contains($acceptance, 'runtime.clock.deadline = candidateDeadline'),
    'Same-turn snapshots must never retarget the local authoritative deadline.'
);
$assert(
    str_contains($acceptance, 'start:candidateStart')
        && !str_contains($acceptance, 'runtime.clock.start = candidateStart'),
    'Same-turn snapshots must never retarget the local start anchor.'
);
$assert(str_contains($acceptance, "if (phase && phase !== 'active') return false;"), 'Countdown actions must remain blocked until the authoritative server-active handoff.');
$assert(str_contains($acceptance, "phase === 'countdown'") && str_contains($acceptance, "|| phase === 'preparation_timeout'"), 'Visible first-turn clock must stay full throughout preparing/countdown before active handoff.');
$assert(str_contains($acceptance, "const serverReady = phase === 'active';"), 'Launch overlay must not release from a local countdown alone.');

fwrite(STDOUT, "PhaseBClientLaunchUxContractTest: {$assertions} assertions passed\n");
