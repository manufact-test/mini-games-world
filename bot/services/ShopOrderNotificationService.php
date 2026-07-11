<?php
declare(strict_types=1);

final class ShopOrderNotificationService
{
    public function __construct(private TelegramService $telegram, private array $config) {}

    public function notifyAdminsAboutNewOrder(array $order): void
    {
        $adminIds = $this->config['admin_ids'] ?? [];
        if (!is_array($adminIds) || !$adminIds) {
            return;
        }

        $text = $this->adminNewOrderText($order);
        $replyMarkup = $this->adminNewOrderKeyboard($order);

        foreach ($adminIds as $adminId) {
            $chatId = trim((string)$adminId);
            if ($chatId === '') {
                continue;
            }

            try {
                $this->telegram->api('sendMessage', [
                    'chat_id' => $chatId,
                    'text' => $text,
                    'reply_markup' => $replyMarkup,
                    'disable_web_page_preview' => true,
                ]);
            } catch (Throwable $e) {
                error_log('Mini Games World shop admin notification failed for ' . $chatId . ': ' . $e->getMessage());
            }
        }
    }

    public function notifyUserAboutDecision(array $order, string $decision): void
    {
        $chatId = trim((string)($order['user_id'] ?? ''));
        if ($chatId === '') {
            return;
        }

        if (!in_array($decision, ['done', 'rejected'], true)) {
            return;
        }

        try {
            $this->telegram->api('sendMessage', [
                'chat_id' => $chatId,
                'text' => $this->userDecisionText($order, $decision),
                'disable_web_page_preview' => true,
            ]);
        } catch (Throwable $e) {
            error_log('Mini Games World shop user notification failed for ' . $chatId . ': ' . $e->getMessage());
        }
    }

    private function adminNewOrderText(array $order): string
    {
        $shortId = $this->shortId((string)($order['id'] ?? ''));
        $username = trim((string)($order['username'] ?? ''));
        $userId = trim((string)($order['user_id'] ?? ''));
        $player = $username !== '' ? '@' . ltrim($username, '@') : ($userId !== '' ? 'ID ' . $userId : '—');
        $country = trim((string)($order['country'] ?? '')) ?: '—';
        $prize = $this->prizeLabel($order);
        $denomination = $this->denominationLabel($order);
        $amount = $this->goldAmount($order);
        $createdAt = trim((string)($order['created_at'] ?? '')) ?: '—';

        return "🎁 Новый заказ приза\n\n"
            . "Заявка: #{$shortId}\n"
            . "Игрок: {$player}\n"
            . "Telegram ID: " . ($userId !== '' ? $userId : '—') . "\n"
            . "Страна: {$country}\n"
            . "Приз: {$prize}\n"
            . "Номинал: {$denomination}\n"
            . "Списано: {$amount} Gold\n"
            . "Создана: {$createdAt}\n\n"
            . "Откройте заявку или сразу выберите действие кнопками ниже.";
    }

    private function adminNewOrderKeyboard(array $order): array
    {
        $id = (string)($order['id'] ?? '');
        if ($id === '') {
            return [
                'inline_keyboard' => [[
                    ['text' => '🎁 Все заказы', 'callback_data' => 'admin:orders'],
                ]],
            ];
        }

        return [
            'inline_keyboard' => [
                [
                    ['text' => '👁 Открыть заявку', 'callback_data' => 'admin:order_open:' . $id],
                ],
                [
                    ['text' => '✅ Выполнить', 'callback_data' => 'admin:order_done:' . $id],
                    ['text' => '🚫 Отклонить', 'callback_data' => 'admin:order_reject_prompt:' . $id],
                ],
            ],
        ];
    }

    private function userDecisionText(array $order, string $decision): string
    {
        $shortId = $this->shortId((string)($order['id'] ?? ''));
        $prize = $this->prizeLabel($order);
        $denomination = $this->denominationLabel($order);
        $amount = $this->goldAmount($order);

        if ($decision === 'done') {
            return "✅ Заказ выполнен\n\n"
                . "Заявка: #{$shortId}\n"
                . "Приз: {$prize}\n"
                . "Номинал: {$denomination}\n"
                . "Стоимость: {$amount} Gold\n\n"
                . "Статус уже обновлён в Mini App → Магазин → Мои заявки.";
        }

        $reason = trim((string)($order['reject_reason'] ?? $order['admin_note'] ?? ''));
        if ($reason === '') {
            $reason = 'Причина не указана.';
        }

        $refundDone = !empty($order['refund_done']);
        $refundAmount = abs((int)($order['refund_amount'] ?? $amount));
        $refundLine = $refundDone
            ? "Возвращено: +{$refundAmount} Gold\n"
            : "Возврат Gold пока не подтверждён.\n";

        return "🚫 Заказ отклонён\n\n"
            . "Заявка: #{$shortId}\n"
            . "Приз: {$prize}\n"
            . "Номинал: {$denomination}\n"
            . "Причина: {$reason}\n"
            . $refundLine . "\n"
            . "Статус и возврат можно проверить в Mini App → Магазин → Мои заявки.";
    }

    private function prizeLabel(array $order): string
    {
        return trim((string)(($order['prize_title'] ?? '') ?: ($order['provider'] ?? '') ?: 'Приз'));
    }

    private function denominationLabel(array $order): string
    {
        $label = trim((string)($order['denomination_label'] ?? ''));
        return $label !== '' ? $label : ($this->goldAmount($order) . ' Gold');
    }

    private function goldAmount(array $order): int
    {
        return abs((int)($order['gold_cost'] ?? $order['amount'] ?? 0));
    }

    private function shortId(string $id): string
    {
        $id = preg_replace('/^(shop_|order_)/i', '', $id);
        $id = strtoupper(substr((string)$id, 0, 8));
        return $id !== '' ? $id : '—';
    }
}
