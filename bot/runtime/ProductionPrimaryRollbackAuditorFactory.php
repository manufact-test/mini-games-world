<?php
declare(strict_types=1);

final class ProductionPrimaryRollbackAuditorFactory
{
    private const MODULES = [
        'accounts', 'realtime', 'economy', 'notifications', 'invites',
        'history', 'shop', 'payments', 'weekly_bonus',
    ];

    public function __construct(
        private array $config,
        private DatabaseConnectionInterface $database,
        private array $gateReport
    ) {
        if (($this->config['environment'] ?? null) !== 'production') {
            throw new RuntimeException('Rollback auditor factory requires production config.');
        }
        if (($this->gateReport['ready'] ?? false) !== true
            || ($this->gateReport['contract_version'] ?? null)
                !== ProductionPrimaryRollbackExportGate::CONTRACT_VERSION
            || ($this->gateReport['activation_build'] ?? null)
                !== ProductionPrimaryRollbackExportGate::ACTIVATION_BUILD) {
            throw new RuntimeException('Rollback auditor factory requires a ready export gate.');
        }
        if ($this->database->driver() !== 'mysql') {
            throw new RuntimeException('Rollback auditor factory requires MySQL/MariaDB.');
        }
    }

    public function create(): RuntimePrimaryProjectionAuditorInterface
    {
        $projectionConfig = $this->config;
        $projectionConfig['environment'] = 'staging';
        $projectionConfig['storage_driver'] = RuntimeStorageRouter::DRIVER_JSON;
        if (!isset($projectionConfig['feature_flags'])
            || !is_array($projectionConfig['feature_flags'])) {
            $projectionConfig['feature_flags'] = [];
        }
        $projectionConfig['feature_flags']['database_runtime'] = [
            'enabled' => true,
            'modules' => array_fill_keys(self::MODULES, true),
        ];

        $projector = (new RuntimePrimaryRepositoryProjectorFactory(
            $projectionConfig,
            $this->database
        ))->create();

        return new RuntimePrimaryProjectionAuditorAdapter($projector);
    }
}
