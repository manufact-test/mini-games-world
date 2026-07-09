<?php
declare(strict_types=1);

final class ShopService
{
    public function __construct(private array $config, private UserService $users) {}

    public function status(array $user): array
    {
        $min = (int)($this->config['shop_min_order'] ?? 1000);
        $available = $this->users->goldShopAvailable($user);
        return [
            'balance_gold' => (int)($user['balance_gold'] ?? 0),
            'available' => $available,
            'min_order' => $min,
            'wagered_total' => (int)($user['gold_wagered_total'] ?? 0),
            'spent_total' => (int)($user['gold_shop_spent_total'] ?? 0),
            'items' => $this->items(),
            'can_order' => $available >= $min,
        ];
    }

    public function createOrder(array &$db, array &$user, string $country, string $provider, int $amount): array
    {
        $country = clean_string($country, 40);
        $provider = clean_string($provider, 80);
        $min = (int)($this->config['shop_min_order'] ?? 1000);
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

    private function items(): array
    {
        return [
            ['country' => 'Россия', 'provider' => 'Ozon', 'min_amount' => (int)($this->config['shop_min_order'] ?? 1000)],
            ['country' => 'Россия', 'provider' => 'Wildberries', 'min_amount' => (int)($this->config['shop_min_order'] ?? 1000)],
            ['country' => 'Беларусь', 'provider' => 'Wildberries', 'min_amount' => (int)($this->config['shop_min_order'] ?? 1000)],
            ['country' => 'Мир', 'provider' => 'AliExpress', 'min_amount' => (int)($this->config['shop_min_order'] ?? 1000)],
        ];
    }
}
