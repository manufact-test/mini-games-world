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

if (!extension_loaded('pdo_sqlite')) throw new RuntimeException('MVP-19.3 profile reaction test requires pdo_sqlite.');

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
$migration = $runner->migrate(false);
$assertSame(count(glob($databaseDir . '/migrations/*.php') ?: []), (int)$migration['executed_count'], 'Fixture must apply every additive migration');

$rows = $database->fetchAll(
    "SELECT c.item_id, c.item_type, c.item_family, c.equip_slot, c.starter_grant, c.metadata_json,
            o.offer_id, o.price_coins, o.category, o.subcategory
     FROM mgw_product_catalog c
     INNER JOIN mgw_product_offers o ON o.item_id = c.item_id AND o.offer_type = 'item'
     WHERE c.item_family = 'reaction' AND c.catalog_status = 'active' AND o.offer_status = 'active'
     ORDER BY o.sort_order ASC"
);
$assertSame(6, count($rows), 'Reaction launch catalogue must expose four singles and two packs');
$assertSame([500,500,500,500,1500,3500], array_map('intval', array_column($rows, 'price_coins')), 'Reaction prices must preserve canonical single/pack tiers');
$assertSame(['profile_reaction_set'], array_values(array_unique(array_column($rows, 'equip_slot'))), 'Reactions must share one active reaction-set slot');
$assertSame(['profile'], array_values(array_unique(array_column($rows, 'item_type'))), 'Reaction products must remain Profile cosmetics');
$assertSame(['reaction'], array_values(array_unique(array_column($rows, 'item_family'))), 'Reaction family must remain isolated');
$assertSame([0,0,0,0,0,0], array_map('intval', array_column($rows, 'starter_grant')), 'Reaction products must not be starter granted');
$assertSame(['profile'], array_values(array_unique(array_column($rows, 'category'))), 'Reaction offers must remain in Profile Store category');
$assertSame(['reaction'], array_values(array_unique(array_column($rows, 'subcategory'))), 'Reaction offers must use reaction subcategory');

$metadata = array_map(static fn(array $row): array => json_decode((string)$row['metadata_json'], true, 32, JSON_THROW_ON_ERROR), $rows);
$assertSame([1,1,1,1,4,8], array_map(static fn(array $item): int => count((array)($item['reactions'] ?? [])), $metadata), 'Reaction sets must expose deterministic member counts');
$assertSame([500,500,500,500,1500,3500], array_map(static fn(array $item): int => (int)($item['price_coins'] ?? 0), $metadata), 'Reaction metadata prices must match offers');

$accounts = new AccountIdentityService($database, 3600);
$account = $accounts->resolveProviderIdentity('development', 'mvp19-3-profile-reactions-user', 'browser_dev', ['username'=>'profile-reactions'], 'mvp19-3-profile-reactions-session');
$mgwId = (string)$account['mgw_id'];
$inventory = new ProductInventoryService($database);
$store = new CosmeticStoreService($database);

$snapshot = $inventory->snapshot($mgwId);
$reactionCatalog = array_values(array_filter((array)($snapshot['catalog'] ?? []), static fn(array $item): bool => ($item['item_family'] ?? '') === 'reaction'));
$assertSame(6, count($reactionCatalog), 'Inventory snapshot must expose every reaction product');
$assertSame(null, $snapshot['equipped']['profile_reaction_set'] ?? null, 'Fresh account must have no selected reaction set');

$quote = $store->quote($mgwId, 'reaction-wave');
$assertSame(500, (int)$quote['price_coins'], 'Single reaction quote must cost 500 coins');
$purchase = $store->fulfill($mgwId, 'mgw:' . $mgwId, 'legacy-profile-reaction-user', [
    'request_token' => 'store:mvp19-3-profile-reaction-0001',
    'offer_id' => 'reaction-wave',
    'price_coins' => $quote['price_coins'],
    'item_ids' => $quote['item_ids'],
]);
$assertSame(false, $purchase['auto_equipped'], 'Buying a reaction must never auto-equip it');
$assertSame(null, $inventory->snapshot($mgwId)['equipped']['profile_reaction_set'] ?? null, 'Purchase must not select a reaction set');
$inventory->equip($mgwId, 'profile-reaction-wave');
$assertSame('profile-reaction-wave', $inventory->snapshot($mgwId)['equipped']['profile_reaction_set'] ?? null, 'Explicit reaction equip must use ProductInventoryService');

