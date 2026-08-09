<?php
declare(strict_types=1);

require_once __DIR__ . '/../runtime/RuntimeBridgeProjectionCoordinator.php';

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
        if (!$this->runtimeDomainChanged('realtime')) {
            return ['ok' => true, 'action' => 'unchanged', 'skipped' => true, 'parity' => true];
        }

        $storage = $this->storage ??= StorageFactory::create($this->config);
        if ($storage->driver() !== RuntimeStorageRouter::DRIVER_JSON) {
            throw new RuntimeException('Realtime bridge requires JSON rollback storage.');
        }

        $repository = $this->repository ??= new RuntimeRealtimeRepository($this->config, $this->router);

        return RuntimeBridgeProjectionCoordinator::synchronize(
            $this->config,
            $storage,
            static function (array $snapshot, RuntimeBridgeSnapshotStorage $_frozen) use ($repository): array {
                return $repository->synchronize($snapshot);
            }
        );
    }

    private function runtimeDomainChanged(string $domain): bool
    {
        $script = basename(trim((string)($_SERVER['SCRIPT_FILENAME'] ?? $_SERVER['PHP_SELF'] ?? '')));
        if ($script !== 'api.php') return true;

        $dirty = $GLOBALS['mgw_runtime_bridge_dirty'] ?? null;
        if (!is_array($dirty) || !array_key_exists($domain, $dirty)) return true;
        return $dirty[$domain] === true;
    }
}
