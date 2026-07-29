<?php
declare(strict_types=1);

require dirname(__DIR__) . '/services/FeatureFlagService.php';
require dirname(__DIR__) . '/helpers/MaintenanceWebhookGuard.php';

$assertions = 0;
$assertSame = static function (mixed $expected, mixed $actual, string $message) use (&$assertions): void {
    $assertions++;
    if ($expected !== $actual) {
        throw new RuntimeException(
            $message . ': expected ' . var_export($expected, true) . ', got ' . var_export($actual, true)
        );
    }
};
$assertContains = static function (string $needle, string $actual, string $message) use (&$assertions): void {
    $assertions++;
    if (!str_contains($actual, $needle)) {
        throw new RuntimeException($message . ': missing ' . var_export($needle, true));
    }
};

$normal = [
    'feature_flags' => [
        'maintenance_mode' => false,
        'financial_read_only' => false,
    ],
];
$assertSame(
    null,
    MaintenanceWebhookGuard::response($normal, [
        'message' => ['chat' => ['id' => 1001], 'text' => '/start'],
    ]),
    'Normal runtime must not intercept Telegram updates'
);

$maintenance = [
    'feature_flags' => [
        'maintenance_mode' => true,
        'financial_read_only' => true,
        'maintenance_message' => 'Плановые работы. Возвращаемся скоро.',
        'database_runtime' => [
            'enabled' => true,
            'production_activated' => true,
            'rollback_driver' => 'json',
            'modules' => array_fill_keys([
                'accounts', 'realtime', 'invites', 'notifications', 'economy',
                'history', 'shop', 'payments', 'weekly_bonus',
            ], true),
        ],
    ],
];
$before = serialize($maintenance);
$start = MaintenanceWebhookGuard::response($maintenance, [
    'message' => [
        'chat' => ['id' => 1001, 'type' => 'private'],
        'from' => ['id' => 1001],
        'text' => '/start',
    ],
]);
$assertSame(true, $start['blocked'] ?? null, 'Maintenance must intercept /start');
$assertSame(1001, $start['chat_id'] ?? null, 'Maintenance /start must retain chat identity');
$assertSame('', $start['callback_query_id'] ?? null, 'Message update must not invent callback identity');
$assertContains('Плановые работы', (string)($start['message'] ?? ''), 'Maintenance /start must use configured copy');

$inviteStart = MaintenanceWebhookGuard::response($maintenance, [
    'message' => [
        'chat' => ['id' => 1002, 'type' => 'private'],
        'from' => ['id' => 1002],
        'text' => '/start invite_0123456789abcdef01234567',
    ],
]);
$assertSame(true, $inviteStart['blocked'] ?? null, 'Maintenance must intercept invite start before binding');
$assertSame(1002, $inviteStart['chat_id'] ?? null, 'Invite start must receive maintenance in the same chat');

$callback = MaintenanceWebhookGuard::response($maintenance, [
    'callback_query' => [
        'id' => 'callback-1',
        'from' => ['id' => 1003],
        'data' => 'admin:payment_apply:payment-1',
        'message' => ['chat' => ['id' => 1003]],
    ],
]);
$assertSame(true, $callback['blocked'] ?? null, 'Maintenance must intercept callback mutations');
$assertSame('callback-1', $callback['callback_query_id'] ?? null, 'Callback identity must be preserved');
$assertSame(1003, $callback['chat_id'] ?? null, 'Callback maintenance response must retain chat identity');

$unknown = MaintenanceWebhookGuard::response($maintenance, ['update_id' => 99]);
$assertSame(true, $unknown['blocked'] ?? null, 'Maintenance must fail closed for unsupported update shapes');
$assertSame(null, $unknown['chat_id'] ?? null, 'Unsupported update must not invent a chat');
$assertSame($before, serialize($maintenance), 'Webhook maintenance guard must not alter DB route or module flags');

$guardSource = file_get_contents(dirname(__DIR__) . '/helpers/MaintenanceWebhookGuard.php');
$assertSame(true, is_string($guardSource), 'Maintenance guard source must be readable');
$assertSame(false, str_contains((string)$guardSource, 'StorageFactory'), 'Maintenance Telegram response must not open application storage');
$assertSame(false, str_contains((string)$guardSource, 'transaction('), 'Maintenance Telegram response must not start a transaction');

fwrite(STDOUT, "MaintenanceWebhookGuardTest: {$assertions} assertions passed\n");
