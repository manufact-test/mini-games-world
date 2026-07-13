<?php
declare(strict_types=1);

final class TelegramService
{
    public function __construct(private array $config) {}

    public function api(string $method, array $params = []): array
    {
        [$method, $params] = $this->prepareAdminUxRequest($method, $params);

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
        $webAppUrl = rtrim((string)$this->config['base_url'], '/') . '/app/?v=72';

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
        $replyMarkup = $this->paymentAdminNotificationKeyboard($payment);

        foreach ($adminIds as $adminId) {
            $chatId = trim((string)$adminId);
            if ($chatId === '') {
                continue;
            }

            try {
                $this->api('sendMessage', [
                    'chat_id' => $chatId,
                    'text' => $text,
                    'reply_markup' => $replyMarkup,
                    'disable_web_page_preview' => true,
                ]);
            } catch (Throwable $e) {
                error_log('Mini Games World admin notification failed for ' . $chatId . ': ' . $e->getMessage());
            }
        }
    }

    public function notifyUserAboutPaymentDecision(array $payment, string $decision): void
    {
        $this->persistPaymentDecisionActivity($payment, $decision);

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

    private function persistPaymentDecisionActivity(array $payment, string $decision): void
    {
        try {
            $paymentId = trim((string)($payment['id'] ?? ''));
            if ($paymentId === '') {
                return;
            }

            $db = new JsonDatabase((string)($this->config['data_dir'] ?? (__DIR__ . '/../data')));
            $db->transaction(function (array &$data) use ($payment, $decision, $paymentId): void {
                $notifications = new NotificationService();
                $notifications->addPaymentDecision($data, $payment, $decision);

                if (!isset($data['payments']) || !is_array($data['payments'])) {
                    return;
                }

                foreach ($data['payments'] as $index => $storedPayment) {
                    if (!is_array($storedPayment) || (string)($storedPayment['id'] ?? '') !== $paymentId) {
                        continue;
                    }

                    unset($data['payments'][$index]);
                    $data['payments'] = array_values($data['payments']);
                    $data['payments'][] = $storedPayment;
                    break;
                }
            });
        } catch (Throwable $e) {
            error_log('Mini Games World in-app payment notification failed: ' . $e->getMessage());
        }
    }

    private function prepareAdminUxRequest(string $method, array $params): array
    {
        if (isset($params['reply_markup']) && is_array($params['reply_markup'])) {
            $params['reply_markup'] = $this->renameAdminButtons($params['reply_markup']);
        }

        $text = (string)($params['text'] ?? '');
        if ($text !== '') {
            $text = $this->renameAdminSections($text);
            $params['text'] = $text;
        }

        return [$method, $params];
    }

    private function renameAdminButtons(array $replyMarkup): array
    {
        if (!isset($replyMarkup['inline_keyboard']) || !is_array($replyMarkup['inline_keyboard'])) {
            return $replyMarkup;
        }

        foreach ($replyMarkup['inline_keyboard'] as $rowIndex => $row) {
            if (!is_array($row)) {
                continue;
            }

            foreach ($row as $buttonIndex => $button) {
                if (!is_array($button)) {
                    continue;
                }

                $callbackData = (string)($button['callback_data'] ?? '');
                if ($callbackData === 'admin:orders') {
                    $replyMarkup['inline_keyboard'][$rowIndex][$buttonIndex]['text'] = '🎁 Заказы призов';
                }

                if ($callbackData === 'admin:payments') {
                    $oldText = (string)($button['text'] ?? '');
                    $replyMarkup['inline_keyboard'][$rowIndex][$buttonIndex]['text'] = str_contains($oldText, 'Все')
                        ? '💳 Все пополнения'
                        : '💳 Пополнения';
                }
            }
        }

        return $replyMarkup;
    }

    private function renameAdminSections(string $text): string
    {
        $isShopOrders = str_contains($text, '🎁 Заявки магазина');

        $text = str_replace('🎁 Заявки магазина', '🎁 Заказы призов', $text);
        $text = str_replace('💳 Платежи', '💳 Пополнения', $text);

        if ($isShopOrders) {
            $text = str_replace(
                'Заявок пока нет.',
                "Заказов призов пока нет.\n\nЗаявки на покупку коинов находятся в разделе «💳 Пополнения».",
                $text
            );
            $text = str_replace('Нет ожидающих заявок.', 'Нет ожидающих заказов призов.', $text);
        }

        return $text;
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
            . "Выберите действие кнопками ниже.";
    }

    private function paymentAdminNotificationKeyboard(array $payment): array
    {
        $shortId = $this->shortPaymentId((string)($payment['id'] ?? ''));

        return [
            'inline_keyboard' => [
                [
                    ['text' => '👁 Открыть заявку', 'callback_data' => 'admin:payment_open:' . $shortId],
                ],
                [
                    ['text' => '✅ Начислить', 'callback_data' => 'admin:payment_apply:' . $shortId],
                    ['text' => '🚫 Отклонить', 'callback_data' => 'admin:payment_reject_prompt:' . $shortId],
                ],
            ],
        ];
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
                . "Начислено: {$coins} коинов";
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
            . "Причина: {$reason}";
    }

    private function shortPaymentId(string $id): string
    {
        $id = preg_replace('/^(pay_)/', '', $id);
        $id = strtoupper(substr((string)$id, 0, 8));
        return $id !== '' ? $id : '-';
    }
}
