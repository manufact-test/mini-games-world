<?php
declare(strict_types=1);

final class AdminShopOrderUiGuard
{
    private const PENDING_TYPE = 'shop_order_reject';
    private const PENDING_TTL = 1800;

    public function __construct(private TelegramService $telegram, private array $config) {}

    /** Returns true when this update was fully handled here. */
    public function handle(array $update): bool
    {
        if (!empty($update['callback_query']) && is_array($update['callback_query'])) {
            return $this->handleCallback($update['callback_query']);
        }

        $message = $update['message'] ?? $update['edited_message'] ?? null;
        return is_array($message) ? $this->handleMessage($message) : false;
    }

    private function handleCallback(array $callback): bool
    {
        $data = trim((string)($callback['data'] ?? ''));
        $callbackId = (string)($callback['id'] ?? '');
        $fromId = (string)($callback['from']['id'] ?? '');
        $message = $callback['message'] ?? null;
        $chatId = (string)($message['chat']['id'] ?? '');
        $messageId = (int)($message['message_id'] ?? 0);

        if (!str_starts_with($data, 'admin:') || !$this->isAdmin($fromId)) {
            return false;
        }

        $isShopAction = $data === 'admin:orders'
            || str_starts_with($data, 'admin:order_open:')
            || str_starts_with($data, 'admin:order_done:')
            || str_starts_with($data, 'admin:order_reject_prompt:')
            || $data === 'admin:order_reject_cancel';

        if (!$isShopAction) {
            $this->clearPendingReject($fromId);
            return false;
        }

        if ($chatId === '') {
            return false;
        }

        $db = new JsonDatabase($this->dataDir());
        $text = '';
        $replyMarkup = $this->ordersKeyboard([]);
        $callbackText = '';
        $forceReplyPrompt = null;

        if ($data === 'admin:orders') {
            $snapshot = $db->readOnly(function (array $stored): array {
                return [
                    'text' => $this->ordersText($stored),
                    'keyboard' => $this->ordersKeyboard($stored),
                ];
            });
            $text = $snapshot['text'];
            $replyMarkup = $snapshot['keyboard'];
            $callbackText = 'Открываю заказы';
        } elseif (str_starts_with($data, 'admin:order_open:')) {
            $orderId = $this->callbackArgument($data);
            $order = $db->readOnly(fn(array $stored) => $this->findOrder($stored, $orderId));
            if (!$order) {
                $text = "⚠️ Заявка не найдена.\n\nВернитесь к списку и выберите актуальную заявку.";
                $replyMarkup = $this->backToOrdersKeyboard();
            } else {
                $text = $this->orderDetailsText($order);
                $replyMarkup = $this->orderActionKeyboard($order);
            }
            $callbackText = 'Открываю заявку';
        } elseif (str_starts_with($data, 'admin:order_done:')) {
            $orderId = $this->callbackArgument($data);
            $result = $db->transaction(function (array &$stored) use ($orderId, $fromId): array {
                $order = $this->findOrder($stored, $orderId);
                if (!$order) {
                    return ['order' => null, 'message' => 'Заявка не найдена.'];
                }

                $admin = new AdminService($this->config);
                $admin->completeOrder($stored, (string)$order['id'], $fromId);
                return [
                    'order' => $this->findOrder($stored, (string)$order['id']),
                    'message' => 'Заявка обработана.',
                ];
            });

            if (!$result['order']) {
                $text = "⚠️ {$result['message']}";
                $replyMarkup = $this->backToOrdersKeyboard();
            } else {
                $text = $this->orderDetailsText($result['order']);
                $replyMarkup = $this->orderActionKeyboard($result['order']);
            }
            $callbackText = 'Готово';
        } elseif (str_starts_with($data, 'admin:order_reject_prompt:')) {
            $orderId = $this->callbackArgument($data);
            $result = $this->startRejectPrompt($db, $orderId, $fromId);
            $text = (string)$result['text'];
            $replyMarkup = $this->pendingRejectKeyboard();
            $forceReplyPrompt = $result['prompt'] ?? null;
            $callbackText = !empty($result['ok']) ? 'Жду причину' : 'Действие недоступно';
        } else {
            $pendingOrderId = $this->pendingOrderId($fromId);
            $this->clearPendingReject($fromId);
            $order = $pendingOrderId !== ''
                ? $db->readOnly(fn(array $stored) => $this->findOrder($stored, $pendingOrderId))
                : null;
            $text = "❌ Отклонение отменено. Заявка не изменилась.";
            if ($order) {
                $text .= "\n\n" . $this->orderDetailsText($order);
                $replyMarkup = $this->orderActionKeyboard($order);
            } else {
                $replyMarkup = $this->backToOrdersKeyboard();
            }
            $callbackText = 'Отменено';
        }

        $this->answerCallback($callbackId, $callbackText);
        $this->renderMessage($chatId, $messageId, $text, $replyMarkup);

        if (is_array($forceReplyPrompt) && !empty($forceReplyPrompt['text'])) {
            $this->telegram->api('sendMessage', [
                'chat_id' => $chatId,
                'text' => (string)$forceReplyPrompt['text'],
                'reply_markup' => [
                    'force_reply' => true,
                    'selective' => true,
                    'input_field_placeholder' => 'Причина отклонения',
                ],
            ]);
        }

        return true;
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

        if (str_starts_with($text, '/') && $text !== '/cancel') {
            $this->clearPendingReject($fromId);
            return false;
        }

        if ($text === '/cancel') {
            $this->clearPendingReject($fromId);
            $this->telegram->api('sendMessage', [
                'chat_id' => $chatId,
                'text' => '❌ Отклонение отменено. Заявка не изменилась.',
                'reply_markup' => $this->backToOrdersKeyboard(),
            ]);
            return true;
        }

        if ($this->isExpired($pending)) {
            $this->clearPendingReject($fromId);
            $this->telegram->api('sendMessage', [
                'chat_id' => $chatId,
                'text' => "⏱ Время ожидания причины истекло.\n\nЗаявка не изменилась. Откройте её снова и нажмите «Отклонить».",
                'reply_markup' => $this->backToOrdersKeyboard(),
            ]);
            return true;
        }

        if (!$this->isReplyToCurrentPrompt($message, $pending)) {
            $shortId = $this->shortId((string)($pending['order_id'] ?? ''));
            $this->telegram->api('sendMessage', [
                'chat_id' => $chatId,
                'text' => "⚠️ Это сообщение не принято как причина.\n\nЗаявка #{$shortId} не изменилась. Ответьте именно на сообщение бота «Отклонение заказа #{$shortId}».",
                'reply_markup' => $this->pendingRejectKeyboard(),
            ]);
            return true;
        }

        if (mb_strlen($text) < 3) {
            $this->telegram->api('sendMessage', [
                'chat_id' => $chatId,
                'text' => '⚠️ Причина слишком короткая. Напишите хотя бы 3 символа.',
                'reply_markup' => $this->pendingRejectKeyboard(),
            ]);
            return true;
        }

        $db = new JsonDatabase($this->dataDir());
        $result = $db->transaction(function (array &$stored) use ($fromId, $text): array {
            $pending = $stored['system']['admin_pending_actions'][$fromId] ?? null;
            if (!is_array($pending) || (string)($pending['type'] ?? '') !== self::PENDING_TYPE) {
                return ['order' => null, 'message' => 'Режим отклонения уже закрыт.'];
            }

            if ($this->isExpired($pending)) {
                unset($stored['system']['admin_pending_actions'][$fromId]);
                return ['order' => null, 'message' => 'Время ожидания причины истекло.'];
            }

            $orderId = (string)($pending['order_id'] ?? '');
            $order = $this->findOrder($stored, $orderId);
            unset($stored['system']['admin_pending_actions'][$fromId]);

            if (!$order) {
                return ['order' => null, 'message' => 'Заявка не найдена.'];
            }
            if ((string)($order['status'] ?? 'pending') !== 'pending') {
                return ['order' => $order, 'message' => 'Заявка уже была обработана.'];
            }

            $admin = new AdminService($this->config);
            $admin->rejectOrder($stored, (string)$order['id'] . ' ' . $text, $fromId);
            return [
                'order' => $this->findOrder($stored, (string)$order['id']),
                'message' => 'Заявка отклонена, Gold возвращён.',
            ];
        });

        if ($result['order']) {
            $out = "🚫 {$result['message']}\n\n" . $this->orderDetailsText($result['order']);
            $keyboard = $this->orderActionKeyboard($result['order']);
        } else {
            $out = "⚠️ {$result['message']}";
            $keyboard = $this->backToOrdersKeyboard();
        }

        $this->telegram->api('sendMessage', [
            'chat_id' => $chatId,
            'text' => $out,
            'reply_markup' => $keyboard,
            'disable_web_page_preview' => true,
        ]);

        return true;
    }

