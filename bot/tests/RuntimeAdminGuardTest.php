<?php
declare(strict_types=1);

require dirname(__DIR__) . '/helpers/RuntimeAdminGuard.php';

$assertions = 0;
$assertSame = static function (mixed $expected, mixed $actual, string $message) use (&$assertions): void {
    $assertions++;
    if ($expected !== $actual) {
        throw new RuntimeException(
            $message . ': expected ' . var_export($expected, true) . ', got ' . var_export($actual, true)
        );
    }
};

$config = [];
foreach ([
    '/mgw_private_admin_7291_order_done ABC123',
    '/mgw_private_admin_7291_order_reject ABC123 причина',
    '/mgw_private_admin_7291_payment_apply PAY123',
    '/mgw_private_admin_7291_payment_reject PAY123 причина',
    '/mgw_private_admin_7291_gold_add @player 100 тест',
] as $command) {
    $assertSame(
        true,
        RuntimeAdminGuard::isFinancialMutationForConfig($config, $command, ''),
        "Financial command must be guarded: {$command}"
    );
}

foreach ([
    'admin:payment_apply:PAY123',
    'admin:payment_reject_prompt:PAY123',
    'admin:order_done:ABC123',
    'admin:order_reject:ABC123',
    'admin:gold_add:PLAYER123',
] as $callback) {
    $assertSame(
        true,
        RuntimeAdminGuard::isFinancialMutationForConfig($config, '', $callback),
        "Financial callback must be guarded: {$callback}"
    );
}

foreach ([
    '/mgw_private_admin_7291',
    '/mgw_private_admin_7291_check',
    '/mgw_private_admin_7291_users',
    '/start',
] as $command) {
    $assertSame(
        false,
        RuntimeAdminGuard::isFinancialMutationForConfig($config, $command, ''),
        "Read-only admin command must remain available: {$command}"
    );
}

foreach ([
    'admin:dashboard',
    'admin:system_check',
    'admin:users',
    'admin:payments',
] as $callback) {
    $assertSame(
        false,
        RuntimeAdminGuard::isFinancialMutationForConfig($config, '', $callback),
        "Read-only admin callback must remain available: {$callback}"
    );
}

$source = file_get_contents(dirname(__DIR__) . '/helpers/RuntimeAdminGuard.php') ?: '';
$assertSame(
    true,
    str_contains($source, 'hasPendingFinancialAction'),
    'Pending rejection text must be intercepted in financial read-only mode'
);
$assertSame(
    true,
    str_contains($source, 'clearPendingFinancialAction'),
    'Pending financial action must be cleared safely'
);

fwrite(STDOUT, "RuntimeAdminGuardTest: {$assertions} assertions passed\n");
