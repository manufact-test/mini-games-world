<?php
declare(strict_types=1);

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');

require __DIR__ . '/core/bootstrap.php';
require_once dirname(__DIR__) . '/app/runtime/localization/LocalizationCatalog.php';

function mgw_cosmetic_store_copy(string $key, string $emergencyFallback): string
{
    try {
        static $catalog = null;
        if (!$catalog instanceof LocalizationCatalog) {
            $catalog = new LocalizationCatalog(dirname(__DIR__) . '/app/locales');
        }
        return $catalog->translate($key);
    } catch (Throwable $error) {
        error_log('[MiniGamesWorld cosmetic store localization] ' . $error->getMessage());
        return $emergencyFallback;
    }
}
require_once __DIR__ . '/catalog/CosmeticStoreService.php';
require_once __DIR__ . '/catalog/CosmeticStoreRuntimePurchaseService.php';

function mgw_cosmetic_store_error_status(string $reason): int
{
    return match ($reason) {
        'already_owned', 'request_conflict', 'purchase_in_progress', 'ownership_conflict', 'price_changed', 'item_not_owned', 'equip_failed' => 409,
        'offer_unavailable', 'item_unavailable' => 404,
        'request_invalid', 'intent_invalid', 'offer_invalid' => 422,
        'insufficient_balance' => 402,
        'account_unavailable' => 401,
        default => 400,
    };
}

function mgw_cosmetic_store_error_message(string $reason, string $fallback): string
{
    return match ($reason) {
        'already_owned' => mgw_cosmetic_store_copy('server.cosmetic_store.already_owned', 'You already own this item.'),
        'request_conflict' => mgw_cosmetic_store_copy('server.cosmetic_store.request_conflict', 'This purchase request was already used for another offer.'),
        'purchase_in_progress' => mgw_cosmetic_store_copy('server.cosmetic_store.purchase_in_progress', 'The previous purchase is still finishing. Refresh the Store.'),
        'ownership_conflict' => mgw_cosmetic_store_copy('server.cosmetic_store.ownership_conflict', 'The purchase contents changed. Your balance was restored; refresh the Store.'),
        'price_changed' => mgw_cosmetic_store_copy('server.cosmetic_store.price_changed', 'The offer price changed. Your balance was restored; refresh the Store.'),
        'offer_unavailable' => mgw_cosmetic_store_copy('server.cosmetic_store.offer_unavailable', 'This Store offer is no longer available.'),
        'item_unavailable' => mgw_cosmetic_store_copy('server.cosmetic_store.item_unavailable', 'This item is no longer available.'),
        'item_not_owned' => mgw_cosmetic_store_copy('server.cosmetic_store.item_not_owned', 'Buy this item first.'),
        'equip_failed' => mgw_cosmetic_store_copy('server.cosmetic_store.equip_failed', 'The item could not be selected.'),
        'request_invalid', 'intent_invalid', 'offer_invalid' => mgw_cosmetic_store_copy('server.cosmetic_store.prepare_failed', 'The purchase could not be prepared. Refresh the Store.'),
        'insufficient_balance' => mgw_cosmetic_store_copy('server.cosmetic_store.insufficient_balance', 'You do not have enough coins for this purchase.'),
        'account_unavailable' => mgw_cosmetic_store_copy('server.cosmetic_store.account_unavailable', 'The MGW profile is unavailable for this session.'),
        default => $fallback !== '' ? $fallback : mgw_cosmetic_store_copy('server.cosmetic_store.purchase_failed', 'The purchase could not be completed.'),
    };
}

function mgw_store_catalog_item(array $snapshot, string $itemId): ?array
{
    foreach ((array)($snapshot['catalog'] ?? []) as $item) {
        if (!is_array($item) || (string)($item['item_id'] ?? '') !== $itemId) continue;
        return $item;
    }
    return null;
}

function mgw_store_profile_name_color(array $item): bool
{
    return (string)($item['item_type'] ?? '') === 'profile'
        && (string)($item['item_family'] ?? '') === 'name_color'
        && (string)($item['equip_slot'] ?? '') === 'profile_name_color'
        && (string)($item['catalog_status'] ?? '') === 'active';
}

function mgw_store_profile_badge(array $item): bool
{
    return (string)($item['item_type'] ?? '') === 'profile'
        && (string)($item['item_family'] ?? '') === 'badge'
        && (string)($item['equip_slot'] ?? '') === 'profile_badge'
        && (string)($item['catalog_status'] ?? '') === 'active';
}

