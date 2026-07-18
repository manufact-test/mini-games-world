<?php
declare(strict_types=1);
require __DIR__ . '/core/bootstrap.php';
require_once __DIR__ . '/helpers/RuntimeAdminGuard.php';
require_once __DIR__ . '/helpers/AdminPaymentRejectGuard.php';
require_once __DIR__ . '/helpers/AdminGoldTopupNotificationGuard.php';
require_once __DIR__ . '/helpers/AdminSystemCheckGuard.php';
require_once __DIR__ . '/helpers/UserWelcomeGuard.php';
require_once __DIR__ . '/helpers/TelegramWebhookDiagnostic.php';

if (strtolower(trim((string)($config['environment'] ?? 'production'))) !== 'staging') {
    http_response_code(404);
    exit;
}

$update = [];
$diagnostic = new TelegramWebhookDiagnostic($config, is_string($configFile ?? null) ? $configFile : null);

try {
    $update = json_decode(file_get_contents('php://input') ?: '{}', true);
    if (!is_array($update)) {
        http_response_code(200);
        exit('ok');
    }

    $diagnostic->recordReceived($update);
    $telegram = new TelegramService($config);
    $runtimeGuard = new RuntimeAdminGuard($telegram, $config);
    $paymentRejectGuard = new AdminPaymentRejectGuard($telegram, $config);
    $goldTopupGuard = new AdminGoldTopupNotificationGuard($telegram, $config);
    $auditGuard = new AdminSystemCheckGuard($telegram, $config);
    $welcomeGuard = new UserWelcomeGuard($telegram, $config);

    $handledBy = 'webhook_handler';
    if ($runtimeGuard->handle($update)) {
        $handledBy = 'runtime_admin_guard';
    } elseif ($paymentRejectGuard->handle($update)) {
        $handledBy = 'admin_payment_reject_guard';
    } elseif ($goldTopupGuard->handle($update)) {
        $handledBy = 'admin_gold_topup_notification_guard';
    } elseif ($auditGuard->handle($update)) {
        $handledBy = 'admin_system_check_guard';
    } elseif ($welcomeGuard->handle($update)) {
        $handledBy = 'user_welcome_guard';
    } else {
        (new WebhookHandler($telegram, $config))->handle($update);
    }

    $diagnostic->recordHandled($update, $handledBy);
    http_response_code(200);
    echo 'ok';
} catch (Throwable $error) {
    $diagnostic->recordError(is_array($update) ? $update : [], $error);
    error_log('[MiniGamesWorld diagnostic webhook] ' . $error->getMessage());
    http_response_code(200);
    echo 'ok';
}
