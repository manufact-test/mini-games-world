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

if (!extension_loaded('pdo_sqlite')) throw new RuntimeException('MVP-19.3 profile badge test requires pdo_sqlite.');

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
$assertStoreError = static function (callable $callback, string $reason, string $message) use (&$assertions): void {
    $assertions++;
    try {
        $callback();
    } catch (CosmeticStoreException $error) {
        if ($error->reason === $reason) return;
        throw new RuntimeException($message . ': unexpected reason ' . $error->reason);
    }
    throw new RuntimeException($message . ': no store error was thrown');
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
     WHERE c.item_family = 'badge' AND c.catalog_status = 'active' AND o.offer_status = 'active'
     ORDER BY o.sort_order ASC"
);
$assertSame(3, count($rows), 'Badge launch catalogue must contain exactly three active items');
$assertSame([1000,2500,6000], array_map('intval', array_column($rows, 'price_coins')), 'Badge prices must match canonical tiers');
$assertSame(['profile_badge'], array_values(array_unique(array_column($rows, 'equip_slot'))), 'All badges must share one mutually exclusive profile slot');
$assertSame(['profile'], array_values(array_unique(array_column($rows, 'item_type'))), 'Badges must remain profile products');
$assertSame(['badge'], array_values(array_unique(array_column($rows, 'item_family'))), 'Badge family must remain isolated');
$assertSame([0,0,0], array_map('intval', array_column($rows, 'starter_grant')), 'Paid badges must never be starter granted');
$assertSame(['profile'], array_values(array_unique(array_column($rows, 'category'))), 'Badge offers must stay in the Store Profile category');
$assertSame(['badge'], array_values(array_unique(array_column($rows, 'subcategory'))), 'Badge offers must use their own Store subcategory');
$assertSame(['badge-spark','badge-crest','badge-pulse'], array_column($rows, 'offer_id'), 'Badge offer ids must stay deterministic');

$metadata = array_map(static fn(array $row): array => json_decode((string)$row['metadata_json'], true, 32, JSON_THROW_ON_ERROR), $rows);
$assertSame(['normal','rare','animated'], array_column($metadata, 'tier'), 'Badge metadata must preserve canonical tier order');
$assertSame(['spark','crest','pulse'], array_column($metadata, 'variant'), 'Badge visual variants must remain deterministic');
$assertSame([false,false,true], array_column($metadata, 'animated'), 'Only the top badge tier is animated');
$assertSame([1000,2500,6000], array_map('intval', array_column($metadata, 'price_coins')), 'Presentation metadata must match server offer prices');

$accounts = new AccountIdentityService($database, 3600);
$account = $accounts->resolveProviderIdentity('development', 'mvp19-3-profile-badges-user', 'browser_dev', ['username'=>'profile-badges'], 'mvp19-3-profile-badges-session');
$mgwId = (string)$account['mgw_id'];
$inventory = new ProductInventoryService($database);
$store = new CosmeticStoreService($database);

$snapshot = $inventory->snapshot($mgwId);
$badgeCatalog = array_values(array_filter((array)($snapshot['catalog'] ?? []), static fn(array $item): bool => ($item['item_family'] ?? '') === 'badge'));
$assertSame(3, count($badgeCatalog), 'Profile inventory catalogue must expose all three badge products for Store discovery');
$assertSame(null, $snapshot['equipped']['profile_badge'] ?? null, 'Fresh account must not have a paid badge selected');

$quote = $store->quote($mgwId, 'badge-spark');
$assertSame(1000, (int)$quote['price_coins'], 'Normal badge quote must remain 1000 coins');
$purchase = $store->fulfill($mgwId, 'mgw:' . $mgwId, 'legacy-profile-badge-user', [
    'request_token' => 'store:mvp19-3-profile-badge-0001',
    'offer_id' => 'badge-spark',
    'price_coins' => $quote['price_coins'],
    'item_ids' => $quote['item_ids'],
]);
$assertSame(false, $purchase['auto_equipped'], 'Badge purchase must never auto-equip');
$assertSame(null, $inventory->snapshot($mgwId)['equipped']['profile_badge'] ?? null, 'Purchased badge must remain inactive until explicit equip');
$inventory->equip($mgwId, 'profile-badge-spark');
$assertSame('profile-badge-spark', $inventory->snapshot($mgwId)['equipped']['profile_badge'] ?? null, 'Explicit badge equip must use ProductInventoryService');

$crestQuote = $store->quote($mgwId, 'badge-crest');
$store->fulfill($mgwId, 'mgw:' . $mgwId, 'legacy-profile-badge-user', [
    'request_token' => 'store:mvp19-3-profile-badge-0002',
    'offer_id' => 'badge-crest',
    'price_coins' => $crestQuote['price_coins'],
    'item_ids' => $crestQuote['item_ids'],
]);
$assertSame('profile-badge-spark', $inventory->snapshot($mgwId)['equipped']['profile_badge'] ?? null, 'Buying another badge must not replace current equipment');
$inventory->equip($mgwId, 'profile-badge-crest');
$assertSame('profile-badge-crest', $inventory->snapshot($mgwId)['equipped']['profile_badge'] ?? null, 'Choosing another badge must replace the previous item in the same slot');
$inventory->unequip($mgwId, 'profile_badge');
$assertTrue(!isset($inventory->snapshot($mgwId)['equipped']['profile_badge']), 'Badge slot must allow a plain identity state after unequip');
$assertStoreError(static fn() => $store->quote($mgwId, 'badge-spark'), 'already_owned', 'Owned badge must reject duplicate purchase without compensation');

