<?php
declare(strict_types=1);

require dirname(__DIR__) . '/services/FeatureFlagService.php';

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

$defaults = new FeatureFlagService(['environment' => 'local']);
$assertSame(false, $defaults->maintenanceEnabled(), 'Maintenance must default to off');
$assertSame(false, $defaults->financialReadOnly(), 'Financial read-only must default to off');
$assertSame(true, $defaults->featureEnabled('matchmaking'), 'Matchmaking must preserve current behavior');
$assertSame(true, $defaults->featureEnabled('payments'), 'Payment drafts must preserve current behavior');
$assertSame(false, $defaults->featureEnabled('tournaments'), 'Unavailable future feature must default to off');
$assertSame(true, $defaults->gameEnabled('domino'), 'Released games must default to enabled');
$assertSame(null, $defaults->newMatchBlockReason('domino'), 'Default runtime must allow new games');
$assertSame(true, $defaults->activeGameActionsAllowed(), 'Active games must always remain playable');

$dominoDisabled = new FeatureFlagService([
    'environment' => 'staging',
    'feature_flags' => [
        'games' => ['domino' => false],
    ],
]);
$assertContains('игра', $dominoDisabled->newMatchBlockReason('domino'), 'Disabled domino must block a new domino game');
$assertSame(null, $dominoDisabled->newMatchBlockReason('chess'), 'Disabling domino must not block chess');
$assertSame(false, $dominoDisabled->publicStatus()['games']['domino'], 'Public health must report disabled domino');

$maintenance = new FeatureFlagService([
    'feature_flags' => [
        'maintenance_mode' => true,
        'maintenance_message' => 'Плановые работы',
    ],
]);
$assertSame('Плановые работы', $maintenance->newMatchBlockReason('tictactoe'), 'Maintenance must block new matches with configured copy');
$assertSame('Плановые работы', $maintenance->paymentBlockReason(), 'Maintenance must block payments');
$assertSame(true, $maintenance->activeGameActionsAllowed(), 'Maintenance must not interrupt active games');

$readOnly = new FeatureFlagService([
    'feature_flags' => [
        'financial_read_only' => true,
    ],
]);
$assertContains('Новые матчи', $readOnly->newMatchBlockReason('go'), 'Financial read-only must block new stake matches');
$assertContains('только для чтения', $readOnly->paymentBlockReason(), 'Financial read-only must block payment writes');
$assertContains('только для чтения', $readOnly->shopBlockReason(), 'Financial read-only must block shop writes');
$assertSame(true, $readOnly->activeGameActionsAllowed(), 'Financial read-only must let active matches settle');

$invitationsOff = new FeatureFlagService([
    'feature_flags' => [
        'features' => ['invitations' => false],
    ],
]);
$assertContains('Приглашения', $invitationsOff->invitationBlockReason('chess'), 'Invitation feature flag must block new invitations');
$assertSame(null, $invitationsOff->newMatchBlockReason('chess'), 'Invitation flag must not block normal matchmaking');

$publicJson = json_encode($maintenance->publicStatus(), JSON_UNESCAPED_UNICODE);
$assertSame(false, str_contains((string)$publicJson, 'bot_token'), 'Public runtime status must not expose bot tokens');
$assertSame(false, str_contains((string)$publicJson, 'admin_ids'), 'Public runtime status must not expose admin IDs');
$assertSame(false, str_contains((string)$publicJson, 'data_dir'), 'Public runtime status must not expose storage paths');

fwrite(STDOUT, "FeatureFlagServiceTest: {$assertions} assertions passed\n");
