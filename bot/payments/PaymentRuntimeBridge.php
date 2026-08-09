<?php
declare(strict_types=1);

require_once __DIR__ . '/../runtime/RuntimeBridgeProjectionCoordinator.php';

final class PaymentRuntimeBridge
{
    private RuntimeStorageRouter $router;
    private ?RuntimePaymentRepository $repository;
    private ?StorageAdapterInterface $storage;

    public function __construct(
        private array $config,
        ?RuntimeStorageRouter $router = null,
        ?RuntimePaymentRepository $repository = null,
        ?StorageAdapterInterface $storage = null
    ) {
        $this->router = $router ?? new RuntimeStorageRouter($config);
        $this->repository = $repository;
        $this->storage = $storage;
    }

    public function enabled(): bool
    {
        return $this->router->routeFor('payments') === RuntimeStorageRouter::DRIVER_DATABASE;
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
        return $this->enabled() && $this->runtimeDomainChanged('payments');
    }

    public function synchronizeCurrentJson(): ?array
    {
        if (!$this->enabled()) return null;
        if (!$this->runtimeDomainChanged('payments')) {
            return ['ok' => true, 'action' => 'unchanged', 'skipped' => true];
        }

        $storage = $this->storage ??= StorageFactory::create($this->config);
        if ($storage->driver() !== RuntimeStorageRouter::DRIVER_JSON) {
            throw new RuntimeException('Payment bridge requires JSON rollback storage.');
        }

        return RuntimeBridgeProjectionCoordinator::synchronize(
            $this->config,
            $storage,
            function (array $_snapshot, RuntimeBridgeSnapshotStorage $frozen): array {
                $repository = $this->repository ?? new RuntimePaymentRepository(
                    $this->config,
                    $this->router,
                    $frozen
                );
                return $repository->synchronizeCurrentJson();
            }
        );
    }

    public function normalizeApiData(array $data, string $action): array
    {
        if (!$this->enabled()) return $data;

        $userId = trim((string)($data['user']['id'] ?? ''));
        if ($userId === '') return $data;

        $paymentDb = ['payments' => $this->repository()->paymentRecords()];
        $service = new PaymentService($this->config, new UserService($this->config));
        if (isset($data['payments']) && is_array($data['payments'])) {
            $status = $service->status($paymentDb, ['id' => $userId]);
            $data['payments']['recent_payments'] = $status['recent_payments'] ?? [];
        }
        if (isset($data['topups']) && is_array($data['topups'])) {
            $data['topups'] = $service->userTopupHistory($paymentDb, $userId, 20);
        }
        return $data;
    }

    private function repository(): RuntimePaymentRepository
    {
        $storage = $this->storage ??= StorageFactory::create($this->config);
        return $this->repository ??= new RuntimePaymentRepository($this->config, $this->router, $storage);
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
