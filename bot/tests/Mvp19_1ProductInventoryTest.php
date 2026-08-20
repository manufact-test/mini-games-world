<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$databaseDir = $root . '/bot/database';
require_once $databaseDir . '/DatabaseConnectionInterface.php';
require_once $databaseDir . '/PdoDatabaseConnection.php';
require_once $databaseDir . '/DatabaseMigrationInterface.php';
require_once $databaseDir . '/MigrationRepository.php';
require_once $databaseDir . '/MigrationRunner.php';
require_once $root . '/bot/accounts/MgwIdGenerator.php';
require_once $root . '/bot/accounts/AccountIdentityService.php';
require_once $root . '/bot/accounts/MgwProfileService.php';
require_once $root . '/bot/catalog/ProductInventoryService.php';

if (!extension_loaded('pdo_sqlite')) throw new RuntimeException('MVP-19.1 inventory test requires pdo_sqlite.');

$assertions = 0;
$assertSame = static function (mixed $expected, mixed $actual, string $message) use (&$assertions): void {
    $assertions++;
    if ($expected !== $actual) {
        throw new RuntimeException($message . ': expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
};
$assertTrue = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};
$assertThrows = static function (callable $callback, string $contains, string $message) use (&$assertions): void {
    $assertions++;
    try {
        $callback();
    } catch (Throwable $error) {
        if (str_contains(strtolower($error->getMessage()), strtolower($contains))) return;
        throw new RuntimeException($message . ': unexpected error ' . $error->getMessage());
    }
    throw new RuntimeException($message . ': no error was thrown');
};

$newDatabase = static function () use ($databaseDir): PdoDatabaseConnection {
    $pdo = new PDO('sqlite::memory:');
    $pdo->exec('PRAGMA foreign_keys = ON');
    $database = new PdoDatabaseConnection($pdo);
    $runner = new MigrationRunner($database, $databaseDir . '/migrations');
    $migration = $runner->migrate(false);
    $expected = count(glob($databaseDir . '/migrations/*.php') ?: []);
    if ((int)$migration['executed_count'] !== $expected) {
        throw new RuntimeException('MVP-19.1 fixture did not execute the current migration set.');
    }
    return $database;
};

$database = $newDatabase();

$assertSame(12, (int)$database->fetchValue("SELECT COUNT(*) FROM mgw_product_catalog WHERE item_family = 'avatar'"), 'Current avatar catalogue must contain exactly twelve avatars');
$assertSame(3, (int)$database->fetchValue("SELECT COUNT(*) FROM mgw_product_catalog WHERE item_family = 'avatar' AND starter_grant = 1"), 'Exactly three avatars must remain starter grants');
$assertSame(9, (int)$database->fetchValue("SELECT COUNT(*) FROM mgw_product_catalog WHERE item_family = 'avatar' AND is_store_product = 1"), 'Exactly nine avatars must be store products');
$assertSame(0, (int)$database->fetchValue("SELECT COUNT(*) FROM mgw_product_catalog WHERE starter_grant = 1 AND is_store_product = 1"), 'Starter avatars must not be store products');
$assertSame(
    ['profile', 'game', 'bundle', 'seasonal', 'showcase'],
    array_values(array_unique(ProductInventoryService::ITEM_TYPES)),
    'Catalogue service must expose all canonical MVP-19.1 item types'
);

$catalogColumns = array_map(
    static fn(array $row): string => (string)($row['name'] ?? ''),
    $database->fetchAll('PRAGMA table_info(mgw_product_catalog)')
);
$assertTrue(!in_array('price', $catalogColumns, true), 'MVP-19.1 catalogue rows must keep pricing out of the catalogue table');
$assertTrue(!in_array('price_mgw_coin', $catalogColumns, true), 'MVP-19.1 catalogue rows must keep pricing out of the catalogue table');

$accounts = new AccountIdentityService($database, 3600);
$account = $accounts->resolveTelegramUser([
    'id' => '19010001',
    'first_name' => 'Inventory Test',
    'username' => 'inventory_test',
], 'mvp19-1-session-a');
$mgwId = (string)$account['mgw_id'];
$inventory = new ProductInventoryService($database);
$profiles = new MgwProfileService($database);

$assertSame(3, (int)$database->fetchValue(
    'SELECT COUNT(*) FROM mgw_inventory_items WHERE mgw_id = :mgw_id',
    ['mgw_id' => $mgwId]
), 'New account must receive exactly three starter ownership rows');
$assertSame('starter-default-01', (string)$database->fetchValue(
    "SELECT item_id FROM mgw_equipped_items WHERE mgw_id = :mgw_id AND equip_slot = 'profile_avatar'",
    ['mgw_id' => $mgwId]
), 'New account must equip starter-default-01 by default');
$assertSame('starter-default-01', (string)$database->fetchValue(
    'SELECT equipped_avatar_item_id FROM mgw_users WHERE mgw_id = :mgw_id',
    ['mgw_id' => $mgwId]
), 'Compatibility profile projection must match canonical equipment');

$repeatStarter = $inventory->grantStarterItems($mgwId);
$assertSame([], $repeatStarter['granted_item_ids'], 'Repeated starter bootstrap must grant nothing');
$assertSame(3, (int)$database->fetchValue(
    'SELECT COUNT(*) FROM mgw_inventory_items WHERE mgw_id = :mgw_id',
    ['mgw_id' => $mgwId]
), 'Repeated starter bootstrap must not duplicate ownership');

$assertThrows(
    static fn() => $inventory->equip($mgwId, 'store-avatar-02'),
    'not owned',
    'Unowned store avatar must not be equippable'
);

$firstGrant = $inventory->grant($mgwId, 'store-avatar-01', 'test_reward', 'grant-1');
$assertSame(true, $firstGrant['granted'], 'First permanent store-avatar grant must create ownership');
$secondGrant = $inventory->grant($mgwId, 'store-avatar-01', 'test_reward', 'grant-2');
$assertSame(false, $secondGrant['granted'], 'Second grant of owned item must be rejected as duplicate');
$assertSame('already_owned', $secondGrant['reason'], 'Duplicate grant must expose explicit already-owned result without compensation');
$assertSame(1, (int)$database->fetchValue(
    "SELECT COUNT(*) FROM mgw_inventory_items WHERE mgw_id = :mgw_id AND item_id = 'store-avatar-01'",
    ['mgw_id' => $mgwId]
), 'Permanent ownership must remain one row per account/item');
$assertSame('grant-1', (string)$database->fetchValue(
    "SELECT acquired_ref FROM mgw_inventory_items WHERE mgw_id = :mgw_id AND item_id = 'store-avatar-01'",
    ['mgw_id' => $mgwId]
), 'Duplicate grant must not replace original acquisition evidence');

$inventory->equip($mgwId, 'store-avatar-01');
$assertSame('store-avatar-01', (string)$database->fetchValue(
    "SELECT item_id FROM mgw_equipped_items WHERE mgw_id = :mgw_id AND equip_slot = 'profile_avatar'",
    ['mgw_id' => $mgwId]
), 'Owned store avatar must become canonical equipped item');
$assertSame('store-avatar-01', $profiles->publicProfile($mgwId)['avatar']['item_id'] ?? null, 'Profile must consume store-avatar compatibility projection without starter-only normalization');

$profileStarter = $profiles->updateProfile($mgwId, ['avatar_item_id' => 'starter-default-02']);
$assertSame('starter-default-02', $profileStarter['avatar']['item_id'] ?? null, 'Existing Profile avatar mutation must route through inventory owner');
$assertSame('starter-default-02', (string)$database->fetchValue(
    "SELECT item_id FROM mgw_equipped_items WHERE mgw_id = :mgw_id AND equip_slot = 'profile_avatar'",
    ['mgw_id' => $mgwId]
), 'Profile mutation must update canonical equipment row');

$unequipped = $inventory->unequip($mgwId, ProductInventoryService::PROFILE_AVATAR_SLOT);
$assertSame(true, $unequipped['fallback'] ?? false, 'Visible avatar unequip must report fallback behavior');
$assertSame('starter-default-01', $unequipped['item_id'] ?? null, 'Visible avatar unequip must restore starter-default-01');
$assertSame('starter-default-01', $profiles->publicProfile($mgwId)['avatar']['item_id'] ?? null, 'Profile fallback must stay synchronized after unequip');

$snapshotA = $inventory->snapshot($mgwId);
$snapshotB = $inventory->snapshot($mgwId);
$assertSame($snapshotA, $snapshotB, 'Inventory snapshot must be deterministic for unchanged state');
$assertSame(12, count($snapshotA['catalog']), 'Snapshot must expose the twelve current avatars');
$assertSame(4, count($snapshotA['owned']), 'Snapshot must expose three starters plus one granted store avatar');
$assertSame('starter-default-01', $snapshotA['equipped']['profile_avatar'] ?? null, 'Snapshot must expose canonical profile equip slot');
$assertTrue(!array_key_exists('price', $snapshotA['catalog'][0] ?? []), 'Inventory snapshot must not duplicate offer pricing into catalogue rows');

// Existing-account migration proof: create only the schema that existed before
// 0012, insert an account that already selected starter-default-03, then apply
// the 0012 inventory migration directly. Later migrations must not contaminate
// this historical fixture.
$legacyPdo = new PDO('sqlite::memory:');
$legacyPdo->exec('PRAGMA foreign_keys = ON');
$legacyDb = new PdoDatabaseConnection($legacyPdo);
$migrationFiles = glob($databaseDir . '/migrations/*.php') ?: [];
sort($migrationFiles, SORT_STRING);
$inventoryMigration = null;
foreach ($migrationFiles as $migrationFile) {
    $migration = require $migrationFile;
    if (!($migration instanceof DatabaseMigrationInterface)) throw new RuntimeException('Invalid migration fixture.');
    if ($migration->version() === '20260819_0012_create_product_catalog_inventory_equipment') {
        $inventoryMigration = $migration;
        break;
    }
    $migration->up($legacyDb);
}
if (!$inventoryMigration instanceof DatabaseMigrationInterface) throw new RuntimeException('MVP-19.1 migration fixture missing.');
$legacyId = MgwIdGenerator::generate();
$legacyDb->execute(
    'INSERT INTO mgw_users (
        mgw_id, status, nickname, display_name, username, avatar_provider, avatar_external_ref,
        equipped_avatar_item_id, created_at_utc, updated_at_utc, last_seen_at_utc
     ) VALUES (
        :mgw_id, :status, :nickname, :display_name, NULL, NULL, NULL,
        :avatar_item_id, :created_at, :updated_at, :last_seen_at
     )',
    [
        'mgw_id' => $legacyId,
        'status' => 'active',
        'nickname' => 'Legacy1901',
        'display_name' => 'Legacy1901',
        'avatar_item_id' => 'starter-default-03',
        'created_at' => '2026-08-18 00:00:00.000000',
        'updated_at' => '2026-08-18 00:00:00.000000',
        'last_seen_at' => '2026-08-18 00:00:00.000000',
    ]
);
$inventoryMigration->up($legacyDb);
$assertSame(3, (int)$legacyDb->fetchValue(
    'SELECT COUNT(*) FROM mgw_inventory_items WHERE mgw_id = :mgw_id',
    ['mgw_id' => $legacyId]
), 'Migration must grant all three starters to every existing account exactly once');
$assertSame('starter-default-03', (string)$legacyDb->fetchValue(
    "SELECT item_id FROM mgw_equipped_items WHERE mgw_id = :mgw_id AND equip_slot = 'profile_avatar'",
    ['mgw_id' => $legacyId]
), 'Migration must preserve an existing valid starter avatar selection');
$inventoryMigration->up($legacyDb);
$assertSame(3, (int)$legacyDb->fetchValue(
    'SELECT COUNT(*) FROM mgw_inventory_items WHERE mgw_id = :mgw_id',
    ['mgw_id' => $legacyId]
), 'Repeated migration bootstrap must not duplicate starter ownership');

$profileSource = (string)file_get_contents($root . '/bot/accounts/MgwProfileService.php');
$assertTrue(!str_contains($profileSource, 'equipped_avatar_item_id = :avatar_item_id'), 'Profile service must not remain a parallel raw avatar writer');
$assertTrue(str_contains($profileSource, 'ProductInventoryService($database))->equip'), 'Profile service must hand avatar mutations to canonical inventory owner');

fwrite(STDOUT, "PASS: MVP-19.1 product catalogue/inventory/equip ({$assertions} assertions)\n");
