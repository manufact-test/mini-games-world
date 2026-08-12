<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$read = static function (string $path) use ($root): string {
    $value = file_get_contents($root . '/' . $path);
    if (!is_string($value)) throw new RuntimeException('Missing source: ' . $path);
    return $value;
};

$launch = $read('bot/helpers/WebAppLaunchUrl.php');
$welcome = $read('bot/helpers/UserWelcomeGuard.php');
$inviteStart = $read('bot/helpers/InviteStartGuard.php');
$telegram = $read('bot/services/TelegramService.php');
$handler = $read('bot/handlers/WebhookHandler.php');
$webhook = $read('bot/webhook.php');
$menuButton = $read('bot/helpers/StagingMenuButtonReconciler.php');
$v110 = $read('app/v110.php');
$v110Runtime = $read('app/assets/js/production-v110-acceptance-runtime.js');
$gameStyle = $read('app/assets/css/screens/game.css');
$ticTacToeRenderer = $read('app/assets/js/games/tictactoe/renderer.js');
$v114 = $read('app/v114.php');
$currentRuntime = $read('app/assets/js/phase-b-current-runtime.js');
$fingerprint = $read('bot/helpers/staging-e2e-runtime-files.txt');

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

$assert(
    str_contains($launch, "private const ENTRY_PATH = '/app/v110.php?v=1123';"),
    'The accepted v110 graph must remain the canonical /start and invite Web App route.'
);
$assert(
    str_contains($welcome, '$baseWebAppUrl = WebAppLaunchUrl::base($this->config);')
        && str_contains($welcome, "'web_app' => ['url' => \$buttonWebAppUrl]"),
    'UserWelcomeGuard must own the real /start message button through the shared v110 URL builder.'
);
$assert(
    str_contains($welcome, "api('setChatMenuButton'")
        && str_contains($welcome, "'chat_id' => \$chatId")
        && str_contains($welcome, "'type' => 'commands'"),
    'Every private-chat welcome must deterministically replace the historical chat-specific Web App menu with commands.'
);
$assert(
    !str_contains($welcome, "'text' => 'Играть'")
        && !str_contains($welcome, "'web_app' => ['url' => \$baseWebAppUrl]"),
    'UserWelcomeGuard must never recreate the retired per-chat Web App menu button.'
);
$assert(
    str_contains($inviteStart, 'WebAppLaunchUrl::invitation($this->config, $token)')
        && !str_contains($inviteStart, '/app/?v=87'),
    'Invite /start deep links must use the same accepted v110 Web App URL builder and never the retired v87 graph.'
);
$assert(
    str_contains($telegram, "require_once __DIR__ . '/../helpers/WebAppLaunchUrl.php';")
        && str_contains($telegram, 'WebAppLaunchUrl::base($this->config)')
        && !str_contains($telegram, '/app/?v=121'),
    'WebhookHandler fallback /start responses must use the same accepted Web App URL builder and own no v121 URL.'
);
$assert(
    str_contains($handler, '$this->telegram->sendStartMessage($chatId);'),
    'The generic webhook fallback must remain routed through the covered TelegramService start method.'
);
$assert(
    str_contains($webhook, 'StagingMenuButtonReconciler') && str_contains($webhook, '->reconcile();'),
    'The staging webhook must still reconcile the global Telegram menu owner before handling /start.'
);
$assert(
    str_contains($menuButton, "'type' => 'commands'")
        && !str_contains($menuButton, "'type' => 'web_app'")
        && !str_contains($menuButton, "/app/"),
    'The global Telegram menu owner must be commands-only and own no Mini App URL.'
);

