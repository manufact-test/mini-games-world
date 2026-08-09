<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$read = static function (string $path) use ($root): string {
    $value = file_get_contents($root . '/' . $path);
    if (!is_string($value)) throw new RuntimeException('Missing source: ' . $path);
    return $value;
};

$htaccess = $read('app/.htaccess');
$v114 = $read('app/v114.php');
$entry = $read('app/assets/js/phase-b-current-entry.js');
$runtime = $read('app/assets/js/phase-b-current-runtime.js');
$game = $read('app/assets/js/screens/game-screen-phase-b-current.js');
$telegram = $read('bot/services/TelegramService.php');
$webhook = $read('bot/webhook.php');
$menuButton = $read('bot/helpers/StagingMenuButtonReconciler.php');
$fingerprint = $read('bot/helpers/staging-e2e-runtime-files.txt');

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

$assert(str_contains($htaccess, 'DirectoryIndex v114.php index.html'), 'Default /app/ must resolve through v114.php.');
$assert(str_contains($telegram, "/app/?v=121"), 'Telegram /start button must publish the current versioned /app/ entry.');
$assert(!str_contains($telegram, "/app/?v=85"), 'Telegram /start button must not keep the retired stale entry version.');
$assert(!str_contains($telegram, "/app/?v=120"), 'Telegram /start button must not publish the retired v120 entry.');
$assert(str_contains($webhook, 'StagingMenuButtonReconciler') && str_contains($webhook, '->reconcile();'), 'Staging webhook must reconcile the Telegram menu owner before handling /start.');
$assert(str_contains($menuButton, "'type' => 'commands'"), 'Staging menu reconciler must explicitly retire the persistent Web App button to commands.');
$assert(!str_contains($menuButton, "'type' => 'web_app'") && !str_contains($menuButton, "/app/"), 'Staging menu reconciler must not own any Web App entry URL.');
$assert(str_contains($v114, "\$entryVersion = '121';"), 'v114 must own the current Telegram entry version.');
$assert(str_contains($v114, "header('Location: ' . \$location, true, 302);"), 'v114 must redirect stale versioned entries to the current entry version.');
$assert(str_contains($v114, "header('X-MGW-Entry-Version: v' . \$entryVersion);"), 'v114 must expose an explicit entry-version response identity.');

$assert(
    str_contains($v114, './assets/js/phase-b-current-entry.js?v=121&b=2cee1709e1fe'),
    'v114 must publish the current Phase B entry under a fresh immutable URL.'
);
$assert(
    str_contains($v114, '"./assets/js/screens/game-screen.js?v=74": "./assets/js/screens/game-screen-phase-b-current.js?v=116&b=f6d062608b0c"'),
    'v114 import map must route every current D1 game-screen import to the Phase B-aware current owner.'
);
$assert(
    str_contains($v114, 'X-MGW-Phase-B-Build: phase-b-current-v121'),
    'v114 must expose an explicit current Phase B build identity.'
);
$assert(
    str_contains($entry, "import './production-regression-fix-entry.js?v=102';")
        && str_contains($entry, "./phase-b-current-runtime.js?v=121&b=0e808fe731ab")
        && str_contains($entry, "window.__MGW_PHASE_B_BUILD__ = 'phase-b-current-v121';")
        && str_contains($entry, 'initPhaseBCurrentRuntime();'),
    'Phase B entry must preserve the accepted regression owner and initialize the fresh Phase B runtime.'
);

