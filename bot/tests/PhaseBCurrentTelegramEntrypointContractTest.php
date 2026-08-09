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
    str_contains($v110, '"./assets/js/production-v110-acceptance-runtime.js?v=110": "./assets/js/production-v110-acceptance-runtime.js?v=122&b=e248a150239e"'),
    'The accepted v110 graph must publish the migrated Phase B presentation under a fresh immutable URL.'
);
$assert(
    str_contains($v110, 'X-MGW-Phase-B-Presentation: v122-v110-fixed-loader'),
    'The accepted /start graph must expose an explicit migrated loader identity.'
);
$assert(
    str_contains($v110Runtime, 'position:fixed;inset:0;z-index:10000')
        && str_contains($v110Runtime, 'width:min(100%,400px);height:336px;display:grid;grid-template-rows:30px 136px 52px 46px 24px'),
    'The accepted v110 graph must use the fixed global V121 preparation geometry.'
);
$assert(
    str_contains($v110Runtime, 'width:108px;height:108px')
        && str_contains($v110Runtime, '.mgw-phase-b-launch-ring')
        && str_contains($v110Runtime, '@keyframes mgwPhaseBSpin'),
    'The accepted v110 graph must use one invariant 108x108 ring/countdown presentation.'
);
$assert(
    str_contains($v110Runtime, "countdown.textContent = 'VS';")
        && str_contains($v110Runtime, "readyForServer ? 'СТАРТ' : String(seconds)"),
    'The accepted v110 preparation surface must own VS -> 3 -> 2 -> 1 -> START without changing application graph.'
);
$assert(
    str_contains($v110Runtime, "const blocking = status === 'active'")
        && str_contains($v110Runtime, "phase === 'preparing' || phase === 'countdown' || phase === 'preparation_timeout'"),
    'The migrated loader must remain above the accepted app through the complete authoritative countdown phase.'
);
$assert(
    !str_contains($v110Runtime, 'mgw-phase-b-launch-shape')
        && !str_contains($v110Runtime, 'mgwPhaseBFloat'),
    'The old floating-shape loader must be removed from the accepted v110 application graph.'
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
