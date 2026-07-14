<?php
declare(strict_types=1);

final class NotificationService
{
    public function addShopOrderDecision(array &$db, array $order, string $decision): ?array
    {
        if (!in_array($decision, ['done', 'rejected'], true)) return null;

        $userId = trim((string)($order['user_id'] ?? ''));
        $orderId = trim((string)($order['id'] ?? ''));
        if ($userId === '' || $orderId === '') return null;
        if (!isset($db['notifications']) || !is_array($db['notifications'])) $db['notifications'] = [];

        $eventKey = 'shop_order:' . $orderId . ':' . $decision;
        foreach ($db['notifications'] as $existing) {
            if (is_array($existing) && (string)($existing['event_key'] ?? '') === $eventKey) return $existing;
        }

        $shortId = $this->shortOrderId($orderId);
        $prize = trim((string)(($order['prize_title'] ?? '') ?: ($order['provider'] ?? '') ?: 'Приз'));
        $denomination = trim((string)($order['denomination_label'] ?? ''));
        if ($decision === 'done') {
            $title = 'Заказ выполнен';
            $message = "Заявка #{$shortId}: {$prize}" . ($denomination !== '' ? " · {$denomination}" : '') . '.';
            $tone = 'success';
        } else {
            $reason = trim((string)($order['reject_reason'] ?? $order['admin_note'] ?? ''));
            $title = 'Заказ отклонён';
            $message = "Заявка #{$shortId}: {$prize}." . ($reason !== '' ? " Причина: {$reason}." : '');
            $tone = 'danger';
        }

        $notification = [
            'id' => make_id('notification'),
            'event_key' => $eventKey,
            'user_id' => $userId,
            'type' => 'shop_order_' . $decision,
            'title' => $title,
            'message' => $message,
            'tone' => $tone,
            'order_id' => $orderId,
            'created_at' => now_iso(),
            'read_at' => null,
        ];
        $db['notifications'][] = $notification;
        return $notification;
    }

    public function addPaymentDecision(array &$db, array $payment, string $decision): ?array
    {
        if (!in_array($decision, ['applied', 'rejected'], true)) return null;

        $userId = trim((string)($payment['user_id'] ?? ''));
        $paymentId = trim((string)($payment['id'] ?? ''));
        if ($userId === '' || $paymentId === '') return null;
        if (!isset($db['notifications']) || !is_array($db['notifications'])) $db['notifications'] = [];

        $eventKey = 'payment:' . $paymentId . ':' . $decision;
        foreach ($db['notifications'] as $existing) {
            if (is_array($existing) && (string)($existing['event_key'] ?? '') === $eventKey) return $existing;
        }

        $shortId = $this->shortPaymentId($paymentId);
        $room = (string)($payment['room'] ?? 'gold') === 'match' ? 'Match' : 'Gold';
        $coins = max(0, (int)($payment['coins'] ?? 0));
        $price = max(0, (int)($payment['price'] ?? $payment['amount_rub'] ?? 0));
        $currency = trim((string)($payment['currency'] ?? 'RUB')) ?: 'RUB';
        if ($decision === 'applied') {
            $title = 'Пополнение подтверждено';
            $message = "Заявка #{$shortId}: начислено +{$coins} {$room}-коинов"
                . ($price > 0 ? " за {$price} {$currency}" : '') . '.';
            $tone = 'success';
        } else {
            $reason = trim((string)($payment['reject_reason'] ?? ''));
            $title = 'Пополнение отклонено';
            $message = "Заявка #{$shortId} на {$coins} {$room}-коинов отклонена."
                . ($reason !== '' ? " Причина: {$reason}." : '');
            $tone = 'danger';
        }

        $notification = [
            'id' => make_id('notification'),
            'event_key' => $eventKey,
            'user_id' => $userId,
            'type' => 'payment_' . $decision,
            'title' => $title,
            'message' => $message,
            'tone' => $tone,
            'payment_id' => $paymentId,
            'created_at' => now_iso(),
            'read_at' => null,
        ];
        $db['notifications'][] = $notification;
        return $notification;
    }

    public function addAdminGoldTopup(array &$db, array $transaction): ?array
    {
        if ((string)($transaction['category'] ?? '') !== 'admin_gold_topup') return null;

        $userId = trim((string)($transaction['user_id'] ?? ''));
        $transactionId = trim((string)($transaction['id'] ?? ''));
        $amount = max(0, (int)($transaction['amount'] ?? 0));
        if ($userId === '' || $transactionId === '' || $amount <= 0) return null;
        if (!isset($db['notifications']) || !is_array($db['notifications'])) $db['notifications'] = [];

        $eventKey = 'admin_gold_topup:' . $transactionId;
        foreach ($db['notifications'] as $existing) {
            if (is_array($existing) && (string)($existing['event_key'] ?? '') === $eventKey) return $existing;
        }

        $reason = trim((string)($transaction['reason'] ?? ''));
        $message = "Начислено +{$amount} Gold." . ($reason !== '' ? " Причина: {$reason}." : '');
        $notification = [
            'id' => make_id('notification'),
            'event_key' => $eventKey,
            'user_id' => $userId,
            'type' => 'admin_gold_topup',
            'title' => 'Gold начислен',
            'message' => $message,
            'tone' => 'success',
            'transaction_id' => $transactionId,
            'created_at' => (string)($transaction['created_at'] ?? now_iso()),
            'read_at' => null,
        ];
        $db['notifications'][] = $notification;
        return $notification;
    }

