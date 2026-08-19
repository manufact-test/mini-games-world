<?php
declare(strict_types=1);

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');

require __DIR__ . '/core/bootstrap.php';
require_once __DIR__ . '/catalog/CosmeticStoreService.php';

function mgw_cosmetic_store_error_status(string $reason): int
{
    return match ($reason) {
        'already_owned', 'request_conflict', 'purchase_in_progress', 'ownership_conflict', 'price_changed' => 409,
        'offer_unavailable' => 404,
        'request_invalid', 'intent_invalid', 'offer_invalid' => 422,
        'insufficient_balance' => 402,
        'account_unavailable' => 401,
        default => 400,
    };
}

function mgw_cosmetic_store_error_message(string $reason, string $fallback): string
{
    return match ($reason) {
        'already_owned' => 'Этот предмет уже у вас.',
        'request_conflict' => 'Запрос покупки уже использован для другого предложения.',
        'purchase_in_progress' => 'Предыдущая покупка этих предметов ещё завершается. Повторите обновление магазина.',
        'ownership_conflict' => 'Состав покупки изменился. Баланс восстановлен, обновите магазин.',
        'price_changed' => 'Цена предложения изменилась. Баланс восстановлен, обновите магазин.',
        'offer_unavailable' => 'Предложение магазина больше недоступно.',
        'request_invalid', 'intent_invalid', 'offer_invalid' => 'Не удалось подготовить покупку. Обновите магазин.',
        'insufficient_balance' => 'Недостаточно коинов для покупки.',
        'account_unavailable' => 'Профиль MGW недоступен для этой сессии.',
        default => $fallback !== '' ? $fallback : 'Не удалось выполнить покупку.',
    };
}

function mgw_runtime_cosmetic_intent(array $data, string $userId, string $requestToken): ?array
{
    foreach (array_reverse((array)($data['transactions'] ?? [])) as $row) {
        if (!is_array($row) || (string)($row['type'] ?? '') !== CosmeticStoreService::PURCHASE_TRANSACTION_TYPE) continue;
        if ((string)($row['user_id'] ?? '') !== $userId) continue;
        if ((string)($row['request_token'] ?? '') !== $requestToken) continue;
        return $row;
    }
    return null;
}

function mgw_runtime_cosmetic_pending_overlap(array $data, string $userId, array $itemIds): bool
{
    $wanted = array_fill_keys(array_map('strval', $itemIds), true);
    foreach ((array)($data['transactions'] ?? []) as $row) {
        if (!is_array($row) || (string)($row['type'] ?? '') !== CosmeticStoreService::PURCHASE_TRANSACTION_TYPE) continue;
        if ((string)($row['user_id'] ?? '') !== $userId) continue;
        if ((string)($row['status'] ?? '') !== CosmeticStoreService::PURCHASE_PENDING_STATUS) continue;
        foreach ((array)($row['item_ids'] ?? []) as $itemId) {
            if (isset($wanted[(string)$itemId])) return true;
        }
    }
    return false;
}

function mgw_runtime_cosmetic_pending_for_user(array $data, string $userId): array
{
    $pending = [];
    foreach ((array)($data['transactions'] ?? []) as $row) {
        if (!is_array($row) || (string)($row['type'] ?? '') !== CosmeticStoreService::PURCHASE_TRANSACTION_TYPE) continue;
        if ((string)($row['user_id'] ?? '') !== $userId) continue;
        if ((string)($row['status'] ?? '') !== CosmeticStoreService::PURCHASE_PENDING_STATUS) continue;
        $token = trim((string)($row['request_token'] ?? ''));
        if ($token !== '') $pending[$token] = $row;
    }
    return array_values($pending);
}

function mgw_runtime_cosmetic_mark_status(JsonDatabase $storage, string $userId, string $requestToken, string $status): void
{
    $storage->transaction(function (array &$data) use ($userId, $requestToken, $status): void {
        if (!isset($data['transactions']) || !is_array($data['transactions'])) return;
        foreach ($data['transactions'] as &$row) {
            if (!is_array($row) || (string)($row['type'] ?? '') !== CosmeticStoreService::PURCHASE_TRANSACTION_TYPE) continue;
            if ((string)($row['user_id'] ?? '') !== $userId) continue;
            if ((string)($row['request_token'] ?? '') !== $requestToken) continue;
            $row['status'] = $status;
            $row['updated_at'] = now_iso();
            break;
        }
        unset($row);
    });
}

