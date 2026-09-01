<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$assertions = 0;
$assertTrue = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

$profileEndpoint = (string)file_get_contents($root . '/bot/profile.php');
$apiClient = (string)file_get_contents($root . '/app/assets/js/api/client.js');
$profileModel = (string)file_get_contents($root . '/app/assets/js/profile/mgw-profile-model.js');
$ui = (string)file_get_contents($root . '/app/assets/js/ui.js');
$profileScreen = (string)file_get_contents($root . '/app/assets/js/screens/profile-screen-v110.js');
$manifest = require $root . '/app/runtime/client/version-manifest.php';

$assertTrue(
    str_contains($profileEndpoint, '$database = PdoConnectionFactory::create($databaseConfig);'),
    'Boot profile endpoint must use one canonical DB connection for identity and inventory.'
);
$assertTrue(
    str_contains($profileEndpoint, '(new MgwProfileService($database))->publicProfile($mgwId)'),
    'Boot profile endpoint must read canonical profile from MgwProfileService.'
);
$assertTrue(
    str_contains($profileEndpoint, '(new ProductInventoryService($database))->snapshot($mgwId)'),
    'Boot profile endpoint must read canonical inventory from ProductInventoryService.'
);
$assertTrue(
    str_contains($profileEndpoint, "'inventory' => \$inventory"),
    'Boot profile response must expose the authoritative inventory snapshot.'
);

$assertTrue(
    str_contains($apiClient, 'async function requestMgwProfile()'),
    'Client must have one explicit MGW profile bootstrap request owner.'
);
$assertTrue(
    str_contains($apiClient, 'state.profileInventory = result.inventory;'),
    'Canonical boot profile response must hydrate profileInventory before UI profile entry.'
);
$assertTrue(
    str_contains($apiClient, 'mgwProfile: () => requestMgwProfile()'),
    'Existing boot call must route through the inventory-hydrating profile request.'
);

$assertTrue(
    str_contains($profileModel, "const DEFAULT_AVATAR_ITEM_ID = '';"),
    'Client profile model must not fabricate starter-default-01 before authoritative profile hydration.'
);
$assertTrue(
    str_contains($profileModel, 'return itemId || DEFAULT_AVATAR_ITEM_ID;'),
    'Profile model must keep pass-through semantics while empty before hydration.'
);
$assertTrue(
    str_contains($ui, "const resolvedAvatarId = String(state.selectedAvatarId || state.mgwProfile?.avatar?.item_id || user?.avatar_item_id || '').trim();"),
    'Visible identity must resolve selected/profile/user avatar before any visual fallback.'
);
$assertTrue(
    str_contains($ui, 'if (!state.selectedAvatarId && resolvedAvatarId) state.selectedAvatarId = canonicalAvatarId;'),
    'Visual starter fallback must never be persisted as selected avatar state.'
);

$assertTrue(
    str_contains($profileScreen, 'const inventory = state.profileInventory && typeof state.profileInventory === \'object\' ? state.profileInventory : null;'),
    'Profile collection must continue consuming the shared profileInventory state owner.'
);

$apiTarget = (string)($manifest['imports']['./assets/js/api/client.js?v=47'] ?? '');
$uiTarget = (string)($manifest['imports']['./assets/js/ui.js?v=89'] ?? '');
$modelTarget = (string)($manifest['imports']['./assets/js/profile/mgw-profile-model.js?v=1'] ?? '');
$assertTrue(str_contains($apiTarget, 'c7=avatar-bootstrap-inventory'), 'Active runtime must publish the boot inventory hydration client.');
$assertTrue(str_contains($uiTarget, 'c7=no-false-starter'), 'Active runtime must publish non-poisoning avatar UI state.');
$assertTrue(str_contains($modelTarget, 'c7=no-prehydrate-default'), 'Active runtime must publish the no-fabricated-default profile model.');

fwrite(STDOUT, "PASS: C7 authoritative avatar/inventory first-render hydration ({$assertions} assertions)\n");