    public function addWelcomeMatchGrant(array &$db, array $user, array $grant): ?array
    {
        $userId = trim((string)($user['id'] ?? ''));
        $amount = max(0, (int)($grant['amount'] ?? 0));
        if ($userId === '' || $amount <= 0) return null;
        if (!isset($db['notifications']) || !is_array($db['notifications'])) $db['notifications'] = [];

        $eventKey = 'welcome_match_grant:' . $userId;
        foreach ($db['notifications'] as $existing) {
            if (is_array($existing) && (string)($existing['event_key'] ?? '') === $eventKey) return $existing;
        }

        $notification = [
            'id' => make_id('notification'),
            'event_key' => $eventKey,
            'user_id' => $userId,
            'type' => 'welcome_match_grant',
            'title' => 'Добро пожаловать!',
            'message' => "Спасибо, что заглянули в Mini Games World. Мы начислили вам первые +{$amount} коинов в Матч-комнату.",
            'tone' => 'success',
            'created_at' => (string)($grant['created_at'] ?? now_iso()),
            'read_at' => null,
        ];
        $db['notifications'][] = $notification;
        return $notification;
    }

    public function addWeeklyMatchBonus(array &$db, array $user, array $bonus): ?array
    {
        $userId = trim((string)($user['id'] ?? ''));
        $cycleKey = trim((string)($bonus['cycle_key'] ?? ''));
        $amount = max(0, (int)($bonus['amount'] ?? 0));
        if ($userId === '' || $cycleKey === '' || $amount <= 0) return null;
        if (!isset($db['notifications']) || !is_array($db['notifications'])) $db['notifications'] = [];

        $eventKey = 'weekly_match_bonus:' . $userId . ':' . $cycleKey;
        foreach ($db['notifications'] as $existing) {
            if (is_array($existing) && (string)($existing['event_key'] ?? '') === $eventKey) return $existing;
        }

        $games = max(0, (int)($bonus['qualifying_games'] ?? 0));
        $notification = [
            'id' => make_id('notification'),
            'event_key' => $eventKey,
            'user_id' => $userId,
            'type' => 'weekly_match_bonus',
            'title' => 'Еженедельные коины начислены',
            'message' => "Завершено Match-матчей: {$games}. Начислено +{$amount} коинов.",
            'tone' => 'success',
            'cycle_key' => $cycleKey,
            'created_at' => (string)($bonus['created_at'] ?? now_iso()),
            'read_at' => null,
        ];
        $db['notifications'][] = $notification;
        return $notification;
    }

    public function userNotifications(array $db, string $userId, int $limit = 30): array
    {
        $source = array_values(array_filter($db['notifications'] ?? [], static function ($notification) use ($userId): bool {
            return is_array($notification)
                && (string)($notification['user_id'] ?? '') === $userId
                && empty($notification['hidden_at']);
        }));

        usort($source, static function (array $left, array $right): int {
            $leftTime = strtotime((string)($left['created_at'] ?? '')) ?: 0;
            $rightTime = strtotime((string)($right['created_at'] ?? '')) ?: 0;
            if ($leftTime !== $rightTime) return $rightTime <=> $leftTime;
            return strcmp((string)($right['id'] ?? ''), (string)($left['id'] ?? ''));
        });

        $items = [];
        foreach ($source as $notification) {
            $items[] = [
                'id' => (string)($notification['id'] ?? ''),
                'type' => (string)($notification['type'] ?? ''),
                'title' => (string)($notification['title'] ?? 'Уведомление'),
                'message' => (string)($notification['message'] ?? ''),
                'tone' => (string)($notification['tone'] ?? 'info'),
                'order_id' => (string)($notification['order_id'] ?? ''),
                'payment_id' => (string)($notification['payment_id'] ?? ''),
                'transaction_id' => (string)($notification['transaction_id'] ?? ''),
                'invite_token' => (string)($notification['invite_token'] ?? ''),
                'created_at' => (string)($notification['created_at'] ?? ''),
                'read' => !empty($notification['read_at']),
            ];
            if (count($items) >= $limit) break;
        }
        return $items;
    }

    public function unreadCount(array $db, string $userId): int
    {
        $count = 0;
        foreach ($db['notifications'] ?? [] as $notification) {
            if (!is_array($notification)) continue;
            if ((string)($notification['user_id'] ?? '') !== $userId) continue;
            if (!empty($notification['hidden_at']) || !empty($notification['read_at'])) continue;
            $count++;
        }
        return $count;
    }

    public function markAllRead(array &$db, string $userId): void
    {
        if (!isset($db['notifications']) || !is_array($db['notifications'])) {
            $db['notifications'] = [];
            return;
        }

        $now = now_iso();
        foreach ($db['notifications'] as &$notification) {
            if (!is_array($notification)) continue;
            if ((string)($notification['user_id'] ?? '') === $userId && empty($notification['read_at'])) {
                $notification['read_at'] = $now;
            }
        }
        unset($notification);
    }

    private function shortOrderId(string $id): string
    {
        $id = preg_replace('/^(shop_|order_)/i', '', $id);
        $id = strtoupper(substr((string)$id, 0, 8));
        return $id !== '' ? $id : '—';
    }

    private function shortPaymentId(string $id): string
    {
        $id = preg_replace('/^(pay_)/i', '', $id);
        $id = strtoupper(substr((string)$id, 0, 8));
        return $id !== '' ? $id : '—';
    }
}