    private function startRejectPrompt(JsonDatabase $db, string $orderId, string $adminId): array
    {
        return $db->transaction(function (array &$stored) use ($orderId, $adminId): array {
            $order = $this->findOrder($stored, $orderId);
            if (!$order) {
                return [
                    'ok' => false,
                    'text' => "⚠️ Заявка не найдена.\n\nВернитесь к списку заказов.",
                    'prompt' => null,
                ];
            }

            if ((string)($order['status'] ?? 'pending') !== 'pending') {
                return [
                    'ok' => false,
                    'text' => "⚠️ Эта заявка уже обработана.\n\n" . $this->orderDetailsText($order),
                    'prompt' => null,
                ];
            }

            if (!isset($stored['system']) || !is_array($stored['system'])) {
                $stored['system'] = [];
            }
            if (!isset($stored['system']['admin_pending_actions']) || !is_array($stored['system']['admin_pending_actions'])) {
                $stored['system']['admin_pending_actions'] = [];
            }

            $stored['system']['admin_pending_actions'][$adminId] = [
                'type' => self::PENDING_TYPE,
                'order_id' => (string)$order['id'],
                'created_at' => now_iso(),
            ];

            $shortId = $this->shortId((string)$order['id']);
            return [
                'ok' => true,
                'text' => "🚫 Отклонение заказа #{$shortId}\n\nОтветьте на отдельное сообщение бота причиной отклонения. До этого заявка останется без изменений.",
                'prompt' => [
                    'text' => "🚫 Отклонение заказа #{$shortId}\n\nОтветьте на это сообщение причиной. После ответа заявка будет отклонена, а Gold вернётся игроку.",
                ],
            ];
        });
    }