function mgw_store_profile_frame(array $item): bool
{
    return (string)($item['item_type'] ?? '') === 'profile'
        && (string)($item['item_family'] ?? '') === 'frame'
        && (string)($item['equip_slot'] ?? '') === 'profile_frame'
        && (string)($item['catalog_status'] ?? '') === 'active';
}

function mgw_store_profile_background(array $item): bool
{
    return (string)($item['item_type'] ?? '') === 'profile'
        && (string)($item['item_family'] ?? '') === 'background'
        && (string)($item['equip_slot'] ?? '') === 'profile_background'
        && (string)($item['catalog_status'] ?? '') === 'active';
}

function mgw_store_profile_entry_effect(array $item): bool
{
    return (string)($item['item_type'] ?? '') === 'profile'
        && (string)($item['item_family'] ?? '') === 'entry_effect'
        && (string)($item['equip_slot'] ?? '') === 'profile_entry_effect'
        && (string)($item['catalog_status'] ?? '') === 'active';
}

function mgw_store_profile_victory_effect(array $item): bool
{
    return (string)($item['item_type'] ?? '') === 'profile'
        && (string)($item['item_family'] ?? '') === 'victory_effect'
        && (string)($item['equip_slot'] ?? '') === 'profile_victory_effect'
        && (string)($item['catalog_status'] ?? '') === 'active';
}

