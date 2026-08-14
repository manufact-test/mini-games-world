<?php
declare(strict_types=1);

final class RealtimeRuntimeBridge
{
    private RuntimeStorageRouter $router;
    private ?StorageAdapterInterface $storage;
    private ?RuntimeRealtimeRepository $repository;

    public function __construct(
        private array $config,
        ?RuntimeStorageRouter $router = null,
        ?StorageAdapterInterface $storage = null,
        ?RuntimeRealtimeRepository $repository = null
    ) {
        $this->router = $router ?? new RuntimeStorageRouter($config);
        $this->storage = $storage;
        $this->repository = $repository;
    }

    public function enabled(): bool
    {
        return $this->router->routeFor('realtime') === RuntimeStorageRouter::DRIVER_DATABASE;
    }

    public function shouldAttachToCurrentRequest(array $server): bool
    {
        if (!$this->enabled()) return false;

        $script = trim((string)($server['SCRIPT_FILENAME'] ?? $server['PHP_SELF'] ?? ''));
        if ($script === '' || basename($script) !== 'api.php') return false;

        // The weekly API bridge owns realtime as an explicit dependency of its
        // projection cycle when weekly DB routing is enabled.
        return $this->router->routeFor('weekly_bonus') !== RuntimeStorageRouter::DRIVER_DATABASE;
    }

    public function synchronizeCurrentJson(): ?array
    {
        if (!$this->enabled()) return null;
        if ($this->currentApiActionIsLatencyCritical()) return null;

        $storage = $this->storage ??= StorageFactory::create($this->config);
        if ($storage->driver() !== RuntimeStorageRouter::DRIVER_JSON) {
            throw new RuntimeException('Realtime bridge requires JSON rollback storage.');
        }
        if (!$storage instanceof ExclusiveSnapshotStorageInterface) {
            throw new RuntimeException('Realtime bridge requires stable JSON snapshot support.');
        }

        $repository = $this->repository ??= new RuntimeRealtimeRepository($this->config, $this->router);
        $callback = static function (array $snapshot) use ($repository): array {
            return $repository->synchronize($snapshot);
        };

        // Runtime projection must serialize with other projectors, not with
        // gameplay writers. Nested calls reuse the current projection snapshot.
        if ($storage instanceof ProjectionSnapshotStorageInterface) {
            return $storage->projectionReadOnly($callback);
        }

        // Compatibility fallback for narrow adapters that predate the runtime
        // projection snapshot contract keeps the historical fail-closed freeze.
        return $storage->exclusiveReadOnly($callback);
    }

    private function currentApiActionIsLatencyCritical(): bool
    {
        $script = trim((string)($_SERVER['SCRIPT_FILENAME'] ?? $_SERVER['PHP_SELF'] ?? ''));
        if ($script === '' || basename($script) !== 'api.php') return false;

        $action = strtolower(trim((string)($GLOBALS['mgw_api_action'] ?? $GLOBALS['action'] ?? '')));
        return in_array($action, [
            'start_search',
            'leave_search',
            'game_state',
            'game_action',
            'make_move',
            'leave_game',
        ], true);
    }
}
