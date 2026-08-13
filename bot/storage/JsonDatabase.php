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
    private string $projectionLockFile;
    private string $writeBlockFile;
    private string $runtimeProjectionDirtyFile;
    private ?array $exclusiveSnapshot = null;
    private ?array $projectionSnapshot = null;
    private ?string $projectionDirtyToken = null;

    public function __construct(string $dataDir)
    {
        $this->dataDir = rtrim($dataDir, '/');
        if (!is_dir($this->dataDir)) {
            mkdir($this->dataDir, 0755, true);
        }
        $this->lockFile = $this->dataDir . '/app.lock';
        $this->writeBarrierFile = $this->dataDir . '/app-write-barrier.lock';
        $this->projectionLockFile = $this->dataDir . '/app-projection.lock';
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
            // Operational cutover/audit snapshots still own the exclusive side
            // of this barrier. Runtime DB projection uses its own projection lock
            // and therefore never keeps gameplay writers behind external I/O.
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

            // Each published projection-relevant mutation owns a unique marker.
            // A projector may clear only the exact generation it snapped; a
            // writer that commits during projection leaves its newer token intact.
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

    /** @param list<string> $sections */
    public function readOnlySections(array $sections, callable $callback): mixed
    {
        if ($this->exclusiveSnapshot !== null) {
            return $callback($this->snapshotSections($this->exclusiveSnapshot, $sections));
        }
        if ($this->projectionSnapshot !== null) {
            return $callback($this->snapshotSections($this->projectionSnapshot, $sections));
        }
        return $this->readSectionsWithLock($sections, LOCK_SH, $callback);
    }

    public function exclusiveReadOnly(callable $callback): mixed
    {
        return $this->exclusiveReadOnlySections(array_keys(self::FILES), $callback);
    }

    /**
     * True operational freeze. Keep this contract unchanged for cutover, audits
     * and compatibility tools that explicitly require writers not to advance.
     * Runtime DB projections must use projectionReadOnly* instead.
     *
     * @param list<string> $sections
     */
    public function exclusiveReadOnlySections(array $sections, callable $callback): mixed
    {
        if ($this->exclusiveSnapshot !== null) {
            return $callback($this->snapshotSections($this->exclusiveSnapshot, $sections));
        }
        if ($this->projectionSnapshot !== null) {
            return $callback($this->snapshotSections($this->projectionSnapshot, $sections));
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

    public function projectionReadOnly(callable $callback): mixed
    {
        return $this->projectionReadOnlySections(array_keys(self::FILES), $callback);
    }

    /**
     * Runtime projection owner. Projectors serialize with each other but only
     * hold app.lock long enough to copy a stable JSON snapshot. External DB I/O
     * then runs without holding the gameplay writer barrier.
     *
     * @param list<string> $sections
     */
    public function projectionReadOnlySections(array $sections, callable $callback): mixed
    {
        if ($this->exclusiveSnapshot !== null) {
            return $callback($this->snapshotSections($this->exclusiveSnapshot, $sections));
        }
        if ($this->projectionSnapshot !== null) {
            return $callback($this->snapshotSections($this->projectionSnapshot, $sections));
        }

        $projectionHandle = fopen($this->projectionLockFile, 'c+');
        if (!$projectionHandle) {
            throw new RuntimeException('Не удалось открыть runtime projection lock-файл.');
        }

        try {
            if (!flock($projectionHandle, LOCK_EX)) {
                throw new RuntimeException('Не удалось сериализовать runtime projection.');
            }

            $lockHandle = fopen($this->lockFile, 'c+');
            if (!$lockHandle) {
                throw new RuntimeException('Не удалось открыть lock-файл.');
            }
            try {
                if (!flock($lockHandle, LOCK_SH)) {
                    throw new RuntimeException('Не удалось прочитать runtime projection snapshot.');
                }
                $snapshot = $this->loadSections($sections);
                $dirtyToken = $this->readRuntimeProjectionDirtyToken();
            } finally {
                flock($lockHandle, LOCK_UN);
                fclose($lockHandle);
            }

            $this->projectionSnapshot = $snapshot;
            $this->projectionDirtyToken = $dirtyToken;
            try {
                return $callback($snapshot);
            } finally {
                $this->projectionSnapshot = null;
                $this->projectionDirtyToken = null;
            }
        } finally {
            flock($projectionHandle, LOCK_UN);
            fclose($projectionHandle);
        }
    }

    public function runtimeProjectionDirty(): bool
    {
        return is_file($this->runtimeProjectionDirtyFile);
    }

    public function clearRuntimeProjectionDirty(): void
    {
        if ($this->exclusiveSnapshot === null && $this->projectionSnapshot === null) {
            throw new RuntimeException('Runtime projection dirty marker may only be cleared inside a stable JSON snapshot.');
        }
        if (!is_file($this->runtimeProjectionDirtyFile)) {
            return;
        }

        // Exclusive snapshots freeze writers and may always acknowledge the
        // current marker. Projection snapshots must compare-and-clear so a newer
        // writer cannot have its pending DB work erased by an older projector.
        if ($this->exclusiveSnapshot === null) {
            $captured = $this->projectionDirtyToken;
            $current = $this->readRuntimeProjectionDirtyToken();
            if ($captured === null || $current === null || !hash_equals($captured, $current)) {
                return;
            }
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
        $token = bin2hex(random_bytes(16));
        $written = file_put_contents($this->runtimeProjectionDirtyFile, $token . "\n", LOCK_EX);
        if ($written === false) {
            throw new RuntimeException('Не удалось записать runtime projection dirty marker.');
        }
    }

    private function readRuntimeProjectionDirtyToken(): ?string
    {
        if (!is_file($this->runtimeProjectionDirtyFile)) return null;
        $raw = file_get_contents($this->runtimeProjectionDirtyFile);
        if ($raw === false) {
            throw new RuntimeException('Не удалось прочитать runtime projection dirty marker.');
        }
        $token = trim($raw);
        return $token !== '' ? $token : null;
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
