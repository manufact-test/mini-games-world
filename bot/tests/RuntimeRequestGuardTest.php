<?php
declare(strict_types=1);

require dirname(__DIR__) . '/services/FeatureFlagService.php';
require dirname(__DIR__) . '/core/RuntimeRequestGuard.php';

$assertions = 0;
$assertSame = static function (mixed $expected, mixed $actual, string $message) use (&$assertions): void {
    $assertions++;
    if ($expected !== $actual) {
        throw new RuntimeException(
            $message . ': expected ' . var_export($expected, true) . ', got ' . var_export($actual, true)
        );
    }
};
$assertContains = static function (string $needle, ?string $actual, string $message) use (&$assertions): void {
    $assertions++;
    if ($actual === null || !str_contains($actual, $needle)) {
        throw new RuntimeException($message . ': expected text containing ' . var_export($needle, true));
    }
};

$dominoOff = [
    'feature_flags' => [
        'games' => ['domino' => false],
    ],
];
$assertContains(
    'игра',
    RuntimeRequestGuard::blockReason($dominoOff, 'api.php', [
        'action' => 'start_search',
        'gameType' => 'domino',
    ]),
    'Disabled Domino must block new matchmaking'
);
$assertSame(
    null,
    RuntimeRequestGuard::blockReason($dominoOff, 'api.php', [
        'action' => 'start_search',
        'gameType' => 'chess',
    ]),
    'Disabled Domino must not block Chess matchmaking'
);
foreach (['game_state', 'game_action', 'make_move', 'leave_game'] as $action) {
    $assertSame(
        null,
        RuntimeRequestGuard::blockReason($dominoOff, 'api.php', [
            'action' => $action,
            'gameType' => 'domino',
        ]),
        "Ordinary runtime action {$action} must preserve the existing behavior"
    );
}

$maintenance = [
    'feature_flags' => [
        'maintenance_mode' => true,
        'maintenance_message' => 'Плановые работы',
        'financial_read_only' => true,
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
$maintenanceBefore = serialize($maintenance);
foreach ([
    'api.php',
    'invites.php',
    'notifications.php',
    'invite-opponents.php',
    'game-clock.php',
    'game-live-v108.php',
    'search-speed.php',
    'shop-history.php',
] as $script) {
    $assertSame(
        'Плановые работы',
        RuntimeRequestGuard::blockReason($maintenance, $script, []),
        "Maintenance must block {$script} before any action or storage mutation"
    );
}
foreach (['bootstrap', 'stats', 'game_state', 'game_action', 'make_move', 'leave_game', 'support'] as $action) {
    $assertSame(
        'Плановые работы',
        RuntimeRequestGuard::blockReason($maintenance, 'api.php', ['action' => $action]),
        "Maintenance must block API action {$action}"
    );
}
foreach (['sync', 'seen', 'decline', 'cancel', 'discard_draft', 'accept', 'start', 'rematch'] as $action) {
    $assertSame(
        'Плановые работы',
        RuntimeRequestGuard::blockReason($maintenance, 'invites.php', ['action' => $action]),
        "Maintenance must block invitation action {$action}"
    );
}
$assertSame($maintenanceBefore, serialize($maintenance), 'Maintenance guard must not alter DB runtime configuration');

$readOnly = [
    'feature_flags' => [
        'financial_read_only' => true,
    ],
];
$assertContains(
    'только для чтения',
    RuntimeRequestGuard::blockReason($readOnly, 'api.php', ['action' => 'payment_create_draft']),
    'Financial read-only must block payment drafts'
);
$assertContains(
    'только для чтения',
    RuntimeRequestGuard::blockReason($readOnly, 'api.php', ['action' => 'shop_order']),
    'Financial read-only must block shop orders'
);
$assertSame(
    null,
    RuntimeRequestGuard::blockReason($readOnly, 'api.php', ['action' => 'game_action']),
    'Financial read-only alone must preserve active gameplay settlement behavior'
);

$invitesOff = [
    'feature_flags' => [
        'features' => ['invitations' => false],
    ],
];
foreach (['create_link_draft', 'confirm_shared', 'create_direct', 'accept', 'start', 'rematch'] as $action) {
    $assertContains(
        'Приглашения',
        RuntimeRequestGuard::blockReason($invitesOff, 'invites.php', [
            'action' => $action,
            'gameType' => 'chess',
        ]),
        "Invitation action {$action} must be blocked"
    );
}
foreach (['sync', 'seen', 'decline', 'cancel', 'discard_draft'] as $action) {
    $assertSame(
        null,
        RuntimeRequestGuard::blockReason($invitesOff, 'invites.php', ['action' => $action]),
        "Cleanup/read invitation action {$action} must remain allowed outside maintenance"
    );
}

$assertSame(
    null,
    RuntimeRequestGuard::blockReason($maintenance, 'webhook.php', ['action' => 'start_search']),
    'Webhook must use its Telegram-aware maintenance guard'
);
$assertSame(
    null,
    RuntimeRequestGuard::blockReason($maintenance, 'weekly-match.php', ['action' => 'run']),
    'Cron must remain outside the user-request guard'
);

fwrite(STDOUT, "RuntimeRequestGuardTest: {$assertions} assertions passed\n");
