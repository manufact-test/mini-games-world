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

if (!extension_loaded('pdo_sqlite')) throw new RuntimeException('Four Profile test requires pdo_sqlite.');

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
$account = $accounts->resolveProviderIdentity('development', 'four-profile-user', 'browser_dev', ['username'=>'four-profile'], 'four-profile-session');
$mgwId = (string)$account['mgw_id'];
$store = new CosmeticStoreService($database);
$inventory = new ProductInventoryService($database);

foreach ([
    ['four-field-metal','game-four-field-metal','0001'],
    ['four-discs-neon','game-four-discs-neon','0002'],
    ['four-effect-four','game-four-effect-four','0003'],
] as [$offerId,$itemId,$token]) {
    $quote = $store->quote($mgwId, $offerId);
    $purchase = $store->fulfill($mgwId, 'mgw:' . $mgwId, 'legacy-four-profile-user', [
        'request_token'=>'profile:four:' . $token,
        'offer_id'=>$offerId,
        'price_coins'=>$quote['price_coins'],
        'item_ids'=>$quote['item_ids'],
    ]);
    $assertSame(false, $purchase['auto_equipped'], 'Profile fixtures must preserve no-auto-equip for ' . $itemId);
}

$store->equipGameItem($mgwId, 'game-four-field-metal');
$store->equipGameItem($mgwId, 'game-four-discs-neon');

$snapshot = $inventory->snapshot($mgwId);
$catalogById = [];
foreach ($snapshot['catalog'] as $item) $catalogById[(string)$item['item_id']] = $item;

foreach (['game-four-field-metal','game-four-discs-neon','game-four-effect-four'] as $itemId) {
    $assertTrue(isset($catalogById[$itemId]), 'Profile inventory must expose purchased item ' . $itemId);
    $assertSame(true, (bool)$catalogById[$itemId]['owned'], 'Purchased item must be owned in canonical Profile inventory: ' . $itemId);
}
$assertSame('game-four-field-metal', $snapshot['equipped']['game_four_in_a_row_theme'] ?? null, 'Profile inventory must expose equipped Four field');
$assertSame('game-four-discs-neon', $snapshot['equipped']['game_four_in_a_row_elements'] ?? null, 'Profile inventory must expose equipped Four discs');
$assertSame(null, $snapshot['equipped']['game_four_in_a_row_effect'] ?? null, 'Purchased effect must remain unequipped until explicit Profile/Store selection');

$profileApiSource = (string)file_get_contents($root . '/bot/profile-v2.php');
$assertTrue(str_contains($profileApiSource, '(new ProductInventoryService($database))->snapshot($mgwId)'), 'Profile API must return canonical ProductInventoryService snapshot');
$assertTrue(str_contains($profileApiSource, "'inventory'=>\$inventory"), 'Profile API response must publish inventory to the client');

echo "Four in a Row Profile inventory contract passed ({$assertions} assertions).\n";
