<?php
declare(strict_types=1);

final class ShopService
{
    private ShopCatalogService $catalog;

    public function __construct(private array $config, private UserService $users, ?ShopCatalogService $catalog = null)
    {
        $this->catalog = $catalog ?? new ShopCatalogService($config);
    }

    public function status(array $user): array
    {
        $min = $this->catalog->minGoldCost();
        $available = $this->users->goldShopAvailable($user);
        $catalog = $this->catalog->publicCatalog();

        return [
            'balance_gold' => (int)($user['balance_gold'] ?? 0),
            'available' => $available,
            'min_order' => $min,
            'wagered_total' => (int)($user['gold_wagered_total'] ?? 0),
            'spent_total' => (int)($user['gold_shop_spent_total'] ?? 0),
            'catalog_version' => (int)($catalog['version'] ?? 1),
            'catalog_updated_at' => (string)($catalog['updated_at'] ?? ''),
            'catalog_currency' => (string)($catalog['currency'] ?? 'GOLD'),
            'countries' => $catalog['countries'] ?? [],
            'items' => $catalog['items'] ?? [],
            'can_order' => $available >= $min && !empty($catalog['items']),
        ];
    }

    public function createCatalogOrder(
        array &$db,
        array &$user,
        string $itemId,
        string $denominationId,
        string $requestId
    ): array {
        $userId = (string)($user['id'] ?? '');
        if ($userId === '') {
            throw new RuntimeException('Пользователь не найден.');
        }

        $requestId = trim($requestId);
        if (!$this->isValidRequestId($requestId)) {
            throw new RuntimeException('Не удалось подтвердить уникальность заказа. Обновите магазин и попробуйте снова.');
        }

        $existing = $this->findOrderByRequestId($db, $userId, $requestId);
        if ($existing !== null) {
            if ((string)($existing['item_id'] ?? '') !== $itemId
                || (string)($existing['denomination_id'] ?? '') !== $denominationId) {
                throw new RuntimeException('Ключ заказа уже использован для другого приза. Обновите магазин и попробуйте снова.');
            }

            return [
                'created' => false,
                'order' => $existing,
            ];
        }

        $selection = $this->catalog->resolveSelection($itemId, $denominationId);
        $item = $selection['item'];
        $denomination = $selection['denomination'];
        $amount = (int)($denomination['gold_cost'] ?? 0);

        if ($amount <= 0) {
            throw new RuntimeException('У выбранного номинала некорректная стоимость.');
        }

        $available = $this->users->goldShopAvailable($user);
        if ($available < $amount) {
            throw new RuntimeException('Недостаточно Gold, доступных для магазина.');
        }
        if ((int)($user['balance_gold'] ?? 0) < $amount) {
            throw new RuntimeException('Недостаточно Gold на балансе.');
        }

        $now = now_iso();
        $user['balance_gold'] = (int)$user['balance_gold'] - $amount;
        $user['gold_shop_spent_total'] = (int)($user['gold_shop_spent_total'] ?? 0) + $amount;

        $snapshot = [
            'catalog_version' => (int)($selection['catalog_version'] ?? 1),
            'catalog_updated_at' => (string)($selection['catalog_updated_at'] ?? ''),
            'currency' => (string)($selection['currency'] ?? 'GOLD'),
            'item_id' => (string)($item['id'] ?? ''),
            'denomination_id' => (string)($denomination['id'] ?? ''),
            'country_code' => (string)($item['country_code'] ?? ''),
            'country' => (string)($item['country'] ?? ''),
            'provider_code' => (string)($item['provider_code'] ?? ''),
            'provider' => (string)($item['provider'] ?? ''),
            'title' => (string)($item['title'] ?? $item['provider'] ?? 'Приз'),
            'description' => (string)($item['description'] ?? ''),
            'delivery_type' => (string)($item['delivery_type'] ?? 'manual_code'),
            'image' => (string)($item['image'] ?? ''),
            'image_alt' => (string)($item['image_alt'] ?? ''),
            'denomination_label' => (string)($denomination['label'] ?? ($amount . ' Gold')),
            'gold_cost' => $amount,
        ];

        $order = [
            'id' => make_id('shop'),
            'client_request_id' => $requestId,
            'user_id' => $userId,
            'username' => (string)($user['username'] ?? ''),
            'catalog_version' => $snapshot['catalog_version'],
            'catalog_updated_at' => $snapshot['catalog_updated_at'],
            'item_id' => $snapshot['item_id'],
            'denomination_id' => $snapshot['denomination_id'],
            'country_code' => $snapshot['country_code'],
            'country' => $snapshot['country'],
            'provider_code' => $snapshot['provider_code'],
            'provider' => $snapshot['provider'],
            'prize_title' => $snapshot['title'],
            'denomination_label' => $snapshot['denomination_label'],
            'delivery_type' => $snapshot['delivery_type'],
            'amount' => $amount,
            'gold_cost' => $amount,
            'status' => 'pending',
            'refund_done' => false,
            'prize_snapshot' => $snapshot,
            'created_at' => $now,
        ];

        if (!isset($db['shop_orders']) || !is_array($db['shop_orders'])) {
            $db['shop_orders'] = [];
        }
        if (!isset($db['transactions']) || !is_array($db['transactions'])) {
            $db['transactions'] = [];
        }

        $db['shop_orders'][] = $order;
        $db['transactions'][] = [
            'id' => make_id('tx'),
            'type' => 'balance_change',
            'category' => 'shop_order',
            'order_id' => $order['id'],
            'client_request_id' => $requestId,
            'user_id' => $userId,
            'username' => (string)($user['username'] ?? ''),
            'room' => 'gold',
            'item_id' => $snapshot['item_id'],
            'denomination_id' => $snapshot['denomination_id'],
            'provider' => $snapshot['provider'],
            'amount' => -$amount,
            'balance_after' => (int)($user['balance_gold'] ?? 0),
            'description' => 'Заказ приза: ' . $snapshot['title'] . ' · ' . $snapshot['denomination_label'],
            'created_at' => $now,
        ];
        $db['transactions'][] = [
            'id' => make_id('tx'),
            'type' => 'shop_order',
            'order_id' => $order['id'],
            'client_request_id' => $requestId,
            'user_id' => $userId,
            'item_id' => $snapshot['item_id'],
            'denomination_id' => $snapshot['denomination_id'],
            'provider' => $snapshot['provider'],
            'amount' => $amount,
            'created_at' => $now,
        ];

        return [
            'created' => true,
            'order' => $order,
        ];
    }

