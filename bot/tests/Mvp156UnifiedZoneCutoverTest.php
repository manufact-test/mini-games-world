<?php
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/helpers/validators.php';
require_once $root . '/database/DatabaseConnectionInterface.php';
require_once $root . '/database/DatabaseConfig.php';
require_once $root . '/database/PdoDatabaseConnection.php';
require_once $root . '/database/PdoConnectionFactory.php';
require_once $root . '/economy/UnifiedBalanceRuntimeState.php';
require_once $root . '/economy/MatchEconomyRuntimeConfig.php';
require_once $root . '/runtime/UnifiedGameZonePolicy.php';
require_once $root . '/services/GameService.php';
require_once $root . '/services/PresenceService.php';
require_once $root . '/services/StatsService.php';

$assertions = 0;
$assertSame = static function (mixed $expected, mixed $actual, string $message) use (&$assertions): void {
    $assertions++;
    if ($expected !== $actual) {
        throw new RuntimeException($message . ': expected ' . var_export($expected, true)
            . ', got ' . var_export($actual, true));
    }
};
$assertTrue = static function (bool $actual, string $message) use (&$assertions): void {
    $assertions++;
    if (!$actual) throw new RuntimeException($message);
};
$assertNotContains = static function (string $needle, string $haystack, string $message) use (&$assertions): void {
    $assertions++;
    if (str_contains($haystack, $needle)) {
        throw new RuntimeException($message . ': unexpected ' . var_export($needle, true));
    }
};
$read = static fn(string $path): string => (string)file_get_contents($path);

$defaults = EconomyConfigDefinition::defaults();
$config = MatchEconomyRuntimeConfig::apply([
    'environment' => 'local',
    'canonical_economy_snapshot' => ['version' => 8, 'config' => $defaults],
    'board_sizes' => [3, 5, 9],
    'gold_bets' => [10, 20, 30, 50, 100],
    'move_timeout_sec' => 60,
    'queue_timeout_sec' => 60,
    'match_bot_after_sec' => 9999,
]);

$assertSame('match', UnifiedGameZonePolicy::storageRoom(), 'Compatibility storage must have one current zone');
$assertSame(100, UnifiedGameZonePolicy::entryCost($config), 'Unified zone entry must come from canonical economy config');

$service = new GameService($config);
$db = [
    'users' => [
        'legacy-client' => [
            'id' => 'legacy-client',
            'username' => 'legacy',
            'first_name' => 'Legacy',
            'balance' => 1000,
            'status' => 'idle',
            'stats' => [],
        ],
    ],
    'queue' => [],
    'games' => [],
    'transactions' => [],
    'system' => [],
];
$user =& $db['users']['legacy-client'];
$queued = $service->startSearch($db, $user, 'gold', 10, 3);
$assertSame(true, $queued['queued'] ?? false, 'Retained client request must still enter current matchmaking');
$assertSame('match', (string)($db['queue'][0]['room'] ?? ''), 'Legacy Gold request must not create Gold queue data');
$assertSame(100, (int)($db['queue'][0]['bet'] ?? 0), 'Legacy client bet must not override canonical entry');

$oldGoldBlocked = false;
try {
    UnifiedGameZonePolicy::assertInviteWritable(['room' => 'gold']);
} catch (RuntimeException $error) {
    $oldGoldBlocked = str_contains($error->getMessage(), 'Gold');
}
$assertTrue($oldGoldBlocked, 'Archived Gold invite must not create a new game');

$legacyWriteBlocked = false;
try {
    UnifiedGameZonePolicy::rejectLegacyCommerceWrite();
} catch (RuntimeException $error) {
    $legacyWriteBlocked = str_contains($error->getMessage(), 'только для просмотра');
}
$assertTrue($legacyWriteBlocked, 'Legacy payment/shop mutation policy must fail closed');

$presenceDir = sys_get_temp_dir() . '/mgw-mvp156-presence-' . bin2hex(random_bytes(5));
$presence = new PresenceService($presenceDir);
$presence->touch('player-a', 'session-a');
$GLOBALS['config'] = ['environment' => 'local'];
$stats = (new StatsService($presence))->build([
    'users' => [],
    'games' => [
        ['id' => 'active-1', 'status' => 'active', 'player_ids' => ['player-a', 'player-b']],
        ['id' => 'finished-1', 'status' => 'finished', 'player_ids' => ['player-a', 'player-b']],
    ],
]);
$assertSame(['online_players', 'active_games'], array_keys($stats), 'Stats API must expose only the two retained counters');
$assertSame(1, $stats['online_players'], 'Account presence must still count online players');
$assertSame(1, $stats['active_games'], 'Active-game counter must remain intact');

