<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$databaseDir = $root . '/bot/database';
require_once $databaseDir . '/DatabaseConnectionInterface.php';
require_once $databaseDir . '/DatabaseExceptionClassifier.php';
require_once $databaseDir . '/PdoDatabaseConnection.php';
require_once $databaseDir . '/DatabaseMigrationInterface.php';
require_once $databaseDir . '/MigrationRepository.php';
require_once $databaseDir . '/MigrationRunner.php';
require_once $root . '/bot/accounts/MgwIdGenerator.php';
require_once $root . '/bot/accounts/MgwIdentityPolicy.php';
require_once $root . '/bot/catalog/ProductInventoryService.php';
require_once $root . '/bot/accounts/AccountIdentityService.php';
require_once $root . '/bot/catalog/CosmeticStoreService.php';

if (!extension_loaded('pdo_sqlite')) throw new RuntimeException('Battleship Profile test requires pdo_sqlite.');

$assertions = 0;
$assertSame = static function (mixed $expected, mixed $actual, string $message) use (&$assertions): void {
    $assertions++;
    if ($expected !== $actual) throw new RuntimeException($message . ': expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
};
$assertTrue = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

$pdo = new PDO('sqlite::memory:');
$pdo->exec('PRAGMA foreign_keys = ON');
$database = new PdoDatabaseConnection($pdo);
$runner = new MigrationRunner($database, $databaseDir . '/migrations');
$runner->migrate(false);

$accounts = new AccountIdentityService($database, 3600);
$account = $accounts->resolveProviderIdentity(
    'development',
    'battleship-profile-user',
    'browser_dev',
    ['username'=>'battleship-profile'],
    'battleship-profile-session'
);
$mgwId = (string)$account['mgw_id'];
$store = new CosmeticStoreService($database);
$inventory = new ProductInventoryService($database);

foreach ([
    ['battleship-map-neon','game-battleship-map-neon','0001'],
    ['battleship-fleet-classic','game-battleship-fleet-classic','0002'],
    ['battleship-effect-hit','game-battleship-effect-hit','0003'],
] as [$offerId,$itemId,$token]) {
    $quote = $store->quote($mgwId, $offerId);
    $purchase = $store->fulfill($mgwId, 'mgw:' . $mgwId, 'legacy-battleship-profile-user', [
        'request_token'=>'profile:battleship:' . $token,
        'offer_id'=>$offerId,
        'price_coins'=>$quote['price_coins'],
        'item_ids'=>$quote['item_ids'],
    ]);
    $assertSame(false, $purchase['auto_equipped'], 'Profile fixtures must preserve no-auto-equip for ' . $itemId);
}

$store->equipGameItem($mgwId, 'game-battleship-map-neon');
$store->equipGameItem($mgwId, 'game-battleship-fleet-classic');

$snapshot = $inventory->snapshot($mgwId);
$catalogById = [];
foreach ($snapshot['catalog'] as $item) $catalogById[(string)$item['item_id']] = $item;

foreach (['game-battleship-map-neon','game-battleship-fleet-classic','game-battleship-effect-hit'] as $itemId) {
    $assertTrue(isset($catalogById[$itemId]), 'Profile inventory must expose purchased item ' . $itemId);
    $assertSame(true, (bool)$catalogById[$itemId]['owned'], 'Purchased item must be owned in Profile inventory: ' . $itemId);
}
$assertSame('game-battleship-map-neon', $snapshot['equipped']['game_battleship_theme'] ?? null, 'Profile inventory must expose equipped Battleship map');
$assertSame('game-battleship-fleet-classic', $snapshot['equipped']['game_battleship_elements'] ?? null, 'Profile inventory must expose equipped Battleship fleet');
$assertSame(null, $snapshot['equipped']['game_battleship_effect'] ?? null, 'Purchased Battleship effect must remain unequipped until explicit selection');

$profileApiSource = (string)file_get_contents($root . '/bot/profile-v2.php');
$assertTrue(str_contains($profileApiSource, '(new ProductInventoryService($database))->snapshot($mgwId)'), 'Profile API must return canonical ProductInventoryService snapshot');
$assertTrue(str_contains($profileApiSource, "'inventory'=>\$inventory"), 'Profile API response must publish inventory to the client');

echo "Battleship Profile inventory contract passed ({$assertions} assertions).\n";
