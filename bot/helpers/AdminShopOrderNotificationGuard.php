<?php
declare(strict_types=1);

require_once __DIR__ . '/AdminShopOrderUiGuard.php';
require_once __DIR__ . '/../services/NotificationService.php';
require_once __DIR__ . '/../services/ShopOrderNotificationService.php';

final class AdminShopOrderNotificationGuard
{
    public function __construct(private TelegramService $telegram, private array $config) {}

    /**
     * Handles the modern button UI and the legacy direct shop commands.
     * Player notifications are emitted only when an order really moves from
     * pending to a terminal status.
     */
    public function handle(array $update): bool
    {
        $this->clearPendingShopRejectWhenNavigatingAway($update);

        if ($this->handleDirectDecisionCommand($update)) {
            return true;
        }

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

        $decision = $this->decisionTransition($before, $after);
        if ($decision === null) {
            return true;
        }

        $this->persistInAppNotification($after, $decision);
        $this->sendTelegramNotification($after, $decision);

        return true;
    }

    /**
     * Legacy commands stay available as an emergency fallback, but must have
     * the same idempotency and notification behaviour as the button workflow.
     */
    private function handleDirectDecisionCommand(array $update): bool
    {
        $message = $update['message'] ?? $update['edited_message'] ?? null;
        if (!is_array($message)) {
            return false;
        }

        $chatId = (string)($message['chat']['id'] ?? '');
        $fromId = (string)($message['from']['id'] ?? $chatId);
        $text = trim((string)($message['text'] ?? ''));

        if ($chatId === '' || $text === '' || !$this->isAdmin($fromId)) {
            return false;
        }

        $doneCommand = (string)($this->config['admin_order_done_command'] ?? '/mgw_private_admin_7291_order_done');
        $rejectCommand = (string)($this->config['admin_order_reject_command'] ?? '/mgw_private_admin_7291_order_reject');

        if ($this->startsWithCommand($text, $doneCommand)) {
            $decision = 'done';
        } elseif ($this->startsWithCommand($text, $rejectCommand)) {
            $decision = 'rejected';
        } else {
            return false;
        }

        $argument = $this->commandArgument($text);
        [$query, $note] = $this->splitActionArgument($argument);

        if ($query === '') {
            $this->telegram->api('sendMessage', [
                'chat_id' => $chatId,
                'text' => $decision === 'done'
                    ? "✅ Выполнить заказ\n\nУкажите ID заказа."
                    : "🚫 Отклонить заказ\n\nУкажите ID заказа и причину отклонения.",
                'reply_markup' => (new AdminService($this->config))->keyboard(),
            ]);
            return true;
        }

        if ($decision === 'rejected' && mb_strlen($note) < 3) {
            $this->telegram->api('sendMessage', [
                'chat_id' => $chatId,
                'text' => "⚠️ Укажите причину отклонения длиной не менее трёх символов.\n\nЗаказ не изменён.",
                'reply_markup' => (new AdminService($this->config))->keyboard(),
            ]);
            return true;
        }

        $db = StorageFactory::createJson($this->dataDir());
        $admin = new AdminService($this->config);

        $result = $db->transaction(function (array &$stored) use ($admin, $argument, $fromId, $decision, $query): array {
            $before = $this->findOrderByQuery($stored, $query);

            $adminMessage = $decision === 'done'
                ? $admin->completeOrder($stored, $argument, $fromId)
                : $admin->rejectOrder($stored, $argument, $fromId);

            $after = $this->findOrderByQuery($stored, $query);
            $transition = $before && $after ? $this->decisionTransition($before, $after) : null;

            return [
                'message' => $adminMessage,
                'order' => $after,
                'decision' => $transition,
            ];
        });

        $this->telegram->api('sendMessage', [
            'chat_id' => $chatId,
            'text' => (string)($result['message'] ?? ''),
            'reply_markup' => $admin->keyboard(),
            'disable_web_page_preview' => true,
        ]);

        if (is_array($result['order'] ?? null) && is_string($result['decision'] ?? null)) {
            $this->persistInAppNotification($result['order'], $result['decision']);
            $this->sendTelegramNotification($result['order'], $result['decision']);
        }

        return true;
    }

