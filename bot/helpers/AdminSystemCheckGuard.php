<?php
declare(strict_types=1);

final class AdminSystemCheckGuard
{
    public function __construct(private TelegramService $telegram, private array $config) {}

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
        $data = (string)($callback['data'] ?? '');
        $fromId = (string)($callback['from']['id'] ?? '');
        if ($data !== 'admin:system_check' || !$this->isAdmin($fromId)) {
            return false;
        }

        $callbackId = (string)($callback['id'] ?? '');
        $message = $callback['message'] ?? [];
        $chatId = (string)($message['chat']['id'] ?? '');
        $messageId = $message['message_id'] ?? null;
        if ($chatId === '') {
            return false;
        }

        $text = $this->buildReport();
        if ($callbackId !== '') {
            $this->telegram->api('answerCallbackQuery', [
                'callback_query_id' => $callbackId,
                'text' => 'Проверка завершена',
                'show_alert' => false,
            ]);
        }

        $params = [
            'chat_id' => $chatId,
            'text' => $text,
            'reply_markup' => (new AdminService($this->config))->keyboard(),
            'disable_web_page_preview' => true,
        ];

        if ($messageId !== null) {
            $params['message_id'] = $messageId;
            $result = $this->telegram->api('editMessageText', $params);
            if (!empty($result['ok'])) {
                return true;
            }
            unset($params['message_id']);
        }

