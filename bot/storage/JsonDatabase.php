<?php
declare(strict_types=1);

final class JsonDatabase
{
    private string $dataDir;
    private string $lockFile;

    public function __construct(string $dataDir)
    {
        $this->dataDir = rtrim($dataDir, '/');
        if (!is_dir($this->dataDir)) {
            mkdir($this->dataDir, 0755, true);
        }
        $this->lockFile = $this->dataDir . '/app.lock';
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
            $db = $this->loadAll();
            $before = $db;
            $result = $callback($db);
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
        $lockHandle = fopen($this->lockFile, 'c+');
        if (!$lockHandle) {
            throw new RuntimeException('Не удалось открыть lock-файл.');
        }
        try {
            if (!flock($lockHandle, LOCK_SH)) {
                throw new RuntimeException('Не удалось заблокировать хранилище.');
            }
            $db = $this->loadAll();
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
                file_put_contents($path, json_encode($value, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
            }
        }
    }

    private function loadAll(): array
    {
        return [
            'users' => $this->readFile('users.json'),
            'games' => $this->readFile('games.json'),
            'queue' => $this->readFile('queue.json'),
            'transactions' => $this->readFile('transactions.json'),
            'support' => $this->readFile('support.json'),
            'shop_orders' => $this->readFile('shop_orders.json'),
            'payments' => $this->readFile('payments.json'),
            'notifications' => $this->readFile('notifications.json'),
            'invites' => $this->readFile('invites.json'),
            'system' => $this->readFile('system.json'),
        ];
    }

    private function saveChanged(array $before, array $after): void
    {
        $files = [
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

        foreach ($files as $key => $file) {
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