$index = $read(dirname($root) . '/app/index.html');
$home = $read(dirname($root) . '/app/assets/js/screens/home-screen.js');
$ui = $read(dirname($root) . '/app/assets/js/ui.js');
$weekly = $read(dirname($root) . '/app/assets/js/screens/weekly-match-info.js');
$presenceClient = $read(dirname($root) . '/app/assets/js/production-v110-presence.js');
$v110 = $read(dirname($root) . '/app/v110.php');
$api = $read($root . '/api.php');
$payments = $read($root . '/services/PaymentService.php');
$shop = $read($root . '/services/ShopService.php');
$admin = $read($root . '/services/AdminService.php');

$assertNotContains('data-room="gold"', $index, 'Home must not expose Gold room selector');
$assertNotContains('data-room="match"', $index, 'Home must not expose Match room selector');
$assertNotContains('В Матч-комнате', $home, 'Removed Match room counter must not remain in Home');
$assertNotContains('В Gold-комнате', $home, 'Removed Gold room counter must not remain in Home');
$assertNotContains('topUpGold', $home, 'Gold top-up creation UI must be gone');
$assertNotContains('topUpMatch', $home, 'Legacy Match top-up creation UI must be gone');
$assertTrue(str_contains($home, 'Обычные матчи'), 'Home must expose the neutral game-zone card');
$assertTrue(str_contains($home, 'Игроков онлайн') && str_contains($home, 'Активных матчей'), 'Home must retain the two accepted counters');
$assertTrue(str_contains($ui, 'export function renderUser(user)'), 'Accepted user renderer must survive the room-label cutover');
$assertTrue(str_contains($ui, "roomName(){ return 'Обычный матч'; }"), 'Shared room copy must be neutral');
$assertNotContains('Матч-комнату', $weekly, 'Weekly bonus copy must no longer target Match room');
$assertNotContains('в Матч-комнате', $weekly, 'Weekly threshold copy must be room-neutral');
$assertNotContains('room,', $presenceClient, 'Presence payload must not publish room metadata');
$assertTrue(str_contains($v110, "X-MGW-Game-Zone: unified-v1"), 'Accepted /start entry must advertise unified game zone');
$assertTrue(str_contains($v110, 'v=1142&mvp15=unified-zone'), 'Accepted /start graph must cache-bust the unified-zone shell');

$startSearchPos = strpos($api, "case 'start_search':");
$nextCasePos = strpos($api, "case '", $startSearchPos + 20);
$startSearchBlock = substr($api, $startSearchPos, $nextCasePos - $startSearchPos);
$assertNotContains("payload['room']", $startSearchBlock, 'Public start_search must ignore retained client room input');
$assertNotContains("payload['bet']", $startSearchBlock, 'Public start_search must ignore retained client bet input');
$assertTrue(str_contains($startSearchBlock, 'UnifiedGameZonePolicy::storageRoom()'), 'Public start_search must use unified-zone owner');
$assertTrue(str_contains($startSearchBlock, 'UnifiedGameZonePolicy::entryCost($config)'), 'Public start_search must use canonical entry owner');

$assertTrue(str_contains($payments, 'UnifiedGameZonePolicy::rejectLegacyCommerceWrite();'), 'Legacy payment writes must be blocked');
$assertTrue(str_contains($shop, 'UnifiedGameZonePolicy::rejectLegacyCommerceWrite();'), 'Legacy shop writes must be blocked');
$assertTrue(str_contains($admin, "require_once __DIR__ . '/../runtime/UnifiedGameZonePolicy.php';"), 'Admin archive mutations must share the unified-zone policy');
$assertNotContains('Gold-тест', $admin, 'Admin keyboard must not expose legacy Gold mutation surface');

// Best-effort cleanup of the isolated presence fixture.
foreach (glob($presenceDir . '/account-*/*') ?: [] as $path) @unlink($path);
foreach (glob($presenceDir . '/account-*') ?: [] as $path) @rmdir($path);
@unlink($presenceDir . '/.enabled');
@rmdir($presenceDir);

fwrite(STDOUT, "Mvp156UnifiedZoneCutoverTest passed: {$assertions} assertions.\n");
