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
$fingerprint = $read('bot/helpers/staging-e2e-runtime-files.txt');

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

$assert(str_contains($htaccess, 'DirectoryIndex v114.php index.html'), 'Default /app/ must resolve through v114.php.');
$assert(str_contains($telegram, "/app/?v=85"), 'Telegram /start button must use the default /app/ route.');

$assert(
    str_contains($v114, './assets/js/phase-b-current-entry.js?v=118&b=10290ac21228'),
    'v114 must publish the current Phase B entry under a fresh immutable URL.'
);
$assert(
    str_contains($v114, '"./assets/js/screens/game-screen.js?v=74": "./assets/js/screens/game-screen-phase-b-current.js?v=116&b=f6d062608b0c"'),
    'v114 import map must route every current D1 game-screen import to the Phase B-aware current owner.'
);
$assert(
    str_contains($v114, 'X-MGW-Phase-B-Build: phase-b-current-v118'),
    'v114 must expose an explicit current Phase B build identity.'
);
$assert(
    str_contains($entry, "import './production-regression-fix-entry.js?v=102';")
        && str_contains($entry, "./phase-b-current-runtime.js?v=118&b=cbffb6339231")
        && str_contains($entry, "window.__MGW_PHASE_B_BUILD__ = 'phase-b-current-v118';")
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
$assert(str_contains($runtime, 'width:min(100%,430px)'), 'Preparation background composition must use one centered phone-like canvas across viewport aspect ratios.');
$assert(str_contains($runtime, 'height:380px;display:grid;grid-template-rows:30px 150px 54px 48px 28px'), 'Preparation card must keep one fixed geometry across all launch text stages.');
$assert(str_contains($runtime, 'grid-row:4') && str_contains($runtime, 'height:48px'), 'Preparation note must keep a reserved fixed-height text slot.');
$assert(str_contains($runtime, 'background:rgba(5,7,12,.9)') && str_contains($runtime, 'width:88px;height:88px'), 'Countdown text must have a dedicated dark contrast surface above the bright game markers.');

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
] as $path) {
    $assert(str_contains($fingerprint, $path), 'Exact staging fingerprint must cover active path: ' . $path);
}

fwrite(STDOUT, "PhaseBCurrentTelegramEntrypointContractTest: {$assertions} assertions passed\n");