    private function ordersText(array $db): string
    {
        [$pending, $processed] = $this->splitOrders($db);

        $lines = [
            '🎁 Заказы призов',
            '',
            'Ожидают обработки: ' . count($pending),
            'Обработано: ' . count($processed),
        ];

        if ($pending) {
            $lines[] = '';
            $lines[] = '⏳ Выберите заявку кнопкой ниже:';
            foreach (array_slice($pending, 0, 10) as $order) {
                $lines[] = '• #' . $this->shortId((string)($order['id'] ?? ''))
                    . ' · ' . $this->orderUserLabel($order)
                    . ' · ' . $this->orderPrizeLabel($order)
                    . ' · ' . $this->formatGold($order);
            }
        } else {
            $lines[] = '';
            $lines[] = '✅ Ожидающих заявок нет.';
        }

        if ($processed) {
            $lines[] = '';
            $lines[] = 'Последние обработанные:';
            foreach (array_slice($processed, 0, 5) as $order) {
                $lines[] = $this->statusIcon((string)($order['status'] ?? ''))
                    . ' #' . $this->shortId((string)($order['id'] ?? ''))
                    . ' · ' . $this->orderPrizeLabel($order)
                    . ' · ' . $this->statusLabel((string)($order['status'] ?? ''));
            }
        }

        return implode("\n", $lines);
    }

    private function ordersKeyboard(array $db): array
    {
        [$pending, $processed] = $this->splitOrders($db);
        $rows = [];

        foreach (array_slice($pending, 0, 10) as $order) {
            $rows[] = [[
                'text' => '🎁 #' . $this->shortId((string)($order['id'] ?? ''))
                    . ' · ' . $this->orderPrizeLabel($order)
                    . ' · ' . $this->formatGold($order),
                'callback_data' => 'admin:order_open:' . (string)($order['id'] ?? ''),
            ]];
        }

        foreach (array_slice($processed, 0, 3) as $order) {
            $rows[] = [[
                'text' => $this->statusIcon((string)($order['status'] ?? ''))
                    . ' #' . $this->shortId((string)($order['id'] ?? ''))
                    . ' · ' . $this->statusLabel((string)($order['status'] ?? '')),
                'callback_data' => 'admin:order_open:' . (string)($order['id'] ?? ''),
            ]];
        }

        $rows[] = [
            ['text' => '🔄 Обновить', 'callback_data' => 'admin:orders'],
            ['text' => '📊 Панель', 'callback_data' => 'admin:dashboard'],
        ];

        return ['inline_keyboard' => $rows];
    }

