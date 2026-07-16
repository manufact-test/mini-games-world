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

        if ($this->handlePendingAdminPaymentReject((string)$chatId, (string)$fromId, $text)) {
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
        $playerNotification = null;
        $replyMarkup = $admin->keyboard();
        $callbackText = '';

        if ($action === 'fix_payout_done') {
            $text = $db->transaction(function (array &$dbData) use ($admin, $fromId) {
                return $admin->fixLegacyPayoutDone($dbData, $fromId);
            });
        } elseif (str_starts_with($action, 'payment_open:')) {
            $paymentId = $this->callbackArgument($action);
            $text = $db->readOnly(function (array $dbData) use ($admin, $paymentId) {
                return $admin->paymentDetails($dbData, $paymentId);
            });
            $replyMarkup = $this->paymentActionKeyboard($paymentId);
            $callbackText = 'Открываю заявку';
        } elseif (str_starts_with($action, 'payment_apply:')) {
            $paymentId = $this->callbackArgument($action);
            $result = $this->processPaymentDecision($db, $admin, $paymentId, $fromId, 'applied');
            $text = (string)($result['message'] ?? '');
            $playerNotification = $result['player_notification'] ?? null;
            $replyMarkup = $admin->keyboard();
            $callbackText = 'Начисление обработано';
        } elseif (str_starts_with($action, 'payment_reject_prompt:')) {
            $paymentId = $this->callbackArgument($action);
            $text = $this->setPendingPaymentReject($db, $paymentId, $fromId);
            $replyMarkup = $this->pendingRejectKeyboard();
            $callbackText = 'Теперь напишите причину сообщением';
        } elseif ($action === 'payment_reject_cancel') {
            $text = $this->cancelPendingPaymentReject($db, $fromId);
            $replyMarkup = $admin->keyboard();
            $callbackText = 'Отменено';
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

        $this->sendPaymentDecisionNotification($playerNotification);
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
        $playerNotification = null;

        if ($this->startsWithCommand($text, (string)($this->config['admin_order_done_command'] ?? '/mgw_private_admin_7291_order_done'))) {
            $message = $db->transaction(function (array &$data) use ($admin, $text, $fromId) {
                return $admin->completeOrder($data, $this->commandArgument($text), $fromId);
            });
        } elseif ($this->startsWithCommand($text, (string)($this->config['admin_order_reject_command'] ?? '/mgw_private_admin_7291_order_reject'))) {
            $message = $db->transaction(function (array &$data) use ($admin, $text, $fromId) {
                return $admin->rejectOrder($data, $this->commandArgument($text), $fromId);
            });
        } elseif ($this->startsWithCommand($text, (string)($this->config['admin_payment_apply_command'] ?? '/mgw_private_admin_7291_payment_apply'))) {
            $argument = $this->commandArgument($text);
            $result = $this->processPaymentDecision($db, $admin, $argument, $fromId, 'applied');
            $message = (string)($result['message'] ?? '');
            $playerNotification = $result['player_notification'] ?? null;
        } elseif ($this->startsWithCommand($text, (string)($this->config['admin_payment_reject_command'] ?? '/mgw_private_admin_7291_payment_reject'))) {
            $argument = $this->commandArgument($text);
            $result = $this->processPaymentDecision($db, $admin, $argument, $fromId, 'rejected');
            $message = (string)($result['message'] ?? '');
            $playerNotification = $result['player_notification'] ?? null;
        } elseif ($this->startsWithCommand($text, (string)($this->config['admin_gold_add_command'] ?? '/mgw_private_admin_7291_gold_add'))) {
            $message = $db->transaction(function (array &$data) use ($admin, $text, $fromId) {
                return $admin->addGoldToUser($data, $this->commandArgument($text), $fromId);
            });
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

        $this->sendPaymentDecisionNotification($playerNotification);
    }

    private function handlePendingAdminPaymentReject(string $chatId, string $fromId, string $text): bool
    {
        if ($text === '') {
            return false;
        }

        if (str_starts_with($text, '/') && $text !== '/cancel') {
            return false;
        }

        $admin = new AdminService($this->config);
        if (!$admin->isAdmin($fromId)) {
            return false;
        }

        $db = StorageFactory::createJson($this->dataDir());
        $result = $db->transaction(function (array &$data) use ($admin, $fromId, $text) {
            $pending = $data['system']['admin_pending_actions'][$fromId] ?? null;
            if (!is_array($pending) || (string)($pending['type'] ?? '') !== 'payment_reject') {
                return null;
            }

            $createdAt = strtotime((string)($pending['created_at'] ?? '')) ?: 0;
            if ($createdAt > 0 && time() - $createdAt > 1800) {
                unset($data['system']['admin_pending_actions'][$fromId]);
                return [
                    'message' => "⏱ Отклонение заявки отменено\n\nПрошло больше 30 минут. Нажмите «Отклонить» ещё раз.",
                    'player_notification' => null,
                ];
            }

            if ($text === '/cancel') {
                unset($data['system']['admin_pending_actions'][$fromId]);
                return [
                    'message' => "❌ Отклонение заявки отменено. Ни одна заявка не изменилась.",
                    'player_notification' => null,
                ];
            }

            $reason = trim($text);
            if (mb_strlen($reason) < 3) {
                return [
                    'message' => "⚠️ Причина слишком короткая. Напишите причину отклонения одним сообщением или отправьте /cancel.",
                    'player_notification' => null,
                ];
            }

            $paymentId = trim((string)($pending['payment_id'] ?? ''));
            $before = $paymentId !== '' ? $this->paymentForNotification($data, $paymentId) : null;
            unset($data['system']['admin_pending_actions'][$fromId]);

            if ($paymentId === '' || !$before) {
                return [
                    'message' => "⚠️ Не найден ID заявки для отклонения. Заявка не изменена.",
                    'player_notification' => null,
                ];
            }

            if (!empty($before['balance_applied']) || in_array((string)($before['status'] ?? ''), ['paid', 'rejected', 'cancelled'], true)) {
                return [
                    'message' => "⚠️ Заявка уже обработана. Повторное отклонение не выполнено.\n\n" . $admin->paymentDetails($data, $paymentId),
                    'player_notification' => null,
                ];
            }

            $argument = trim($paymentId . ' ' . $reason);
            $message = $admin->rejectPayment($data, $argument, $fromId);
            $after = $this->paymentForNotification($data, $paymentId);

            return [
                'message' => $message,
                'player_notification' => $this->paymentDecisionNotification($before, $after, 'rejected'),
            ];
        });

        if (!is_array($result)) {
            return false;
        }

        $this->telegram->api('sendMessage', [
            'chat_id' => $chatId,
            'text' => (string)($result['message'] ?? ''),
            'reply_markup' => $admin->keyboard(),
            'disable_web_page_preview' => true,
        ]);

        $this->sendPaymentDecisionNotification($result['player_notification'] ?? null);

        return true;
    }

    private function setPendingPaymentReject(JsonDatabase $db, string $paymentId, string $adminId): string
    {
        $paymentId = trim($paymentId);
        if ($paymentId === '') {
            return "⚠️ Не найден ID заявки для отклонения.";
        }

        return $db->transaction(function (array &$data) use ($paymentId, $adminId) {
            $payment = $this->paymentForNotification($data, $paymentId);
            if (!$payment) {
                return "⚠️ Заявка не найдена: {$paymentId}\n\nНажмите «💳 Платежи» и проверьте список актуальных заявок.";
            }

            if (!empty($payment['balance_applied']) || in_array((string)($payment['status'] ?? ''), ['paid', 'rejected', 'cancelled'], true)) {
                return "⚠️ Эта заявка уже обработана. Отклонение не запущено.\n\n" . (new AdminService($this->config))->paymentDetails($data, $paymentId);
            }

            if (!isset($data['system']) || !is_array($data['system'])) {
                $data['system'] = [];
            }
            if (!isset($data['system']['admin_pending_actions']) || !is_array($data['system']['admin_pending_actions'])) {
                $data['system']['admin_pending_actions'] = [];
            }

            $existing = $data['system']['admin_pending_actions'][$adminId] ?? null;
            if (is_array($existing) && (string)($existing['type'] ?? '') === 'payment_reject') {
                $createdAt = strtotime((string)($existing['created_at'] ?? '')) ?: 0;
                $isFresh = $createdAt <= 0 || time() - $createdAt <= 1800;
                $existingPaymentId = trim((string)($existing['payment_id'] ?? ''));

                if ($isFresh && $existingPaymentId !== '' && strtoupper($existingPaymentId) !== strtoupper($paymentId)) {
                    return "⚠️ У вас уже открыт режим отклонения другой заявки: {$existingPaymentId}\n\nСначала отправьте причину для неё одним сообщением или отправьте /cancel.\n\nНовая заявка {$paymentId} не изменилась.";
                }
            }

            $shortId = $this->shortPaymentId((string)($payment['id'] ?? $paymentId));
            $data['system']['admin_pending_actions'][$adminId] = [
                'type' => 'payment_reject',
                'payment_id' => $shortId,
                'created_at' => now_iso(),
            ];

            return "🚫 Отклонение заявки {$shortId}\n\n"
                . "Сейчас бот ждёт причину отклонения именно для этой заявки.\n\n"
                . "Напишите следующим сообщением причину, например:\n"
                . "оплата не найдена\n\n"
                . "После вашего сообщения заявка будет отклонена, а игрок получит эту причину.\n\n"
                . "Чтобы отменить действие, отправьте /cancel.";
        });
    }

    private function cancelPendingPaymentReject(JsonDatabase $db, string $adminId): string
    {
        return $db->transaction(function (array &$data) use ($adminId) {
            if (isset($data['system']['admin_pending_actions'][$adminId])) {
                unset($data['system']['admin_pending_actions'][$adminId]);
            }

            return "❌ Отклонение заявки отменено. Ни одна заявка не изменилась.";
        });
    }

    private function processPaymentDecision(JsonDatabase $db, AdminService $admin, string $argument, string $fromId, string $decision): array
    {
        return $db->transaction(function (array &$data) use ($admin, $argument, $fromId, $decision) {
            $before = $this->paymentForNotification($data, $argument);

            if ($decision === 'applied') {
                $message = $admin->applyPayment($data, $argument, $fromId);
            } else {
                $message = $admin->rejectPayment($data, $argument, $fromId);
            }

            $after = $this->paymentForNotification($data, $argument);
            $this->clearPendingPaymentRejectForSamePayment($data, $fromId, $argument);

            return [
                'message' => $message,
                'player_notification' => $this->paymentDecisionNotification($before, $after, $decision),
            ];
        });
    }

    private function clearPendingPaymentRejectForSamePayment(array &$data, string $adminId, string $argument): void
    {
        $pending = $data['system']['admin_pending_actions'][$adminId] ?? null;
        if (!is_array($pending) || (string)($pending['type'] ?? '') !== 'payment_reject') {
            return;
        }

        $pendingId = strtoupper(trim((string)($pending['payment_id'] ?? '')));
        $query = strtoupper($this->paymentQueryFromArgument($argument));
        if ($pendingId !== '' && $query !== '' && ($pendingId === $query || str_starts_with($pendingId, $query) || str_starts_with($query, $pendingId))) {
            unset($data['system']['admin_pending_actions'][$adminId]);
        }
    }

    private function sendPaymentDecisionNotification(mixed $playerNotification): void
    {
        if (is_array($playerNotification)
            && isset($playerNotification['payment'], $playerNotification['decision'])
            && is_array($playerNotification['payment'])) {
            $this->telegram->notifyUserAboutPaymentDecision(
                $playerNotification['payment'],
                (string)$playerNotification['decision']
            );
        }
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

    private function paymentForNotification(array $data, string $argument): ?array
    {
        $query = $this->paymentQueryFromArgument($argument);
        if ($query === '') {
            return null;
        }

        $query = strtoupper($query);
        foreach (($data['payments'] ?? []) as $payment) {
            if (!is_array($payment)) {
                continue;
            }

            $id = (string)($payment['id'] ?? '');
            $short = $this->shortPaymentId($id);
            $normalizedId = strtoupper($id);

            if ($query === $normalizedId || $query === $short) {
                return $payment;
            }

            if (strlen($query) >= 4 && str_starts_with($short, $query)) {
                return $payment;
            }
        }

        return null;
    }

    private function paymentDecisionNotification(?array $before, ?array $after, string $decision): ?array
    {
        if (!$after || (string)($after['user_id'] ?? '') === '') {
            return null;
        }

        if ($decision === 'applied') {
            $wasApplied = !empty($before['balance_applied']);
            $isApplied = !empty($after['balance_applied']) && (string)($after['status'] ?? '') === 'paid';

            if (!$wasApplied && $isApplied) {
                return ['decision' => 'applied', 'payment' => $after];
            }
        }

        if ($decision === 'rejected') {
            $beforeStatus = (string)($before['status'] ?? '');
            $afterStatus = (string)($after['status'] ?? '');
            $wasApplied = !empty($before['balance_applied']);

            if (!$wasApplied && $beforeStatus !== 'rejected' && $afterStatus === 'rejected') {
                return ['decision' => 'rejected', 'payment' => $after];
            }
        }

        return null;
    }

    private function paymentQueryFromArgument(string $argument): string
    {
        $parts = preg_split('/\s+/', trim($argument), 2);
        return trim((string)($parts[0] ?? ''));
    }

    private function shortPaymentId(string $id): string
    {
        $id = preg_replace('/^(pay_)/', '', $id);
        $id = strtoupper(substr((string)$id, 0, 8));
        return $id !== '' ? $id : '-';
    }

    private function paymentActionKeyboard(string $paymentId): array
    {
        $paymentId = strtoupper(trim($paymentId));
        return [
            'inline_keyboard' => [
                [
                    ['text' => '✅ Начислить', 'callback_data' => 'admin:payment_apply:' . $paymentId],
                    ['text' => '🚫 Отклонить', 'callback_data' => 'admin:payment_reject_prompt:' . $paymentId],
                ],
                [
                    ['text' => '💳 Все платежи', 'callback_data' => 'admin:payments'],
                    ['text' => '📊 Панель', 'callback_data' => 'admin:dashboard'],
                ],
            ],
        ];
    }

    private function pendingRejectKeyboard(): array
    {
        return [
            'inline_keyboard' => [
                [
                    ['text' => '❌ Отменить отклонение', 'callback_data' => 'admin:payment_reject_cancel'],
                ],
                [
                    ['text' => '💳 Все платежи', 'callback_data' => 'admin:payments'],
                ],
            ],
        ];
    }
}
