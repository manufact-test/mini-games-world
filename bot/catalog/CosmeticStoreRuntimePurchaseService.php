<?php
declare(strict_types=1);

require_once __DIR__ . '/CosmeticStoreService.php';

final class CosmeticStoreRuntimePurchaseService
{
    private const ITEM_ID_PATTERN = '/^[a-z0-9][a-z0-9_.-]{0,63}$/';

    public function __construct(private StorageTransactionInterface $storage) {}

    public function prepare(array &$data, array &$user, array $quote, string $requestToken): array
    {
        $userId = trim((string)($user['id'] ?? ''));
        if ($userId === '') throw new CosmeticStoreException('account_unavailable', 'Runtime user is unavailable.');
        UnifiedBalanceRuntimeState::ensureUser($user);

        $existing = $this->intent($data, $userId, $requestToken);
        if ($existing !== null) {
            $this->assertSameIntent($existing, $quote);
            return ['intent' => $existing, 'replayed_runtime' => true];
        }

        $itemIds = $this->sortedItemIds($quote['item_ids'] ?? null);
        if ($this->hasPendingOverlap($data, $userId, $itemIds)) {
            throw new CosmeticStoreException('purchase_in_progress', 'Overlapping cosmetic purchase is pending.');
        }

        $price = (int)($quote['price_coins'] ?? 0);
        $balance = (int)($user[UnifiedBalanceRuntimeState::FIELD] ?? 0);
        if ($price <= 0) throw new CosmeticStoreException('intent_invalid', 'Purchase price is invalid.');
        if ($balance < $price) throw new CosmeticStoreException('insufficient_balance', 'Insufficient available balance.');

        $user[UnifiedBalanceRuntimeState::FIELD] = $balance - $price;
        if (!isset($data['transactions']) || !is_array($data['transactions'])) $data['transactions'] = [];
        $intent = [
            'id' => make_id('tx'),
            'type' => CosmeticStoreService::PURCHASE_TRANSACTION_TYPE,
            'category' => 'cosmetic_purchase',
            'user_id' => $userId,
            'mgw_id' => (string)($user['mgw_id'] ?? ''),
            'request_token' => $requestToken,
            'offer_id' => (string)($quote['offer_id'] ?? ''),
            'item_ids' => $itemIds,
            'price_coins' => $price,
            'amount' => -$price,
            'balance_after' => (int)$user[UnifiedBalanceRuntimeState::FIELD],
            'status' => CosmeticStoreService::PURCHASE_PENDING_STATUS,
            'description' => 'Покупка косметики MGW',
            'created_at' => now_iso(),
        ];
        $data['transactions'][] = $intent;
        return ['intent' => $intent, 'replayed_runtime' => false];
    }

    public function pendingForUser(array $data, string $userId): array
    {
        $pending = [];
        foreach ((array)($data['transactions'] ?? []) as $row) {
            if (!$this->isPurchaseRow($row, $userId)) continue;
            if ((string)($row['status'] ?? '') !== CosmeticStoreService::PURCHASE_PENDING_STATUS) continue;
            $token = trim((string)($row['request_token'] ?? ''));
            if ($token !== '') $pending[$token] = $row;
        }
        return array_values($pending);
    }

    public function intent(array $data, string $userId, string $requestToken): ?array
    {
        foreach (array_reverse((array)($data['transactions'] ?? [])) as $row) {
            if (!$this->isPurchaseRow($row, $userId)) continue;
            if ((string)($row['request_token'] ?? '') === $requestToken) return $row;
        }
        return null;
    }

    public function markCompleted(string $userId, string $requestToken): void
    {
        $this->storage->transaction(function (array &$data) use ($userId, $requestToken): void {
            if (!isset($data['transactions']) || !is_array($data['transactions'])) return;
            foreach ($data['transactions'] as &$row) {
                if (!$this->isPurchaseRow($row, $userId)) continue;
                if ((string)($row['request_token'] ?? '') !== $requestToken) continue;
                if ((string)($row['status'] ?? '') === 'refunded') return;
                $row['status'] = CosmeticStoreService::PURCHASE_COMPLETED_STATUS;
                $row['updated_at'] = now_iso();
                return;
            }
            unset($row);
        });
    }