    private function orderDetailsText(array $order): string
    {
        $status = (string)($order['status'] ?? 'pending');
        $lines = [
            '🎁 Заявка #' . $this->shortId((string)($order['id'] ?? '')),
            '',
            'Статус: ' . $this->statusLabel($status),
            'Игрок: ' . $this->orderUserLabel($order),
            'Страна: ' . ((string)($order['country'] ?? '') ?: '—'),
            'Приз: ' . $this->orderPrizeLabel($order),
            'Номинал: ' . ((string)($order['denomination_label'] ?? '') ?: $this->formatGold($order)),
            'Стоимость: ' . $this->formatGold($order),
            'Создана: ' . $this->formatDate((string)($order['created_at'] ?? '')),
        ];

        if (!empty($order['completed_at'])) {
            $lines[] = 'Выполнена: ' . $this->formatDate((string)$order['completed_at']);
        }
        if (!empty($order['rejected_at'])) {
            $lines[] = 'Отклонена: ' . $this->formatDate((string)$order['rejected_at']);
        }
        if (!empty($order['refund_done'])) {
            $lines[] = 'Возврат: +' . abs((int)($order['refund_amount'] ?? $order['amount'] ?? 0)) . ' Gold';
        }
        if ($status === 'rejected' && !empty($order['admin_note'])) {
            $lines[] = 'Причина: ' . (string)$order['admin_note'];
        }

        if ($status === 'pending') {
            $lines[] = '';
            $lines[] = 'Выберите действие кнопкой ниже.';
        }

        return implode("\n", $lines);
    }

    private function orderActionKeyboard(array $order): array
    {
        $id = (string)($order['id'] ?? '');
        $status = (string)($order['status'] ?? 'pending');
        $rows = [];

        if ($status === 'pending' && $id !== '') {
            $rows[] = [
                ['text' => '✅ Выполнить', 'callback_data' => 'admin:order_done:' . $id],
                ['text' => '🚫 Отклонить', 'callback_data' => 'admin:order_reject_prompt:' . $id],
            ];
        }

        $rows[] = [
            ['text' => '🎁 Все заказы', 'callback_data' => 'admin:orders'],
            ['text' => '📊 Панель', 'callback_data' => 'admin:dashboard'],
        ];

        return ['inline_keyboard' => $rows];
    }

    private function pendingRejectKeyboard(): array
    {
        return [
            'inline_keyboard' => [
                [['text' => '❌ Отменить отклонение', 'callback_data' => 'admin:order_reject_cancel']],
                [['text' => '🎁 Все заказы', 'callback_data' => 'admin:orders']],
            ],
        ];
    }

    private function backToOrdersKeyboard(): array
    {
        return [
            'inline_keyboard' => [
                [['text' => '🎁 Открыть заказы', 'callback_data' => 'admin:orders']],
                [['text' => '📊 Панель', 'callback_data' => 'admin:dashboard']],
            ],
        ];
    }

    private function splitOrders(array $db): array
    {
        $pending = [];
        $processed = [];

        foreach (($db['shop_orders'] ?? []) as $order) {
            if (!is_array($order)) {
                continue;
            }
            if ((string)($order['status'] ?? 'pending') === 'pending') {
                $pending[] = $order;
            } else {
                $processed[] = $order;
            }
        }

        $pendingSort = static fn(array $a, array $b): int => strcmp((string)($b['created_at'] ?? ''), (string)($a['created_at'] ?? ''));
        $processedSort = static fn(array $a, array $b): int => strcmp(
            (string)(($b['updated_at'] ?? '') ?: ($b['created_at'] ?? '')),
            (string)(($a['updated_at'] ?? '') ?: ($a['created_at'] ?? ''))
        );
        usort($pending, $pendingSort);
        usort($processed, $processedSort);

        return [$pending, $processed];
    }

    private function findOrder(array $db, string $orderId): ?array
    {
        $orderId = trim($orderId);
        if ($orderId === '') {
            return null;
        }

        foreach (($db['shop_orders'] ?? []) as $order) {
            if (is_array($order) && (string)($order['id'] ?? '') === $orderId) {
                return $order;
            }
        }
        return null;
    }

