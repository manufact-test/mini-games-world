<?php
declare(strict_types=1);

final class StagingShopRuntimeOperation
{
    private const BUILD = 'v98-mvp14-db-shop-routing';
    private const OPERATION_ID = 'mvp-14.8.4g-shop-runtime-v1';

    public function __construct(
        private array $config,
        private StorageAdapterInterface $storage,
        private DatabaseConnectionInterface $database,
        private string $privateDir
    ) {
        $this->privateDir = rtrim(trim($this->privateDir), '/\\');
        if ($this->privateDir === '' || !is_dir($this->privateDir)) {
            throw new RuntimeException('Shop runtime operation private directory is unavailable.');
        }
    }

    public function definition(): StagingOperationDefinition
    {
        return new StagingOperationDefinition(
            self::OPERATION_ID,
            self::BUILD,
            function (): array {
                $report = $this->controller()->run();
                $report['operation_type'] = 'shop_runtime_activation';
                $report['operation_revision'] = 1;
                return $report;
            },
            function (): array {
                $report = $this->controller()->disable('automatic staging operations runner rollback');
                return [
                    'ok' => !empty($report['ok']) && empty($report['module_enabled']),
                    'module' => 'shop',
                    'module_enabled' => !empty($report['module_enabled']),
                    'controller_state' => (string)($report['state'] ?? ''),
                    'production_changed' => false,
                    'sensitive_identifiers_exposed' => false,
                ];
            }
        );
    }

    private function controller(): RuntimeModuleActivationController
    {
        $configForRuntime = function (array $runtime): array {
            $next = $this->config;
            $flags = $next['feature_flags'] ?? [];
            if (!is_array($flags)) $flags = [];
            $next['feature_flags'] = array_replace_recursive($flags, $runtime);
            return $next;
        };

        $repository = function (array $runtime) use ($configForRuntime): RuntimeShopRepository {
            $runtimeConfig = $configForRuntime($runtime);
            return new RuntimeShopRepository(
                $runtimeConfig,
                new RuntimeStorageRouter($runtimeConfig),
                $this->storage,
                $this->database
            );
        };

        $synchronize = function (array $runtime) use ($repository): array {
            return $repository($runtime)->synchronizeCurrentJson();
        };
        $audit = function (array $runtime) use ($repository): array {
            return $repository($runtime)->auditParity();
        };

        return new RuntimeModuleActivationController(
            'shop',
            ['accounts', 'economy', 'history'],
            $this->privateDir . '/runtime.php',
            $this->privateDir . '/shop-runtime-activation-v1.json',
            $this->privateDir . '/shop-runtime-activation-v1.runtime.backup',
            $synchronize,
            $audit,
            'mvp-14.8.4g-shop-runtime-activation'
        );
    }
}
