<?php
declare(strict_types=1);

final class WebhookHandler
{
    public function __construct(private TelegramService $telegram, private array $config) {}

    public function handle(array $update): void
    {
        if (!empty($update['callback_query'])) {
            $this->handleCallback($update['callback_query']);
            return;
        }

        $message = $update['message'] ?? $update['edited_message'] ?? null;
        if (!$message) return;

        $chatId = $message['chat']['id'] ?? null;
        $fromId = $message['from']['id'] ?? $chatId;
        $text = trim((string)($message['text'] ?? ''));

        if (!$chatId) return;

        if ($this->isAdminCommand($text)) {
            $this->handleAdminCommand((string)$chatId, (string)$fromId, $text);
            return;
        }

        if ($text === '/start' || $text === '/app' || $text === '/help' || $text === '') {
            $this->telegram->sendStartMessage($chatId);
            return;
        }

        $this->telegram->api('sendMessage', [
            'chat_id' => $chatId,
            'text' => 'Нажмите /start, чтобы открыть Mini Games World.',
        ]);
    }

    private function handleCallback(array $callback): void
    {
        $data = (string)($callback['data'] ?? '');
        $callbackId = (string)($callback['id'] ?? '');
        $fromId = (string)($callback['from']['id'] ?? '');
        $message = $callback['message'] ?? null;
        $chatId = $message['chat']['id'] ?? null;
        $messageId = $message['message_id'] ?? null;

        if (!str_starts_with($data, 'admin:')) {
            if ($callbackId !== '') {
                $this->telegram->api('answerCallbackQuery', ['callback_query_id' => $callbackId]);
            }
            return;
        }

        $admin = new AdminService($this->config);
        if (!$admin->isAdmin($fromId)) {
            if ($callbackId !== '') {
                $this->telegram->api('answerCallbackQuery', [
                    'callback_query_id' => $callbackId,
                    'text' => 'Недоступно',
                    'show_alert' => false,
                ]);
            }
            return;
        }

        if (!$chatId) return;

        $action = substr($data, strlen('admin:'));
        $db = StorageFactory::createJson($this->dataDir());
        $replyMarkup = $admin->keyboard();
        $callbackText = '';

        if ($action === 'fix_payout_done') {
            $text = $db->transaction(function (array &$dbData) use ($admin, $fromId) {
                return $admin->fixLegacyPayoutDone($dbData, $fromId);
            });
        } elseif (str_starts_with($action, 'order_open:')) {
            $orderId = $this->callbackArgument($action);
            $text = $db->readOnly(fn(array $dbData): string => $admin->orderDetails($dbData, $orderId));
            $replyMarkup = $this->legacyArchiveKeyboard('orders');
            $callbackText = 'Открываю архивную заявку';
        } elseif (str_starts_with($action, 'payment_open:')) {
            $paymentId = $this->callbackArgument($action);
            $text = $db->readOnly(fn(array $dbData): string => $admin->paymentDetails($dbData, $paymentId));
            $replyMarkup = $this->legacyArchiveKeyboard('payments');
            $callbackText = 'Открываю архивный платёж';
        } elseif ($this->isLegacyCommerceMutationCallback($action)) {
            $text = UnifiedGameZonePolicy::legacyArchiveMessage();
            $replyMarkup = $this->legacyArchiveKeyboard(str_starts_with($action, 'order_') ? 'orders' : 'payments');
            $callbackText = 'Архив: только просмотр';
        } else {
            $text = $db->readOnly(function (array $dbData) use ($admin, $action) {
                return match ($action) {
                    'orders' => $admin->orders($dbData),
                    'payments' => $admin->payments($dbData),
                    'gold_tools' => $admin->goldTools($dbData),
                    'support' => $admin->support($dbData),
                    'users' => $admin->users($dbData, ''),
                    'user_search_help' => $admin->userSearchHelp($dbData),
                    'system_check' => $admin->systemCheck($dbData),
                    default => $admin->dashboard($dbData),
                };
            });
        }

        if ($callbackId !== '') {
            $answer = ['callback_query_id' => $callbackId];
            if ($callbackText !== '') {
                $answer['text'] = $callbackText;
                $answer['show_alert'] = false;
            }
            $this->telegram->api('answerCallbackQuery', $answer);
        }

        if ($messageId) {
            $result = $this->telegram->api('editMessageText', [
                'chat_id' => $chatId,
                'message_id' => $messageId,
                'text' => $text,
                'reply_markup' => $replyMarkup,
                'disable_web_page_preview' => true,
            ]);

            if (empty($result['ok'])) {
                $this->telegram->api('sendMessage', [
                    'chat_id' => $chatId,
                    'text' => $text,
                    'reply_markup' => $replyMarkup,
                    'disable_web_page_preview' => true,
                ]);
            }
        } else {
            $this->telegram->api('sendMessage', [
                'chat_id' => $chatId,
                'text' => $text,
                'reply_markup' => $replyMarkup,
                'disable_web_page_preview' => true,
            ]);
        }
    }
    private function isAdminCommand(string $text): bool
    {
        $commands = [
            (string)($this->config['admin_command'] ?? ''),
            (string)($this->config['admin_orders_command'] ?? ''),
            (string)($this->config['admin_order_command'] ?? '/mgw_private_admin_7291_order'),
            (string)($this->config['admin_order_done_command'] ?? '/mgw_private_admin_7291_order_done'),
            (string)($this->config['admin_order_reject_command'] ?? '/mgw_private_admin_7291_order_reject'),
            (string)($this->config['admin_payments_command'] ?? '/mgw_private_admin_7291_payments'),
            (string)($this->config['admin_payment_command'] ?? '/mgw_private_admin_7291_payment'),
            (string)($this->config['admin_payment_apply_command'] ?? '/mgw_private_admin_7291_payment_apply'),
            (string)($this->config['admin_payment_reject_command'] ?? '/mgw_private_admin_7291_payment_reject'),
            (string)($this->config['admin_gold_tools_command'] ?? '/mgw_private_admin_7291_gold'),
            (string)($this->config['admin_gold_add_command'] ?? '/mgw_private_admin_7291_gold_add'),
            (string)($this->config['admin_support_command'] ?? ''),
            (string)($this->config['admin_users_command'] ?? ''),
            (string)($this->config['admin_user_command'] ?? '/mgw_private_admin_7291_user'),
            (string)($this->config['admin_check_command'] ?? '/mgw_private_admin_7291_check'),
            (string)($this->config['admin_fix_payout_done_command'] ?? '/mgw_private_admin_7291_fix_payout_done'),
        ];

        foreach (array_filter($commands) as $command) {
            if ($this->startsWithCommand($text, $command)) return true;
        }

        return false;
    }

