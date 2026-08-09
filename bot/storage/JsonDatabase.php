<?php
declare(strict_types=1);

final class JsonDatabase
{
    private const FILES = [
        'users' => 'users.json',
        'games' => 'games.json',
        'queue' => 'queue.json',
        'transactions' => 'transactions.json',
        'support' => 'support.json',
        'shop_orders' => 'shop_orders.json',
        'payments' => 'payments.json',
        'notifications' => 'notifications.json',
        'invites' => 'invites.json',
        'system' => 'system.json',
    ];

    private const WEEKLY_USER_FIELDS = [
        'weekly_match_welcome_grant_done',
        'weekly_match_welcome_grant_at',
        'weekly_match_welcome_grant_amount',
        'weekly_match_welcome_grant_migrated_at',
        'weekly_match_first_grant_done',
        'weekly_match_bonus_checked_key',
        'weekly_match_bonus_checked_at',
        'weekly_match_bonus_checked_games',
        'weekly_match_bonus_last_key',
        'weekly_match_bonus_last_at',
        'weekly_match_bonus_last_amount',
        'weekly_match_bonus_last_qualification',
        'weekly_bonus_last',
    ];

    private string $dataDir;
    private string $lockFile;
    private string $writeBlockFile;
    private ?array $exclusiveSnapshot = null;

    public function __construct(string $dataDir)
    {
        $this->dataDir = rtrim($dataDir, '/');
        if (!is_dir($this->dataDir)) {
            mkdir($this->dataDir, 0755, true);
        }
        $this->lockFile = $this->dataDir . '/app.lock';
        $this->writeBlockFile = $this->dataDir . '/.cutover-write-block';
        $this->ensureFiles();
    }

    public function transaction(callable $callback): mixed
    {
        $lockHandle = fopen($this->lockFile, 'c+');
        if (!$lockHandle) {
            throw new RuntimeException('Не удалось открыть lock-файл.');
        }
        try {
            if (!flock($lockHandle, LOCK_EX)) {
                throw new RuntimeException('Не удалось заблокировать хранилище.');
            }
            if (is_file($this->writeBlockFile)) {
                throw new RuntimeException('Хранилище временно доступно только для чтения. Повторите действие после завершения технической проверки.');
            }
            $db = $this->loadAll();
            $before = $db;
            $result = $callback($db);
            $this->publishRuntimeBridgeDirty($before, $db);
            $this->saveChanged($before, $db);
            flock($lockHandle, LOCK_UN);
            fclose($lockHandle);
            return $result;
        } catch (Throwable $e) {
            flock($lockHandle, LOCK_UN);
            fclose($lockHandle);
            throw $e;
        }
    }

    public function readOnly(callable $callback): mixed
    {
        return $this->readOnlySections(array_keys(self::FILES), $callback);
    }

    /**
     * @param list<string> $sections
     */
    public function readOnlySections(array $sections, callable $callback): mixed
    {
        if ($this->exclusiveSnapshot !== null) {
            return $callback($this->snapshotSections($this->exclusiveSnapshot, $sections));
        }
        return $this->readSectionsWithLock($sections, LOCK_SH, $callback);
    }

    public function exclusiveReadOnly(callable $callback): mixed
    {
        return $this->exclusiveReadOnlySections(array_keys(self::FILES), $callback);
    }

    /**
     * Hold the JSON lock exclusively while a stable snapshot is consumed by
     * an external bridge. The callback receives data by value and therefore
     * cannot mutate the JSON source. Writers remain blocked until the bridge
     * has completed, so no stale snapshot can race a newer JSON transaction.
     *
     * Nested bridge reads on the same adapter reuse the already-frozen
     * snapshot instead of trying to acquire app.lock a second time. A second
     * flock() on another file handle would self-block while LOCK_EX is held.
     *
     * @param list<string> $sections
     */
    public function exclusiveReadOnlySections(array $sections, callable $callback): mixed
    {
        if ($this->exclusiveSnapshot !== null) {
            return $callback($this->snapshotSections($this->exclusiveSnapshot, $sections));
        }

        return $this->readSectionsWithLock($sections, LOCK_EX, function (array $snapshot) use ($callback): mixed {
            $this->exclusiveSnapshot = $snapshot;
            try {
                return $callback($snapshot);
            } finally {
                $this->exclusiveSnapshot = null;
            }
        });
    }

