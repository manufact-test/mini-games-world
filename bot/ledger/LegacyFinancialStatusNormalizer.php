<?php
declare(strict_types=1);

final class LegacyFinancialStatusNormalizer
{
    public function payment(string $rawStatus): string
    {
        return match ($this->normalize($rawStatus)) {
            'paid', 'applied', 'completed', 'success', 'succeeded' => 'completed',
            'rejected', 'declined', 'failed' => 'rejected',
            'cancelled', 'canceled' => 'cancelled',
            'draft', 'pending', 'waiting', 'created' => 'pending',
            default => 'unknown',
        };
    }

    public function order(string $rawStatus): string
    {
        return match ($this->normalize($rawStatus)) {
            'fulfilled', 'completed', 'delivered', 'issued' => 'completed',
            'rejected', 'declined', 'failed' => 'rejected',
            'cancelled', 'canceled', 'refunded' => 'cancelled',
            'draft', 'pending', 'processing', 'created' => 'pending',
            default => 'unknown',
        };
    }

    public function transaction(array $transaction): string
    {
        $category = $this->normalize((string)($transaction['category'] ?? $transaction['type'] ?? ''));
        if (in_array($category, ['payment_reject', 'shop_order_reject'], true)) {
            return 'rejected';
        }
        if (in_array($category, ['payment_cancel', 'shop_order_refund', 'shop_order_cancel'], true)) {
            return 'cancelled';
        }
        if (in_array($category, [
            'payment_draft',
            'payment_apply',
            'shop_order',
            'shop_order_complete',
            'shop_order_fulfill',
        ], true)) {
            return $category === 'payment_draft' ? 'pending' : 'completed';
        }
        return 'unknown';
    }

    private function normalize(string $value): string
    {
        return strtolower(trim($value));
    }
}