    private function handleAdminCommand(string $chatId, string $fromId, string $text): void
    {
        $admin = new AdminService($this->config);

        if (!$admin->isAdmin($fromId)) {
            $this->telegram->api('sendMessage', [
                'chat_id' => $chatId,
                'text' => 'Нажмите /start, чтобы открыть Mini Games World.',
            ]);
            return;
        }

        $db = StorageFactory::createJson($this->dataDir());

        if ($this->isLegacyCommerceMutationCommand($text)) {
            $message = UnifiedGameZonePolicy::legacyArchiveMessage();
        } elseif ($this->startsWithCommand($text, (string)($this->config['admin_fix_payout_done_command'] ?? '/mgw_private_admin_7291_fix_payout_done'))) {
            $message = $db->transaction(function (array &$data) use ($admin, $fromId) {
                return $admin->fixLegacyPayoutDone($data, $fromId);
            });
        } else {
            $message = $db->readOnly(function (array $data) use ($admin, $text) {
                if ($this->startsWithCommand($text, (string)($this->config['admin_orders_command'] ?? ''))) {
                    return $admin->orders($data);
                }

                if ($this->startsWithCommand($text, (string)($this->config['admin_order_command'] ?? '/mgw_private_admin_7291_order'))) {
                    return $admin->orderDetails($data, $this->commandArgument($text));
                }

                if ($this->startsWithCommand($text, (string)($this->config['admin_payments_command'] ?? '/mgw_private_admin_7291_payments'))) {
                    return $admin->payments($data);
                }

                if ($this->startsWithCommand($text, (string)($this->config['admin_payment_command'] ?? '/mgw_private_admin_7291_payment'))) {
                    return $admin->paymentDetails($data, $this->commandArgument($text));
                }

                if ($this->startsWithCommand($text, (string)($this->config['admin_gold_tools_command'] ?? '/mgw_private_admin_7291_gold'))) {
                    return $admin->goldTools($data);
                }

                if ($this->startsWithCommand($text, (string)($this->config['admin_support_command'] ?? ''))) {
                    return $admin->support($data);
                }

                if ($this->startsWithCommand($text, (string)($this->config['admin_users_command'] ?? ''))) {
                    return $admin->users($data, $this->commandArgument($text));
                }

                if ($this->startsWithCommand($text, (string)($this->config['admin_user_command'] ?? '/mgw_private_admin_7291_user'))) {
                    $query = $this->commandArgument($text);
                    if ($query === '') return $admin->userSearchHelp($data);
                    return $admin->userDetails($data, $query);
                }

                if ($this->startsWithCommand($text, (string)($this->config['admin_check_command'] ?? '/mgw_private_admin_7291_check'))) {
                    return $admin->systemCheck($data);
                }

                return $admin->dashboard($data);
            });
        }

        $this->telegram->api('sendMessage', [
            'chat_id' => $chatId,
            'text' => $message,
            'reply_markup' => $admin->keyboard(),
            'disable_web_page_preview' => true,
        ]);
    }






