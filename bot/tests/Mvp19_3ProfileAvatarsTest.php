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

if (!extension_loaded('pdo_sqlite')) throw new RuntimeException('MVP-19.3 test requires pdo_sqlite.');

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

$pdo = new PDO('sqlite::memory:');
$pdo->exec('PRAGMA foreign_keys = ON');
$database = new PdoDatabaseConnection($pdo);
$runner = new MigrationRunner($database, $databaseDir . '/migrations');
$migration = $runner->migrate(false);
$expectedMigrations = count(glob($databaseDir . '/migrations/*.php') ?: []);
$assertSame($expectedMigrations, (int)$migration['executed_count'], 'Fixture must execute the complete current migration set without a fixed historical count');

$accounts = new AccountIdentityService($database, 3600);
$account = $accounts->resolveTelegramUser([
    'id' => '19030001',
    'first_name' => 'Avatar Profile Test',
    'username' => 'avatar_profile_test',
], 'mvp19-3-session-a');
$mgwId = (string)$account['mgw_id'];
$inventory = new ProductInventoryService($database);
$profiles = new MgwProfileService($database);

$initial = $inventory->snapshot($mgwId);
$assertSame(3, count($initial['owned']), 'Fresh account must expose all three starter avatars in canonical inventory');
$assertSame('starter-default-01', $initial['equipped']['profile_avatar'] ?? null, 'Fresh account must keep starter-default-01 equipped');

$grant = $inventory->grant($mgwId, 'store-avatar-03', 'mvp19_3_test', 'paid-avatar-proof');
$assertSame(true, $grant['granted'] ?? false, 'Purchased/rewarded paid avatar must become permanent owned inventory');
$afterGrant = $inventory->snapshot($mgwId);
$assertSame(4, count($afterGrant['owned']), 'Profile inventory must contain three starters plus newly owned paid avatar');
$paidCatalog = null;
foreach ($afterGrant['catalog'] as $catalogItem) {
    if ((string)($catalogItem['item_id'] ?? '') === 'store-avatar-03') {
        $paidCatalog = $catalogItem;
        break;
    }
}
$assertSame(true, (bool)($paidCatalog['owned'] ?? false), 'Canonical snapshot must mark the newly granted paid avatar as owned');

$updated = $profiles->updateProfile($mgwId, ['avatar_item_id' => 'store-avatar-03']);
$assertSame('store-avatar-03', $updated['avatar']['item_id'] ?? null, 'Profile explicit equip must accept an owned paid avatar');
$afterEquip = $inventory->snapshot($mgwId);
$assertSame('store-avatar-03', $afterEquip['equipped']['profile_avatar'] ?? null, 'Canonical equipment must match paid avatar selection');
$assertSame('store-avatar-03', $profiles->publicProfile($mgwId)['avatar']['item_id'] ?? null, 'Public profile must expose the same equipped paid avatar');

$profileEndpoint = (string)file_get_contents($root . '/bot/profile-v2.php');
$profileClient = (string)file_get_contents($root . '/app/assets/js/screens/profile-screen-v110.js');
$storeClient = (string)file_get_contents($root . '/app/assets/js/screens/store-screen.js');
$stateClient = (string)file_get_contents($root . '/app/assets/js/state.js');
$profileUi = (string)file_get_contents($root . '/app/assets/js/ui.js');
$responseHelper = (string)file_get_contents($root . '/bot/helpers/response.php');
$avatarPresentation = (string)file_get_contents($root . '/app/assets/js/profile/mgw-avatar-presentation.js');
$avatarCss = (string)file_get_contents($root . '/app/assets/css/components/mgw-avatars.css');
$cleanEntry = (string)file_get_contents($root . '/app/assets/js/production-clean-entry-v110.js');
$manifest = require $root . '/app/runtime/client/version-manifest.php';

$assertTrue(str_contains($profileEndpoint, "'inventory'=>\$inventory"), 'Profile v2 must return canonical inventory snapshot');
$assertTrue(str_contains($profileEndpoint, 'ProductInventoryService($database))->snapshot'), 'Profile endpoint must consume canonical inventory owner');
$assertTrue(!str_contains($profileClient, 'STARTER_AVATARS'), 'Profile client must not remain starter-only after MVP-19.3');
$assertTrue(str_contains($profileClient, 'ownedAvatarItems'), 'Profile client must render owned avatar collection');
$assertTrue(str_contains($profileClient, 'data-profile-avatar-preview'), 'Owned avatar click must open preview before equip');
$assertTrue(str_contains($profileClient, "api.profileV2({ avatar_item_id:itemId })"), 'Explicit equip must continue through canonical Profile API');
$assertTrue(str_contains($profileClient, "'store-avatar-05'"), 'Launch order must include all five paid avatar IDs');

$assertTrue(str_contains($stateClient, 'selectedAvatarId: null'), 'Client state must expose one explicit selectedAvatarId owner');
$assertTrue(str_contains($profileClient, 'state.selectedAvatarId = itemId;'), 'Equip must update selectedAvatarId optimistically before server confirmation');
$assertTrue(str_contains($profileClient, 'const previousSelectedAvatarId = state.selectedAvatarId;'), 'Equip must snapshot the selected avatar before optimistic mutation');
$assertTrue(str_contains($profileClient, 'state.selectedAvatarId = previousSelectedAvatarId;'), 'Failed equip must roll selectedAvatarId back');
$assertTrue(str_contains($profileClient, 'const activeAvatar = currentAvatarItemId();'), 'Profile card and collection must render from the selected avatar owner');
$assertTrue(str_contains($profileClient, 'if (confirmedAvatar) state.selectedAvatarId = confirmedAvatar;'), 'Authoritative Profile response must reconcile selectedAvatarId after confirmation');
$assertTrue(str_contains($profileUi, 'state.selectedAvatarId || state.mgwProfile?.avatar?.item_id'), 'Header identity render must prefer selectedAvatarId over independent profile fallback state');

