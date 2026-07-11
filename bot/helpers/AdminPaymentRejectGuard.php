<?php
declare(strict_types=1);
require_once __DIR__ . '/AdminShopOrderUiGuard.php';

final class AdminPaymentRejectGuard
{
    public function __construct(private TelegramService $telegram, private array $config) {}

    /**
     * Returns true when the update was fully handled by the guard.
     * Returns false when WebhookHandler should continue processing it.
     */
    public function handle(array $update): bool
    {
        $shopOrderGuard = new AdminShopOrderUiGuard($this->telegram, $this->config);
        if ($shopOrderGuard->handle($update)) {
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

        if ($this->isReplyToCurrentRejectPrompt($message, $pending)) {
            return false;
        }

        $shortId = $this->pendingShortId($pending);
        $this->telegram->api('sendMessage', [
            'chat_id' => $chatId,
            'text' => "⚠️ Это сообщение не принято как причина отклонения.\n\n"
                . "Заявка {$shortId} НЕ отклонена и всё ещё ожидает решения.\n\n"
                . "Чтобы отклонить именно её, ответьте на сообщение бота «Отклонение пополнения {$shortId}». "
                . "Либо отмените действие кнопкой ниже.",
            'reply_markup' => $this->navigationKeyboard(),
            'disable_web_page_preview' => true,
        ]);

        return true;
    }

    private function isReplyToCurrentRejectPrompt(array $message, array $pending): bool
    {
        $reply = $message['reply_to_message'] ?? null;
        if (!is_array($reply)) {
            return false;
        }

        $replyText = trim((string)($reply['text'] ?? ''));
        if ($replyText === '') {
            return false;
        }

        $shortId = $this->pendingShortId($pending);
        $hasPromptTitle = str_contains($replyText, 'Отклонение пополнения')
            || str_contains($replyText, 'Отклонение заявки');

        return $hasPromptTitle && $shortId !== '-' && str_contains(strtoupper($replyText), strtoupper($shortId));
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

    private function pendingShortId(array $pending): string
    {
        $id = trim((string)($pending['payment_id'] ?? ''));
        $id = preg_replace('/^(pay_)/i', '', $id);
        $id = strtoupper(substr((string)$id, 0, 8));
        return $id !== '' ? $id : '-';
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

    private function isAdmin(string $telegramId): bool
    {
        return (new AdminService($this->config))->isAdmin($telegramId);
    }

    private function dataDir(): string
    {
        return (string)($this->config['data_dir'] ?? (__DIR__ . '/../data'));
    }
}