    public function refund(string $userId, string $requestToken): bool
    {
        return (bool)$this->storage->transaction(function (array &$data) use ($userId, $requestToken): bool {
            if (!isset($data['transactions']) || !is_array($data['transactions'])) return false;
            $intentIndex = null;
            $intent = null;
            foreach ($data['transactions'] as $index => $row) {
                if (!$this->isPurchaseRow($row, $userId)) continue;
                if ((string)($row['request_token'] ?? '') !== $requestToken) continue;
                $intentIndex = $index;
                $intent = $row;
                break;
            }
            if ($intentIndex === null || !is_array($intent)) return false;
            if ((string)($intent['status'] ?? '') !== CosmeticStoreService::PURCHASE_PENDING_STATUS) return false;
            if (!isset($data['users'][$userId]) || !is_array($data['users'][$userId])) return false;
            $amount = max(0, (int)($intent['price_coins'] ?? 0));
            if ($amount <= 0) return false;

            UnifiedBalanceRuntimeState::ensureUser($data['users'][$userId]);
            $data['users'][$userId][UnifiedBalanceRuntimeState::FIELD] =
                (int)($data['users'][$userId][UnifiedBalanceRuntimeState::FIELD] ?? 0) + $amount;
            $data['transactions'][$intentIndex]['status'] = 'refunded';
            $data['transactions'][$intentIndex]['updated_at'] = now_iso();
            $data['transactions'][] = [
                'id' => make_id('tx'),
                'type' => 'balance_change',
                'category' => 'cosmetic_purchase_refund',
                'user_id' => $userId,
                'amount' => $amount,
                'balance_after' => (int)$data['users'][$userId][UnifiedBalanceRuntimeState::FIELD],
                'request_token' => $requestToken,
                'offer_id' => (string)($intent['offer_id'] ?? ''),
                'description' => 'Возврат коинов за незавершённую покупку косметики',
                'created_at' => now_iso(),
            ];
            return true;
        });
    }

    public function balance(string $userId): int
    {
        return (int)$this->storage->readOnly(static function (array $data) use ($userId): int {
            return max(0, (int)($data['users'][$userId][UnifiedBalanceRuntimeState::FIELD] ?? 0));
        });
    }

    private function assertSameIntent(array $existing, array $quote): void
    {
        $existingItems = $this->sortedItemIds($existing['item_ids'] ?? null);
        $quoteItems = $this->sortedItemIds($quote['item_ids'] ?? null);
        if ((string)($existing['offer_id'] ?? '') !== (string)($quote['offer_id'] ?? '')
            || (int)($existing['price_coins'] ?? 0) !== (int)($quote['price_coins'] ?? 0)
            || $existingItems !== $quoteItems) {
            throw new CosmeticStoreException('request_conflict', 'Purchase request token conflict.');
        }
    }

    private function hasPendingOverlap(array $data, string $userId, array $itemIds): bool
    {
        $wanted = array_fill_keys($itemIds, true);
        foreach ((array)($data['transactions'] ?? []) as $row) {
            if (!$this->isPurchaseRow($row, $userId)) continue;
            if ((string)($row['status'] ?? '') !== CosmeticStoreService::PURCHASE_PENDING_STATUS) continue;
            foreach ((array)($row['item_ids'] ?? []) as $itemId) {
                if (isset($wanted[(string)$itemId])) return true;
            }
        }
        return false;
    }

    private function isPurchaseRow(mixed $row, string $userId): bool
    {
        return is_array($row)
            && (string)($row['type'] ?? '') === CosmeticStoreService::PURCHASE_TRANSACTION_TYPE
            && (string)($row['user_id'] ?? '') === $userId;
    }

    private function sortedItemIds(mixed $value): array
    {
        if (!is_array($value) || !array_is_list($value) || $value === []) {
            throw new CosmeticStoreException('intent_invalid', 'Purchase items are invalid.');
        }
        $result = [];
        foreach ($value as $itemId) {
            $itemId = strtolower(trim((string)$itemId));
            if (preg_match(self::ITEM_ID_PATTERN, $itemId) !== 1) {
                throw new CosmeticStoreException('intent_invalid', 'Purchase item is unavailable.');
            }
            $result[$itemId] = true;
        }
        $result = array_keys($result);
        sort($result, SORT_STRING);
        return $result;
    }
}
