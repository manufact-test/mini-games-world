<?php
declare(strict_types=1);

final class EconomyRuntimeBridge
{
    private RuntimeStorageRouter $router;
    private ?StorageAdapterInterface $storage;
    private ?RuntimeEconomyRepository $repository;

    public function __construct(
        private array $config,
        ?RuntimeStorageRouter $router = null,
        ?StorageAdapterInterface $storage = null,
        ?RuntimeEconomyRepository $repository = null
    ) {
        $this->router = $router ?? new RuntimeStorageRouter($config);
        $this->storage = $storage;
        $this->repository = $repository;
    }

    public function enabled(): bool
    {
        return $this->router->routeFor('economy') === RuntimeStorageRouter::DRIVER_DATABASE;
    }

    public function shouldAttachToCurrentRequest(array $server): bool
    {
        if (!$this->enabled()) return false;
        $script = trim((string)($server['SCRIPT_FILENAME'] ?? $server['PHP_SELF'] ?? ''));
        if ($script === '') return false;

        $basename = basename($script);
        if ($basename === 'webhook.php') return true;
        if ($basename !== 'api.php') return false;

        // Weekly API synchronization already reconciles economy from the same
        // projection snapshot before auditing weekly state. Keep webhook economy
        // ownership unchanged, but avoid a duplicate top-level API projection
        // when Weekly is active. Direct synchronizeCurrentJson() remains intact.
        return $this->router->routeFor('weekly_bonus') !== RuntimeStorageRouter::DRIVER_DATABASE;
    }

    public function synchronizeCurrentJson(): ?array
    {
        if (!$this->enabled()) return null;
        if ($this->currentApiActionIsLatencyCritical()) return null;

        $storage = $this->storage ??= StorageFactory::create($this->config);
        if ($storage->driver() !== RuntimeStorageRouter::DRIVER_JSON) {
            throw new RuntimeException('Economy bridge requires JSON rollback storage.');
        }
        if (!$storage instanceof ExclusiveSnapshotStorageInterface) {
            throw new RuntimeException('Economy bridge requires stable JSON snapshot support.');
        }

        $repository = $this->repository ??= new RuntimeEconomyRepository($this->config, $this->router);
        $callback = static function (array $snapshot) use ($repository): array {
            return $repository->synchronize($snapshot);
        };

        if ($storage instanceof ProjectionSnapshotStorageInterface) {
            return $storage->projectionReadOnly($callback);
        }
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