    /**
     * Временная совместимость со старым API до переключения endpoint на каталог.
     * После MVP-8.3 удаляется.
     */
    public function createOrder(array &$db, array &$user, string $country, string $provider, int $amount): array
    {
        $country = clean_string($country, 40);
        $provider = clean_string($provider, 80);
        $min = $this->catalog->minGoldCost();
        if ($amount < $min) {
            throw new RuntimeException('Минимальная сумма заказа — ' . $min . ' коинов.');
        }
        $available = $this->users->goldShopAvailable($user);
        if ($available < $amount) {
            throw new RuntimeException('Недостаточно коинов, доступных для магазина.');
        }
        if ((int)($user['balance_gold'] ?? 0) < $amount) {
            throw new RuntimeException('Недостаточно коинов на балансе Gold-комнаты.');
        }

        $user['balance_gold'] = (int)$user['balance_gold'] - $amount;
        $user['gold_shop_spent_total'] = (int)($user['gold_shop_spent_total'] ?? 0) + $amount;

        $order = [
            'id' => make_id('shop'),
            'user_id' => (string)$user['id'],
            'username' => $user['username'] ?? '',
            'country' => $country,
            'provider' => $provider,
            'amount' => $amount,
            'status' => 'pending',
            'created_at' => now_iso(),
        ];
        $db['shop_orders'][] = $order;
        $db['transactions'][] = [
            'id' => make_id('tx'),
            'type' => 'balance_change',
            'category' => 'shop_order',
            'order_id' => $order['id'],
            'user_id' => (string)$user['id'],
            'username' => (string)($user['username'] ?? ''),
            'room' => 'gold',
            'provider' => $provider,
            'amount' => -$amount,
            'balance_after' => (int)($user['balance_gold'] ?? 0),
            'description' => 'Заказ приза: ' . $provider,
            'created_at' => now_iso(),
        ];
        $db['transactions'][] = [
            'id' => make_id('tx'),
            'type' => 'shop_order',
            'order_id' => $order['id'],
            'user_id' => (string)$user['id'],
            'provider' => $provider,
            'amount' => $amount,
            'created_at' => now_iso(),
        ];
        return $order;
    }

    private function findOrderByRequestId(array $db, string $userId, string $requestId): ?array
    {
        foreach (($db['shop_orders'] ?? []) as $order) {
            if ((string)($order['user_id'] ?? '') === $userId
                && (string)($order['client_request_id'] ?? '') === $requestId) {
                return $order;
            }
        }

        return null;
    }

    private function isValidRequestId(string $requestId): bool
    {
        return preg_match('/^[a-zA-Z0-9_-]{8,120}$/', $requestId) === 1;
    }
}