    /** @param list<string> $sections */
    private function readSectionsWithLock(array $sections, int $lockMode, callable $callback): mixed
    {
        if (!in_array($lockMode, [LOCK_SH, LOCK_EX], true)) {
            throw new InvalidArgumentException('Некорректный режим блокировки JSON-хранилища.');
        }

        $lockHandle = fopen($this->lockFile, 'c+');
        if (!$lockHandle) {
            throw new RuntimeException('Не удалось открыть lock-файл.');
        }
        try {
            if (!flock($lockHandle, $lockMode)) {
                throw new RuntimeException('Не удалось заблокировать хранилище.');
            }
            $db = $this->loadSections($sections);
            $result = $callback($db);
            flock($lockHandle, LOCK_UN);
            fclose($lockHandle);
            return $result;
        } catch (Throwable $e) {
            flock($lockHandle, LOCK_UN);
            fclose($lockHandle);
            throw $e;
        }
    }

    /**
     * Runtime DB bridges are publication mirrors, not the JSON state owner.
     * Publish only domains whose authoritative JSON source actually changed in
     * this transaction. This prevents passive profile/session/game reads from
     * re-running full SQL projection/parity work on every request.
     */
    private function publishRuntimeBridgeDirty(array $before, array $after): void
    {
        $GLOBALS['mgw_runtime_bridge_dirty'] = [
            'realtime' => ($before['games'] ?? []) !== ($after['games'] ?? [])
                || ($before['queue'] ?? []) !== ($after['queue'] ?? []),
            'economy' => $this->economyProjection($before) !== $this->economyProjection($after),
            'shop' => ($before['shop_orders'] ?? []) !== ($after['shop_orders'] ?? []),
            'payments' => ($before['payments'] ?? []) !== ($after['payments'] ?? []),
            'weekly_bonus' => $this->weeklyProjection($before) !== $this->weeklyProjection($after),
        ];
    }

    private function economyProjection(array $data): array
    {
        $users = [];
        foreach (is_array($data['users'] ?? null) ? $data['users'] : [] as $key => $user) {
            if (!is_array($user)) continue;
            $userId = (string)($user['id'] ?? $key);
            if ($userId === '') continue;
            $users[$userId] = [
                'telegram_id' => (string)($user['telegram_id'] ?? $userId),
                'balance_match' => (int)($user['balance_match'] ?? 0),
                'balance_gold' => (int)($user['balance_gold'] ?? 0),
                'gold_deposited_total' => (int)($user['gold_deposited_total'] ?? 0),
                'gold_wagered_total' => (int)($user['gold_wagered_total'] ?? 0),
                'gold_shop_spent_total' => (int)($user['gold_shop_spent_total'] ?? 0),
                'registered_at' => (string)($user['registered_at'] ?? ''),
            ];
        }
        ksort($users, SORT_STRING);

        return [
            'users' => $users,
            'transactions' => is_array($data['transactions'] ?? null) ? $data['transactions'] : [],
        ];
    }

