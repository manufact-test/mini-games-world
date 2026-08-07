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
        return $script !== '' && basename($script) === 'api.php';
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
        return $storage->exclusiveReadOnly(static function (array $snapshot) use ($repository): array {
            return $repository->synchronize($snapshot);
        });
    }
}
