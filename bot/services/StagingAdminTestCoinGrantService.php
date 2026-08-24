<?php
declare(strict_types=1);

final class StagingAdminTestCoinGrantService
{
    public const MAX_GRANT = 250000;

    public function __construct(
        private array $config,
        private StorageTransactionInterface $storage
    ) {}

    public function grant(
        string $playerQuery,
        int $amount,
        string $actorRef,
        string $reason,
        string $requestToken
    ): array {
        $this->assertStaging();
        $playerQuery = trim($playerQuery);
        $actorRef = trim($actorRef);
        $reason = trim($reason);
        $requestToken = trim($requestToken);

        if ($playerQuery === '') {
            throw new InvalidArgumentException('Укажите игрока или начислите коины себе через пустое поле в Web Admin.');
        }
        if ($amount < 1 || $amount > self::MAX_GRANT) {
            throw new InvalidArgumentException('Сумма должна быть от 1 до ' . self::MAX_GRANT . ' коинов.');
        }
        if ($actorRef === '') {
            throw new InvalidArgumentException('Admin actor is unavailable.');
        }
        if ($reason === '' || $this->length($reason) < 3 || $this->length($reason) > 200) {
            throw new InvalidArgumentException('Укажите причину длиной от 3 до 200 символов.');
        }
        if (preg_match('/^admin-test-coins:[a-zA-Z0-9:._-]{12,160}$/', $requestToken) !== 1) {
            throw new InvalidArgumentException('Некорректный идентификатор операции.');
        }

        return $this->storage->transaction(function (array &$data) use (
            $playerQuery,
            $amount,
            $actorRef,
            $reason,
            $requestToken
        ): array {
            if (!isset($data['users']) || !is_array($data['users'])) $data['users'] = [];
            if (!isset($data['transactions']) || !is_array($data['transactions'])) $data['transactions'] = [];

            foreach (array_reverse($data['transactions']) as $row) {
                if (!is_array($row)
                    || (string)($row['category'] ?? '') !== 'admin_test_coin_grant'
                    || (string)($row['request_token'] ?? '') !== $requestToken) {
                    continue;
                }
                if ((int)($row['amount'] ?? 0) !== $amount
                    || !$this->queryMatchesUser($playerQuery, (array)($data['users'][(string)($row['user_id'] ?? '')] ?? []), (string)($row['user_id'] ?? ''))) {
                    throw new InvalidArgumentException('Этот идентификатор уже использован для другого начисления.');
                }
                return $this->publicResult($row, true);
            }

            $matches = [];
            foreach ($data['users'] as $storageUserId => $user) {
                if (!is_array($user)) continue;
                $storageUserId = (string)$storageUserId;
                if ($this->queryMatchesUser($playerQuery, $user, $storageUserId)) {
                    $matches[$storageUserId] = $user;
                }
            }
            if ($matches === []) {
                throw new InvalidArgumentException('Игрок не найден. Используйте @username, Telegram ID или MGW-ID.');
            }
            if (count($matches) !== 1) {
                throw new InvalidArgumentException('Найдено несколько игроков. Укажите точный Telegram ID или MGW-ID.');
            }

            $storageUserId = (string)array_key_first($matches);
            $user =& $data['users'][$storageUserId];
            UnifiedBalanceRuntimeState::ensureUser($user);
            $before = (int)$user[UnifiedBalanceRuntimeState::FIELD];
            if ($before > PHP_INT_MAX - $amount) {
                throw new InvalidArgumentException('Баланс игрока слишком велик для начисления.');
            }
            $after = $before + $amount;
            $user[UnifiedBalanceRuntimeState::FIELD] = $after;

            $row = [
                'id' => make_id('tx'),
                'type' => 'balance_change',
                'category' => 'admin_test_coin_grant',
                'user_id' => $storageUserId,
                'telegram_id' => (string)($user['telegram_id'] ?? $user['id'] ?? $storageUserId),
                'mgw_id' => (string)($user['mgw_id'] ?? ''),
                'username' => (string)($user['username'] ?? ''),
                'amount' => $amount,
                'balance_before' => $before,
                'balance_after' => $after,
                'actor_ref' => $actorRef,
                'reason' => $reason,
                'request_token' => $requestToken,
                'description' => 'Тестовое начисление коинов через staging Web Admin',
                'created_at' => now_iso(),
            ];
            $data['transactions'][] = $row;
            return $this->publicResult($row, false);
        });
    }

    private function assertStaging(): void
    {
        if (strtolower(trim((string)($this->config['environment'] ?? ''))) !== 'staging') {
            throw new RuntimeException('Test coin grants are unavailable outside staging.');
        }
    }

    private function queryMatchesUser(string $query, array $user, string $storageUserId): bool
    {
        $query = ltrim(trim($query), '@');
        if ($query === '') return false;
        $exact = [
            $storageUserId,
            (string)($user['id'] ?? ''),
            (string)($user['telegram_id'] ?? ''),
            (string)($user['mgw_id'] ?? ''),
        ];
        foreach ($exact as $candidate) {
            if ($candidate !== '' && hash_equals($candidate, $query)) return true;
        }
        $username = ltrim(trim((string)($user['username'] ?? '')), '@');
        return $username !== '' && $this->lower($username) === $this->lower($query);
    }

    private function publicResult(array $row, bool $replayed): array
    {
        $username = trim((string)($row['username'] ?? ''));
        return [
            'player' => $username !== '' ? '@' . ltrim($username, '@') : ('ID ' . (string)($row['telegram_id'] ?? $row['user_id'] ?? '')),
            'user_id' => (string)($row['user_id'] ?? ''),
            'mgw_id' => (string)($row['mgw_id'] ?? ''),
            'amount' => (int)($row['amount'] ?? 0),
            'balance_before' => (int)($row['balance_before'] ?? 0),
            'balance_after' => (int)($row['balance_after'] ?? 0),
            'replayed' => $replayed,
        ];
    }

    private function lower(string $value): string
    {
        return function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
    }

    private function length(string $value): int
    {
        return function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
    }
}
