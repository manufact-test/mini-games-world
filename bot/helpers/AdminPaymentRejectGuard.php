<?php
declare(strict_types=1);
require_once __DIR__ . '/AdminShopOrderNotificationGuard.php';

final class AdminPaymentRejectGuard
{
    public function __construct(private TelegramService $telegram, private array $config) {}

    /**
     * Returns true when the update was fully handled by the guard.
     * Returns false when WebhookHandler should continue processing it.
     */
    public function handle(array $update): bool
    {
        $shopOrderGuard = new AdminShopOrderNotificationGuard($this->telegram, $this->config);
        if ($shopOrderGuard->handle($update)) {
            // A shop action means the admin has left any unfinished payment-reject
            // input mode. Clear only that pending mode; the payment itself is untouched.
            $adminId = $this->adminIdFromUpdate($update);
            if ($adminId !== '' && $this->isAdmin($adminId) && $this->pendingReject($adminId)) {
                $this->clearPendingReject($adminId);
            }
            return true;
        }

        if (!empty($update['callback_query']) && is_array($update['callback_query'])) {
            return $this->handleCallback($update['callback_query']);
        }

        $message = $update['message'] ?? $update['edited_message'] ?? null;
        if (!is_array($message)) {
            return false;
        }

        return $this->handleMessage($message);
    }

    private function handleCallback(array $callback): bool
    {
        $data = (string)($callback['data'] ?? '');
        $fromId = (string)($callback['from']['id'] ?? '');

        if (!str_starts_with($data, 'admin:') || !$this->isAdmin($fromId)) {
            return false;
        }

        if (str_starts_with($data, 'admin:payment_reject_prompt:') || $data === 'admin:payment_reject_cancel') {
            return false;
        }

        // Leaving the rejection prompt cancels only the pending input mode.
        // The payment itself remains unchanged and visible in Top-ups.
        $this->clearPendingReject($fromId);
        return false;
    }

    private function handleMessage(array $message): bool
    {
        $chatId = (string)($message['chat']['id'] ?? '');
        $fromId = (string)($message['from']['id'] ?? $chatId);
        $text = trim((string)($message['text'] ?? ''));

        if ($chatId === '' || $text === '' || !$this->isAdmin($fromId)) {
            return false;
        }

        $pending = $this->pendingReject($fromId);
        if (!$pending) {
            return false;
        }

        // Explicit commands must never be consumed as a rejection reason.
        // /cancel remains handled by the existing WebhookHandler.
        if (str_starts_with($text, '/') && $text !== '/cancel') {
            $this->clearPendingReject($fromId);
            return false;
        }

        if ($text === '/cancel') {
            return false;
        }

        $createdAt = strtotime((string)($pending['created_at'] ?? '')) ?: 0;
        if ($createdAt > 0 && time() - $createdAt > 1800) {
            $this->clearPendingReject($fromId);
            $this->telegram->api('sendMessage', [
                'chat_id' => $chatId,
                'text' => "⏱ Режим отклонения закрыт: прошло больше 30 минут.\n\nЗаявка не изменилась и остаётся в разделе «💳 Пополнения».",
                'reply_markup' => $this->navigationKeyboard(),
            ]);
            return true;
        }

        // While the mode is active, the next ordinary admin message is the reason.
        // WebhookHandler validates it, rejects the payment and clears the pending mode.
        return false;
    }

    private function pendingReject(string $adminId): ?array
    {
        $db = new JsonDatabase($this->dataDir());
        return $db->readOnly(function (array $data) use ($adminId) {
            $pending = $data['system']['admin_pending_actions'][$adminId] ?? null;
            if (!is_array($pending) || (string)($pending['type'] ?? '') !== 'payment_reject') {
                return null;
            }
            return $pending;
        });
    }

    private function clearPendingReject(string $adminId): void
    {
        $db = new JsonDatabase($this->dataDir());
        $db->transaction(function (array &$data) use ($adminId) {
            $pending = $data['system']['admin_pending_actions'][$adminId] ?? null;
            if (is_array($pending) && (string)($pending['type'] ?? '') === 'payment_reject') {
                unset($data['system']['admin_pending_actions'][$adminId]);
            }
            return null;
        });
    }

    private function navigationKeyboard(): array
    {
        return [
            'inline_keyboard' => [
                [
                    ['text' => '❌ Отменить отклонение', 'callback_data' => 'admin:payment_reject_cancel'],
                ],
                [
                    ['text' => '💳 Открыть пополнения', 'callback_data' => 'admin:payments'],
                ],
            ],
        ];
    }

    private function adminIdFromUpdate(array $update): string
    {
        if (!empty($update['callback_query']) && is_array($update['callback_query'])) {
            return (string)($update['callback_query']['from']['id'] ?? '');
        }

        $message = $update['message'] ?? $update['edited_message'] ?? null;
        return is_array($message)
            ? (string)($message['from']['id'] ?? $message['chat']['id'] ?? '')
            : '';
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
