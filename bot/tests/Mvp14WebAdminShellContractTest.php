<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);

$endpoint = file_get_contents($root . '/bot/admin-read.php');
$auth = file_get_contents($root . '/bot/services/AuthService.php');
$storageFactory = file_get_contents($root . '/bot/storage/StorageFactory.php');
$entrypoints = file_get_contents($root . '/bot/runtime/ProductionPrimaryApplicationEntrypoints.php');
$launchUrl = file_get_contents($root . '/bot/helpers/WebAppLaunchUrl.php');
$telegram = file_get_contents($root . '/bot/services/TelegramService.php');
$readiness = file_get_contents($root . '/bot/services/StagingReadinessService.php');
$runtimeManifest = file_get_contents($root . '/bot/helpers/staging-e2e-runtime-files.txt');
$page = file_get_contents($root . '/app/admin.php');
$client = file_get_contents($root . '/app/assets/js/admin-shell.js');

foreach ([
    'admin endpoint' => $endpoint,
    'auth service' => $auth,
    'storage factory' => $storageFactory,
    'production entrypoints' => $entrypoints,
    'launch URL' => $launchUrl,
    'telegram service' => $telegram,
    'staging readiness' => $readiness,
    'runtime fingerprint manifest' => $runtimeManifest,
    'admin page' => $page,
    'admin client' => $client,
] as $label => $source) {
    if (!is_string($source) || $source === '') {
        throw new RuntimeException('MVP-14.10 source unavailable: ' . $label);
    }
}

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

$assert(
    str_contains($auth, 'public function getTelegramUserFromInitData')
        && str_contains($auth, '$this->validateTelegramInitData($initData)')
        && !str_contains($endpoint, 'StagingTestAuthService'),
    'Web admin must reuse the canonical signed Telegram initData validator without staging/dev auth fallbacks.'
);

$assert(
    str_contains($endpoint, 'mgw_admin_init_data_is_fresh')
        && str_contains($endpoint, '$maxAgeSec = 15 * 60')
        && str_contains($endpoint, 'getTelegramUserFromInitData($initData, false)')
        && str_contains($endpoint, '$admin->isAdmin'),
    'Web admin must require fresh signed Telegram auth and the existing admin_ids authorization owner.'
);

$assert(
    str_contains($endpoint, '$storage->readOnly')
        && !str_contains($endpoint, '$storage->transaction')
        && !str_contains($endpoint, 'api_ok('),
    'The first web-admin surface must stay read-only and must not trigger API success-hook projections.'
);

$assert(
    str_contains($endpoint, 'mgw_admin_read_only_text')
        && str_contains($endpoint, '"\\nКоманды:\\n"')
        && str_contains($endpoint, '"\\nКоманда:\\n"'),
    'Web admin must strip legacy Telegram mutation-command help from the read-only browser output.'
);

$assert(
    str_contains($storageFactory, "'api.php', 'admin-read.php' => 'api'")
        && str_contains($entrypoints, 'if ($relative === \'bot/admin-read.php\')')
        && str_contains($entrypoints, "return 'api';"),
    'Staging and production admin reads must reuse the existing API DB-primary storage context.'
);

$assert(
    str_contains($launchUrl, "private const ADMIN_PATH = '/app/admin.php?v=1'")
        && str_contains($launchUrl, 'public static function admin(array $config): string'),
    'The existing WebApp launch URL owner must publish the admin shell URL.'
);

$assert(
    str_contains($telegram, "'text' => '🌐 Web Admin'")
        && str_contains($telegram, 'WebAppLaunchUrl::admin($this->config)')
        && str_contains($telegram, '$mainAdminCallbacks[\'admin:dashboard\']')
        && str_contains($telegram, '$mainAdminCallbacks[\'admin:orders\']')
        && str_contains($telegram, '$mainAdminCallbacks[\'admin:support\']')
        && str_contains($telegram, '$mainAdminCallbacks[\'admin:users\']'),
    'Only the existing full Telegram admin keyboard should gain the Web Admin launch button.'
);

$assert(
    str_contains($page, 'telegram-web-app.js')
        && str_contains($page, 'Content-Security-Policy')
        && str_contains($page, 'noindex,nofollow,noarchive')
        && str_contains($page, 'data-admin-api="../bot/admin-read.php"'),
    'Admin shell must be non-indexed, CSP-protected and wired only to the read-only endpoint.'
);

$assert(
    str_contains($client, 'telegram.initData')
        && str_contains($client, "refresh.addEventListener('click', load)")
        && !str_contains($client, 'setInterval(')
        && !str_contains($client, 'localStorage')
        && !str_contains($client, 'sessionStorage')
        && !str_contains($client, 'document.cookie'),
    'Admin client must use current Telegram initData, manual refresh and no persistent browser session owner.'
);

$assert(
    !preg_match('/payment_apply|gold_add|order_done|delete_user|runtime_switch/i', $endpoint),
    'MVP-14.10 web endpoint must not expose financial, destructive or runtime mutation actions.'
);

$runtimePaths = [
    'app/admin.php',
    'app/assets/css/admin-shell.css',
    'app/assets/js/admin-shell.js',
    'bot/admin-read.php',
    'bot/helpers/WebAppLaunchUrl.php',
    'bot/runtime/ProductionPrimaryApplicationEntrypoints.php',
    'bot/services/AuthService.php',
    'bot/services/StagingReadinessService.php',
    'bot/services/TelegramService.php',
    'bot/storage/StorageFactory.php',
];
foreach ($runtimePaths as $relativePath) {
    $assert(
        preg_match('/^' . preg_quote($relativePath, '/') . '$/m', $runtimeManifest) === 1,
        'Exact Hostinger staging fingerprint must cover MVP-14.10 runtime file: ' . $relativePath
    );
}

$assert(
    str_contains($readiness, "'app/admin.php'")
        && str_contains($readiness, "'bot/admin-read.php'")
        && str_contains($readiness, "'bot/storage/StorageFactory.php'"),
    'The staging readiness source fingerprint must also include the admin shell and its storage owner.'
);

fwrite(STDOUT, "Mvp14WebAdminShellContractTest: {$assertions} assertions passed\n");