$assert(
    str_contains($v110, '"./assets/js/production-v110-acceptance-runtime.js?v=110": "./assets/js/production-v110-acceptance-runtime.js?v=124&b=ef4ea6257fb9"'),
    'The accepted v110 graph must publish the player-facing pregame runtime under a fresh immutable URL.'
);
$assert(
    str_contains($v110, './assets/css/main.css?v=142&sk=3&icons=c1efd5af&render=18&review=ttt-ui-polish'),
    'The accepted v110 graph must publish the right-aligned stable timer CSS under a fresh immutable URL.'
);
$assert(
    str_contains($v110, '"./assets/js/games/tictactoe/renderer.js?v=53": "./assets/js/games/tictactoe/renderer.js?v=54&mark=full-size-nought"'),
    'The accepted v110 graph must publish the full-size Tic Tac Toe nought under a fresh immutable URL.'
);
$assert(
    str_contains($v110, '<p>Те самые игры. То самое чувство.</p>')
        && str_contains($v110, 'X-MGW-App-Entry-Presentation: shield-king-v1141-nostalgic-entry-copy'),
    'The real Telegram app entry must replace technical preload copy with the accepted nostalgic line.'
);
$assert(
    str_contains($v110, 'X-MGW-Phase-B-Presentation: v124-v110-player-copy-stable-frame'),
    'The accepted /start graph must expose the player-copy and stable-frame presentation identity.'
);
$assert(
    str_contains($v110Runtime, 'position:fixed;inset:0;z-index:10000')
        && str_contains($v110Runtime, 'width:min(100%,400px);height:336px;display:grid;grid-template-rows:30px 136px 52px 46px 24px'),
    'The accepted v110 graph must keep the fixed preparation geometry.'
);
$assert(
    str_contains($v110Runtime, 'width:108px;height:108px')
        && str_contains($v110Runtime, '.mgw-phase-b-launch-ring')
        && str_contains($v110Runtime, '@keyframes mgwPhaseBSpin'),
    'The accepted v110 graph must keep one invariant 108x108 ring/countdown presentation.'
);
$assert(
    str_contains($v110Runtime, 'const LAUNCH_COUNTDOWN_STEP_MS = 600;')
        && str_contains($v110Runtime, 'const LAUNCH_READY_HOLD_MS = 450;')
        && str_contains($v110Runtime, "return { type:'number', value:'3' };")
        && str_contains($v110Runtime, "return { type:'number', value:'2' };")
        && str_contains($v110Runtime, "return { type:'number', value:'1' };")
        && str_contains($v110Runtime, "return { type:'ready' };")
        && str_contains($v110Runtime, "return { type:'sync' };")
        && !str_contains($v110Runtime, "countdown.textContent = 'VS';")
        && !str_contains($v110Runtime, "'СТАРТ'"),
    'The v110 loader must own one monotonic 3 -> 2 -> 1 -> ready presentation without VS or START text.'
);
$assert(
    str_contains($v110Runtime, '.mgw-phase-b-countdown[data-stage="prepare"]:before')
        && str_contains($v110Runtime, '.mgw-phase-b-countdown[data-stage="ready"]:before')
        && str_contains($v110Runtime, '@keyframes mgwPhaseBCheckIn')
        && str_contains($v110Runtime, "if (title) title.textContent = 'Всё готово';"),
    'Preparation must use a neutral animated mark and completion must use a visual success check.'
);
$assert(
    str_contains($v110Runtime, "if (title) title.textContent = 'Матч скоро начнётся';")
        && str_contains($v110Runtime, "if (note) note.textContent = 'Готовьтесь к игре';")
        && str_contains($v110Runtime, "if (note) note.textContent = 'Приготовьтесь к первому ходу';")
        && str_contains($v110Runtime, "if (note) note.textContent = 'Ещё мгновение';")
        && str_contains($v110Runtime, "if (note) note.textContent = 'Вперёд!';")
        && !str_contains($v110Runtime, 'Соединяем игроков')
        && !str_contains($v110Runtime, 'Начинаем одновременно')
        && !str_contains($v110Runtime, 'Синхронизируем игроков')
        && !str_contains($v110Runtime, 'Открываем игру'),
    'The real Telegram pregame surface must contain player-facing copy and no synchronization implementation text.'
);
$assert(
    str_contains($gameStyle, 'flex:0 0 80px;width:80px;min-width:80px')
        && str_contains($gameStyle, 'border-radius:13px;text-align:right'),
    'The shared game timer badge must keep one fixed 80px frame and anchor its label to the right edge.'
);
$assert(
    str_contains($ticTacToeRenderer, "if (player?.symbol === 'O') return '◯';"),
    'Tic Tac Toe player labels must use a full-size nought glyph that remains legible on mobile.'
);
$assert(
    str_contains($v110Runtime, "const presentationBlocking = status === 'active'")
        && str_contains($v110Runtime, '&& !presentation.complete')
        && str_contains($v110Runtime, 'const blocking = serverBlocking || presentationBlocking;'),
    'The loader presentation must finish even when the authoritative game becomes active first.'
);
$assert(
    !str_contains($v110Runtime, 'mgw-phase-b-launch-shape')
        && !str_contains($v110Runtime, 'mgwPhaseBFloat'),
    'The old floating-shape loader must remain removed from the accepted v110 application graph.'
);

$assert(
    str_contains($v114, "\$entryVersion = '121';")
        && str_contains($currentRuntime, '.mgw-phase-b-launch-ring'),
    'The v121 staging/reference graph may remain available, but it must not be a Telegram menu owner.'
);

foreach ([
    'app/v110.php',
    'app/assets/js/production-clean-entry-v110.js',
    'app/assets/js/production-v110-acceptance-runtime.js',
    'app/assets/css/screens/game.css',
    'app/assets/js/games/tictactoe/renderer.js',
    'bot/helpers/WebAppLaunchUrl.php',
    'bot/helpers/UserWelcomeGuard.php',
    'bot/helpers/InviteStartGuard.php',
    'bot/handlers/WebhookHandler.php',
    'bot/services/TelegramService.php',
    'bot/webhook.php',
    'bot/helpers/StagingMenuButtonReconciler.php',
    'app/v114.php',
    'app/assets/js/phase-b-current-runtime.js',
] as $path) {
    $assert(str_contains($fingerprint, $path), 'Exact staging fingerprint must cover active or retained route owner: ' . $path);
}

fwrite(STDOUT, "PhaseBCurrentTelegramEntrypointContractTest: {$assertions} assertions passed\n");
