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
require_once $root . '/bot/services/GameReactionService.php';

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
$assertSame(['profile_reaction_set'], array_values(array_unique(array_column($rows, 'equip_slot'))), 'Reaction catalogue keeps the legacy slot for inventory compatibility');
$assertSame(['profile'], array_values(array_unique(array_column($rows, 'item_type'))), 'Reaction products must remain Profile cosmetics');
$assertSame(['reaction'], array_values(array_unique(array_column($rows, 'item_family'))), 'Reaction family must remain isolated');
$assertSame([0,0,0,0,0,0], array_map('intval', array_column($rows, 'starter_grant')), 'Reaction products must not be starter granted');
$assertSame(['profile'], array_values(array_unique(array_column($rows, 'category'))), 'Reaction offers must remain in Profile Store category');
$assertSame(['reaction'], array_values(array_unique(array_column($rows, 'subcategory'))), 'Reaction offers must use reaction subcategory');

$metadata = array_map(static fn(array $row): array => json_decode((string)$row['metadata_json'], true, 32, JSON_THROW_ON_ERROR), $rows);
$assertSame([1,1,1,1,4,8], array_map(static fn(array $item): int => count((array)($item['reactions'] ?? [])), $metadata), 'Reaction products must expose deterministic member counts');
$assertSame([500,500,500,500,1500,3500], array_map(static fn(array $item): int => (int)($item['price_coins'] ?? 0), $metadata), 'Reaction metadata prices must match offers');

$accounts = new AccountIdentityService($database, 3600);
$account = $accounts->resolveProviderIdentity('development', 'mvp19-3-profile-reactions-user', 'browser_dev', ['username'=>'profile-reactions'], 'mvp19-3-profile-reactions-session');
$mgwId = (string)$account['mgw_id'];
$inventory = new ProductInventoryService($database);
$store = new CosmeticStoreService($database);
$reactionServiceObject = new GameReactionService([], $database);

$snapshot = $inventory->snapshot($mgwId);
$reactionCatalog = array_values(array_filter((array)($snapshot['catalog'] ?? []), static fn(array $item): bool => ($item['item_family'] ?? '') === 'reaction'));
$assertSame(6, count($reactionCatalog), 'Inventory snapshot must expose every reaction product');
$assertSame([], $reactionServiceObject->allowedReactionCodes($mgwId), 'Fresh account must have no usable reactions');

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
$assertSame(['wave'], $reactionServiceObject->allowedReactionCodes($mgwId), 'Purchased single reaction must become usable immediately without equip');

$packQuote = $store->quote($mgwId, 'reaction-pack-4');
$assertSame(1500, (int)$packQuote['price_coins'], 'Pack-4 quote must cost 1500 coins');
$store->fulfill($mgwId, 'mgw:' . $mgwId, 'legacy-profile-reaction-user', [
    'request_token' => 'store:mvp19-3-profile-reaction-0002',
    'offer_id' => 'reaction-pack-4',
    'price_coins' => $packQuote['price_coins'],
    'item_ids' => $packQuote['item_ids'],
]);
$assertSame(['wave','clap','heart','fire'], $reactionServiceObject->allowedReactionCodes($mgwId), 'Purchased reactions must accumulate into one usable palette with duplicates removed');
$assertSame(null, $inventory->snapshot($mgwId)['equipped']['profile_reaction_set'] ?? null, 'Cumulative reaction ownership must not create an equipped-set state');