try {
    if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
        json_response(['ok' => false, 'error' => 'Method not allowed.'], 405);
    }
    $payload = json_decode(file_get_contents('php://input') ?: '{}', true);
    if (!is_array($payload)) json_response(['ok' => false, 'error' => mgw_cosmetic_store_copy('server.cosmetic_store.invalid_request', 'Invalid request.')], 400);

    $authenticatedUser = (new AuthService($config))->getUserFromRequest($payload);
    $mgwId = trim((string)($authenticatedUser['mgw_id'] ?? ''));
    if (!MgwIdGenerator::isValid($mgwId)) {
        json_response(['ok' => false, 'error' => mgw_cosmetic_store_copy('server.cosmetic_store.account_unavailable', 'The MGW profile is unavailable for this session.')], 401);
    }

    $databaseConfig = DatabaseConfig::fromApplicationConfig($config);
    $router = new RuntimeStorageRouter($config);
    if (!$databaseConfig->enabled()
        || $router->routeFor('accounts') !== RuntimeStorageRouter::DRIVER_DATABASE
        || $router->routeFor('economy') !== RuntimeStorageRouter::DRIVER_DATABASE) {
        json_response(['ok' => false, 'error' => mgw_cosmetic_store_copy('server.cosmetic_store.unavailable', 'The MGW Store is temporarily unavailable.')], 503);
    }

    $database = PdoConnectionFactory::create($databaseConfig);
    $store = new CosmeticStoreService($database);
    $inventory = new ProductInventoryService($database);
    $storage = StorageFactory::createJson((string)($config['data_dir'] ?? (__DIR__ . '/data')));
    if (!$storage instanceof StorageTransactionInterface) {
        throw new RuntimeException('Cosmetic Store requires transactional runtime storage.');
    }
    $runtimePurchases = new CosmeticStoreRuntimePurchaseService($storage);
    $users = new UserService($config, $database);
    $weekly = new WeeklyMatchEconomyService($config, new NotificationService());
    $economyBridge = new EconomyRuntimeBridge($config, $router);
    $economyConfig = (new EconomyConfigService($database))->current();
    $coinPackages = (array)($economyConfig['config']['coin_packages'] ?? []);
    $action = strtolower(trim((string)($payload['action'] ?? 'status')));

    $captureRuntimeUser = static function () use ($storage, $authenticatedUser, $users, $weekly, $runtimePurchases): array {
        return $storage->transaction(function (array &$data) use ($authenticatedUser, $users, $weekly, $runtimePurchases): array {
            $user = $users->ensureUser($data, $authenticatedUser);
            $userId = (string)$user['id'];
            $data['users'][$userId] = $user;
            $user =& $data['users'][$userId];
            $weekly->applyDueForUser($data, $user);
            return [
                'user_id' => $userId,
                'mgw_id' => (string)($user['mgw_id'] ?? ''),
                'account_ref' => (string)($user['mgw_account_ref'] ?? ''),
                'balance' => (int)($user[UnifiedBalanceRuntimeState::FIELD] ?? 0),
                'pending' => $runtimePurchases->pendingForUser($data, $userId),
            ];
        });
    };

    $fulfill = static function (array $runtime, array $intent) use (
        $store, $runtimePurchases, $economyBridge, $mgwId
    ): array {
        $requestToken = (string)($intent['request_token'] ?? '');
        $economyBridge->synchronizeCurrentJson();
        try {
            $purchase = $store->fulfill(
                $mgwId,
                (string)$runtime['account_ref'],
                (string)$runtime['user_id'],
                $intent
            );
            $runtimePurchases->markCompleted((string)$runtime['user_id'], $requestToken);
            return $purchase;
        } catch (CosmeticStoreException $error) {
            if (in_array($error->reason, ['ownership_conflict', 'price_changed', 'offer_unavailable', 'offer_invalid', 'intent_invalid'], true)
                && $runtimePurchases->refund((string)$runtime['user_id'], $requestToken)) {
                $economyBridge->synchronizeCurrentJson();
            }
            throw $error;
        }
    };

    if ($action === 'equip') {
        $runtime = $captureRuntimeUser();
        $itemId = strtolower(trim((string)($payload['item_id'] ?? '')));
        $inventorySnapshot = $inventory->snapshot($mgwId);
        $catalogItem = mgw_store_catalog_item($inventorySnapshot, $itemId);
        if (!is_array($catalogItem) || empty($catalogItem['owned'])) {
            throw new CosmeticStoreException(is_array($catalogItem) ? 'item_not_owned' : 'item_unavailable', mgw_cosmetic_store_copy('server.cosmetic_store.item_cannot_equip', 'This item cannot be selected.'));
        }

        $isGameItem = (string)($catalogItem['item_type'] ?? '') === 'game'
            && (string)($catalogItem['catalog_status'] ?? '') === 'active'
            && str_starts_with((string)($catalogItem['equip_slot'] ?? ''), 'game_');
        if ($isGameItem) {
            $equipment = $store->equipGameItem($mgwId, $itemId);
        } elseif (mgw_store_profile_name_color($catalogItem)
            || mgw_store_profile_badge($catalogItem)
            || mgw_store_profile_frame($catalogItem)
            || mgw_store_profile_background($catalogItem)
            || mgw_store_profile_entry_effect($catalogItem)
            || mgw_store_profile_victory_effect($catalogItem)) {
            try {
                $equipment = $inventory->equip($mgwId, $itemId);
            } catch (Throwable $error) {
                throw new CosmeticStoreException('equip_failed', mgw_cosmetic_store_copy('server.cosmetic_store.profile_equip_failed', 'The profile appearance could not be selected.'));
            }
        } else {
            throw new CosmeticStoreException('item_unavailable', mgw_cosmetic_store_copy('server.cosmetic_store.item_not_selectable', 'This item cannot be selected through the Store.'));
        }

        json_response([
            'ok' => true,
            'equipment' => $equipment,
            'store' => $store->snapshot(
                $mgwId,
                $runtimePurchases->balance((string)$runtime['user_id']),
                $coinPackages
            ),
        ]);
    }

    if ($action === 'unequip') {
        $runtime = $captureRuntimeUser();
        $equipSlot = strtolower(trim((string)($payload['equip_slot'] ?? '')));
        $inventorySnapshot = $inventory->snapshot($mgwId);
        $knownSlot = false;
        foreach ((array)($inventorySnapshot['catalog'] ?? []) as $catalogItem) {
            if (!is_array($catalogItem)) continue;
            if ((string)($catalogItem['catalog_status'] ?? '') !== 'active') continue;
            if ((string)($catalogItem['equip_slot'] ?? '') !== $equipSlot) continue;
            $isGameSlot = (string)($catalogItem['item_type'] ?? '') === 'game' && str_starts_with($equipSlot, 'game_');
            if ($isGameSlot || mgw_store_profile_name_color($catalogItem) || mgw_store_profile_badge($catalogItem) || mgw_store_profile_frame($catalogItem) || mgw_store_profile_background($catalogItem)) {
                $knownSlot = true;
                break;
            }
            if (mgw_store_profile_entry_effect($catalogItem) || mgw_store_profile_victory_effect($catalogItem)) {
                $knownSlot = true;
                break;
            }
        }
        if (!$knownSlot) {
            throw new CosmeticStoreException('item_unavailable', mgw_cosmetic_store_copy('server.cosmetic_store.slot_not_unequip', 'This slot cannot be unequipped through the Store.'));
        }
        $equipment = $inventory->unequip($mgwId, $equipSlot);
        json_response([
            'ok' => true,
            'equipment' => $equipment,
            'store' => $store->snapshot(
                $mgwId,
                $runtimePurchases->balance((string)$runtime['user_id']),
                $coinPackages
            ),
        ]);
    }

    if ($action === 'status') {
        $runtime = $captureRuntimeUser();
        $economyBridge->synchronizeCurrentJson();
        foreach ((array)$runtime['pending'] as $pendingIntent) {
            if (!is_array($pendingIntent)) continue;
            try {
                $fulfill($runtime, $pendingIntent);
            } catch (Throwable $recoveryError) {
                error_log('[MiniGamesWorld Cosmetic Store recovery] ' . $recoveryError->getMessage());
            }
        }
        json_response([
            'ok' => true,
            'store' => $store->snapshot(
                $mgwId,
                $runtimePurchases->balance((string)$runtime['user_id']),
                $coinPackages
            ),
        ]);
    }

    if ($action !== 'purchase') {
        json_response(['ok' => false, 'error' => mgw_cosmetic_store_copy('server.cosmetic_store.invalid_action', 'Unknown Store action.')], 422);
    }

    $offerId = strtolower(trim((string)($payload['offer_id'] ?? '')));
    $requestToken = $store->normalizeRequestToken((string)($payload['request_token'] ?? ''));

    $completedPurchase = $store->purchaseByToken($mgwId, $requestToken);
    if ($completedPurchase !== null) {
        if ((string)$completedPurchase['offer_id'] !== $offerId) {
            throw new CosmeticStoreException('request_conflict', 'Purchase request token conflict.');
        }
        $runtime = $captureRuntimeUser();
        $runtimePurchases->markCompleted((string)$runtime['user_id'], $requestToken);
        $economyBridge->synchronizeCurrentJson();
        json_response([
            'ok' => true,
            'purchase' => $completedPurchase + ['replayed' => true],
            'store' => $store->snapshot(
                $mgwId,
                $runtimePurchases->balance((string)$runtime['user_id']),
                $coinPackages
            ),
        ]);
    }

    $quote = $store->quote($mgwId, $offerId);
    $runtime = $storage->transaction(function (array &$data) use (
        $authenticatedUser, $users, $weekly, $runtimePurchases, $quote, $requestToken
    ): array {
        $user = $users->ensureUser($data, $authenticatedUser);
        $userId = (string)$user['id'];
        $data['users'][$userId] = $user;
        $user =& $data['users'][$userId];
        $weekly->applyDueForUser($data, $user);
        $prepared = $runtimePurchases->prepare($data, $user, $quote, $requestToken);
        return [
            'user_id' => $userId,
            'mgw_id' => (string)($user['mgw_id'] ?? ''),
            'account_ref' => (string)($user['mgw_account_ref'] ?? ''),
            'balance' => (int)($user[UnifiedBalanceRuntimeState::FIELD] ?? 0),
            'intent' => $prepared['intent'],
            'replayed_runtime' => !empty($prepared['replayed_runtime']),
        ];
    });

    $purchase = $fulfill($runtime, (array)$runtime['intent']);
    json_response([
        'ok' => true,
        'purchase' => $purchase,
        'store' => $store->snapshot(
            $mgwId,
            $runtimePurchases->balance((string)$runtime['user_id']),
            $coinPackages
        ),
    ]);
} catch (CosmeticStoreException $error) {
    json_response([
        'ok' => false,
        'code' => $error->reason,
        'error' => mgw_cosmetic_store_error_message($error->reason, $error->getMessage()),
    ], mgw_cosmetic_store_error_status($error->reason));
} catch (Throwable $error) {
    error_log('[MiniGamesWorld Cosmetic Store] ' . $error->getMessage());
    json_response(['ok' => false, 'error' => mgw_cosmetic_store_copy('server.cosmetic_store.load_failed', 'The MGW Store could not be loaded.')], 500);
}