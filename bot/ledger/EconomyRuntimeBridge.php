<?php
declare(strict_types=1);

require_once __DIR__ . '/../runtime/RuntimeBridgeProjectionCoordinator.php';

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
        return in_array(basename($script), ['api.php', 'webhook.php'], true);
    }

    public function synchronizeCurrentJson(): ?array
    {
        if (!$this->enabled()) return null;
        if (!$this->runtimeDomainChanged('economy')) {
            return ['ok' => true, 'action' => 'unchanged', 'skipped' => true];
        }

        $storage = $this->storage ??= StorageFactory::create($this->config);
        if ($storage->driver() !== RuntimeStorageRouter::DRIVER_JSON) {
            throw new RuntimeException('Economy bridge requires JSON rollback storage.');
        }

        $repository = $this->repository ??= new RuntimeEconomyRepository($this->config, $this->router);

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