$assertTrue(!str_contains($profileUi, 'photo_url'), 'Visible UI avatar owner must not consume provider photo_url');
$assertTrue(!str_contains($profileUi, 'Telegram?.WebApp'), 'Telegram photo must not remain a visible avatar fallback');
$assertTrue(str_contains($profileUi, "'starter-default-01'"), 'MGW default avatar must remain the only pre-profile fallback');

$optimisticOffset = strpos($storeClient, 'applyOptimisticPurchase(offer);');
$networkOffset = strpos($storeClient, "await api.cosmeticStorePurchase(String(offer.offer_id || ''), token)");
$assertTrue($optimisticOffset !== false && $networkOffset !== false && $optimisticOffset < $networkOffset, 'Store must apply optimistic purchase UI before waiting for the network response');
$assertTrue(str_contains($storeClient, 'const previousStoreState = cloneObject(storeState);'), 'Optimistic purchase must snapshot Store state for rollback');
$assertTrue(str_contains($storeClient, 'const previousProfileInventory = cloneObject(state.profileInventory);'), 'Optimistic purchase must snapshot Profile inventory for rollback');
$assertTrue(str_contains($storeClient, 'storeState = previousStoreState;'), 'Failed purchase must restore Store snapshot');
$assertTrue(str_contains($storeClient, 'state.profileInventory = previousProfileInventory;'), 'Failed purchase must restore Profile inventory snapshot');
$assertTrue(str_contains($storeClient, 'if (!purchaseBusy) applyStoreResponse(result);'), 'Background Store refresh must not overwrite a pending optimistic purchase');
$assertTrue(str_contains($storeClient, 'state.selectedAvatarId || storeState?.inventory?.equipped?.profile_avatar'), 'Store selected check must follow the same selected avatar owner');

$assertTrue(str_contains($responseHelper, 'u.equipped_avatar_item_id'), 'Game identity projection must read canonical equipped avatar');
$assertTrue(str_contains($responseHelper, "\$player['avatar_item_id']"), 'Game response must expose equipped avatar to presentation');
$assertTrue(str_contains($responseHelper, 'mgw_canonical_game_player_profiles'), 'Name and avatar must share one canonical game identity projection');

$assertTrue(str_contains($avatarPresentation, "document.getElementById('playersRow')"), 'Shared avatar presentation must decorate the existing player row');
$assertTrue(str_contains($avatarPresentation, 'state.activeGame'), 'Presentation must consume current canonical game response instead of a second game owner');
$assertTrue(!str_contains($avatarPresentation, 'gameAction('), 'Avatar presentation must not mutate game mechanics');
$assertTrue(str_contains($cleanEntry, 'initMgwAvatarPresentation'), 'Active clean entry must initialize avatar presentation');

$launchIds = [
    'starter-default-01','starter-default-02','starter-default-03',
    'store-avatar-01','store-avatar-02','store-avatar-03','store-avatar-04','store-avatar-05',
];
foreach ($launchIds as $itemId) {
    $assertTrue(str_contains($avatarCss, 'data-avatar-item-id="' . $itemId . '"'), 'Shared avatar CSS must own launch item ' . $itemId);
}
$assertTrue(str_contains($avatarCss, 'prefers-reduced-motion:reduce'), 'Avatar presentation must preserve reduced-motion safety');

$profileTarget = (string)($manifest['imports']['./assets/js/screens/profile-screen-v110.js?v=1108'] ?? '');
$storeTarget = (string)($manifest['imports']['./assets/js/screens/store-screen.js?v=34'] ?? '');
$stateTarget = (string)($manifest['imports']['./assets/js/state.js?v=27'] ?? '');
$uiTarget = (string)($manifest['imports']['./assets/js/ui.js?v=89'] ?? '');
$cleanTarget = (string)($manifest['imports']['@mgw/clean-entry'] ?? '');
$cssTarget = (string)($manifest['assets']['main_css'] ?? '');
$assertTrue(str_contains($profileTarget, 'mvp19=avatar-collection'), 'Active manifest must publish MVP-19.3 Profile client');
$assertTrue(str_contains($profileTarget, 'mvp19_3_1=avatar-sync'), 'Active manifest must publish MVP-19.3.1 Profile avatar sync target');
$assertTrue(str_contains($storeTarget, 'mvp19_3_1=optimistic-purchase'), 'Active manifest must publish MVP-19.3.1 optimistic Store target');
$assertTrue(str_contains($stateTarget, 'mvp19_3_1=avatar-owner'), 'Active manifest must publish MVP-19.3.1 avatar state owner target');
$assertTrue(str_contains($uiTarget, 'mvp19_3_1=selected-avatar-owner'), 'Active manifest must publish MVP-19.3.1 shared identity render target');
$assertTrue(str_contains($cleanTarget, 'mvp19=avatar-presentation'), 'Active manifest must publish shared game avatar presentation');
$assertTrue(str_contains($cssTarget, 'avatar=profile-v1'), 'Active manifest must bust shared avatar CSS cache');

fwrite(STDOUT, "PASS: MVP-19.3 profile avatar collection/equip/visibility + MVP-19.3.1 state sync/purchase UX ({$assertions} assertions)\n");
