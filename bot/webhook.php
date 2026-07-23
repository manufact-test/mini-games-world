<?php
declare(strict_types=1);
require __DIR__ . '/core/bootstrap.php';
require_once __DIR__ . '/helpers/RuntimeAdminGuard.php';
require_once __DIR__ . '/helpers/AdminPaymentRejectGuard.php';
require_once __DIR__ . '/helpers/AdminGoldTopupNotificationGuard.php';
require_once __DIR__ . '/helpers/AdminSystemCheckGuard.php';
require_once __DIR__ . '/helpers/UserWelcomeGuard.php';

try {
    $update = json_decode(file_get_contents('php://input') ?: '{}', true);
    if (!is_array($update)) {
        http_response_code(200);
        exit('ok');
    }

    // Install the guarded request storage before any guard or handler. This
    // prevents a storage-free webhook path from reaching a legacy JSON bridge
    // after an exact production DB-primary activation.
    $requestStorage = StorageFactory::createJson((string)($config['data_dir'] ?? ''));

    $telegram = new TelegramService($config);
    $runtimeGuard = new RuntimeAdminGuard($telegram, $config);
    $guard = new AdminPaymentRejectGuard($telegram, $config);
    $goldTopupGuard = new AdminGoldTopupNotificationGuard($telegram, $config);
    $auditGuard = new AdminSystemCheckGuard($telegram, $config);
    $welcomeGuard = new UserWelcomeGuard($telegram, $config);
    if (!$runtimeGuard->handle($update)
        && !$guard->handle($update)
        && !$goldTopupGuard->handle($update)
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