function mgw_runtime_cosmetic_refund(JsonDatabase $storage, string $userId, string $requestToken): bool
{
    return (bool)$storage->transaction(function (array &$data) use ($userId, $requestToken): bool {
        if (!isset($data['transactions']) || !is_array($data['transactions'])) return false;
        $intentIndex = null;
        $intent = null;
        foreach ($data['transactions'] as $index => $row) {
            if (!is_array($row) || (string)($row['type'] ?? '') !== CosmeticStoreService::PURCHASE_TRANSACTION_TYPE) continue;
            if ((string)($row['user_id'] ?? '') !== $userId) continue;
            if ((string)($row['request_token'] ?? '') !== $requestToken) continue;
            $intentIndex = $index;
            $intent = $row;
            break;
        }
        if ($intentIndex === null || !is_array($intent)) return false;
        if ((string)($intent['status'] ?? '') === 'refunded') return false;
        if ((string)($intent['status'] ?? '') !== CosmeticStoreService::PURCHASE_PENDING_STATUS) return false;
        if (!isset($data['users'][$userId]) || !is_array($data['users'][$userId])) return false;
        $amount = max(0, (int)($intent['price_coins'] ?? 0));
        if ($amount <= 0) return false;

        UnifiedBalanceRuntimeState::ensureUser($data['users'][$userId]);
        $data['users'][$userId][UnifiedBalanceRuntimeState::FIELD] =
            (int)($data['users'][$userId][UnifiedBalanceRuntimeState::FIELD] ?? 0) + $amount;
        $data['transactions'][$intentIndex]['status'] = 'refunded';
        $data['transactions'][$intentIndex]['updated_at'] = now_iso();
        $data['transactions'][] = [
            'id' => make_id('tx'),
            'type' => 'balance_change',
            'category' => 'cosmetic_purchase_refund',
            'user_id' => $userId,
            'amount' => $amount,
            'balance_after' => (int)$data['users'][$userId][UnifiedBalanceRuntimeState::FIELD],
            'request_token' => $requestToken,
            'offer_id' => (string)($intent['offer_id'] ?? ''),
            'description' => 'Возврат коинов за незавершённую покупку косметики',
            'created_at' => now_iso(),
        ];
        return true;
    });
}

function mgw_cosmetic_fulfill_intent(
    CosmeticStoreService $store,
    JsonDatabase $storage,
    EconomyRuntimeBridge $economyBridge,
    string $mgwId,
    string $userId,
    string $accountRef,
    array $intent
): array {
    $requestToken = (string)($intent['request_token'] ?? '');
    $economyBridge->synchronizeCurrentJson();
    try {
        $purchase = $store->fulfill($mgwId, $accountRef, $userId, $intent);
        mgw_runtime_cosmetic_mark_status($storage, $userId, $requestToken, CosmeticStoreService::PURCHASE_COMPLETED_STATUS);
        return $purchase;
    } catch (CosmeticStoreException $error) {
        if (in_array($error->reason, ['ownership_conflict', 'price_changed', 'offer_unavailable', 'offer_invalid', 'intent_invalid'], true)) {
            if (mgw_runtime_cosmetic_refund($storage, $userId, $requestToken)) {
                $economyBridge->synchronizeCurrentJson();
            }
        }
        throw $error;
    }
}