$packQuote = $store->quote($mgwId, 'reaction-pack-4');
$assertSame(1500, (int)$packQuote['price_coins'], 'Pack-4 quote must cost 1500 coins');
$store->fulfill($mgwId, 'mgw:' . $mgwId, 'legacy-profile-reaction-user', [
    'request_token' => 'store:mvp19-3-profile-reaction-0002',
    'offer_id' => 'reaction-pack-4',
    'price_coins' => $packQuote['price_coins'],
    'item_ids' => $packQuote['item_ids'],
]);
$assertSame('profile-reaction-wave', $inventory->snapshot($mgwId)['equipped']['profile_reaction_set'] ?? null, 'Buying another reaction set must not replace current equipment');
$inventory->equip($mgwId, 'profile-reaction-pack-4');
$assertSame('profile-reaction-pack-4', $inventory->snapshot($mgwId)['equipped']['profile_reaction_set'] ?? null, 'Reaction set slot must replace the previous selection');
$inventory->unequip($mgwId, 'profile_reaction_set');
$assertTrue(!isset($inventory->snapshot($mgwId)['equipped']['profile_reaction_set']), 'Reaction set must allow an explicit no-reactions state');

$reactionUi = (string)file_get_contents($root . '/app/assets/js/profile/mgw-profile-reactions.js');
$reactionCss = (string)file_get_contents($root . '/app/assets/css/production-v97-reactions.css');
$reactionService = (string)file_get_contents($root . '/bot/services/GameReactionService.php');
$watcher = (string)file_get_contents($root . '/app/assets/js/production-v110-readonly-game-sync.js');
$manifest = (string)file_get_contents($root . '/app/runtime/client/version-manifest.php');
$assertTrue(str_contains($reactionUi, "const REACTION_SLOT = 'profile_reaction_set'") && str_contains($reactionUi, 'api.cosmeticStorePurchase'), 'Reaction UI must reuse canonical Store purchase flow');
$assertTrue(str_contains($reactionUi, 'api.profileReactionEquip') && str_contains($reactionUi, 'api.profileReactionUnequip'), 'Reaction UI must route selection to the inventory-backed reaction endpoint');
$assertTrue(str_contains($reactionUi, 'data-profile-reaction-store-section') && str_contains($reactionUi, 'data-profile-reaction-collection'), 'Reaction UI must provide Store and Profile collection surfaces');
$assertTrue(str_contains($reactionUi, 'data-send-reaction') && str_contains($reactionUi, 'mgw:game-reaction'), 'Reaction UI must expose a match composer and consume realtime presentation events');
$assertTrue(str_contains($reactionService, "public const SLOT = 'profile_reaction_set'") && str_contains($reactionService, 'ProductInventoryService'), 'Reaction delivery must validate the selected set through canonical inventory');
$assertTrue(str_contains($reactionService, 'COOLDOWN_MS = 900') && str_contains($reactionService, 'EVENT_TTL_MS = 5000'), 'Reaction delivery must be bounded and ephemeral');
$assertTrue(str_contains($watcher, "document.addEventListener('mgw:app-ready', initMgwProfileReactions") && str_contains($watcher, "new CustomEvent('mgw:game-reaction'"), 'Reaction UI must initialize after app-ready and reuse the existing read-only watcher');
$assertTrue(!str_contains($reactionUi, '!game?.is_bot_game') && !str_contains($reactionService, "if (!empty(\$game['is_bot_game']))"), 'Owned reactions must remain available in active bot matches without adding bot response ownership');
$assertTrue(str_contains($reactionService, 'activeGameForParticipant') && !str_contains($reactionService, 'activeHumanGameForParticipant'), 'Reaction participant validation must no longer encode a human-only product restriction');
$assertTrue(str_contains($reactionUi, 'aria-label="Реакции"') && !str_contains($reactionUi, '> Реакция</button>'), 'Match launcher must be an icon-only compact reaction control');
$assertTrue(str_contains($reactionUi, ':scope > .game-player-avatar') && str_contains($reactionUi, '--mgw-reaction-origin-x'), 'Live reaction must calculate its visual origin from the sender avatar when present');
$assertTrue(str_contains($reactionCss, 'width:28px') && str_contains($reactionCss, 'width:31px') && str_contains($reactionCss, 'mgwReactionFromSender'), 'Match reaction trigger, palette items and sender-origin animation must stay compact');
$assertTrue(str_contains($manifest, 'mgw-profile-reactions.js?v=2&mvp19_3=ingame-corrective') && str_contains($manifest, 'production-v97-reactions.css?v=2&mvp19_3=ingame-corrective'), 'Corrected reaction JS and CSS must be cache-published through the active manifest');

fwrite(STDOUT, "MVP-19.3 profile reactions passed ({$assertions} assertions).\n");
