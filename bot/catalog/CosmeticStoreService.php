<?php
declare(strict_types=1);

require_once __DIR__ . '/ProductInventoryService.php';

final class CosmeticStoreException extends RuntimeException
{
    public function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }
}

final class CosmeticStoreService
{
    public const AVATAR_BUNDLE_OFFER_ID = 'avatar-bundle-5';
    public const TICTACTOE_BUNDLE_OFFER_ID = 'ttt-premium-bundle';
    public const PURCHASE_TRANSACTION_TYPE = 'cosmetic_purchase';
    public const PURCHASE_PENDING_STATUS = 'debited';
    public const PURCHASE_COMPLETED_STATUS = 'completed';

    private const OFFER_ID_PATTERN = '/^[a-z0-9][a-z0-9_.-]{0,63}$/';
    private const REQUEST_TOKEN_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9_.:-]{7,95}$/';

    private ProductInventoryService $inventory;

    public function __construct(private DatabaseConnectionInterface $database)
    {
        $this->inventory = new ProductInventoryService($database);
    }

    public function snapshot(string $mgwId, int $balance, array $coinPackages = []): array
    {
        $mgwId = $this->normalizeMgwId($mgwId);
        $balance = max(0, $balance);
        $inventory = $this->inventory->snapshot($mgwId);
        $owned = [];
        foreach ((array)($inventory['owned'] ?? []) as $row) {
            if (!is_array($row)) continue;
            $itemId = trim((string)($row['item_id'] ?? ''));
            if ($itemId !== '') $owned[$itemId] = true;
        }
        $equipped = is_array($inventory['equipped'] ?? null) ? $inventory['equipped'] : [];

        $catalogByItem = [];
        foreach ((array)($inventory['catalog'] ?? []) as $item) {
            if (!is_array($item)) continue;
            $itemId = trim((string)($item['item_id'] ?? ''));
            if ($itemId !== '') $catalogByItem[$itemId] = $item;
        }

        $activeOfferRows = $this->activeOffers();
        $itemPrices = $this->itemOfferPrices($activeOfferRows);
        $offers = [];
        foreach ($activeOfferRows as $row) {
            $offer = $this->publicOffer($row, $owned, $equipped, $catalogByItem, $itemPrices, $balance);
            $offers[$offer['offer_id']] = $offer;
        }

        $profileAvatars = [];
        foreach (ProductInventoryService::STORE_AVATAR_ITEM_IDS as $index => $itemId) {
            $offerId = 'avatar-' . substr($itemId, -2);
            if (!isset($offers[$offerId])) continue;
            $profileAvatars[] = $offers[$offerId] + [
                'preview_number' => $index + 4,
                'preview_kind' => 'numbered_avatar_placeholder',
            ];
        }

        $avatarBundle = $offers[self::AVATAR_BUNDLE_OFFER_ID] ?? null;
        if (is_array($avatarBundle)) {
            $avatarBundle['preview_kind'] = 'avatar_bundle_placeholder';
        }

        $gameOffers = [];
        foreach ($offers as $offer) {
            if (($offer['category'] ?? '') !== 'games' || ($offer['subcategory'] ?? '') !== 'tictactoe') continue;
            $layer = (string)($offer['metadata']['layer'] ?? '');
            if (!in_array($layer, ['theme', 'elements', 'effect'], true)) continue;
            $gameOffers[$layer][] = $offer;
        }

        $tictactoeBundle = $offers[self::TICTACTOE_BUNDLE_OFFER_ID] ?? null;
        if (is_array($tictactoeBundle)) {
            $tictactoeBundle['display_name'] = 'Неоновый комплект';
            $tictactoeBundle['preview_kind'] = 'tictactoe_premium_bundle';
            $tictactoeBundle['game_type'] = 'tictactoe';
        }

        $ownedItems = [];
        foreach ((array)($inventory['owned'] ?? []) as $ownedRow) {
            if (!is_array($ownedRow)) continue;
            $itemId = trim((string)($ownedRow['item_id'] ?? ''));
            if ($itemId === '') continue;
            $catalog = $catalogByItem[$itemId] ?? [];
            $family = (string)($catalog['item_family'] ?? '');
            $slot = (string)($catalog['equip_slot'] ?? '');
            $metadata = is_array($catalog['metadata'] ?? null) ? $catalog['metadata'] : [];
            $starterIndex = array_search($itemId, MgwIdentityPolicy::STARTER_AVATAR_ITEM_IDS, true);
            $storeIndex = array_search($itemId, ProductInventoryService::STORE_AVATAR_ITEM_IDS, true);
            $ownedItems[] = [
                'item_id' => $itemId,
                'item_type' => (string)($catalog['item_type'] ?? 'profile'),
                'item_family' => $family,
                'equip_slot' => $slot,
                'starter' => !empty($catalog['starter_grant']),
                'store_product' => !empty($catalog['is_store_product']),
                'display_name' => (string)($metadata['display_name'] ?? $itemId),
                'metadata' => $metadata,
                'preview_number' => $starterIndex !== false ? $starterIndex + 1 : ($storeIndex !== false ? $storeIndex + 4 : null),
                'preview_kind' => $family === 'avatar' ? 'numbered_avatar_placeholder' : 'game_cosmetic',
                'equipped' => $slot !== '' && (string)($equipped[$slot] ?? '') === $itemId,
                'acquired_source' => (string)($ownedRow['acquired_source'] ?? ''),
                'acquired_at' => $ownedRow['acquired_at'] ?? null,
            ];
        }

        usort($ownedItems, static function (array $left, array $right): int {
            $leftKey = sprintf('%s|%04d|%s', (string)($left['item_family'] ?? ''), (int)($left['preview_number'] ?? 999), (string)($left['item_id'] ?? ''));
            $rightKey = sprintf('%s|%04d|%s', (string)($right['item_family'] ?? ''), (int)($right['preview_number'] ?? 999), (string)($right['item_id'] ?? ''));
            return $leftKey <=> $rightKey;
        });

        $packages = [];
        foreach ($coinPackages as $package) {
            if (!is_array($package) || empty($package['enabled'])) continue;
            $id = trim((string)($package['id'] ?? ''));
            $coins = (int)($package['coins'] ?? 0);
            $price = (int)($package['price_eur_cents'] ?? 0);
            if ($id === '' || $coins <= 0 || $price <= 0) continue;
            $packages[] = [
                'id' => $id,
                'coins' => $coins,
                'price_eur_cents' => $price,
                'billing_available' => false,
            ];
        }

        return [
            'currency' => 'mgw_coin',
            'balance' => $balance,
            'tabs' => [
                ['id' => 'coins', 'label' => 'Коины', 'available' => true],
                ['id' => 'profile', 'label' => 'Профиль', 'available' => true],
                ['id' => 'games', 'label' => 'Игры', 'available' => true],
                ['id' => 'bundles', 'label' => 'Наборы', 'available' => true],
            ],
            'coins' => [
                'packages' => $packages,
                'billing_available' => false,
            ],
            'profile' => [
                'avatars' => $profileAvatars,
            ],
            'games' => [
                'available' => true,
                'catalogs' => [
                    'tictactoe' => [
                        'game_type' => 'tictactoe',
                        'title' => 'Крестики-нолики',
                        'themes' => array_values($gameOffers['theme'] ?? []),
                        'elements' => array_values($gameOffers['elements'] ?? []),
                        'effects' => array_values($gameOffers['effect'] ?? []),
                    ],
                ],
            ],
            'bundles' => [
                'avatar_bundle' => $avatarBundle,
                'tictactoe_bundle' => $tictactoeBundle,
                'game_bundles' => is_array($tictactoeBundle) ? [$tictactoeBundle] : [],
            ],
            'inventory' => [
                'items' => $ownedItems,
                'equipped' => $equipped,
            ],
            'purchase_rules' => [
                'auto_equip' => false,
                'duplicate_purchase' => false,
                'duplicate_compensation' => false,
            ],
        ];
    }

    public function quote(string $mgwId, string $offerId): array
    {
        $mgwId = $this->normalizeMgwId($mgwId);
        $offerId = $this->normalizeOfferId($offerId);
        $offer = $this->offer($offerId, true);
        $members = $this->members($offer);
        $missing = [];
        foreach ($members as $itemId) {
            if (!$this->inventory->isOwned($mgwId, $itemId)) $missing[] = $itemId;
        }
        if ($missing === []) {
            throw new CosmeticStoreException('already_owned', 'Все предметы этого предложения уже принадлежат аккаунту.');
        }

        $itemPrices = $this->itemOfferPrices($this->activeOffers());
        $price = $this->offerPrice($offer, $missing, $members, $itemPrices);
        if ($price <= 0) throw new CosmeticStoreException('offer_invalid', 'Цена предложения недоступна.');

        return [
            'offer_id' => $offerId,
            'offer_type' => (string)$offer['offer_type'],
            'item_ids' => array_values($missing),
            'price_coins' => $price,
            'full_price_coins' => (int)$offer['price_coins'],
            'partial_unit_price_coins' => isset($offer['partial_unit_price_coins']) ? (int)$offer['partial_unit_price_coins'] : null,
        ];
    }

    public function equipGameItem(string $mgwId, string $itemId): array
    {
        $mgwId = $this->normalizeMgwId($mgwId);
        $itemId = strtolower(trim($itemId));
        if (preg_match(self::OFFER_ID_PATTERN, $itemId) !== 1) {
            throw new CosmeticStoreException('item_unavailable', 'Игровой предмет недоступен.');
        }

        $snapshot = $this->inventory->snapshot($mgwId);
        $catalog = null;
        foreach ((array)($snapshot['catalog'] ?? []) as $item) {
            if (!is_array($item) || (string)($item['item_id'] ?? '') !== $itemId) continue;
            $catalog = $item;
            break;
        }
        if (!is_array($catalog)
            || (string)($catalog['item_type'] ?? '') !== 'game'
            || !str_starts_with((string)($catalog['equip_slot'] ?? ''), 'game_')) {
            throw new CosmeticStoreException('item_unavailable', 'Игровой предмет недоступен.');
        }
        if (empty($catalog['owned'])) {
            throw new CosmeticStoreException('item_not_owned', 'Сначала купите этот предмет.');
        }

        try {
            return $this->inventory->equip($mgwId, $itemId);
        } catch (Throwable $error) {
            throw new CosmeticStoreException('equip_failed', 'Не удалось выбрать игровой предмет.');
        }
    }

    public function fulfill(
        string $mgwId,
        string $accountRef,
        string $legacyUserId,
        array $intent
    ): array {
        $mgwId = $this->normalizeMgwId($mgwId);
        $accountRef = $this->requiredText($accountRef, 191, 'account reference');
        $legacyUserId = $this->requiredText($legacyUserId, 191, 'legacy user id');
        $requestToken = $this->normalizeRequestToken((string)($intent['request_token'] ?? ''));
        $offerId = $this->normalizeOfferId((string)($intent['offer_id'] ?? ''));
        $priceCoins = (int)($intent['price_coins'] ?? 0);
        if ($priceCoins <= 0) throw new CosmeticStoreException('intent_invalid', 'Стоимость покупки недоступна.');
        $itemIds = $this->normalizeItemIds($intent['item_ids'] ?? null);

        return $this->database->transaction(function (DatabaseConnectionInterface $database) use (
            $mgwId, $accountRef, $legacyUserId, $requestToken, $offerId, $priceCoins, $itemIds
        ): array {
            $existing = $this->purchaseByRequest($database, $mgwId, $requestToken, true);
            if ($existing !== null) {
                $existingItems = $this->decodeItemIds((string)($existing['items_json'] ?? ''));
                if ((string)$existing['offer_id'] !== $offerId
                    || (int)$existing['price_coins'] !== $priceCoins
                    || $existingItems !== $itemIds) {
                    throw new CosmeticStoreException('request_conflict', 'Токен покупки уже использован для другого запроса.');
                }
                return $this->publicPurchase($existing, true);
            }

            $offer = $this->offer($offerId, true, $database);
            $members = $this->members($offer);
            foreach ($itemIds as $itemId) {
                if (!in_array($itemId, $members, true)) {
                    throw new CosmeticStoreException('intent_invalid', 'Покупка содержит предмет вне предложения.');
                }
            }
            $itemPrices = $this->itemOfferPrices($this->activeOffers($database));
            $expectedPrice = $this->offerPrice($offer, $itemIds, $members, $itemPrices);
            if ($expectedPrice !== $priceCoins || $expectedPrice <= 0) {
                throw new CosmeticStoreException('price_changed', 'Цена предложения изменилась. Обновите магазин.');
            }

            foreach ($itemIds as $itemId) {
                if ($this->inventory->isOwned($mgwId, $itemId)) {
                    throw new CosmeticStoreException('ownership_conflict', 'Один из предметов уже получен другим способом. Обновите магазин.');
                }
            }
            foreach ($itemIds as $itemId) {
                $grant = $this->inventory->grant($mgwId, $itemId, 'store_purchase', $requestToken);
                if (empty($grant['granted'])) {
                    throw new CosmeticStoreException('ownership_conflict', 'Не удалось зафиксировать новый предмет.');
                }
            }

            $purchaseId = 'cp_' . substr(hash('sha256', $mgwId . '|' . $requestToken), 0, 64);
            $createdAt = $this->timestamp();
            $database->execute(
                'INSERT INTO mgw_cosmetic_purchases (
                    purchase_id, request_token, mgw_id, account_ref, legacy_user_id,
                    offer_id, price_coins, items_json, purchase_status, created_at_utc
                 ) VALUES (
                    :purchase_id, :request_token, :mgw_id, :account_ref, :legacy_user_id,
                    :offer_id, :price_coins, :items_json, :purchase_status, :created_at_utc
                 )',
                [
                    'purchase_id' => $purchaseId,
                    'request_token' => $requestToken,
                    'mgw_id' => $mgwId,
                    'account_ref' => $accountRef,
                    'legacy_user_id' => $legacyUserId,
                    'offer_id' => $offerId,
                    'price_coins' => $priceCoins,
                    'items_json' => json_encode($itemIds, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                    'purchase_status' => 'completed',
                    'created_at_utc' => $createdAt,
                ]
            );
            $row = $this->purchaseByRequest($database, $mgwId, $requestToken, false);
            if ($row === null) throw new RuntimeException('Cosmetic purchase audit row is unavailable.');
            return $this->publicPurchase($row, false);
        });
    }

    public function purchaseByToken(string $mgwId, string $requestToken): ?array
    {
        $mgwId = $this->normalizeMgwId($mgwId);
        $requestToken = $this->normalizeRequestToken($requestToken);
        $row = $this->purchaseByRequest($this->database, $mgwId, $requestToken, false);
        return $row === null ? null : $this->publicPurchase($row, true);
    }

    private function activeOffers(?DatabaseConnectionInterface $database = null): array
    {
        $database ??= $this->database;
        return $database->fetchAll(
            "SELECT offer_id, offer_type, item_id, category, subcategory, price_coins,
                    partial_unit_price_coins, members_json, offer_status, sort_order
             FROM mgw_product_offers
             WHERE offer_status = 'active'
             ORDER BY sort_order ASC, offer_id ASC"
        );
    }

    private function publicOffer(
        array $row,
        array $owned,
        array $equipped,
        array $catalogByItem,
        array $itemPrices,
        int $balance
    ): array {
        $members = $this->members($row);
        $missing = array_values(array_filter($members, static fn(string $itemId): bool => !isset($owned[$itemId])));
        $type = (string)($row['offer_type'] ?? '');
        $fullPrice = (int)($row['price_coins'] ?? 0);
        $partial = $row['partial_unit_price_coins'] === null ? null : (int)$row['partial_unit_price_coins'];
        $price = $missing === [] ? 0 : $this->offerPrice($row, $missing, $members, $itemPrices);
        $regularPrice = 0;
        foreach ($members as $itemId) $regularPrice += (int)($itemPrices[$itemId] ?? 0);
        $regularMissingPrice = 0;
        foreach ($missing as $itemId) $regularMissingPrice += (int)($itemPrices[$itemId] ?? 0);
        $catalog = $type === 'item' ? ($catalogByItem[$members[0] ?? ''] ?? []) : [];
        $metadata = is_array($catalog['metadata'] ?? null) ? $catalog['metadata'] : [];
        $slot = (string)($catalog['equip_slot'] ?? '');
        $purchasable = $missing !== [] && $price > 0;
        return [
            'offer_id' => (string)$row['offer_id'],
            'offer_type' => $type,
            'category' => (string)$row['category'],
            'subcategory' => $row['subcategory'] === null ? null : (string)$row['subcategory'],
            'item_ids' => $members,
            'missing_item_ids' => $missing,
            'owned_count' => count($members) - count($missing),
            'missing_count' => count($missing),
            'price_coins' => $purchasable ? $price : 0,
            'full_price_coins' => $fullPrice,
            'regular_price_coins' => $regularPrice > 0 ? $regularPrice : $fullPrice,
            'regular_missing_price_coins' => $regularMissingPrice,
            'partial_unit_price_coins' => $partial,
            'item_type' => $type === 'item' ? (string)($catalog['item_type'] ?? '') : 'bundle',
            'item_family' => $type === 'item' ? (string)($catalog['item_family'] ?? '') : 'bundle',
            'equip_slot' => $slot !== '' ? $slot : null,
            'metadata' => $metadata,
            'display_name' => (string)($metadata['display_name'] ?? ''),
            'equipped' => $slot !== '' && (string)($equipped[$slot] ?? '') === (string)($members[0] ?? ''),
            'purchasable' => $purchasable,
            'affordable' => $purchasable && $balance >= $price,
            'already_owned' => $missing === [],
            'auto_equip' => false,
        ];
    }

    private function itemOfferPrices(array $offerRows): array
    {
        $prices = [];
        foreach ($offerRows as $row) {
            if (!is_array($row) || (string)($row['offer_type'] ?? '') !== 'item') continue;
            $itemId = trim((string)($row['item_id'] ?? ''));
            $price = (int)($row['price_coins'] ?? 0);
            if ($itemId !== '' && $price > 0) $prices[$itemId] = $price;
        }
        return $prices;
    }

    private function offerPrice(array $offer, array $missing, array $members, array $itemPrices): int
    {
        $type = (string)($offer['offer_type'] ?? '');
        $fullPrice = (int)($offer['price_coins'] ?? 0);
        if ($type === 'item') {
            if (count($members) !== 1 || count($missing) !== 1) {
                throw new CosmeticStoreException('offer_invalid', 'Предложение магазина повреждено.');
            }
            return $fullPrice;
        }
        if ($type !== 'bundle' || $fullPrice <= 0) {
            throw new CosmeticStoreException('offer_invalid', 'Цена набора недоступна.');
        }

        $separatePrice = 0;
        foreach ($missing as $itemId) {
            $itemPrice = (int)($itemPrices[$itemId] ?? 0);
            if ($itemPrice <= 0) {
                throw new CosmeticStoreException('offer_invalid', 'Состав набора недоступен.');
            }
            $separatePrice += $itemPrice;
        }
        return min($fullPrice, $separatePrice);
    }

    private function offer(string $offerId, bool $mustBeActive, ?DatabaseConnectionInterface $database = null): array
    {
        $database ??= $this->database;
        $rows = $database->fetchAll(
            'SELECT offer_id, offer_type, item_id, category, subcategory, price_coins,
                    partial_unit_price_coins, members_json, offer_status, sort_order
             FROM mgw_product_offers WHERE offer_id = :offer_id',
            ['offer_id' => $offerId]
        );
        if (count($rows) !== 1 || !is_array($rows[0] ?? null)) {
            throw new CosmeticStoreException('offer_unavailable', 'Предложение магазина недоступно.');
        }
        $offer = $rows[0];
        if ($mustBeActive && (string)($offer['offer_status'] ?? '') !== 'active') {
            throw new CosmeticStoreException('offer_unavailable', 'Предложение магазина недоступно.');
        }
        if (!in_array((string)($offer['offer_type'] ?? ''), ['item', 'bundle'], true)) {
            throw new CosmeticStoreException('offer_invalid', 'Предложение магазина повреждено.');
        }
        return $offer;
    }

    private function members(array $offer): array
    {
        $members = $this->decodeItemIds((string)($offer['members_json'] ?? ''));
        if ((string)($offer['offer_type'] ?? '') === 'item') {
            $itemId = trim((string)($offer['item_id'] ?? ''));
            if ($itemId === '' || $members !== [$itemId]) {
                throw new CosmeticStoreException('offer_invalid', 'Состав предложения магазина поврежден.');
            }
        }
        return $members;
    }

    private function purchaseByRequest(
        DatabaseConnectionInterface $database,
        string $mgwId,
        string $requestToken,
        bool $lock
    ): ?array {
        $sql = 'SELECT purchase_id, request_token, mgw_id, account_ref, legacy_user_id,
                       offer_id, price_coins, items_json, purchase_status, created_at_utc
                FROM mgw_cosmetic_purchases
                WHERE mgw_id = :mgw_id AND request_token = :request_token';
        if ($lock && $database->driver() === 'mysql') $sql .= ' FOR UPDATE';
        $rows = $database->fetchAll($sql, ['mgw_id' => $mgwId, 'request_token' => $requestToken]);
        if (count($rows) > 1) throw new RuntimeException('Cosmetic purchase request is ambiguous.');
        return $rows[0] ?? null;
    }

    private function publicPurchase(array $row, bool $replayed): array
    {
        return [
            'purchase_id' => (string)$row['purchase_id'],
            'request_token' => (string)$row['request_token'],
            'offer_id' => (string)$row['offer_id'],
            'price_coins' => (int)$row['price_coins'],
            'item_ids' => $this->decodeItemIds((string)$row['items_json']),
            'status' => (string)$row['purchase_status'],
            'replayed' => $replayed,
            'auto_equipped' => false,
            'created_at' => (string)$row['created_at_utc'],
        ];
    }

    private function normalizeItemIds(mixed $value): array
    {
        if (!is_array($value) || !array_is_list($value) || $value === []) {
            throw new CosmeticStoreException('intent_invalid', 'Состав покупки недоступен.');
        }
        $result = [];
        foreach ($value as $itemId) {
            $itemId = strtolower(trim((string)$itemId));
            if (preg_match(self::OFFER_ID_PATTERN, $itemId) !== 1) {
                throw new CosmeticStoreException('intent_invalid', 'В покупке есть неизвестный предмет.');
            }
            $result[$itemId] = true;
        }
        $result = array_keys($result);
        sort($result, SORT_STRING);
        return $result;
    }

    private function decodeItemIds(string $json): array
    {
        try {
            $decoded = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException $error) {
            throw new CosmeticStoreException('offer_invalid', 'Состав предложения магазина поврежден.');
        }
        return $this->normalizeItemIds($decoded);
    }

    private function normalizeMgwId(string $mgwId): string
    {
        $mgwId = trim($mgwId);
        if ($mgwId === '' || strlen($mgwId) > 24) {
            throw new CosmeticStoreException('account_unavailable', 'Профиль MGW недоступен.');
        }
        return $mgwId;
    }

    private function normalizeOfferId(string $offerId): string
    {
        $offerId = strtolower(trim($offerId));
        if (preg_match(self::OFFER_ID_PATTERN, $offerId) !== 1) {
            throw new CosmeticStoreException('offer_unavailable', 'Предложение магазина недоступно.');
        }
        return $offerId;
    }

    public function normalizeRequestToken(string $requestToken): string
    {
        $requestToken = trim($requestToken);
        if (preg_match(self::REQUEST_TOKEN_PATTERN, $requestToken) !== 1) {
            throw new CosmeticStoreException('request_invalid', 'Токен покупки недоступен.');
        }
        return $requestToken;
    }

    private function requiredText(string $value, int $max, string $label): string
    {
        $value = trim($value);
        if ($value === '' || strlen($value) > $max || preg_match('/[\x00-\x1F\x7F]/', $value)) {
            throw new CosmeticStoreException('account_unavailable', 'MGW ' . $label . ' недоступен.');
        }
        return $value;
    }

    private function timestamp(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');
    }
}
