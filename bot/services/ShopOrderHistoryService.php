<?php
declare(strict_types=1);

final class ShopOrderHistoryService
{
    public function userOrders(array $db, string $userId, int $limit = 20): array
    {
        $userId = trim($userId);
        if ($userId === '') {
            return [];
        }

        $orders = [];
        foreach (($db['shop_orders'] ?? []) as $order) {
            if (!is_array($order) || (string)($order['user_id'] ?? '') !== $userId) {
                continue;
            }

            $orders[] = $this->publicOrder($order);
        }

        usort($orders, static function (array $a, array $b): int {
            return strcmp((string)($b['created_at'] ?? ''), (string)($a['created_at'] ?? ''));
        });

        $limit = max(1, min(50, $limit));
        return array_slice($orders, 0, $limit);
    }

    private function publicOrder(array $order): array
    {
        $status = $this->normalizeStatus((string)($order['status'] ?? 'pending'));
        $amount = abs((int)($order['gold_cost'] ?? $order['amount'] ?? 0));
        $refundAmount = abs((int)($order['refund_amount'] ?? $amount));

        $rejectReason = '';
        if ($status === 'rejected') {
            $rejectReason = trim((string)($order['reject_reason'] ?? $order['admin_note'] ?? ''));
            if ($rejectReason === '') {
                $rejectReason = 'Причина не указана.';
            }
        }

        return [
            'id' => (string)($order['id'] ?? ''),
            'short_id' => $this->shortId((string)($order['id'] ?? '')),
            'status' => $status,
            'status_label' => $this->statusLabel($status),
            'status_tone' => $this->statusTone($status),
            'country' => (string)($order['country'] ?? ''),
            'country_code' => (string)($order['country_code'] ?? ''),
            'provider' => (string)($order['provider'] ?? ''),
            'prize_title' => (string)($order['prize_title'] ?? $order['provider'] ?? 'Приз'),
            'denomination_label' => (string)($order['denomination_label'] ?? ($amount . ' Gold')),
            'gold_cost' => $amount,
            'created_at' => (string)($order['created_at'] ?? ''),
            'updated_at' => (string)($order['updated_at'] ?? ''),
            'completed_at' => (string)($order['completed_at'] ?? ''),
            'rejected_at' => (string)($order['rejected_at'] ?? ''),
            'cancelled_at' => (string)($order['cancelled_at'] ?? ''),
            'refund_done' => !empty($order['refund_done']),
            'refund_amount' => !empty($order['refund_done']) ? $refundAmount : 0,
            'refunded_at' => (string)($order['refunded_at'] ?? ''),
            'reject_reason' => $rejectReason,
        ];
    }

    private function normalizeStatus(string $status): string
    {
        $status = strtolower(trim($status));
        return in_array($status, ['pending', 'processing', 'done', 'rejected', 'cancelled'], true)
            ? $status
            : 'unknown';
    }

    private function statusLabel(string $status): string
    {
        return match ($status) {
            'pending' => 'Ожидает обработки',
            'processing' => 'В обработке',
            'done' => 'Выполнена',
            'rejected' => 'Отклонена',
            'cancelled' => 'Отменена',
            default => 'Статус уточняется',
        };
    }

    private function statusTone(string $status): string
    {
        return match ($status) {
            'done' => 'success',
            'rejected' => 'danger',
            'processing' => 'info',
            'cancelled' => 'muted',
            'pending' => 'warning',
            default => 'muted',
        };
    }

    private function shortId(string $id): string
    {
        $id = preg_replace('/^(shop_|order_)/', '', $id);
        $id = strtoupper(substr((string)$id, 0, 8));
        return $id !== '' ? $id : '—';
    }
}
