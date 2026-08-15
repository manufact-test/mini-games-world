<?php
declare(strict_types=1);

require_once __DIR__ . '/../economy/UnifiedBalanceRuntimeState.php';
require_once __DIR__ . '/../runtime/UnifiedGameZonePolicy.php';

final class ShopService
{
    private const ORDER_TOKEN_BASE = 1000000000;
    private const RECENT_DUPLICATE_WINDOW_SECONDS = 20;

    private ShopCatalogService $catalog;

    public function __construct(private array $config, private UserService $users, ?ShopCatalogService $catalog = null)
    {
        $this->catalog = $catalog ?? new ShopCatalogService($config);
    }

    public function status(array $user): array
{
    return [
        'balance' => (int)($user[UnifiedBalanceRuntimeState::FIELD] ?? 0),
        'available' => 0,
        'can_order' => false,
        'mode' => 'archive_read_only',
        'message' => UnifiedGameZonePolicy::legacyArchiveMessage(),
    ];
}

    public function createOrder(array &$db, array &$user, string $itemId, string $denominationId, int $requestToken): array
{
    UnifiedGameZonePolicy::rejectLegacyCommerceWrite();
}

    public function createCatalogOrder(
    array &$db,
    array &$user,
    string $itemId,
    string $denominationId,
    string $requestId,
    int $expectedAmount
): array {
    UnifiedGameZonePolicy::rejectLegacyCommerceWrite();
}

    private function scheduleAdminNotificationAfterPersist(string $orderId): void
    {
        $config = $this->config;

        register_shutdown_function(static function () use ($config, $orderId): void {
            try {
                $dataDir = (string)($config['data_dir'] ?? (__DIR__ . '/../data'));
                $database = StorageFactory::createJson($dataDir);
                $order = $database->readOnly(static function (array $stored) use ($orderId): ?array {
                    foreach (($stored['shop_orders'] ?? []) as $candidate) {
                        if (is_array($candidate) && (string)($candidate['id'] ?? '') === $orderId) {
                            return $candidate;
                        }
                    }
                    return null;
                });

                if (!$order) {
                    return;
                }

                $telegram = new TelegramService($config);
                $notifications = new ShopOrderNotificationService($telegram, $config);
                $notifications->notifyAdminsAboutNewOrder($order);
            } catch (Throwable $e) {
                error_log('Mini Games World shop order admin notification failed: ' . $e->getMessage());
            }
        });
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

    private function findRecentPendingDuplicate(array $db, string $userId, string $itemId, string $denominationId): ?array
    {
        $now = time();
        foreach (array_reverse($db['shop_orders'] ?? []) as $order) {
            if (!is_array($order)) {
                continue;
            }
            if ((string)($order['user_id'] ?? '') !== $userId
                || (string)($order['item_id'] ?? '') !== $itemId
                || (string)($order['denomination_id'] ?? '') !== $denominationId
                || (string)($order['status'] ?? 'pending') !== 'pending') {
                continue;
            }

            $createdAt = strtotime((string)($order['created_at'] ?? '')) ?: 0;
            if ($createdAt > 0 && ($now - $createdAt) <= self::RECENT_DUPLICATE_WINDOW_SECONDS) {
                return $order;
            }

            // Orders are appended chronologically. Once the newest matching pending
            // order is outside the protection window, older ones cannot qualify.
            if ($createdAt > 0 && ($now - $createdAt) > self::RECENT_DUPLICATE_WINDOW_SECONDS) {
                break;
            }
        }

        return null;
    }

    private function isValidRequestId(string $requestId): bool
    {
        return preg_match('/^[a-zA-Z0-9_-]{8,120}$/', $requestId) === 1;
    }
}