        $this->telegram->api('sendMessage', $params);
        return true;
    }

    private function handleMessage(array $message): bool
    {
        $chatId = (string)($message['chat']['id'] ?? '');
        $fromId = (string)($message['from']['id'] ?? $chatId);
        $text = trim((string)($message['text'] ?? ''));
        $command = (string)($this->config['admin_check_command'] ?? '/mgw_private_admin_7291_check');

        if ($chatId === '' || !$this->isAdmin($fromId) || !$this->startsWithCommand($text, $command)) {
            return false;
        }

        $this->telegram->api('sendMessage', [
            'chat_id' => $chatId,
            'text' => $this->buildReport(),
            'reply_markup' => (new AdminService($this->config))->keyboard(),
            'disable_web_page_preview' => true,
        ]);
        return true;
    }

    private function buildReport(): string
    {
        $db = StorageFactory::createJson($this->dataDir());
        return $db->readOnly(function (array $data) {
            $base = (new AdminService($this->config))->systemCheck($data);
            return $base
                . "\n\n" . $this->paymentAudit($data)
                . "\n\n" . $this->shopAudit($data)
                . "\n\n" . $this->weeklyEconomyAudit($data);
        });
    }

    private function paymentAudit(array $data): string
    {
        $payments = $data['payments'] ?? [];
        $users = $data['users'] ?? [];
        $transactions = $data['transactions'] ?? [];
        $pendingActions = $data['system']['admin_pending_actions'] ?? [];

        $issues = [];
        $warnings = [];
        $waiting = 0;
        $paid = 0;
        $rejected = 0;
        $seenIds = [];
        $applyTransactions = [];
        $rejectTransactions = [];

        foreach ($transactions as $tx) {
            if (!is_array($tx)) continue;
            $paymentId = (string)($tx['payment_id'] ?? '');
            if ($paymentId === '') continue;
            $category = (string)($tx['category'] ?? '');
            if ($category === 'payment_apply') $applyTransactions[$paymentId] = ($applyTransactions[$paymentId] ?? 0) + 1;
            if ($category === 'payment_reject') $rejectTransactions[$paymentId] = ($rejectTransactions[$paymentId] ?? 0) + 1;
        }

        foreach ($payments as $index => $payment) {
            if (!is_array($payment)) {
                $issues[] = "платёжная запись #{$index} имеет некорректный формат";
                continue;
            }

            $id = trim((string)($payment['id'] ?? ''));
            $label = $id !== '' ? $this->shortPaymentId($id) : ('#' . $index);
            $status = (string)($payment['status'] ?? 'draft');
            $applied = !empty($payment['balance_applied']);
            $userId = (string)($payment['user_id'] ?? '');
            $room = (string)($payment['room'] ?? '');
            $coins = (int)($payment['coins'] ?? 0);
            $amount = (int)($payment['price'] ?? $payment['amount_rub'] ?? 0);

            if ($id === '') $issues[] = "пополнение {$label}: отсутствует ID";
            elseif (isset($seenIds[$id])) $issues[] = "дублирующийся ID пополнения {$label}";
            else $seenIds[$id] = true;

            if ($userId === '' || !isset($users[$userId])) $issues[] = "пополнение {$label}: пользователь не найден";
            if (!in_array($room, ['match', 'gold'], true)) $issues[] = "пополнение {$label}: неизвестная комната {$room}";
            if ($coins <= 0 || $amount <= 0) $issues[] = "пополнение {$label}: некорректная сумма или количество коинов";
            if (empty($payment['created_at'])) $warnings[] = "пополнение {$label}: отсутствует дата создания";

            if (in_array($status, ['draft', 'pending'], true)) {
                $waiting++;
                if ($applied) $issues[] = "пополнение {$label}: ожидает решения, но баланс уже начислен";
            } elseif ($status === 'paid') {
                $paid++;
                if (!$applied) $issues[] = "пополнение {$label}: статус paid без начисления";
                if (($applyTransactions[$id] ?? 0) === 0) $warnings[] = "пополнение {$label}: нет операции payment_apply";
            } elseif ($status === 'rejected') {
                $rejected++;
                if ($applied) $issues[] = "пополнение {$label}: отклонено после начисления";
                if (trim((string)($payment['reject_reason'] ?? '')) === '') $warnings[] = "пополнение {$label}: нет причины отклонения";
                if (($rejectTransactions[$id] ?? 0) === 0) $warnings[] = "пополнение {$label}: нет операции payment_reject";
            } elseif ($status !== 'cancelled') {
                $issues[] = "пополнение {$label}: неизвестный статус {$status}";
            }

            if (($applyTransactions[$id] ?? 0) > 1) $issues[] = "пополнение {$label}: несколько операций начисления";
            if (($rejectTransactions[$id] ?? 0) > 1) $warnings[] = "пополнение {$label}: несколько операций отклонения";
        }

        $activeRejectModes = 0;
        $staleRejectModes = 0;
        foreach ($pendingActions as $adminId => $pending) {
            if (!is_array($pending) || (string)($pending['type'] ?? '') !== 'payment_reject') continue;
            $activeRejectModes++;
            $createdAt = strtotime((string)($pending['created_at'] ?? '')) ?: 0;
            if ($createdAt > 0 && time() - $createdAt > 1800) {
                $staleRejectModes++;
                $warnings[] = "у администратора {$adminId} просрочен режим отклонения";
            }

            $query = strtoupper(trim((string)($pending['payment_id'] ?? '')));
            $found = false;
            foreach ($payments as $payment) {
                if (!is_array($payment)) continue;
                $id = (string)($payment['id'] ?? '');
                if ($query !== '' && ($query === strtoupper($id) || $query === $this->shortPaymentId($id))) {
                    $found = true;
                    $status = (string)($payment['status'] ?? 'draft');
                    if (!in_array($status, ['draft', 'pending'], true) || !empty($payment['balance_applied'])) {
                        $warnings[] = "режим отклонения {$query} относится к уже обработанной заявке";
                    }
                    break;
                }
            }
            if (!$found) $warnings[] = "режим отклонения {$query} не связан с существующей заявкой";
        }

        $lines = ['💳 Контроль пополнений'];
        $lines[] = "Всего: " . count($payments);
        $lines[] = "Ожидают решения: {$waiting}";
        $lines[] = "Начислены: {$paid}";
        $lines[] = "Отклонены: {$rejected}";
        $lines[] = "Активных вводов причины: {$activeRejectModes}";
        if ($staleRejectModes > 0) $lines[] = "Просроченных вводов причины: {$staleRejectModes}";

        $lines[] = "\nОшибки платежей:";
        if ($issues) foreach (array_slice(array_values(array_unique($issues)), 0, 12) as $issue) $lines[] = "❌ {$issue}";
        else $lines[] = '✅ критичных ошибок не найдено';

        $lines[] = "\nПредупреждения платежей:";
        if ($warnings) foreach (array_slice(array_values(array_unique($warnings)), 0, 12) as $warning) $lines[] = "⚠️ {$warning}";
        else $lines[] = '✅ предупреждений нет';

        return implode("\n", $lines);
    }

    private function shopAudit(array $data): string
    {
        $orders = $data['shop_orders'] ?? [];
        $users = $data['users'] ?? [];
        $transactions = $data['transactions'] ?? [];
        $pendingActions = $data['system']['admin_pending_actions'] ?? [];

        $issues = [];
        $warnings = [];
        $statusCounts = [
            'pending' => 0,
            'processing' => 0,
            'done' => 0,
            'rejected' => 0,
            'cancelled' => 0,
            'unknown' => 0,
        ];

        $seenIds = [];
        $seenRequests = [];
        $ordersById = [];
        $debitTransactions = [];
        $refundTransactions = [];
        $doneTransactions = [];
        $rejectTransactions = [];

        foreach ($transactions as $tx) {
            if (!is_array($tx)) continue;

            $orderId = trim((string)($tx['order_id'] ?? ''));
            if ($orderId === '') continue;

            $type = (string)($tx['type'] ?? '');
            $category = (string)($tx['category'] ?? '');
            $amount = (int)($tx['amount'] ?? 0);

            if ($type === 'balance_change' && $category === 'shop_order' && $amount < 0) {
                $debitTransactions[$orderId] = ($debitTransactions[$orderId] ?? 0) + 1;
            }
            if ($type === 'balance_change' && $category === 'shop_refund' && $amount > 0) {
                $refundTransactions[$orderId] = ($refundTransactions[$orderId] ?? 0) + 1;
            }
            if ($type === 'shop_order_done' || $category === 'shop_order_done') {
                $doneTransactions[$orderId] = ($doneTransactions[$orderId] ?? 0) + 1;
            }
            if ($type === 'shop_order_reject' || $category === 'shop_order_reject') {
                $rejectTransactions[$orderId] = ($rejectTransactions[$orderId] ?? 0) + 1;
            }
        }

        foreach ($orders as $index => $order) {
            if (!is_array($order)) {
                $issues[] = "заказ #{$index} имеет некорректный формат";
                continue;
            }

            $id = trim((string)($order['id'] ?? ''));
            $label = $id !== '' ? $this->shortOrderId($id) : ('#' . $index);
            $userId = trim((string)($order['user_id'] ?? ''));
            $status = strtolower(trim((string)($order['status'] ?? 'pending')));
            $amount = abs((int)($order['gold_cost'] ?? $order['amount'] ?? 0));
            $requestId = trim((string)($order['client_request_id'] ?? ''));
            $refundDone = !empty($order['refund_done']);

            if ($id === '') {
                $issues[] = "заказ {$label}: отсутствует ID";
            } elseif (isset($seenIds[$id])) {
                $issues[] = "дублирующийся ID заказа {$label}";
            } else {
                $seenIds[$id] = true;
                $ordersById[$id] = $order;
            }

            if ($requestId !== '' && $userId !== '') {
                $requestKey = $userId . '|' . $requestId;
                if (isset($seenRequests[$requestKey])) {
                    $issues[] = "заказ {$label}: повторный client_request_id у одного игрока";
                } else {
                    $seenRequests[$requestKey] = true;
                }
            }

            if ($userId === '' || !isset($users[$userId])) {
                $issues[] = "заказ {$label}: пользователь не найден";
            }
            if ($amount <= 0) {
                $issues[] = "заказ {$label}: некорректная стоимость";
            }
            if (empty($order['created_at'])) {
                $warnings[] = "заказ {$label}: отсутствует дата создания";
            }

            if (!isset($statusCounts[$status])) {
                $statusCounts['unknown']++;
                $issues[] = "заказ {$label}: неизвестный статус {$status}";
            } else {
                $statusCounts[$status]++;
            }

            $debitCount = $id !== '' ? (int)($debitTransactions[$id] ?? 0) : 0;
            $refundCount = $id !== '' ? (int)($refundTransactions[$id] ?? 0) : 0;
            $doneCount = $id !== '' ? (int)($doneTransactions[$id] ?? 0) : 0;
            $rejectCount = $id !== '' ? (int)($rejectTransactions[$id] ?? 0) : 0;

            if ($debitCount === 0) {
                $warnings[] = "заказ {$label}: нет финансовой операции списания";
            } elseif ($debitCount > 1) {
                $issues[] = "заказ {$label}: несколько финансовых списаний";
            }

            if ($refundCount > 1) {
                $issues[] = "заказ {$label}: несколько операций возврата";
            }
            if ($refundDone && $refundCount === 0) {
                $warnings[] = "заказ {$label}: отмечен возврат, но нет операции shop_refund";
            }
            if (!$refundDone && $refundCount > 0) {
                $issues[] = "заказ {$label}: есть возврат, но refund_done не установлен";
            }

            if (in_array($status, ['pending', 'processing'], true)) {
                if ($refundDone) {
                    $issues[] = "заказ {$label}: активный статус после возврата Gold";
                }
                if (!empty($order['completed_at']) || !empty($order['rejected_at'])) {
                    $warnings[] = "заказ {$label}: активный статус с датой завершения";
                }
            }

            if ($status === 'done') {
                if ($refundDone) {
                    $issues[] = "заказ {$label}: выполнен, но Gold возвращён";
                }
                if (empty($order['completed_at'])) {
                    $warnings[] = "заказ {$label}: выполнен без completed_at";
                }
                if ($doneCount === 0) {
                    $warnings[] = "заказ {$label}: нет операции shop_order_done";
                } elseif ($doneCount > 1) {
                    $warnings[] = "заказ {$label}: несколько операций выполнения";
                }
                if ($rejectCount > 0) {
                    $issues[] = "заказ {$label}: одновременно есть операция отклонения";
                }
            }

            if ($status === 'rejected') {
                if (!$refundDone) {
                    $issues[] = "заказ {$label}: отклонён без подтверждённого возврата";
                }
                if (empty($order['rejected_at'])) {
                    $warnings[] = "заказ {$label}: отклонён без rejected_at";
                }
                if (trim((string)($order['reject_reason'] ?? $order['admin_note'] ?? '')) === '') {
                    $warnings[] = "заказ {$label}: нет причины отклонения";
                }
                if ($refundDone && $amount > 0 && abs((int)($order['refund_amount'] ?? 0)) !== $amount) {
                    $issues[] = "заказ {$label}: сумма возврата не совпадает со стоимостью заказа";
                }
                if ($refundCount === 0) {
                    $warnings[] = "заказ {$label}: нет операции shop_refund";
                }
                if ($rejectCount === 0) {
                    $warnings[] = "заказ {$label}: нет операции shop_order_reject";
                } elseif ($rejectCount > 1) {
                    $warnings[] = "заказ {$label}: несколько операций отклонения";
                }
                if ($doneCount > 0) {
                    $issues[] = "заказ {$label}: одновременно есть операция выполнения";
                }
            }

            if ($status === 'cancelled' && $debitCount > 0 && !$refundDone) {
                $issues[] = "заказ {$label}: отменён после списания без возврата Gold";
            }
        }

        foreach ($debitTransactions as $orderId => $count) {
            if (!isset($ordersById[$orderId])) {
                $issues[] = 'списание связано с несуществующим заказом ' . $this->shortOrderId($orderId);
            }
        }
        foreach ($refundTransactions as $orderId => $count) {
            if (!isset($ordersById[$orderId])) {
                $issues[] = 'возврат связан с несуществующим заказом ' . $this->shortOrderId($orderId);
            }
        }

        $activeRejectModes = 0;
        $staleRejectModes = 0;
        foreach ($pendingActions as $adminId => $pendingAction) {
            if (!is_array($pendingAction) || (string)($pendingAction['type'] ?? '') !== 'shop_order_reject') {
                continue;
            }

            $activeRejectModes++;
            $createdAt = strtotime((string)($pendingAction['created_at'] ?? '')) ?: 0;
            if ($createdAt > 0 && time() - $createdAt > 1800) {
                $staleRejectModes++;
                $warnings[] = "у администратора {$adminId} просрочен ввод причины отклонения заказа";
            }

            $orderId = trim((string)($pendingAction['order_id'] ?? ''));
            if ($orderId === '' || !isset($ordersById[$orderId])) {
                $warnings[] = "режим отклонения у администратора {$adminId} не связан с существующим заказом";
                continue;
            }

            if ((string)($ordersById[$orderId]['status'] ?? 'pending') !== 'pending') {
                $warnings[] = "режим отклонения относится к уже обработанному заказу " . $this->shortOrderId($orderId);
            }
        }

        $lines = ['🎁 Контроль магазина'];
        $lines[] = 'Всего заказов: ' . count($orders);
        $lines[] = 'Ожидают: ' . $statusCounts['pending'];
        if ($statusCounts['processing'] > 0) $lines[] = 'В обработке: ' . $statusCounts['processing'];
        $lines[] = 'Выполнены: ' . $statusCounts['done'];
        $lines[] = 'Отклонены: ' . $statusCounts['rejected'];
        if ($statusCounts['cancelled'] > 0) $lines[] = 'Отменены: ' . $statusCounts['cancelled'];
        if ($statusCounts['unknown'] > 0) $lines[] = 'Неизвестный статус: ' . $statusCounts['unknown'];
        $lines[] = "Активных вводов причины: {$activeRejectModes}";
        if ($staleRejectModes > 0) $lines[] = "Просроченных вводов причины: {$staleRejectModes}";

        $lines[] = "\nОшибки магазина:";
        if ($issues) foreach (array_slice(array_values(array_unique($issues)), 0, 14) as $issue) $lines[] = "❌ {$issue}";
        else $lines[] = '✅ критичных ошибок не найдено';

        $lines[] = "\nПредупреждения магазина:";
        if ($warnings) foreach (array_slice(array_values(array_unique($warnings)), 0, 14) as $warning) $lines[] = "⚠️ {$warning}";
        else $lines[] = '✅ предупреждений нет';

        return implode("\n", $lines);
    }

    private function weeklyEconomyAudit(array $data): string
    {
        $users = is_array($data['users'] ?? null) ? $data['users'] : [];
        $transactions = is_array($data['transactions'] ?? null) ? $data['transactions'] : [];
        $notifications = is_array($data['notifications'] ?? null) ? $data['notifications'] : [];
        $expectedAmount = max(1, (int)($this->config['weekly_match_bonus_amount'] ?? 50));

        $issues = [];
        $warnings = [];
        $welcomeTransactions = [];
        $weeklyTransactions = [];
        $notificationKeys = [];

        foreach ($transactions as $index => $tx) {
            if (!is_array($tx)) continue;

            $category = (string)($tx['category'] ?? '');
            if (!in_array($category, ['welcome_bonus', 'weekly_bonus'], true)) continue;

            $userId = trim((string)($tx['user_id'] ?? ''));
            $amount = (int)($tx['amount'] ?? 0);
            $room = (string)($tx['room'] ?? '');
            $label = $userId !== '' ? $userId : ('transaction #' . $index);

            if ($userId === '' || !isset($users[$userId])) {
                $issues[] = "бонус {$label}: пользователь не найден";
            }
            if ($amount !== $expectedAmount) {
                $issues[] = "бонус {$label}: сумма {$amount}, ожидалось {$expectedAmount}";
            }
            if ($room !== 'match') {
                $issues[] = "бонус {$label}: начисление проведено не в Match-баланс";
            }

            if ($category === 'welcome_bonus') {
                if ($userId !== '') {
                    $welcomeTransactions[$userId] = ($welcomeTransactions[$userId] ?? 0) + 1;
                }
            } else {
                $cycleKey = trim((string)($tx['cycle_key'] ?? ''));
                if ($cycleKey === '') {
                    $issues[] = "недельный бонус {$label}: отсутствует cycle_key";
                } elseif ($userId !== '') {
                    $key = $userId . '|' . $cycleKey;
                    $weeklyTransactions[$key] = ($weeklyTransactions[$key] ?? 0) + 1;
                }
            }
        }

        foreach ($welcomeTransactions as $userId => $count) {
            if ($count > 1) {
                $issues[] = "игрок {$userId}: стартовый бонус начислен {$count} раз";
            }
        }
        foreach ($weeklyTransactions as $key => $count) {
            if ($count > 1) {
                [$userId, $cycleKey] = array_pad(explode('|', $key, 2), 2, '');
                $issues[] = "игрок {$userId}: недельный бонус за {$cycleKey} начислен {$count} раз";
            }
        }

        foreach ($notifications as $notification) {
            if (!is_array($notification)) continue;
            $type = (string)($notification['type'] ?? '');
            if (!in_array($type, ['welcome_match_grant', 'weekly_match_bonus'], true)) continue;

            $eventKey = trim((string)($notification['event_key'] ?? ''));
            if ($eventKey === '') {
                $warnings[] = "уведомление {$type}: отсутствует event_key";
                continue;
            }

            $notificationKeys[$eventKey] = ($notificationKeys[$eventKey] ?? 0) + 1;
        }

        foreach ($notificationKeys as $eventKey => $count) {
            if ($count > 1) {
                $issues[] = "уведомление {$eventKey}: создано {$count} дубля";
            }
        }

        foreach ($users as $userId => $user) {
            if (!is_array($user) || !empty($user['is_dev_user'])) continue;

            if (!empty($user['weekly_match_welcome_grant_done'])
                && empty($user['weekly_match_welcome_grant_migrated_at'])
                && (int)($welcomeTransactions[(string)$userId] ?? 0) === 0) {
                $warnings[] = "игрок {$userId}: стартовый бонус отмечен выданным, но операции welcome_bonus нет";
            }

            $lastKey = trim((string)($user['weekly_match_bonus_last_key'] ?? ''));
            if ($lastKey !== '') {
                $txKey = (string)$userId . '|' . $lastKey;
                if ((int)($weeklyTransactions[$txKey] ?? 0) === 0) {
                    $warnings[] = "игрок {$userId}: last_key {$lastKey} без операции weekly_bonus";
                }
            }
        }

        $lines = ['🎲 Контроль бесплатных коинов'];
        $lines[] = 'Стартовых начислений: ' . array_sum($welcomeTransactions);
        $lines[] = 'Недельных начислений: ' . array_sum($weeklyTransactions);

        $lines[] = "\nОшибки бонусов:";
        if ($issues) foreach (array_slice(array_values(array_unique($issues)), 0, 14) as $issue) $lines[] = "❌ {$issue}";
        else $lines[] = '✅ критичных ошибок не найдено';

        $lines[] = "\nПредупреждения бонусов:";
        if ($warnings) foreach (array_slice(array_values(array_unique($warnings)), 0, 14) as $warning) $lines[] = "⚠️ {$warning}";
        else $lines[] = '✅ предупреждений нет';

        return implode("\n", $lines);
    }

    private function startsWithCommand(string $text, string $command): bool
    {
        if ($command === '') return false;
        $token = preg_split('/\s+/', trim($text), 2)[0] ?? '';
        $tokenWithoutBot = explode('@', (string)$token, 2)[0];
        return $token === $command || $tokenWithoutBot === $command;
    }

    private function shortPaymentId(string $id): string
    {
        $id = preg_replace('/^(pay_)/i', '', $id);
        $id = strtoupper(substr((string)$id, 0, 8));
        return $id !== '' ? $id : '-';
    }

    private function shortOrderId(string $id): string
    {
        $id = preg_replace('/^(shop_|order_)/i', '', $id);
        $id = strtoupper(substr((string)$id, 0, 8));
        return $id !== '' ? $id : '-';
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
