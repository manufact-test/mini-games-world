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
        "Active-game action {$action} must remain allowed"
    );
}

$maintenance = [
    'feature_flags' => [
        'maintenance_mode' => true,
        'maintenance_message' => 'Плановые работы',
    ],
];
$assertSame(
    'Плановые работы',
    RuntimeRequestGuard::blockReason($maintenance, 'api.php', [
        'action' => 'start_search',
        'gameType' => 'tictactoe',
    ]),
    'Maintenance must block new matchmaking'
);
foreach (['game_state', 'game_action', 'make_move', 'leave_game'] as $action) {
    $assertSame(
        null,
        RuntimeRequestGuard::blockReason($maintenance, 'api.php', ['action' => $action]),
        "Maintenance must not block active-game action {$action}"
    );
}

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
    'Financial read-only must not block settlement-producing active gameplay'
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
        "Cleanup/read invitation action {$action} must remain allowed"
    );
}

$assertSame(
    null,
    RuntimeRequestGuard::blockReason($maintenance, 'webhook.php', ['action' => 'start_search']),
    'Unrelated endpoints must not be intercepted'
);

fwrite(STDOUT, "RuntimeRequestGuardTest: {$assertions} assertions passed\n");