$reactionUi = (string)file_get_contents($root . '/app/assets/js/profile/mgw-profile-reactions.js');
$reactionHeader = (string)file_get_contents($root . '/app/assets/js/profile/mgw-profile-reactions-header.js');
$reactionCss = (string)file_get_contents($root . '/app/assets/css/production-v97-reactions.css');
$reactionPolishCss = (string)file_get_contents($root . '/app/assets/css/production-v99-profile-reaction-polish.css');
$reactionPerfCss = (string)file_get_contents($root . '/app/assets/css/production-v98-mobile-profile-perf.css');
$reactionService = (string)file_get_contents($root . '/bot/services/GameReactionService.php');
$watcher = (string)file_get_contents($root . '/app/assets/js/production-v110-readonly-game-sync.js');
$profileScreen = (string)file_get_contents($root . '/app/assets/js/screens/profile-screen-v110.js');
$shell = (string)file_get_contents($root . '/app/assets/js/main-v110-handoff-shell.js');
$router = (string)file_get_contents($root . '/app/assets/js/router.js');
$manifest = (string)file_get_contents($root . '/app/runtime/client/version-manifest.php');
$assertTrue(str_contains($reactionUi, "const REACTION_SLOT = 'profile_reaction_set'") && str_contains($reactionUi, 'api.cosmeticStorePurchase'), 'Reaction UI must reuse canonical Store purchase flow while keeping inventory compatibility');
$assertTrue(str_contains($reactionUi, 'ownedReactionCodes') && !str_contains($reactionUi, 'api.profileReactionEquip') && !str_contains($reactionUi, 'api.profileReactionUnequip'), 'Reaction UI must derive one cumulative palette from ownership instead of selecting one set');
$assertTrue(str_contains($reactionUi, 'store-v2-reaction-owned') && !str_contains($reactionUi, 'data-reaction-equip') && !str_contains($reactionUi, 'data-reaction-unequip'), 'Owned Store reactions must be passive collection state, never Select/Remove actions');
$assertTrue(str_contains($reactionUi, 'data-profile-reaction-store-section') && str_contains($reactionUi, 'data-profile-reaction-collection'), 'Reaction UI must provide Store and Profile collection surfaces');
$assertTrue(str_contains($reactionUi, 'data-send-reaction') && str_contains($reactionUi, 'mgw:game-reaction'), 'Reaction UI must expose a match composer and consume realtime presentation events');
$assertTrue(str_contains($reactionService, "public const SLOT = 'profile_reaction_set'") && str_contains($reactionService, '$allowed[$code] = true') && str_contains($reactionService, 'ProductInventoryService'), 'Reaction delivery must union all owned reaction products through canonical inventory');
$assertTrue(str_contains($reactionService, 'COOLDOWN_MS = 900') && str_contains($reactionService, 'EVENT_TTL_MS = 5000'), 'Reaction delivery must be bounded and ephemeral');
$assertTrue(str_contains($watcher, "document.addEventListener('mgw:app-ready', initMgwProfileReactions") && str_contains($watcher, "new CustomEvent('mgw:game-reaction'"), 'Reaction UI must initialize after app-ready and reuse the existing read-only watcher');
$assertTrue(!str_contains($reactionUi, '!game?.is_bot_game') && !str_contains($reactionService, "if (!empty(\$game['is_bot_game']))"), 'Owned reactions must remain available in active bot matches without adding bot response ownership');
$assertTrue(str_contains($reactionService, 'activeGameForParticipant') && !str_contains($reactionService, 'activeHumanGameForParticipant'), 'Reaction participant validation must not encode a human-only product restriction');
$assertTrue(str_contains($reactionUi, 'aria-label="Реакции"') && !str_contains($reactionUi, '> Реакция</button>'), 'Match launcher must be an icon-only compact reaction control');
$assertTrue(str_contains($reactionUi, ':scope > .game-player-avatar') && str_contains($reactionUi, '--mgw-reaction-origin-x'), 'Live reaction must calculate its visual origin from the sender avatar when present');
$assertTrue(str_contains($reactionUi, 'lastReactionFingerprint') && str_contains($reactionUi, 'bubble.remove(), 2400'), 'Live reaction delivery must suppress duplicate projection and keep the bubble readable long enough');
$assertTrue(str_contains($reactionUi, "bubble.style.animationPlayState = 'paused'") && str_contains($reactionUi, 'void bubble.offsetWidth') && str_contains($reactionUi, "bubble.style.animationPlayState = 'running'") && substr_count($reactionUi, 'window.requestAnimationFrame(() => {') >= 2, 'First live reaction must be compositor-primed before the accepted animation starts');
$assertTrue(!str_contains($reactionUi, 'primeProfileNavPressFeedback') && !str_contains($reactionUi, "addEventListener('pointerdown'") && !str_contains($reactionHeader, 'suppressLegacyMobileProfilePressFeedback') && !str_contains($reactionHeader, "addEventListener('pointerdown'"), 'Profile bottom navigation must have no reaction-owned pointerdown interception or synthetic press state');
$assertTrue(str_contains($reactionUi, "if (from === 'game' || to === 'game') scheduleDecorate();") && str_contains($reactionHeader, "if (from === 'game' || to === 'game') queueMicrotask(syncReactionHeader);"), 'Reaction route work must be scoped to actual game enter/leave and stay off shell Profile transitions');
$assertTrue(str_contains($reactionHeader, 'stabilizeMobileReactionBubbles') && str_contains($reactionHeader, 'data-mobile-stable-reaction') && str_contains($reactionHeader, 'screen.append(bubble)'), 'Mobile live reactions must be reparented to the stable game screen so player-card remounts cannot erase the first delivery');
$assertTrue(str_contains($reactionCss, 'width:28px') && str_contains($reactionCss, 'width:31px') && str_contains($reactionCss, 'mgwReactionFromSender'), 'Base match reaction trigger, palette items and sender-origin animation must stay compact');
$assertTrue(str_contains($reactionPolishCss, 'data-reaction-count="4"') && str_contains($reactionPolishCss, 'data-reaction-count="8"') && str_contains($reactionPolishCss, 'max-width: 100%') && str_contains($reactionPolishCss, 'border-color: transparent'), 'Four/eight reaction Store previews must stay bounded and lose the redundant nested frame');
$assertTrue(str_contains($reactionPolishCss, '.store-v2-reaction-owned') && str_contains($reactionPolishCss, 'min-height: 11px'), 'Owned state and single-line cards must avoid empty subtitle space while preserving card alignment');
$assertTrue(str_contains($reactionPerfCss, 'width:32px') && str_contains($reactionPerfCss, 'width:31px') === false && str_contains($reactionPerfCss, 'border-radius:8px') && str_contains($reactionPerfCss, 'linear-gradient(180deg,#25202D,#12151C)') && str_contains($reactionPerfCss, 'transform:translate(-1px,-1px) !important') && str_contains($reactionPerfCss, '2.35s'), 'Header reaction/rules framing, metallic parity and smooth motion must stay published');
$assertTrue(str_contains($reactionPerfCss, '#app #screen-profile.screen > .content') && str_contains($reactionPerfCss, 'transform:translate3d(0,10px,0);') && str_contains($reactionPerfCss, 'transition:opacity .24s var(--sk-ease-standard)!important;') && !str_contains($reactionPerfCss, '#screen-profile.active .profile-v2 > .profile-v2-section') && !str_contains($reactionPerfCss, '#app[data-shell-screen="profile"]') && !str_contains($reactionPerfCss, '#screen-profile.active[data-profile-background-item-id'), 'Mobile Profile must animate the existing scroll viewport instead of the heavy premium surface and must not invalidate its long subtree on route class changes');
$assertTrue(str_contains($reactionPerfCss, '#app.has-shell-chrome .app-shell-topbar') && str_contains($reactionPerfCss, 'content-visibility:auto !important;') && str_contains($reactionPerfCss, 'mgwMobileProfileAtmosphere 14s'), 'Mobile shell material, offscreen containment and premium atmosphere must remain stable across Profile enter/leave');
$assertTrue(str_contains($reactionPerfCss, '#app #screen-profile.screen:not(.active)') && str_contains($reactionPerfCss, 'opacity:.001!important;') && str_contains($reactionPerfCss, '#app > .screen.active') && str_contains($reactionPerfCss, 'z-index:2;'), 'Mobile Profile must keep a practically invisible prepainted first viewport below the active route instead of paying the full first-raster cost on tap');
$assertTrue(str_contains($reactionPerfCss, '#screen-profile.screen.mgw-profile-prewarm-pass:not(.active)') && str_contains($reactionPerfCss, 'opacity:1!important;') && str_contains($reactionPerfCss, 'z-index:3!important;'), 'Covered mobile prewarm must force a real final Profile raster below the preloader before first user navigation');
$assertTrue(str_contains($profileScreen, "showScreen('profile');") && str_contains($profileScreen, 'if (hasCachedProfileDom) return;'), 'Profile entry must paint the pre-rendered hidden DOM before any fallback long render work');
$bootPos = strpos($shell, 'async function boot(){');
$profileInitPos = strpos($shell, 'initProfileScreen();');
$assertTrue($bootPos !== false && $profileInitPos !== false && $profileInitPos > $bootPos && substr_count($shell, 'initProfileScreen();') === 1, 'Profile must initialize once from authoritative boot state instead of warming before boot');
$assertTrue(str_contains($shell, 'showScreen(route);') && !str_contains($shell, "new CustomEvent('mgw:open-profile')"), 'Bottom Profile navigation must use the same canonical showScreen route path as every other shell tab');
$assertTrue(!str_contains($router, 'deferProfileTransition') && !str_contains($router, 'flushDeferredProfileTransition') && str_contains($router, "dispatchScreenChanged({ from:previous, to:next });"), 'All shell routes including Profile must dispatch synchronously without a delayed event that can stall the next tab tap');
$assertTrue(str_contains($shell, 'shouldPrimeMobileProfile') && str_contains($shell, 'initMgwProfileBackgrounds();') && str_contains($shell, 'await api.profileV2();') && str_contains($shell, "classList.add('mgw-profile-prewarm-pass')") && str_contains($shell, 'window.requestAnimationFrame(() => {'), 'First mobile Profile presentation must complete background hydration and a real covered raster pass before reveal');
$assertTrue(str_contains($shell, "if (String(result?.active_game?.id || '').trim()) return false;"), 'Profile first-open prewarm must stay off active-game reloads');
$assertTrue(str_contains($manifest, 'mgw-profile-reactions-header.js?v=5&mvp19_3=header-square-smooth&mobile=stable-bubble-canonical-profile-nav&profile_nav=canonical-pointer-v2') && str_contains($manifest, 'mgw-profile-reactions.js?v=6&mvp19_3=cumulative-owned-reactions&store=passive-owned&preview=bounded-packs-v2&route_work=game-only-v1') && str_contains($manifest, 'router.js?v=32&profile_nav=direct-sync-v2') && str_contains($manifest, 'production-v99-profile-reaction-polish.css?v=2&mvp19_3=cumulative-reaction-cards') && str_contains($manifest, 'main-v110-handoff-shell.js?v=1155&mvp18=friend-request-lifecycle&store=post-boot-warm&profile=boot-prepared-direct-route-v1&profile_first=background-raster-prewarm-v1') && str_contains($manifest, 'entry=paint-cached-first-v1'), 'Cumulative reactions and direct mobile shell routing must be cache-published without changing accepted Profile preparation');

fwrite(STDOUT, "MVP-19.3 profile reactions passed ({$assertions} assertions).\n");