    private function clearPendingShopRejectWhenNavigatingAway(array $update): void
    {
        $callback = $update['callback_query'] ?? null;
        if (is_array($callback)) {
            $fromId = (string)($callback['from']['id'] ?? '');
            $data = trim((string)($callback['data'] ?? ''));

            if ($fromId === '' || !$this->isAdmin($fromId)) {
                return;
            }

            $leavesPrompt = $data === 'admin:orders'
                || str_starts_with($data, 'admin:order_open:')
                || str_starts_with($data, 'admin:order_done:');

            if ($leavesPrompt) {
                $this->clearPendingShopReject($fromId);
            }
            return;
        }

        $message = $update['message'] ?? $update['edited_message'] ?? null;
        if (!is_array($message)) {
            return;
        }

        $fromId = (string)($message['from']['id'] ?? $message['chat']['id'] ?? '');
        $text = trim((string)($message['text'] ?? ''));
        if ($fromId === '' || $text === '' || !$this->isAdmin($fromId)) {
            return;
        }

        $doneCommand = (string)($this->config['admin_order_done_command'] ?? '/mgw_private_admin_7291_order_done');
        $rejectCommand = (string)($this->config['admin_order_reject_command'] ?? '/mgw_private_admin_7291_order_reject');
        if ($this->startsWithCommand($text, $doneCommand) || $this->startsWithCommand($text, $rejectCommand)) {
            $this->clearPendingShopReject($fromId);
        }
    }

    private function clearPendingShopReject(string $adminId): void
    {
        $db = StorageFactory::createJson($this->dataDir());
        $db->transaction(function (array &$stored) use ($adminId): void {
            $pending = $stored['system']['admin_pending_actions'][$adminId] ?? null;
            if (is_array($pending) && (string)($pending['type'] ?? '') === 'shop_order_reject') {
                unset($stored['system']['admin_pending_actions'][$adminId]);
            }
        });
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

        $db = StorageFactory::createJson($this->dataDir());
        return $db->readOnly(function (array $stored) use ($fromId): ?array {
            $pending = $stored['system']['admin_pending_actions'][$fromId] ?? null;
            if (!is_array($pending) || (string)($pending['type'] ?? '') !== 'shop_order_reject') {
                return null;
            }

            $orderId = trim((string)($pending['order_id'] ?? ''));
            return $orderId !== '' ? $this->findOrder($stored, $orderId) : null;
        });
    }

    private function decisionTransition(array $before, array $after): ?string
    {
        $beforeStatus = (string)($before['status'] ?? 'pending');
        $afterStatus = (string)($after['status'] ?? '');

        if ($beforeStatus === 'pending' && $afterStatus === 'done') {
            return 'done';
        }
        if ($beforeStatus === 'pending' && $afterStatus === 'rejected') {
            return 'rejected';
        }

        return null;
    }

    private function persistInAppNotification(array $order, string $decision): void
    {
        try {
            $db = StorageFactory::createJson($this->dataDir());
            $db->transaction(function (array &$stored) use ($order, $decision): void {
                $notifications = new NotificationService();
                $notifications->addShopOrderDecision($stored, $order, $decision);
            });
        } catch (Throwable $e) {
            error_log('Mini Games World in-app shop notification failed: ' . $e->getMessage());
        }
    }

    private function sendTelegramNotification(array $order, string $decision): void
    {
        try {
            $telegramNotifications = new ShopOrderNotificationService($this->telegram, $this->config);
            $telegramNotifications->notifyUserAboutDecision($order, $decision);
        } catch (Throwable $e) {
            error_log('Mini Games World Telegram shop notification failed: ' . $e->getMessage());
        }
    }

    private function orderById(string $orderId): ?array
    {
        $orderId = trim($orderId);
        if ($orderId === '') {
            return null;
        }

        $db = StorageFactory::createJson($this->dataDir());
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

    private function findOrderByQuery(array $db, string $query): ?array
    {
        $query = ltrim(trim($query), '#№');
        if ($query === '') {
            return null;
        }

        $queryLower = mb_strtolower($query);
        foreach (($db['shop_orders'] ?? []) as $order) {
            if (!is_array($order)) {
                continue;
            }

            $id = (string)($order['id'] ?? '');
            $short = $this->shortId($id);

            if ($query === $id
                || mb_strtolower($short) === $queryLower
                || str_starts_with(mb_strtolower($id), $queryLower)) {
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

    private function commandArgument(string $text): string
    {
        $parts = preg_split('/\s+/', trim($text), 2);
        return trim((string)($parts[1] ?? ''));
    }

    private function splitActionArgument(string $argument): array
    {
        $parts = preg_split('/\s+/', trim($argument), 2);
        return [
            trim((string)($parts[0] ?? '')),
            trim((string)($parts[1] ?? '')),
        ];
    }

    private function startsWithCommand(string $text, string $command): bool
    {
        if ($command === '') {
            return false;
        }

        $token = preg_split('/\s+/', trim($text), 2)[0] ?? '';
        $tokenWithoutBot = explode('@', (string)$token, 2)[0];
        return $token === $command || $tokenWithoutBot === $command;
    }

    private function shortId(string $id): string
    {
        $id = preg_replace('/^(shop_|order_)/i', '', $id);
        $id = strtoupper(substr((string)$id, 0, 8));
        return $id !== '' ? $id : '—';
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
