<?php
declare(strict_types=1);

require_once __DIR__ . '/AdminShopOrderUiGuard.php';
require_once __DIR__ . '/../services/NotificationService.php';
require_once __DIR__ . '/../services/ShopOrderNotificationService.php';

final class AdminShopOrderNotificationGuard
{
    public function __construct(private TelegramService $telegram, private array $config) {}

    /**
     * Delegates the existing shop-order admin UI and notifies the player only when
     * the order really changes from pending to a terminal status.
     */
    public function handle(array $update): bool
    {
        $before = $this->decisionSnapshot($update);

        $ui = new AdminShopOrderUiGuard($this->telegram, $this->config);
        $handled = $ui->handle($update);

        if (!$handled || !$before) {
            return $handled;
        }

        $after = $this->orderById((string)($before['id'] ?? ''));
        if (!$after) {
            return true;
        }

        $beforeStatus = (string)($before['status'] ?? 'pending');
        $afterStatus = (string)($after['status'] ?? '');
        $decision = null;

        if ($beforeStatus === 'pending' && $afterStatus === 'done') {
            $decision = 'done';
        } elseif ($beforeStatus === 'pending' && $afterStatus === 'rejected') {
            $decision = 'rejected';
        }

        if ($decision === null) {
            return true;
        }

        // First persist an in-app notification. The event key inside the service
        // makes this idempotent even if the same update is delivered again.
        $db = new JsonDatabase($this->dataDir());
        $db->transaction(function (array &$stored) use ($after, $decision): void {
            $notifications = new NotificationService();
            $notifications->addShopOrderDecision($stored, $after, $decision);
        });

        // Telegram is an additional delivery channel. Failure here must not erase
        // the already-saved in-app notification or change the financial result.
        $telegramNotifications = new ShopOrderNotificationService($this->telegram, $this->config);
        $telegramNotifications->notifyUserAboutDecision($after, $decision);

        return true;
    }

    private function decisionSnapshot(array $update): ?array
    {
        $callback = $update['callback_query'] ?? null;
        if (is_array($callback)) {
            $fromId = (string)($callback['from']['id'] ?? '');
            if (!$this->isAdmin($fromId)) {
                return null;
            }

            $data = trim((string)($callback['data'] ?? ''));
            if (!str_starts_with($data, 'admin:order_done:')) {
                return null;
            }

            return $this->orderById($this->callbackArgument($data));
        }

        $message = $update['message'] ?? $update['edited_message'] ?? null;
        if (!is_array($message)) {
            return null;
        }

        $fromId = (string)($message['from']['id'] ?? $message['chat']['id'] ?? '');
        $text = trim((string)($message['text'] ?? ''));
        if ($fromId === '' || $text === '' || !$this->isAdmin($fromId)) {
            return null;
        }

        $db = new JsonDatabase($this->dataDir());
        return $db->readOnly(function (array $stored) use ($fromId): ?array {
            $pending = $stored['system']['admin_pending_actions'][$fromId] ?? null;
            if (!is_array($pending) || (string)($pending['type'] ?? '') !== 'shop_order_reject') {
                return null;
            }

            $orderId = trim((string)($pending['order_id'] ?? ''));
            return $orderId !== '' ? $this->findOrder($stored, $orderId) : null;
        });
    }

    private function orderById(string $orderId): ?array
    {
        $orderId = trim($orderId);
        if ($orderId === '') {
            return null;
        }

        $db = new JsonDatabase($this->dataDir());
        return $db->readOnly(fn(array $stored): ?array => $this->findOrder($stored, $orderId));
    }

    private function findOrder(array $db, string $orderId): ?array
    {
        foreach (($db['shop_orders'] ?? []) as $order) {
            if (is_array($order) && (string)($order['id'] ?? '') === $orderId) {
                return $order;
            }
        }
        return null;
    }

    private function callbackArgument(string $data): string
    {
        $parts = explode(':', $data);
        return trim((string)end($parts));
    }

    private function isAdmin(string $telegramId): bool
    {
        return (new AdminService($this->config))->isAdmin($telegramId);
    }

    private function dataDir(): string
    {
        return (string)($this->config['data_dir'] ?? (__DIR__ . '/../data'));
    }
}