$assert(str_contains($runtime, '/bot/game-watch.php'), 'Current Phase B runtime must use the read-only game watch.');
$assert(!str_contains($runtime, 'api.gameState('), 'Current Phase B runtime must not create a second primary game_state owner.');
$assert(str_contains($runtime, 'position:fixed;inset:0;z-index:10000'), 'Preparation UI must be a global fixed layer above the application.');
$assert(str_contains($runtime, "title.textContent = 'Подготовка матча'"), 'Preparation UI must remain one continuous product-facing stage.');
$assert(str_contains($runtime, "const blocking = phase === 'preparing' || phase === 'countdown';"), 'Preparation overlay must stay above the app through the entire authoritative countdown phase.');
$assert(str_contains($runtime, "readyForServer ? 'Открываем игру' : 'Начинаем одновременно'"), 'Countdown completion must remain visibly inside preparation until authoritative active arrives.');
$assert(str_contains($runtime, "readyForServer ? 'СТАРТ' : String(seconds)"), 'One continuous preparation surface must own countdown completion.');
$assert(!str_contains(strtolower($runtime), 'синхрониза'), 'Player-facing Phase B runtime must not contain technical synchronization wording.');
$assert(str_contains($runtime, 'WATCH_INTERVAL_MS = 250'), 'Read-only Phase B freshness cadence must remain explicit and bounded.');
$assert(str_contains($runtime, 'PRIMARY_GAME_POLL_FLOOR_MS = 1500'), 'Primary game_state polling must remain slower than read-only freshness.');
$assert(str_contains($runtime, 'width:min(100%,460px)'), 'Preparation background composition must use one centered stable canvas across viewport aspect ratios.');
$assert(str_contains($runtime, 'width:min(100%,400px);height:336px;display:grid;grid-template-rows:30px 136px 52px 46px 24px'), 'Preparation card must keep one fixed compact geometry across all launch stages.');
$assert(str_contains($runtime, 'white-space:nowrap'), 'Preparation title must not reflow between launch stages.');
$assert(str_contains($runtime, 'width:108px;height:108px') && str_contains($runtime, '.mgw-phase-b-countdown[data-ready="1"]{font-size:25px;letter-spacing:.08em}'), 'Preparation, countdown and START must share one invariant circular surface geometry.');
$assert(str_contains($runtime, '.mgw-phase-b-launch-ring') && str_contains($runtime, '@keyframes mgwPhaseBSpin'), 'Preparation visual must use one lightweight ring owner.');
$assert(!str_contains($runtime, 'mgw-phase-b-launch-shape') && !str_contains($runtime, 'mgwPhaseBFloat'), 'Retired floating shape loader must not remain in the current Phase B surface.');
$assert(str_contains($runtime, "progress.dataset.visible = '0'") && str_contains($runtime, 'visibility:hidden'), 'Progress stage changes must preserve its reserved grid row without layout shift.');
$assert(
    str_contains($runtime, 'const accepted = applyReadonlyGameProjection(game, result.me || null);')
        && str_contains($runtime, 'paintLaunchState(canonical);'),
    'Read-only watch must never advance launch presentation beyond the canonical accepted game projection.'
);

$assert(str_contains($game, 'pollBusy:false'), 'Current game owner must track one primary poll in flight.');
$assert(
    str_contains($game, 'if (gameScreenRuntime.pollBusy || gameScreenRuntime.actionBusy) return;'),
    'Current game owner must reject overlapping primary polls/actions.'
);
$assert(str_contains($game, "game.finish_reason === 'preparation_timeout'"), 'Current result owner must distinguish a match that never started.');
$assert(str_contains($game, "title = 'Матч не начался';"), 'Preparation timeout must not render as a draw.');

foreach ([
    'app/.htaccess',
    'app/index.html',
    'app/v114.php',
    'app/assets/js/phase-b-current-entry.js',
    'app/assets/js/phase-b-current-runtime.js',
    'app/assets/js/main.js',
    'app/assets/js/screens/game-screen-phase-b-current.js',
    'app/assets/js/games/game-invites.js',
    'app/assets/js/screens/notifications-screen-v99.js',
    'bot/services/TelegramService.php',
    'bot/webhook.php',
    'bot/helpers/StagingMenuButtonReconciler.php',
] as $path) {
    $assert(str_contains($fingerprint, $path), 'Exact staging fingerprint must cover active path: ' . $path);
}

fwrite(STDOUT, "PhaseBCurrentTelegramEntrypointContractTest: {$assertions} assertions passed\n");