    private function isLegacyCommerceMutationCommand(string $text): bool
    {
        foreach ([
            (string)($this->config['admin_order_done_command'] ?? '/mgw_private_admin_7291_order_done'),
            (string)($this->config['admin_order_reject_command'] ?? '/mgw_private_admin_7291_order_reject'),
            (string)($this->config['admin_payment_apply_command'] ?? '/mgw_private_admin_7291_payment_apply'),
            (string)($this->config['admin_payment_reject_command'] ?? '/mgw_private_admin_7291_payment_reject'),
            (string)($this->config['admin_gold_add_command'] ?? '/mgw_private_admin_7291_gold_add'),
        ] as $command) {
            if ($command !== '' && $this->startsWithCommand($text, $command)) return true;
        }

        return false;
    }

    private function isLegacyCommerceMutationCallback(string $action): bool
    {
        foreach ([
            'payment_apply:',
            'payment_reject_prompt:',
            'payment_reject_cancel',
            'order_done:',
            'order_reject_prompt:',
            'order_reject_cancel',
            'gold_add:',
        ] as $legacyAction) {
            if ($action === $legacyAction || str_starts_with($action, $legacyAction)) return true;
        }

        return false;
    }

    private function legacyArchiveKeyboard(string $section): array
    {
        $callback = $section === 'orders' ? 'admin:orders' : 'admin:payments';
        $label = $section === 'orders' ? '🗂 Архив заявок' : '🗂 Архив платежей';

        return [
            'inline_keyboard' => [
                [
                    ['text' => $label, 'callback_data' => $callback],
                    ['text' => '📊 Панель', 'callback_data' => 'admin:dashboard'],
                ],
            ],
        ];
    }

    private function dataDir(): string
    {
        return (string)($this->config['data_dir'] ?? (__DIR__ . '/../data'));
    }

    private function startsWithCommand(string $text, string $command): bool
    {
        if ($command === '') return false;

        $token = $this->firstToken($text);
        $tokenWithoutBot = explode('@', $token, 2)[0];

        return $token === $command
            || $tokenWithoutBot === $command
            || str_starts_with($text, $command . ' ')
            || str_starts_with($text, $command . '@');
    }

    private function commandArgument(string $text): string
    {
        $parts = preg_split('/\s+/', trim($text), 2);
        return trim((string)($parts[1] ?? ''));
    }

    private function firstToken(string $text): string
    {
        $parts = preg_split('/\s+/', trim($text), 2);
        return (string)($parts[0] ?? '');
    }

    private function callbackArgument(string $action): string
    {
        $parts = explode(':', $action);
        return trim((string)end($parts));
    }
}
