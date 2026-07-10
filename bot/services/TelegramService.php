<?php
declare(strict_types=1);

final class TelegramService
{
    public function __construct(private array $config) {}

    public function api(string $method, array $params = []): array
    {
        $token = (string)$this->config['bot_token'];
        if ($token === '' || $token === 'PASTE_BOT_TOKEN_HERE') {
            throw new RuntimeException('Не указан токен бота в config.php.');
        }

        $url = 'https://api.telegram.org/bot' . $token . '/' . $method;
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($params, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15,
        ]);

        $raw = curl_exec($ch);
        $error = curl_error($ch);
        curl_close($ch);

        if ($raw === false) {
            throw new RuntimeException('Telegram API error: ' . $error);
        }

        $data = json_decode($raw, true);
        if (!is_array($data)) {
            throw new RuntimeException('Telegram API returned invalid JSON.');
        }

        return $data;
    }

    public function sendStartMessage(int|string $chatId): void
    {
        $webAppUrl = rtrim((string)$this->config['base_url'], '/') . '/app/?v=21';

        $this->api('sendMessage', [
            'chat_id' => $chatId,
            'text' => "Добро пожаловать в Mini Games World.\n\nОткройте приложение и сыграйте первый матч.",
            'reply_markup' => [
                'inline_keyboard' => [[
                    ['text' => 'Открыть игру', 'web_app' => ['url' => $webAppUrl]],
                ]],
            ],
        ]);
    }

    public function notifyAdminsAboutPayment(array $payment): void
    {
        $adminIds = $this->config['admin_ids'] ?? [];
        if (!is_array($adminIds) || !$adminIds) {
            return;
        }

        $text = $this->paymentAdminNotificationText($payment);
        foreach ($adminIds as $adminId) {
            $chatId = trim((string)$adminId);
            if ($chatId === '') {
                continue;
            }

            try {
                $this->api('sendMessage', [
                    'chat_id' => $chatId,
                    'text' => $text,
                    'disable_web_page_preview' => true,
                ]);
            } catch (Throwable $e) {
                error_log('Mini Games World admin notification failed for ' . $chatId . ': ' . $e->getMessage());
            }
        }
    }

    public function notifyUserAboutPaymentDecision(array $payment, string $decision): void
    {
        $chatId = trim((string)($payment['user_id'] ?? ''));
        if ($chatId === '') {
            return;
        }

        $text = $this->paymentUserDecisionText($payment, $decision);

        try {
            $this->api('sendMessage', [
                'chat_id' => $chatId,
                'text' => $text,
                'disable_web_page_preview' => true,
            ]);
        } catch (Throwable $e) {
            error_log('Mini Games World user payment notification failed for ' . $chatId . ': ' . $e->getMessage());
        }
    }

    private function paymentAdminNotificationText(array $payment): string
    {
        $id = (string)($payment['id'] ?? '');
        $shortId = $this->shortPaymentId($id);
        $room = (string)($payment['room'] ?? 'gold');
        $roomLabel = $room === 'match' ? 'Match' : 'Gold';
        $price = (int)($payment['price'] ?? $payment['amount_rub'] ?? 0);
        $currency = (string)($payment['currency'] ?? 'RUB');
        $coins = (int)($payment['coins'] ?? 0);
        $username = (string)($payment['username'] ?? '');
        $name = trim((string)($payment['first_name'] ?? '') . ' ' . (string)($payment['last_name'] ?? ''));
        $userId = (string)($payment['user_id'] ?? '');
        $createdAt = (string)($payment['created_at'] ?? '');

        $playerParts = [];
        if ($name !== '') {
            $playerParts[] = $name;
        }
        if ($username !== '') {
            $playerParts[] = '@' . ltrim($username, '@');
        }
        if (!$playerParts) {
            $playerParts[] = 'Без имени';
        }

        return "💳 Новая заявка на пополнение\n\n"
            . "Игрок: " . implode(' · ', $playerParts) . "\n"
            . "Telegram ID: " . ($userId !== '' ? $userId : '—') . "\n"
            . "Комната: {$roomLabel}\n"
            . "Сумма: {$price} {$currency}\n"
            . "К зачислению: {$coins} коинов\n"
            . "Заявка: {$shortId}\n"
            . "Создана: " . ($createdAt !== '' ? $createdAt : '—') . "\n\n"
            . "Открыть:\n/mgw_private_admin_7291_payment {$shortId}\n\n"
            . "Начислить:\n/mgw_private_admin_7291_payment_apply {$shortId}\n\n"
            . "Отклонить:\n/mgw_private_admin_7291_payment_reject {$shortId} причина";
    }

    private function paymentUserDecisionText(array $payment, string $decision): string
    {
        $shortId = $this->shortPaymentId((string)($payment['id'] ?? ''));
        $room = (string)($payment['room'] ?? 'gold');
        $roomLabel = $room === 'match' ? 'Match' : 'Gold';
        $price = (int)($payment['price'] ?? $payment['amount_rub'] ?? 0);
        $currency = (string)($payment['currency'] ?? 'RUB');
        $coins = (int)($payment['coins'] ?? 0);

        if ($decision === 'applied') {
            return "✅ Пополнение подтверждено\n\n"
                . "Заявка: {$shortId}\n"
                . "Комната: {$roomLabel}\n"
                . "Сумма: {$price} {$currency}\n"
                . "Начислено: {$coins} коинов\n\n"
                . "Баланс уже обновлён. Откройте Mini App и проверьте счёт.";
        }

        $reason = trim((string)($payment['reject_reason'] ?? ''));
        if ($reason === '') {
            $reason = 'отклонено администратором';
        }

        return "🚫 Заявка на пополнение отклонена\n\n"
            . "Заявка: {$shortId}\n"
            . "Комната: {$roomLabel}\n"
            . "Сумма: {$price} {$currency}\n"
            . "К зачислению было: {$coins} коинов\n"
            . "Причина: {$reason}\n\n"
            . "Баланс не изменён. При необходимости напишите в поддержку.";
    }

    private function shortPaymentId(string $id): string
    {
        $id = preg_replace('/^(pay_)/', '', $id);
        $id = strtoupper(substr((string)$id, 0, 8));
        return $id !== '' ? $id : '-';
    }
}