    private function pendingReject(string $adminId): ?array
    {
        $db = new JsonDatabase($this->dataDir());
        return $db->readOnly(function (array $stored) use ($adminId) {
            $pending = $stored['system']['admin_pending_actions'][$adminId] ?? null;
            return is_array($pending) && (string)($pending['type'] ?? '') === self::PENDING_TYPE
                ? $pending
                : null;
        });
    }

    private function pendingOrderId(string $adminId): string
    {
        return (string)($this->pendingReject($adminId)['order_id'] ?? '');
    }

    private function clearPendingReject(string $adminId): void
    {
        if ($adminId === '') {
            return;
        }

        $db = new JsonDatabase($this->dataDir());
        $db->transaction(function (array &$stored) use ($adminId) {
            $pending = $stored['system']['admin_pending_actions'][$adminId] ?? null;
            if (is_array($pending) && (string)($pending['type'] ?? '') === self::PENDING_TYPE) {
                unset($stored['system']['admin_pending_actions'][$adminId]);
            }
            return null;
        });
    }

    private function isExpired(array $pending): bool
    {
        $createdAt = strtotime((string)($pending['created_at'] ?? '')) ?: 0;
        return $createdAt > 0 && time() - $createdAt > self::PENDING_TTL;
    }

    private function isReplyToCurrentPrompt(array $message, array $pending): bool
    {
        $reply = $message['reply_to_message'] ?? null;
        if (!is_array($reply)) {
            return false;
        }

        $replyText = trim((string)($reply['text'] ?? ''));
        $shortId = $this->shortId((string)($pending['order_id'] ?? ''));
        return $replyText !== ''
            && str_contains($replyText, 'Отклонение заказа')
            && $shortId !== '—'
            && str_contains(strtoupper($replyText), strtoupper($shortId));
    }

    private function renderMessage(string $chatId, int $messageId, string $text, array $replyMarkup): void
    {
        if ($messageId > 0) {
            $result = $this->telegram->api('editMessageText', [
                'chat_id' => $chatId,
                'message_id' => $messageId,
                'text' => $text,
                'reply_markup' => $replyMarkup,
                'disable_web_page_preview' => true,
            ]);
            if (!empty($result['ok'])) {
                return;
            }
        }

        $this->telegram->api('sendMessage', [
            'chat_id' => $chatId,
            'text' => $text,
            'reply_markup' => $replyMarkup,
            'disable_web_page_preview' => true,
        ]);
    }

    private function answerCallback(string $callbackId, string $text = ''): void
    {
        if ($callbackId === '') {
            return;
        }

        $payload = ['callback_query_id' => $callbackId];
        if ($text !== '') {
            $payload['text'] = $text;
            $payload['show_alert'] = false;
        }
        $this->telegram->api('answerCallbackQuery', $payload);
    }

    private function callbackArgument(string $data): string
    {
        $parts = explode(':', $data);
        return trim((string)end($parts));
    }

    private function orderUserLabel(array $order): string
    {
        $username = trim((string)($order['username'] ?? ''));
        return $username !== ''
            ? '@' . ltrim($username, '@')
            : 'ID ' . ((string)($order['user_id'] ?? '') ?: '—');
    }

    private function orderPrizeLabel(array $order): string
    {
        return (string)(($order['prize_title'] ?? '') ?: ($order['provider'] ?? '') ?: 'Приз');
    }

    private function formatGold(array $order): string
    {
        $amount = abs((int)($order['gold_cost'] ?? $order['amount'] ?? 0));
        return number_format($amount, 0, '.', ' ') . ' Gold';
    }

    private function shortId(string $id): string
    {
        $id = preg_replace('/^(shop_|order_)/i', '', $id);
        $id = strtoupper(substr((string)$id, 0, 8));
        return $id !== '' ? $id : '—';
    }

    private function statusLabel(string $status): string
    {
        return match ($status) {
            'pending' => 'Ожидает обработки',
            'processing' => 'В обработке',
            'done' => 'Выполнена',
            'rejected' => 'Отклонена',
            'cancelled' => 'Отменена',
            default => $status !== '' ? $status : '—',
        };
    }

    private function statusIcon(string $status): string
    {
        return match ($status) {
            'done' => '✅',
            'rejected' => '🚫',
            'processing' => '🔄',
            'cancelled' => '❌',
            default => '•',
        };
    }

    private function formatDate(string $value): string
    {
        if ($value === '') {
            return '—';
        }
        $timestamp = strtotime($value);
        return $timestamp ? date('d.m H:i', $timestamp) : $value;
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
