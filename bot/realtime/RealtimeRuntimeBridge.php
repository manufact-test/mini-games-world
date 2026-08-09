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
        // frozen-snapshot synchronization cycle. Registering another top-level
        // realtime hook before it repeats the same DB projection and holds the
        // API response unnecessarily. Direct synchronizeCurrentJson() calls stay
        // unchanged, so Weekly can still fail closed on realtime parity.
        return $this->router->routeFor('weekly_bonus') !== RuntimeStorageRouter::DRIVER_DATABASE;
    }

    public function synchronizeCurrentJson(): ?array
    {
        if (!$this->enabled()) return null;

        $storage = $this->storage ??= StorageFactory::create($this->config);
        if ($storage->driver() !== RuntimeStorageRouter::DRIVER_JSON) {
            throw new RuntimeException('Realtime bridge requires JSON rollback storage.');
        }
        if (!$storage instanceof ExclusiveSnapshotStorageInterface) {
            throw new RuntimeException('Realtime bridge requires exclusive JSON snapshot support.');
        }

        $repository = $this->repository ??= new RuntimeRealtimeRepository($this->config, $this->router);

        // Realtime projection is part of the JSON write publication boundary.
        // Keep the same exclusive JSON lock from snapshot read until DB projection
        // and its parity comparison finish. Otherwise a newer api.php transaction
        // can mutate JSON while this bridge is still projecting the older snapshot,
        // allowing concurrent success hooks to observe transient JSON↔DB mismatch.
        return $storage->exclusiveReadOnly(static function (array $snapshot) use ($repository): array {
            return $repository->synchronize($snapshot);
        });
    }
}