<?php
declare(strict_types=1);

$root = dirname(__DIR__);
require $root . '/services/InviteOpponentService.php';

$assertions = 0;
$assertSame = static function (mixed $expected, mixed $actual, string $message) use (&$assertions): void {
    $assertions++;
    if ($expected !== $actual) {
        throw new RuntimeException($message . ': expected ' . var_export($expected, true)
            . ', got ' . var_export($actual, true));
    }
};
$assertTrue = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

$now = gmdate('c');
$service = new InviteOpponentService();
$data = [
    'users' => [
        'main_complex_account' => [
            'id' => 'main_complex_account',
            'first_name' => 'Main',
            'username' => 'main_account',
            'status' => 'idle',
            'last_seen_at' => $now,
        ],
        'carl_account' => [
            'id' => 'carl_account',
            'first_name' => 'Карл',
            'username' => 'carl_player',
            'status' => 'idle',
            'last_seen_at' => $now,
        ],
        'stg_test_player_a' => [
            'id' => 'stg_test_player_a',
            'first_name' => 'TEST PLAYER A',
            'status' => 'idle',
            'last_seen_at' => $now,
            'is_dev_user' => true,
            'is_staging_test_user' => true,
        ],
        'stg_test_player_b' => [
            'id' => 'stg_test_player_b',
            'first_name' => 'TEST PLAYER B',
            'status' => 'idle',
            'last_seen_at' => $now,
            'is_dev_user' => true,
            'is_staging_test_user' => true,
        ],
        'stg_tour_v2_deadbeefcafe' => [
            'id' => 'stg_tour_v2_deadbeefcafe',
            'first_name' => 'Tournament Fixture',
            'status' => 'idle',
            'last_seen_at' => $now,
            'is_dev_user' => true,
            'is_staging_test_user' => true,
            'mgw_identity_provider' => 'staging_fixture',
        ],
        'bot_training' => [
            'id' => 'bot_training',
            'first_name' => 'Bot',
            'status' => 'idle',
            'last_seen_at' => $now,
        ],
        'stale_account' => [
            'id' => 'stale_account',
            'first_name' => 'Stale',
            'status' => 'idle',
            'last_seen_at' => gmdate('c', time() - 86400 * 31),
        ],
    ],
    'games' => [
        'finished-main-carl' => [
            'id' => 'finished-main-carl',
            'status' => 'finished',
            'is_bot_game' => false,
            'player_ids' => ['main_complex_account', 'carl_account'],
            'finished_at' => $now,
        ],
    ],
];

$mainItems = $service->list($data, 'main_complex_account', ['main_complex_account', 'carl_account']);
$mainIds = array_column($mainItems, 'id');
$assertTrue(in_array('carl_account', $mainIds, true), 'Main account must see Carl from the same active state');
$assertTrue(!in_array('stg_test_player_a', $mainIds, true), 'Real player picker must hide staging A fixture');
$assertTrue(!in_array('stg_test_player_b', $mainIds, true), 'Real player picker must hide staging B fixture');
$assertTrue(!in_array('stg_tour_v2_deadbeefcafe', $mainIds, true), 'Real player picker must hide tournament fixtures');
$assertTrue(!in_array('bot_training', $mainIds, true), 'Bots must remain excluded');
$assertTrue(!in_array('stale_account', $mainIds, true), 'Stale unrelated accounts must remain excluded');

$carlItems = $service->list($data, 'carl_account', ['main_complex_account', 'carl_account']);
$carlIds = array_column($carlItems, 'id');
$assertTrue(in_array('main_complex_account', $carlIds, true), 'Carl must see the main account symmetrically');
$assertTrue(!in_array('carl_account', $carlIds, true), 'A player must never see itself');

$carl = $mainItems[array_search('carl_account', $mainIds, true)] ?? null;
$assertSame('@carl_player', $carl['name'] ?? null, 'Telegram username must remain the preferred display name');
$assertSame(true, $carl['online'] ?? null, 'Presence must mark Carl online');
$assertSame(false, $carl['busy'] ?? null, 'Idle online player must not be marked busy');
$assertSame('онлайн', $carl['activity'] ?? null, 'Online activity label must remain stable');
$assertSame(1, count($mainItems), 'Only eligible real human opponents other than the current account must be returned');

$stagingItems = $service->list($data, 'stg_test_player_a', ['stg_test_player_a', 'stg_test_player_b']);
$stagingIds = array_column($stagingItems, 'id');
$assertTrue(in_array('stg_test_player_b', $stagingIds, true), 'Staging A/B acceptance must keep the paired staging player visible to a staging viewer');
$assertTrue(in_array('stg_tour_v2_deadbeefcafe', $stagingIds, true), 'Staging automation viewers may still see staging tournament fixtures');

fwrite(STDOUT, "InviteOpponentServiceTest: {$assertions} assertions passed\n");
