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
        $db = new JsonDatabase($this->dataDir());
        return $db->readOnly(function (array $data) {
            $base = (new AdminService($this->config))->systemCheck($data);
            return $base . "\n\n" . $this->paymentAudit($data);
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

    private function isAdmin(string $telegramId): bool
    {
        return (new AdminService($this->config))->isAdmin($telegramId);
    }

    private function dataDir(): string
    {
        return (string)($this->config['data_dir'] ?? (__DIR__ . '/../data'));
    }
}
