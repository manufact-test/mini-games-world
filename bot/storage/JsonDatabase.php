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

    /**
     * Exact JSON sections consumed by the API compatibility projection bundle
     * currently owned by Weekly: normalized realtime, economy, weekly state,
     * legacy realtime shadow and notification parity.
     */
    private const RUNTIME_PROJECTION_SECTIONS = [
        'users',
        'games',
        'queue',
        'transactions',
        'notifications',
        'invites',
    ];

    private string $dataDir;
    private string $lockFile;
    private string $writeBarrierFile;
    private string $writeBlockFile;
    private string $runtimeProjectionDirtyFile;
    private ?array $exclusiveSnapshot = null;

    public function __construct(string $dataDir)
    {
        $this->dataDir = rtrim($dataDir, '/');
        if (!is_dir($this->dataDir)) {
            mkdir($this->dataDir, 0755, true);
        }
        $this->lockFile = $this->dataDir . '/app.lock';
        $this->writeBarrierFile = $this->dataDir . '/app-write-barrier.lock';
        $this->writeBlockFile = $this->dataDir . '/.cutover-write-block';
        $this->runtimeProjectionDirtyFile = $this->dataDir . '/.runtime-projection-dirty';
        $this->ensureFiles();
    }

    public function transaction(callable $callback): mixed
    {
        $writeBarrierHandle = fopen($this->writeBarrierFile, 'c+');
        if (!$writeBarrierHandle) {
            throw new RuntimeException('Не удалось открыть writer barrier-файл.');
        }

        $lockHandle = fopen($this->lockFile, 'c+');
        if (!$lockHandle) {
            fclose($writeBarrierHandle);
            throw new RuntimeException('Не удалось открыть lock-файл.');
        }

        try {
            // API writers may run one at a time under app.lock, but every writer
            // also joins the shared writer side of this barrier. JSON→DB bridges
            // take the barrier exclusively so their frozen source cannot be
            // overtaken by a newer writer while read-only clients remain free to
            // observe the already-published JSON snapshot.
            if (!flock($writeBarrierHandle, LOCK_SH)) {
                throw new RuntimeException('Не удалось открыть writer barrier.');
            }
            if (!flock($lockHandle, LOCK_EX)) {
                throw new RuntimeException('Не удалось заблокировать хранилище.');
            }
            if (is_file($this->writeBlockFile)) {
                throw new RuntimeException('Хранилище временно доступно только для чтения. Повторите действие после завершения технической проверки.');
            }
            $db = $this->loadAll();
            $before = $db;
            $result = $callback($db);

            // Mark projection work before publishing changed JSON. If a later
            // file write fails, the conservative dirty marker remains and the
            // next successful API hook performs a harmless catch-up projection.
            // If the callback itself throws, no JSON was published and no marker
            // is created.
            if ($this->runtimeProjectionSourceChanged($before, $db)) {
                $this->markRuntimeProjectionDirty();
            }

            $this->saveChanged($before, $db);
            flock($lockHandle, LOCK_UN);
            flock($writeBarrierHandle, LOCK_UN);
            fclose($lockHandle);
            fclose($writeBarrierHandle);
            return $result;
        } catch (Throwable $e) {
            flock($lockHandle, LOCK_UN);
            flock($writeBarrierHandle, LOCK_UN);
            fclose($lockHandle);
            fclose($writeBarrierHandle);
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
     * Freeze JSON writers while a stable source snapshot is consumed by an
     * external DB bridge. The callback receives data by value and cannot mutate
     * the JSON source.
     *
     * This exclusivity is intentionally against writers, not readers:
     * - bridge takes app-write-barrier.lock with LOCK_EX;
     * - transaction writers take the same barrier with LOCK_SH;
     * - the bridge copies JSON under a short app.lock LOCK_SH and releases it
     *   before projection/parity work begins;
     * - ordinary readOnly/game-watch readers use only app.lock LOCK_SH and can
     *   therefore observe the committed snapshot while DB projection runs.
     *
     * Nested bridge reads on the same adapter reuse the already-frozen snapshot
     * instead of trying to reacquire either lock.
     *
     * @param list<string> $sections
     */
    public function exclusiveReadOnlySections(array $sections, callable $callback): mixed
    {
        if ($this->exclusiveSnapshot !== null) {
            return $callback($this->snapshotSections($this->exclusiveSnapshot, $sections));
        }

        $writeBarrierHandle = fopen($this->writeBarrierFile, 'c+');
        if (!$writeBarrierHandle) {
            throw new RuntimeException('Не удалось открыть writer barrier-файл.');
        }

        try {
            if (!flock($writeBarrierHandle, LOCK_EX)) {
                throw new RuntimeException('Не удалось заморозить JSON writers для внешней проекции.');
            }

            $snapshot = $this->snapshotSectionsWithSharedLock($sections);
            $this->exclusiveSnapshot = $snapshot;
            try {
                return $callback($snapshot);
            } finally {
                $this->exclusiveSnapshot = null;
            }
        } finally {
            flock($writeBarrierHandle, LOCK_UN);
            fclose($writeBarrierHandle);
        }
    }

    public function runtimeProjectionDirty(): bool
    {
        return is_file($this->runtimeProjectionDirtyFile);
    }

    public function clearRuntimeProjectionDirty(): void
    {
        if ($this->exclusiveSnapshot === null) {
            throw new RuntimeException('Runtime projection dirty marker may only be cleared inside an exclusive JSON snapshot.');
        }
        if (!is_file($this->runtimeProjectionDirtyFile)) {
            return;
        }
        if (!unlink($this->runtimeProjectionDirtyFile)) {
            throw new RuntimeException('Не удалось очистить runtime projection dirty marker.');
        }
    }

    /** @param list<string> $sections */
    private function snapshotSectionsWithSharedLock(array $sections): array
    {
        $lockHandle = fopen($this->lockFile, 'c+');
        if (!$lockHandle) {
            throw new RuntimeException('Не удалось открыть lock-файл.');
        }

        try {
            if (!flock($lockHandle, LOCK_SH)) {
                throw new RuntimeException('Не удалось прочитать стабильный снимок хранилища.');
            }
            return $this->loadSections($sections);
        } finally {
            flock($lockHandle, LOCK_UN);
            fclose($lockHandle);
        }
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

    private function runtimeProjectionSourceChanged(array $before, array $after): bool
    {
        foreach (self::RUNTIME_PROJECTION_SECTIONS as $section) {
            if (($before[$section] ?? []) !== ($after[$section] ?? [])) {
                return true;
            }
        }
        return false;
    }

    private function markRuntimeProjectionDirty(): void
    {
        $written = file_put_contents($this->runtimeProjectionDirtyFile, "dirty\n", LOCK_EX);
        if ($written === false) {
            throw new RuntimeException('Не удалось записать runtime projection dirty marker.');
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
