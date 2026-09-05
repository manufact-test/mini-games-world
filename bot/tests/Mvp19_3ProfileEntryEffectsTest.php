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

if (!extension_loaded('pdo_sqlite')) throw new RuntimeException('MVP-19.3 Entry Effects test requires pdo_sqlite.');

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
     WHERE c.item_family = 'entry_effect' AND c.catalog_status = 'active' AND o.offer_status = 'active'
     ORDER BY o.sort_order ASC"
);
$assertSame(3, count($rows), 'Entry Effects catalogue must expose exactly three launch tiers');
$assertSame([4000,7500,12000], array_map('intval', array_column($rows, 'price_coins')), 'Entry Effect prices must match canonical tiers');
$assertSame(['profile_entry_effect'], array_values(array_unique(array_column($rows, 'equip_slot'))), 'Entry Effects must share one active slot');
$assertSame(['profile'], array_values(array_unique(array_column($rows, 'item_type'))), 'Entry Effects must remain Profile cosmetics');
$assertSame(['entry_effect'], array_values(array_unique(array_column($rows, 'item_family'))), 'Entry Effects must remain an isolated family');
$assertSame([0,0,0], array_map('intval', array_column($rows, 'starter_grant')), 'Entry Effects must not be starter-granted');
$assertSame(['profile'], array_values(array_unique(array_column($rows, 'category'))), 'Entry Effect offers must stay in Profile Store');
$assertSame(['entry_effect'], array_values(array_unique(array_column($rows, 'subcategory'))), 'Entry Effect offer subcategory must be stable');

$metadata = array_map(static fn(array $row): array => json_decode((string)$row['metadata_json'], true, 32, JSON_THROW_ON_ERROR), $rows);
$assertSame([2400,3000,3600], array_map(static fn(array $item): int => (int)($item['duration_ms'] ?? 0), $metadata), 'Entry Effect durations must stay inside the canonical 2-4 second window');
$assertSame(['entry-01','entry-02','entry-03'], array_map(static fn(array $item): string => (string)($item['variant'] ?? ''), $metadata), 'Entry Effect variants must be deterministic');

$accounts = new AccountIdentityService($database, 3600);
$account = $accounts->resolveProviderIdentity('development', 'mvp19-3-entry-effects-user', 'browser_dev', ['username'=>'entry-effects'], 'mvp19-3-entry-effects-session');
$mgwId = (string)$account['mgw_id'];
$inventory = new ProductInventoryService($database);
$store = new CosmeticStoreService($database);

$snapshot = $inventory->snapshot($mgwId);
$assertSame(null, $snapshot['equipped']['profile_entry_effect'] ?? null, 'Fresh account must have no Entry Effect selected');

$quote = $store->quote($mgwId, 'entry-effect-01');
$assertSame(4000, (int)$quote['price_coins'], 'Tier I quote must cost 4000 coins');
$purchase = $store->fulfill($mgwId, 'mgw:' . $mgwId, 'legacy-entry-effect-user', [
    'request_token' => 'store:mvp19-3-entry-effect-0001',
    'offer_id' => 'entry-effect-01',
    'price_coins' => $quote['price_coins'],
    'item_ids' => $quote['item_ids'],
]);
$assertSame(false, $purchase['auto_equipped'], 'Buying an Entry Effect must never auto-equip it');
$assertSame(null, $inventory->snapshot($mgwId)['equipped']['profile_entry_effect'] ?? null, 'Purchase must not select an Entry Effect');
$inventory->equip($mgwId, 'profile-entry-effect-01');
$assertSame('profile-entry-effect-01', $inventory->snapshot($mgwId)['equipped']['profile_entry_effect'] ?? null, 'Explicit Entry Effect equip must use canonical inventory');

$quote2 = $store->quote($mgwId, 'entry-effect-02');
$store->fulfill($mgwId, 'mgw:' . $mgwId, 'legacy-entry-effect-user', [
    'request_token' => 'store:mvp19-3-entry-effect-0002',
    'offer_id' => 'entry-effect-02',
    'price_coins' => $quote2['price_coins'],
    'item_ids' => $quote2['item_ids'],
]);
$assertSame('profile-entry-effect-01', $inventory->snapshot($mgwId)['equipped']['profile_entry_effect'] ?? null, 'Buying another Entry Effect must not replace current equipment');
$inventory->equip($mgwId, 'profile-entry-effect-02');
$assertSame('profile-entry-effect-02', $inventory->snapshot($mgwId)['equipped']['profile_entry_effect'] ?? null, 'One Entry Effect slot must replace the previous selection');
$inventory->unequip($mgwId, 'profile_entry_effect');
$assertTrue(!isset($inventory->snapshot($mgwId)['equipped']['profile_entry_effect']), 'Entry Effect slot must support explicit remove');

