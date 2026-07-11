<?php
declare(strict_types=1);

final class NotificationService
{
    public function addShopOrderDecision(array &$db, array $order, string $decision): ?array
    {
        if (!in_array($decision, ['done', 'rejected'], true)) {
            return null;
        }

        $userId = trim((string)($order['user_id'] ?? ''));
        $orderId = trim((string)($order['id'] ?? ''));
        if ($userId === '' || $orderId === '') {
            return null;
        }

        if (!isset($db['notifications']) || !is_array($db['notifications'])) {
            $db['notifications'] = [];
        }

        $eventKey = 'shop_order:' . $orderId . ':' . $decision;
        foreach ($db['notifications'] as $existing) {
            if (is_array($existing) && (string)($existing['event_key'] ?? '') === $eventKey) {
                return $existing;
            }
        }

        $amount = abs((int)($order['gold_cost'] ?? $order['amount'] ?? 0));
        $shortId = $this->shortOrderId($orderId);
        $prize = trim((string)(($order['prize_title'] ?? '') ?: ($order['provider'] ?? '') ?: 'Приз'));
        $denomination = trim((string)($order['denomination_label'] ?? ''));

        if ($decision === 'done') {
            $title = 'Заказ выполнен';
            $message = "Заявка #{$shortId}: {$prize}"
                . ($denomination !== '' ? " · {$denomination}" : '')
                . '. Статус обновлён в разделе «Мои заявки».';
            $tone = 'success';
        } else {
            $reason = trim((string)($order['reject_reason'] ?? $order['admin_note'] ?? ''));
            $refundDone = !empty($order['refund_done']);
            $refundAmount = abs((int)($order['refund_amount'] ?? $amount));
            $title = 'Заказ отклонён';
            $message = "Заявка #{$shortId}: {$prize}.";
            if ($reason !== '') {
                $message .= " Причина: {$reason}.";
            }
            $message .= $refundDone
                ? " Возвращено +{$refundAmount} Gold."
                : ' Проверьте статус возврата в разделе «Мои заявки».';
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

    public function addWeeklyMatchBonus(array &$db, array $user, array $bonus): ?array
    {
        $userId = trim((string)($user['id'] ?? ''));
        $cycleKey = trim((string)($bonus['cycle_key'] ?? ''));
        $amount = max(0, (int)($bonus['amount'] ?? 0));

        if ($userId === '' || $cycleKey === '' || $amount <= 0) {
            return null;
        }

        if (!isset($db['notifications']) || !is_array($db['notifications'])) {
            $db['notifications'] = [];
        }

        $eventKey = 'weekly_match_bonus:' . $userId . ':' . $cycleKey;
        foreach ($db['notifications'] as $existing) {
            if (is_array($existing) && (string)($existing['event_key'] ?? '') === $eventKey) {
                return $existing;
            }
        }

        $games = max(0, (int)($bonus['qualifying_games'] ?? 0));
        $notification = [
            'id' => make_id('notification'),
            'event_key' => $eventKey,
            'user_id' => $userId,
            'type' => 'weekly_match_bonus',
            'title' => 'Еженедельные коины',
            'message' => "+{$amount} коинов в Матч-комнату за активность. За квалификационную неделю завершено матчей: {$games}.",
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
        $items = [];
        foreach (array_reverse($db['notifications'] ?? []) as $notification) {
            if (!is_array($notification) || (string)($notification['user_id'] ?? '') !== $userId) {
                continue;
            }

            $items[] = [
                'id' => (string)($notification['id'] ?? ''),
                'type' => (string)($notification['type'] ?? ''),
                'title' => (string)($notification['title'] ?? 'Уведомление'),
                'message' => (string)($notification['message'] ?? ''),
                'tone' => (string)($notification['tone'] ?? 'info'),
                'order_id' => (string)($notification['order_id'] ?? ''),
                'created_at' => (string)($notification['created_at'] ?? ''),
                'read' => !empty($notification['read_at']),
            ];

            if (count($items) >= $limit) {
                break;
            }
        }

        return $items;
    }

    public function unreadCount(array $db, string $userId): int
    {
        $count = 0;
        foreach ($db['notifications'] ?? [] as $notification) {
            if (!is_array($notification)) {
                continue;
            }
            if ((string)($notification['user_id'] ?? '') === $userId && empty($notification['read_at'])) {
                $count++;
            }
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
            if (!is_array($notification)) {
                continue;
            }
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
}
