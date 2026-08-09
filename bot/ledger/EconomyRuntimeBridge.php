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
        // frozen JSON snapshot before auditing weekly state. Keep webhook economy
        // ownership unchanged, but avoid a duplicate top-level API projection
        // when Weekly is active. Direct synchronizeCurrentJson() remains intact.
        return $this->router->routeFor('weekly_bonus') !== RuntimeStorageRouter::DRIVER_DATABASE;
    }

    public function synchronizeCurrentJson(): ?array
    {
        if (!$this->enabled()) return null;

        $storage = $this->storage ??= StorageFactory::create($this->config);
        if ($storage->driver() !== RuntimeStorageRouter::DRIVER_JSON) {
            throw new RuntimeException('Economy bridge requires JSON rollback storage.');
        }
        if (!$storage instanceof ExclusiveSnapshotStorageInterface) {
            throw new RuntimeException('Economy bridge requires exclusive JSON snapshot support.');
        }

        $repository = $this->repository ??= new RuntimeEconomyRepository($this->config, $this->router);
        return $storage->exclusiveReadOnly(static function (array $snapshot) use ($repository): array {
            return $repository->synchronize($snapshot);
        });
    }
}