$storeEndpoint = (string)file_get_contents($root . '/bot/cosmetic-store.php');
$responseProjection = (string)file_get_contents($root . '/bot/helpers/response.php');
$entryUi = (string)file_get_contents($root . '/app/assets/js/profile/mgw-profile-entry-effects.js');
$entryCss = (string)file_get_contents($root . '/app/assets/css/components/mgw-profile-entry-effects.css');
$acceptanceCss = (string)file_get_contents($root . '/app/assets/css/production-v101-entry-effects-acceptance-corrective.css');
$gameEntry = (string)file_get_contents($root . '/app/assets/js/screens/game-screen-v102-safe.js');
$watcher = (string)file_get_contents($root . '/app/assets/js/production-v110-readonly-game-sync.js');
$manifest = (string)file_get_contents($root . '/app/runtime/client/version-manifest.php');

$assertTrue(str_contains($storeEndpoint, 'function mgw_store_profile_entry_effect') && str_contains($storeEndpoint, "'profile_entry_effect'"), 'Canonical Store endpoint must whitelist only the Entry Effect profile slot');
$assertTrue(str_contains($responseProjection, 'entry_effect_item_id') && str_contains($responseProjection, "e.equip_slot = \\'profile_entry_effect\\'"), 'Public game identity projection must carry equipped Entry Effects for player presentation');
$assertTrue(str_contains($entryUi, "const ENTRY_EFFECT_SLOT = 'profile_entry_effect'") && str_contains($entryUi, 'api.cosmeticStorePurchase') && str_contains($entryUi, 'api.cosmeticStoreEquip'), 'Entry Effect UI must reuse canonical Store purchase/equip owners');
$assertTrue(str_contains($entryUi, 'playedGames') && str_contains($entryUi, 'mgw-entry-effect-skip') && str_contains($entryUi, 'Math.min(4000, Math.max(2000, duration))'), 'Live Entry Effects must be once-per-game, skippable and bounded to 2-4 seconds');
$assertTrue(str_contains($gameEntry, "new CustomEvent('mgw:game-entered'") && str_contains($gameEntry, 'detail:{ game:state.activeGame, me }'), 'Canonical game entry must publish exact adopted game and viewer identity after enterBaseGame');
$assertTrue(str_contains($entryUi, "document.addEventListener('mgw:game-entered'") && str_contains($entryUi, "event?.detail?.me?.id"), 'Entry Effect presentation must consume the exact game-entry viewer handoff instead of guessing the local player');
$assertTrue(str_contains($entryUi, 'applyLocalEntryEffectProjection(game, viewerId') && str_contains($entryUi, 'player.entry_effect_item_id = selected') && str_contains($entryUi, 'entryEffectIdForPlayer(player, viewerId'), 'Canonical Entry Effect owner must preserve server projection first and repair only a missing local projection from selected Profile inventory');
$assertTrue(str_contains($entryUi, "mgw:phase-b-game-entering") && str_contains($entryUi, "mgw:screen-changed"), 'Canonical Entry Effect owner must preserve same-route Phase-B and route fallback coverage');
$assertTrue(str_contains($entryUi, 'function launchOverlayVisible()') && str_contains($entryUi, ".mgw-phase-b-launch-overlay") && str_contains($entryUi, 'if (launchOverlayVisible()) return;'), 'Entry Effect must wait until the accepted Phase-B launch overlay is hidden before consuming its once-per-game presentation');
$assertTrue(str_contains($entryUi, 'Date.now() >= deadline && !waitingForLaunch'), 'Entry Effect probe must keep waiting past its ordinary deadline while the launch overlay still owns the screen');
$assertTrue(!str_contains($entryUi, 'profile-v2-entry-effect-status'), 'Profile Entry Effect cards must match other selected Profile cards: check/border only, no inline Selected status row');
$assertTrue(str_contains($entryCss, '@media(prefers-reduced-motion:reduce)') && str_contains($entryCss, 'pointer-events:none'), 'Entry Effect presentation must be reduced-motion safe and non-blocking');
$assertTrue(str_contains($acceptanceCss, '#screen-profile .profile-v2-game-collection') && str_contains($acceptanceCss, '#screen-profile .profile-v2-game-card'), 'Profile corrective must carry the shared section/card rhythm through the Games collection too');
$assertTrue(str_contains($acceptanceCss, 'margin-top:20px !important') && str_contains($acceptanceCss, 'padding-top:14px !important') && str_contains($acceptanceCss, 'gap:8px !important') && str_contains($acceptanceCss, 'margin-bottom:0 !important'), 'Every post-Avatar Profile collection must share one section rhythm with no family-owned bottom margin');
$assertTrue(str_contains($acceptanceCss, 'padding:5px 5px 10px !important'), 'Every text-bearing Profile cosmetic card must reserve the same explicit bottom breathing room');
$assertTrue(str_contains($watcher, "document.addEventListener('mgw:app-ready', initMgwProfileEntryEffects") && str_contains($watcher, "mgw-profile-entry-effects.js?v=1&mvp19_3=entry-effects"), 'Shared runtime must initialize Entry Effects after app-ready');
$assertTrue(str_contains($manifest, 'mgw-profile-entry-effects.js?v=5&mvp19_3=launch-overlay-gated') && str_contains($manifest, 'entry_effect_handoff=1') && str_contains($manifest, 'profile-bottom-spacing-parity-v3'), 'Active v110 manifest must cache-publish launch-gated Entry Effects plus the final Profile bottom-spacing corrective');

fwrite(STDOUT, "MVP-19.3 Profile Entry Effects passed ({$assertions} assertions).\n");