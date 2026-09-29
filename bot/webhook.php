<?php
declare(strict_types=1);

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');

require __DIR__ . '/core/bootstrap.php';
require_once __DIR__ . '/helpers/TelegramWebhookSecurity.php';
require_once __DIR__ . '/helpers/RuntimeAdminGuard.php';
require_once __DIR__ . '/helpers/AdminSystemCheckGuard.php';
require_once __DIR__ . '/helpers/UserWelcomeGuard.php';
require_once __DIR__ . '/helpers/MaintenanceWebhookGuard.php';
require_once __DIR__ . '/helpers/StagingMenuButtonReconciler.php';

if (!TelegramWebhookSecurity::incomingAuthorized($config, $_SERVER)) {
    http_response_code(403);
    exit('forbidden');
}

try {
    $update = json_decode(file_get_contents('php://input') ?: '{}', true);
    if (!is_array($update)) {
        http_response_code(200);
        exit('ok');
    }

    $telegram = new TelegramService($config);
    try {
        (new StagingMenuButtonReconciler($telegram, $config))->reconcile();
    } catch (Throwable $menuButtonError) {
        error_log('[MiniGamesWorld staging menu button] ' . $menuButtonError->getMessage());
    }

    $maintenanceGuard = new MaintenanceWebhookGuard($telegram, $config);
    if ($maintenanceGuard->handle($update)) {
        unset($GLOBALS['mgw_webhook_success_hook']);
        http_response_code(200);
        exit('ok');
    }

    $requestStorage = StorageFactory::createJson((string)($config['data_dir'] ?? ''));

    $runtimeGuard = new RuntimeAdminGuard($telegram, $config);
    $auditGuard = new AdminSystemCheckGuard($telegram, $config);

    $welcomeGuard = new UserWelcomeGuard($telegram, $config);
    if (!$runtimeGuard->handle($update)
        && !$auditGuard->handle($update)
        && !$welcomeGuard->handle($update)) {
        $handler = new WebhookHandler($telegram, $config);
        $handler->handle($update);
    }

    $successHook = $GLOBALS['mgw_webhook_success_hook'] ?? null;
    unset($GLOBALS['mgw_webhook_success_hook']);
    if (is_callable($successHook)) $successHook();

    http_response_code(200);
    echo 'ok';
} catch (Throwable $e) {
    error_log('[MiniGamesWorld webhook] ' . $e->getMessage());

    $runtimeSettings = is_array($config['feature_flags']['database_runtime'] ?? null)
        ? $config['feature_flags']['database_runtime']
        : [];
    $productionDbPrimaryRequested = ($config['environment'] ?? null) === 'production'
        && ($runtimeSettings['enabled'] ?? null) === true
        && ($runtimeSettings['production_activated'] ?? null) === true;

    http_response_code($productionDbPrimaryRequested ? 503 : 200);
    echo $productionDbPrimaryRequested ? 'temporary failure' : 'ok';
}