    private function weeklyProjection(array $data): array
    {
        $users = [];
        foreach (is_array($data['users'] ?? null) ? $data['users'] : [] as $key => $user) {
            if (!is_array($user)) continue;
            $userId = (string)($user['id'] ?? $key);
            if ($userId === '') continue;

            $state = [
                'id' => $userId,
                'is_dev_user' => !empty($user['is_dev_user']),
            ];
            foreach (self::WEEKLY_USER_FIELDS as $field) {
                if (array_key_exists($field, $user)) $state[$field] = $user[$field];
            }
            $users[$userId] = $state;
        }
        ksort($users, SORT_STRING);

        $finishedGames = [];
        foreach (is_array($data['games'] ?? null) ? $data['games'] : [] as $key => $game) {
            if (!is_array($game) || (string)($game['status'] ?? '') !== 'finished') continue;
            $gameId = (string)($game['id'] ?? $key);
            if ($gameId === '') continue;
            $players = array_values(array_map('strval', is_array($game['player_ids'] ?? null) ? $game['player_ids'] : []));
            $finishedGames[$gameId] = [
                'room' => (string)($game['room'] ?? 'match'),
                'player_ids' => $players,
                'finished_at' => (string)($game['finished_at'] ?? ''),
            ];
        }
        ksort($finishedGames, SORT_STRING);

        return [
            'users' => $users,
            'finished_games' => $finishedGames,
            'notifications' => is_array($data['notifications'] ?? null) ? $data['notifications'] : [],
        ];
    }

    /**
     * @param array<string,mixed> $snapshot
     * @param list<string> $sections
     * @return array<string,mixed>
     */
    private function snapshotSections(array $snapshot, array $sections): array
    {
        $result = [];
        foreach ($sections as $section) {
            $section = trim((string)$section);
            if ($section === '' || !array_key_exists($section, self::FILES)) {
                throw new InvalidArgumentException('Неизвестная секция JSON-хранилища: ' . $section);
            }
            if (array_key_exists($section, $result)) continue;
            if (!array_key_exists($section, $snapshot)) {
                throw new RuntimeException('Вложенное чтение запросило секцию вне активного JSON-снимка: ' . $section);
            }
            $result[$section] = $snapshot[$section];
        }
        return $result;
    }

    private function ensureFiles(): void
    {
        $defaults = [
            'users.json' => [],
            'games.json' => [],
            'queue.json' => [],
            'transactions.json' => [],
            'support.json' => [],
            'shop_orders.json' => [],
            'payments.json' => [],
            'notifications.json' => [],
            'invites.json' => [],
            'system.json' => ['fees_match' => 0, 'fees_gold' => 0],
        ];
        foreach ($defaults as $file => $value) {
            $path = $this->dataDir . '/' . $file;
            if (!file_exists($path)) {
                if (is_file($this->writeBlockFile)) {
                    throw new RuntimeException('Не удалось подготовить отсутствующий JSON-файл: хранилище временно доступно только для чтения.');
                }
                file_put_contents($path, json_encode($value, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
            }
        }
    }

    private function loadAll(): array
    {
        return $this->loadSections(array_keys(self::FILES));
    }

    /** @param list<string> $sections */
    private function loadSections(array $sections): array
    {
        $result = [];
        foreach ($sections as $section) {
            $section = trim((string)$section);
            if ($section === '' || !array_key_exists($section, self::FILES)) {
                throw new InvalidArgumentException('Неизвестная секция JSON-хранилища: ' . $section);
            }
            if (array_key_exists($section, $result)) continue;
            $result[$section] = $this->readFile(self::FILES[$section]);
        }
        return $result;
    }

    private function saveChanged(array $before, array $after): void
    {
        foreach (self::FILES as $key => $file) {
            $previous = $before[$key] ?? [];
            $current = $after[$key] ?? [];
            if ($previous === $current) {
                continue;
            }
            $this->writeFile($file, is_array($current) ? $current : []);
        }
    }

    private function readFile(string $file): array
    {
        $path = $this->dataDir . '/' . $file;
        $raw = file_get_contents($path);
        $data = json_decode($raw ?: '[]', true);
        return is_array($data) ? $data : [];
    }

    private function writeFile(string $file, array $data): void
    {
        $path = $this->dataDir . '/' . $file;
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        file_put_contents($path, $json === false ? '[]' : $json, LOCK_EX);
    }
}
