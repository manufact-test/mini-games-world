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

if (!extension_loaded('pdo_sqlite')) throw new RuntimeException('MVP-19.3 profile frame test requires pdo_sqlite.');

$assertions = 0;
$assertSame = static function (mixed $expected, mixed $actual, string $message) use (&$assertions): void {
    $assertions++;
    if ($expected !== $actual) throw new RuntimeException($message . ': expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
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
     WHERE c.item_family = 'frame' AND c.catalog_status = 'active' AND o.offer_status = 'active'
     ORDER BY o.sort_order ASC"
);
$assertSame(4, count($rows), 'Frame launch catalogue must contain exactly four active items');
$assertSame([2500,5000,8000,12000], array_map('intval', array_column($rows, 'price_coins')), 'Frame prices must match canonical MVP-19.3 tiers');
$assertSame(['profile_frame'], array_values(array_unique(array_column($rows, 'equip_slot'))), 'All frames must share one mutually exclusive profile slot');
$assertSame(['profile'], array_values(array_unique(array_column($rows, 'item_type'))), 'Frames must remain profile products');
$assertSame(['frame'], array_values(array_unique(array_column($rows, 'item_family'))), 'Frame family must remain isolated');
$assertSame([0,0,0,0], array_map('intval', array_column($rows, 'starter_grant')), 'Paid frames must never be starter granted');
$assertSame(['profile'], array_values(array_unique(array_column($rows, 'category'))), 'Frame offers must stay in Store Profile category');
$assertSame(['frame'], array_values(array_unique(array_column($rows, 'subcategory'))), 'Frame offers must use their own Store subcategory');
$assertSame(['frame-01','frame-02','frame-03','frame-animated'], array_column($rows, 'offer_id'), 'Frame offer ids must remain deterministic');

$metadata = array_map(static fn(array $row): array => json_decode((string)$row['metadata_json'], true, 32, JSON_THROW_ON_ERROR), $rows);
$assertSame(['normal','rare','epic','animated'], array_column($metadata, 'tier'), 'Frame tier order must remain deterministic');
$assertSame([false,false,false,true], array_column($metadata, 'animated'), 'Only the top 12000 frame tier is animated');
$assertSame([2500,5000,8000,12000], array_map('intval', array_column($metadata, 'price_coins')), 'Presentation metadata must match server offer prices');
$assertSame(['Небо','Золото','Аврора','Спектр'], array_column($metadata, 'display_name'), 'Frame product names must replace placeholder Roman numerals with concise user-facing names');

$accounts = new AccountIdentityService($database, 3600);
$account = $accounts->resolveProviderIdentity('development', 'mvp19-3-profile-frames-user', 'browser_dev', ['username'=>'profile-frames'], 'mvp19-3-profile-frames-session');
$mgwId = (string)$account['mgw_id'];
$inventory = new ProductInventoryService($database);
$store = new CosmeticStoreService($database);

$snapshot = $inventory->snapshot($mgwId);
$frameCatalog = array_values(array_filter((array)($snapshot['catalog'] ?? []), static fn(array $item): bool => ($item['item_family'] ?? '') === 'frame'));
$assertSame(4, count($frameCatalog), 'Profile inventory catalogue must expose all four frame products');
$assertSame(null, $snapshot['equipped']['profile_frame'] ?? null, 'Fresh account must not have a paid frame selected');

$quote = $store->quote($mgwId, 'frame-01');
$assertSame(2500, (int)$quote['price_coins'], 'First frame quote must remain 2500 coins');
$purchase = $store->fulfill($mgwId, 'mgw:' . $mgwId, 'legacy-profile-frame-user', [
    'request_token' => 'store:mvp19-3-profile-frame-0001',
    'offer_id' => 'frame-01',
    'price_coins' => $quote['price_coins'],
    'item_ids' => $quote['item_ids'],
]);
$assertSame(false, $purchase['auto_equipped'], 'Frame purchase must never auto-equip');
$assertSame(null, $inventory->snapshot($mgwId)['equipped']['profile_frame'] ?? null, 'Purchased frame must remain inactive until explicit equip');
$inventory->equip($mgwId, 'profile-frame-01');
$assertSame('profile-frame-01', $inventory->snapshot($mgwId)['equipped']['profile_frame'] ?? null, 'Explicit frame equip must use ProductInventoryService');

$secondQuote = $store->quote($mgwId, 'frame-02');
$store->fulfill($mgwId, 'mgw:' . $mgwId, 'legacy-profile-frame-user', [
    'request_token' => 'store:mvp19-3-profile-frame-0002',
    'offer_id' => 'frame-02',
    'price_coins' => $secondQuote['price_coins'],
    'item_ids' => $secondQuote['item_ids'],
]);
$assertSame('profile-frame-01', $inventory->snapshot($mgwId)['equipped']['profile_frame'] ?? null, 'Buying another frame must not replace current equipment');
$inventory->equip($mgwId, 'profile-frame-02');
$assertSame('profile-frame-02', $inventory->snapshot($mgwId)['equipped']['profile_frame'] ?? null, 'Choosing another frame must replace previous frame in same slot');
$inventory->unequip($mgwId, 'profile_frame');
$assertTrue(!isset($inventory->snapshot($mgwId)['equipped']['profile_frame']), 'Frame slot must allow no-frame state after unequip');
$assertStoreError(static fn() => $store->quote($mgwId, 'frame-01'), 'already_owned', 'Owned frame must reject duplicate purchase without compensation');

$endpoint = (string)file_get_contents($root . '/bot/cosmetic-store.php');
$response = (string)file_get_contents($root . '/bot/helpers/response.php');
$apiClient = (string)file_get_contents($root . '/app/assets/js/api/client.js');
$frameSource = (string)file_get_contents($root . '/app/assets/js/profile/mgw-profile-frames.js');
$frameCss = (string)file_get_contents($root . '/app/assets/css/components/mgw-profile-frames.css');
$cleanEntry = (string)file_get_contents($root . '/app/assets/js/production-clean-entry-v110.js');
$mainCss = (string)file_get_contents($root . '/app/assets/css/main.css');
$manifest = (string)file_get_contents($root . '/app/runtime/client/version-manifest.php');

$assertTrue(str_contains($endpoint, 'function mgw_store_profile_frame') && str_contains($endpoint, "'profile_frame'"), 'Store mutation endpoint must whitelist canonical profile frame family and slot');
$assertTrue(str_contains($response, 'pf.item_id AS frame_item_id') && str_contains($response, "\$player['frame_item_id']"), 'Shared game identity projection must expose equipped frames for human players');
$assertTrue(str_contains($frameSource, "const FRAME_SLOT = 'profile_frame'") && str_contains($frameSource, 'api.cosmeticStorePurchase') && str_contains($frameSource, 'api.cosmeticStoreEquip') && str_contains($frameSource, 'api.cosmeticStoreUnequip'), 'Frame UX must use canonical Store purchase and inventory equip transport');
$assertTrue(str_contains($frameSource, 'data-profile-frame-store-section') && str_contains($frameSource, 'data-profile-frame-collection'), 'Frame UX must render Store discovery and Profile owned collection surfaces');
$assertTrue(str_contains($frameSource, "String(players[index]?.frame_item_id") && str_contains($frameSource, 'dataset.profileFrameAvatarItemId'), 'Live game presentation must consume canonical frame projection on avatar surfaces');
$assertTrue(str_contains($frameSource, "getElementById('topAvatar')") && str_contains($frameSource, "getElementById('profileV2Avatar')") && str_contains($frameSource, "getElementById('searchMeAvatar')") && str_contains($frameSource, "querySelector(':scope > .game-player-avatar')"), 'Live frame presentation must cover chrome, Profile, search and game avatars');
$assertTrue(str_contains($frameSource, "const FRAME_PREVIEW_AVATAR = 'starter-default-01'") && str_contains($frameSource, 'mgw-profile-frame-avatar') && str_contains($frameSource, 'data-avatar-item-id'), 'Frame cards must demonstrate every frame on the canonical starter avatar');
$assertTrue(str_contains($frameCss, '[data-profile-frame-avatar-item-id]::before') && str_contains($frameCss, 'profile-frame-animated'), 'One CSS owner must define zero-width avatar frames and animated top tier');
$assertTrue(str_contains($frameCss, '.mgw-profile-frame-avatar') && str_contains($frameCss, '.mgw-profile-frame-preview'), 'Frame preview CSS must render the real avatar inside each frame sample');
$assertTrue(str_contains($frameCss, '@media (prefers-reduced-motion:reduce)') && str_contains($frameCss, 'animation:none!important'), 'Animated frame must be reduced-motion safe');
$assertTrue(str_contains($cleanEntry, 'initMgwProfileFrames') && str_contains($cleanEntry, 'mgw-profile-frames.js?v=2&mvp19_3=profile-frame-avatar-demo'), 'Active clean entry must initialize polished profile frame previews');
$assertTrue(str_contains($apiClient, 'let profileV2ReadPromise = null;') && str_contains($apiClient, 'if (profileV2ReadPromise) return profileV2ReadPromise;') && str_contains($apiClient, '.finally(() => { profileV2ReadPromise = null; });'), 'Concurrent read-only Profile v2 hydration must coalesce to one in-flight request');
$assertTrue(str_contains($apiClient, "return requestUrl(PROFILE_V2_URL, { profile_update:profileUpdate });"), 'Profile mutations must bypass the read-only hydration coalescer');
$assertTrue(str_contains($mainCss, 'mgw-profile-frames.css?v=2&mvp19_3=profile-frame-avatar-demo') && str_contains($manifest, 'mvp19_3_8=badge-frame-visual-polish') && str_contains($manifest, 'mvp19_3_7=profile-v2-read-coalesce'), 'Active delivery graph must carry fresh profile-frame JS/CSS and coalesced Profile v2 client identities');

fwrite(STDOUT, "MVP-19.3 profile frames passed ({$assertions} assertions).\n");
