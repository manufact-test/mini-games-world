<?php
declare(strict_types=1);

require_once __DIR__ . '/../runtime/RuntimeBridgeProjectionCoordinator.php';

final class ShopRuntimeBridge
{
    private RuntimeStorageRouter $router;
    private ?RuntimeShopRepository $repository;
    private ?StorageAdapterInterface $storage;

    public function __construct(
        private array $config,
        ?RuntimeStorageRouter $router = null,
        ?RuntimeShopRepository $repository = null,
        ?StorageAdapterInterface $storage = null
    ) {
        $this->router = $router ?? new RuntimeStorageRouter($config);
        $this->repository = $repository;
        $this->storage = $storage;
    }

    public function enabled(): bool
    {
        return $this->router->routeFor('shop') === RuntimeStorageRouter::DRIVER_DATABASE;
    }

    public function shouldAttachToCurrentRequest(array $server): bool
    {
        if (!$this->enabled()) return false;
        $script = trim((string)($server['SCRIPT_FILENAME'] ?? $server['PHP_SELF'] ?? ''));
        if ($script === '') return false;
        return in_array(basename($script), ['api.php', 'webhook.php'], true);
    }

    public function shouldSynchronizeApiAction(string $action): bool
    {
        return $this->enabled() && $this->runtimeDomainChanged('shop');
    }

    public function synchronizeCurrentJson(): ?array
    {
        if (!$this->enabled()) return null;
        if (!$this->runtimeDomainChanged('shop')) {
            return ['ok' => true, 'action' => 'unchanged', 'skipped' => true];
        }

        $storage = $this->storage ??= StorageFactory::create($this->config);
        if ($storage->driver() !== RuntimeStorageRouter::DRIVER_JSON) {
            throw new RuntimeException('Shop bridge requires JSON rollback storage.');
        }

        return RuntimeBridgeProjectionCoordinator::synchronize(
            $this->config,
            $storage,
            function (array $_snapshot, RuntimeBridgeSnapshotStorage $frozen): array {
                $repository = $this->repository ?? new RuntimeShopRepository(
                    $this->config,
                    $this->router,
                    $frozen
                );
                return $repository->synchronizeCurrentJson();
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
