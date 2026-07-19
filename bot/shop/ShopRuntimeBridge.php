<?php
declare(strict_types=1);

final class ShopRuntimeBridge
{
    private RuntimeStorageRouter $router;
    private ?RuntimeShopRepository $repository;

    public function __construct(
        private array $config,
        ?RuntimeStorageRouter $router = null,
        ?RuntimeShopRepository $repository = null
    ) {
        $this->router = $router ?? new RuntimeStorageRouter($config);
        $this->repository = $repository;
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
        return $this->enabled();
    }

    public function synchronizeCurrentJson(): ?array
    {
        if (!$this->enabled()) return null;
        $repository = $this->repository ??= new RuntimeShopRepository($this->config, $this->router);
        return $repository->synchronizeCurrentJson();
    }
}