$endpoint = (string)file_get_contents($root . '/bot/cosmetic-store.php');
$response = (string)file_get_contents($root . '/bot/helpers/response.php');
$badgeSource = (string)file_get_contents($root . '/app/assets/js/profile/mgw-profile-badges.js');
$badgeCss = (string)file_get_contents($root . '/app/assets/css/components/mgw-profile-badges.css');
$cleanEntry = (string)file_get_contents($root . '/app/assets/js/production-clean-entry-v110.js');
$mainCss = (string)file_get_contents($root . '/app/assets/css/main.css');
$manifest = (string)file_get_contents($root . '/app/runtime/client/version-manifest.php');

$assertTrue(str_contains($endpoint, 'function mgw_store_profile_badge') && str_contains($endpoint, "'profile_badge'"), 'Store mutation endpoint must whitelist the canonical profile badge family and slot');
$assertTrue(str_contains($response, 'pb.item_id AS badge_item_id') && str_contains($response, "\$player['badge_item_id']"), 'Shared game identity projection must expose equipped badges for human players');
$assertTrue(str_contains($badgeSource, "const BADGE_SLOT = 'profile_badge'") && str_contains($badgeSource, 'api.cosmeticStorePurchase') && str_contains($badgeSource, 'api.cosmeticStoreEquip') && str_contains($badgeSource, 'api.cosmeticStoreUnequip'), 'Badge UX must use canonical Store purchase and inventory equip transport');
$assertTrue(str_contains($badgeSource, 'data-profile-badge-store-section') && str_contains($badgeSource, 'data-profile-badge-collection'), 'Badge UX must render Store discovery and Profile owned collection surfaces');
$assertTrue(str_contains($badgeSource, "item.owned === true") && !str_contains($badgeSource, 'ProductInventoryService'), 'Profile badge collection must consume authoritative ownership instead of recreating it');
$assertTrue(str_contains($badgeSource, "String(players[index]?.badge_item_id") && str_contains($badgeSource, 'dataset.profileBadgeAvatarItemId'), 'Live player presentation must consume the canonical badge projection on avatar surfaces');
$assertTrue(str_contains($badgeSource, "getElementById('topAvatar')") && str_contains($badgeSource, "getElementById('profileV2Avatar')") && str_contains($badgeSource, "getElementById('searchMeAvatar')") && str_contains($badgeSource, "querySelector(':scope > .game-player-avatar')"), 'Live badge presentation must target avatars in chrome, Profile, search and games');
$assertTrue(str_contains($badgeSource, 'clearLegacyNameBadge') && !str_contains($badgeSource, 'function applyBadgeAttribute'), 'Nickname elements must not retain the old layout-consuming badge projection');
$assertTrue(str_contains($badgeSource, "const BADGE_PREVIEW_AVATAR = 'starter-default-01'") && str_contains($badgeSource, 'mgw-profile-badge-demo-avatar'), 'Store/Profile badge previews must demonstrate the badge on the canonical starter avatar instead of clipping it inside nickname text');
$assertTrue(str_contains($badgeCss, 'profile-badge-spark') && str_contains($badgeCss, 'profile-badge-crest') && str_contains($badgeCss, 'profile-badge-pulse'), 'One CSS owner must define all three badge visuals');
$assertTrue(str_contains($badgeCss, '[data-profile-badge-avatar-item-id]') && str_contains($badgeCss, 'position:absolute') && str_contains($badgeCss, 'pointer-events:none'), 'Equipped badges must be zero-width avatar overlays');
$assertTrue(str_contains($badgeCss, '.mgw-profile-badge-demo-avatar') && str_contains($badgeCss, '.mgw-profile-badge-demo>strong'), 'Badge demo must keep the icon adjacent to a real avatar/name preview without text clipping');
$assertTrue(str_contains($badgeCss, '@media (prefers-reduced-motion:reduce)') && str_contains($badgeCss, 'animation:none!important'), 'Animated badge must be reduced-motion safe');
$assertTrue(str_contains($cleanEntry, 'initMgwProfileBadges') && str_contains($cleanEntry, 'mgw-profile-badges.js?v=4&mvp19_3=profile-badge-avatar-demo'), 'Active v110 clean entry must initialize the avatar-demo badge presentation build');
$assertTrue(str_contains($mainCss, 'mgw-profile-badges.css?v=3&mvp19_3=profile-badge-avatar-demo') && str_contains($manifest, 'mvp19_3_8=badge-frame-visual-polish'), 'Active v110 delivery graph must carry fresh badge demo JS/CSS identities');

fwrite(STDOUT, "MVP-19.3 profile badges passed ({$assertions} assertions).\n");