try {
    if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
        json_response(['ok' => false, 'error' => 'Method not allowed.'], 405);
    }
    $payload = json_decode(file_get_contents('php://input') ?: '{}', true);
    if (!is_array($payload)) json_response(['ok' => false, 'error' => 'Некорректный запрос.'], 400);

    $configRef = $config;
    $authenticatedUser = (new AuthService($configRef))->getUserFromRequest($payload);
    $mgwId = trim((string)($authenticatedUser['mgw_id'] ?? ''));
    if (!MgwIdGenerator::isValid($mgwId)) {
        json_response(['ok' => false, 'error' => 'Профиль MGW недоступен для этой сессии.'], 401);
    }

    $databaseConfig = DatabaseConfig::fromApplicationConfig($configRef);
    $router = new RuntimeStorageRouter($configRef);
    if (!$databaseConfig->enabled()
        || $router->routeFor('accounts') !== RuntimeStorageRouter::DRIVER_DATABASE
        || $router->routeFor('economy') !== RuntimeStorageRouter::DRIVER_DATABASE) {
        json_response(['ok' => false, 'error' => 'Магазин MGW временно недоступен.'], 503);
    }

    $database = PdoConnectionFactory::create($databaseConfig);
    $store = new CosmeticStoreService($database);
    $storage = StorageFactory::createJson((string)($configRef['data_dir'] ?? (__DIR__ . '/data')));
    $users = new UserService($configRef, $database);
    $weekly = new WeeklyMatchEconomyService($configRef, new NotificationService());
    $economyBridge = new EconomyRuntimeBridge($configRef, $router);
    $action = strtolower(trim((string)($payload['action'] ?? 'status')));

    if ($action === 'status') {
        $runtime = $storage->transaction(function (array &$data) use ($authenticatedUser, $users, $weekly): array {
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
                'pending' => mgw_runtime_cosmetic_pending_for_user($data, $userId),
            ];
        });
        $economyBridge->synchronizeCurrentJson();
        foreach ((array)$runtime['pending'] as $pendingIntent) {
            if (!is_array($pendingIntent)) continue;
            try {
                mgw_cosmetic_fulfill_intent(
                    $store,
                    $storage,
                    $economyBridge,
                    $mgwId,
                    (string)$runtime['user_id'],
                    (string)$runtime['account_ref'],
                    $pendingIntent
                );
            } catch (Throwable $recoveryError) {
                error_log('[MiniGamesWorld Cosmetic Store recovery] ' . $recoveryError->getMessage());
            }
        }
        $freshBalance = (int)$storage->readOnly(static function (array $data) use ($runtime): int {
            return (int)($data['users'][(string)$runtime['user_id']][UnifiedBalanceRuntimeState::FIELD] ?? 0);
        });
        $economy = (new EconomyConfigService($database))->current();
        json_response([
            'ok' => true,
            'store' => $store->snapshot($mgwId, $freshBalance, (array)($economy['config']['coin_packages'] ?? [])),
        ]);
    }

    if ($action !== 'purchase') {
        json_response(['ok' => false, 'error' => 'Неизвестное действие магазина.'], 422);
    }

    $offerId = strtolower(trim((string)($payload['offer_id'] ?? '')));
    $requestToken = $store->normalizeRequestToken((string)($payload['request_token'] ?? ''));
    $completedPurchase = $store->purchaseByToken($mgwId, $requestToken);
    if ($completedPurchase !== null) {
        if ((string)$completedPurchase['offer_id'] !== $offerId) {
            throw new CosmeticStoreException('request_conflict', 'Purchase request token conflict.');
        }
        $runtime = $storage->transaction(function (array &$data) use ($authenticatedUser, $users, $weekly, $requestToken): array {
            $user = $users->ensureUser($data, $authenticatedUser);
            $userId = (string)$user['id'];
            $data['users'][$userId] = $user;
            $user =& $data['users'][$userId];
            $weekly->applyDueForUser($data, $user);
            $intent = mgw_runtime_cosmetic_intent($data, $userId, $requestToken);
            if ($intent !== null && (string)($intent['status'] ?? '') !== CosmeticStoreService::PURCHASE_COMPLETED_STATUS) {
                foreach ($data['transactions'] as &$row) {
                    if (!is_array($row) || (string)($row['type'] ?? '') !== CosmeticStoreService::PURCHASE_TRANSACTION_TYPE) continue;
                    if ((string)($row['user_id'] ?? '') === $userId && (string)($row['request_token'] ?? '') === $requestToken) {
                        $row['status'] = CosmeticStoreService::PURCHASE_COMPLETED_STATUS;
                        $row['updated_at'] = now_iso();
                        break;
                    }
                }
                unset($row);
            }
            return ['user_id' => $userId, 'balance' => (int)($user[UnifiedBalanceRuntimeState::FIELD] ?? 0)];
        });
        $economyBridge->synchronizeCurrentJson();
        $economy = (new EconomyConfigService($database))->current();
        json_response([
            'ok' => true,
            'purchase' => $completedPurchase + ['replayed' => true],
            'store' => $store->snapshot($mgwId, (int)$runtime['balance'], (array)($economy['config']['coin_packages'] ?? [])),
        ]);
    }

    $quote = $store->quote($mgwId, $offerId);
    $runtime = $storage->transaction(function (array &$data) use (
        $authenticatedUser, $users, $weekly, $quote, $requestToken
    ): array {
        $user = $users->ensureUser($data, $authenticatedUser);
        $userId = (string)$user['id'];
        $data['users'][$userId] = $user;
        $user =& $data['users'][$userId];
        $weekly->applyDueForUser($data, $user);
        UnifiedBalanceRuntimeState::ensureUser($user);

        $existing = mgw_runtime_cosmetic_intent($data, $userId, $requestToken);
        if ($existing !== null) {
            $existingItems = array_values(array_map('strval', (array)($existing['item_ids'] ?? [])));
            sort($existingItems, SORT_STRING);
            $quoteItems = array_values(array_map('strval', (array)$quote['item_ids']));
            sort($quoteItems, SORT_STRING);
            if ((string)($existing['offer_id'] ?? '') !== (string)$quote['offer_id']
                || (int)($existing['price_coins'] ?? 0) !== (int)$quote['price_coins']
                || $existingItems !== $quoteItems) {
                throw new CosmeticStoreException('request_conflict', 'Purchase request token conflict.');
            }
            return [
                'user_id' => $userId,
                'mgw_id' => (string)($user['mgw_id'] ?? ''),
                'account_ref' => (string)($user['mgw_account_ref'] ?? ''),
                'balance' => (int)($user[UnifiedBalanceRuntimeState::FIELD] ?? 0),
                'intent' => $existing,
                'replayed_runtime' => true,
            ];
        }

        if (mgw_runtime_cosmetic_pending_overlap($data, $userId, (array)$quote['item_ids'])) {
            throw new CosmeticStoreException('purchase_in_progress', 'Overlapping cosmetic purchase is pending.');
        }

        $price = (int)$quote['price_coins'];
        $balance = (int)($user[UnifiedBalanceRuntimeState::FIELD] ?? 0);
        if ($balance < $price) {
            throw new CosmeticStoreException('insufficient_balance', 'Insufficient available balance.');
        }
        $user[UnifiedBalanceRuntimeState::FIELD] = $balance - $price;
        if (!isset($data['transactions']) || !is_array($data['transactions'])) $data['transactions'] = [];
        $intent = [
            'id' => make_id('tx'),
            'type' => CosmeticStoreService::PURCHASE_TRANSACTION_TYPE,
            'category' => 'cosmetic_purchase',
            'user_id' => $userId,
            'mgw_id' => (string)($user['mgw_id'] ?? ''),
            'request_token' => $requestToken,
            'offer_id' => (string)$quote['offer_id'],
            'item_ids' => array_values($quote['item_ids']),
            'price_coins' => $price,
            'amount' => -$price,
            'balance_after' => (int)$user[UnifiedBalanceRuntimeState::FIELD],
            'status' => CosmeticStoreService::PURCHASE_PENDING_STATUS,
            'description' => 'Покупка косметики MGW',
            'created_at' => now_iso(),
        ];
        $data['transactions'][] = $intent;
        return [
            'user_id' => $userId,
            'mgw_id' => (string)($user['mgw_id'] ?? ''),
            'account_ref' => (string)($user['mgw_account_ref'] ?? ''),
            'balance' => (int)$user[UnifiedBalanceRuntimeState::FIELD],
            'intent' => $intent,
            'replayed_runtime' => false,
        ];
    });

    $purchase = mgw_cosmetic_fulfill_intent(
        $store,
        $storage,
        $economyBridge,
        $mgwId,
        (string)$runtime['user_id'],
        (string)$runtime['account_ref'],
        (array)$runtime['intent']
    );
    $freshBalance = (int)$storage->readOnly(static function (array $data) use ($runtime): int {
        return (int)($data['users'][(string)$runtime['user_id']][UnifiedBalanceRuntimeState::FIELD] ?? 0);
    });
    $economy = (new EconomyConfigService($database))->current();
    json_response([
        'ok' => true,
        'purchase' => $purchase,
        'store' => $store->snapshot($mgwId, $freshBalance, (array)($economy['config']['coin_packages'] ?? [])),
    ]);
} catch (CosmeticStoreException $error) {
    json_response([
        'ok' => false,
        'code' => $error->reason,
        'error' => mgw_cosmetic_store_error_message($error->reason, $error->getMessage()),
    ], mgw_cosmetic_store_error_status($error->reason));
} catch (Throwable $error) {
    error_log('[MiniGamesWorld Cosmetic Store] ' . $error->getMessage());
    json_response(['ok' => false, 'error' => 'Не удалось загрузить магазин MGW.'], 500);
}
