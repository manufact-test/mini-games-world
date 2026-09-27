<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$read = static function (string $path) use ($root): string {
    $content = file_get_contents($root . '/' . $path);
    if (!is_string($content)) throw new RuntimeException('Cannot read MVP-24.3a source: ' . $path);
    return $content;
};
$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

$webhook = $read('bot/webhook.php');
$handler = $read('bot/handlers/WebhookHandler.php');
$admin = $read('bot/services/AdminService.php');
$payments = $read('bot/services/PaymentService.php');
$policy = $read('bot/runtime/UnifiedGameZonePolicy.php');

$assert(
    !str_contains($webhook, 'AdminPaymentRejectGuard')
        && !str_contains($webhook, 'AdminGoldTopupNotificationGuard'),
    'Telegram webhook must not wire legacy manual-payment or Gold-topup guards.'
);

foreach ([
    'handlePendingAdminPaymentReject',
    'setPendingPaymentReject',
    'cancelPendingPaymentReject',
    'processPaymentDecision',
    'clearPendingPaymentRejectForSamePayment',
    'sendPaymentDecisionNotification',
    'paymentActionKeyboard',
    'pendingRejectKeyboard',
] as $removedOwner) {
    $assert(
        !str_contains($handler, 'function ' . $removedOwner . '(')
            && !str_contains($handler, '$this->' . $removedOwner . '('),
        'Legacy manual-payment handler owner must be removed: ' . $removedOwner
    );
}

$assert(
    str_contains($handler, 'isLegacyCommerceMutationCommand')
        && str_contains($handler, 'isLegacyCommerceMutationCallback')
        && str_contains($handler, 'UnifiedGameZonePolicy::legacyArchiveMessage()'),
    'Old Telegram mutation inputs must terminate at the archive-only compatibility boundary.'
);

$assert(
    !str_contains($handler, '$admin->completeOrder(')
        && !str_contains($handler, '$admin->rejectOrder(')
        && !str_contains($handler, '$admin->applyPayment(')
        && !str_contains($handler, '$admin->rejectPayment(')
        && !str_contains($handler, '$admin->addGoldToUser('),
    'WebhookHandler must not execute legacy Gold/order/payment write services.'
);

$assert(
    !str_contains($handler, "['text' => '✅ Начислить'")
        && !str_contains($handler, "['text' => '🚫 Отклонить'")
        && str_contains($handler, "str_starts_with(\$action, 'order_open:')")
        && str_contains($handler, "str_starts_with(\$action, 'payment_open:')"),
    'Admin callback compatibility must keep archive reads without write buttons.'
);

$assert(
    str_contains($admin, '🗂 Архив заявок')
        && str_contains($admin, 'Только просмотр')
        && !str_contains($admin, 'отметить выполненной')
        && !str_contains($admin, 'отклонить и вернуть коины')
        && !str_contains($admin, 'Действия недоступны: заявка уже обработана.'),
    'Legacy prize-order Admin copy must be archive-only for every historical status.'
);

foreach (['completeOrder','rejectOrder','applyPayment','rejectPayment','addGoldToUser'] as $compatMethod) {
    $needle = 'public function ' . $compatMethod;
    $position = strpos($admin, $needle);
    $assert($position !== false, 'Archive compatibility method must remain callable: ' . $compatMethod);
    $slice = substr($admin, (int)$position, 260);
    $assert(
        str_contains($slice, 'UnifiedGameZonePolicy::legacyArchiveMessage()'),
        'Archive compatibility method must be no-op/read-only: ' . $compatMethod
    );
}

$assert(
    str_contains($payments, '🗂 Архив платежей')
        && str_contains($payments, 'Только просмотр')
        && !str_contains($payments, '/mgw_private_admin_7291_payment_apply')
        && !str_contains($payments, '/mgw_private_admin_7291_payment_reject')
        && !str_contains($payments, 'Начисление делает админ вручную'),
    'Legacy manual-payment Admin presentation must not advertise writes.'
);

$assert(
    str_contains($payments, "'mode' => 'archive_read_only'")
        && str_contains($policy, 'Legacy Match/Gold операции доступны только для просмотра'),
    'Legacy payment data must remain available through an explicit read-only compatibility policy.'
);

foreach ([
    'bot/ledger/LegacyFinancialArchiveImportService.php',
    'bot/ledger/LegacyFinancialArchiveDeltaService.php',
    'ops/ledger/LEGACY_FINANCIAL_ARCHIVE.md',
] as $archivePath) {
    $assert(is_file($root . '/' . $archivePath), 'Historical financial archive owner must be preserved: ' . $archivePath);
}

$assert(
    is_file($root . '/bot/admin-economy.php')
        && is_file($root . '/bot/admin-test-coins.php')
        && is_file($root . '/app/assets/js/admin-shell.js'),
    'Modern Web Admin economy/test tooling must remain outside legacy commerce removal.'
);

fwrite(STDOUT, "Mvp24LegacyAdminCommerceReadOnlyContractTest: {$assertions} assertions passed\n");
