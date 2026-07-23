<?php
declare(strict_types=1);

final class ProductionPrimaryProjectorFactory
{
    private const MODULES = [
        'accounts', 'realtime', 'economy', 'notifications', 'invites',
        'history', 'shop', 'payments', 'weekly_bonus',
    ];

    public function __construct(
        private array $config,
        private DatabaseConnectionInterface $database,
        private array $activationReport
    ) {
        if (($this->config['environment'] ?? null) !== 'production') {
            throw new RuntimeException('Production projector factory requires production config.');
        }
        if (($this->activationReport['ready'] ?? false) !== true
            || ($this->activationReport['state'] ?? '') !== 'completed'
            || ($this->activationReport['contract_version'] ?? '')
                !== ProductionPrimaryRuntimeActivationContract::CONTRACT_VERSION
            || ($this->activationReport['activation_build'] ?? '')
                !== ProductionPrimaryRuntimeActivationContract::ACTIVATION_BUILD) {
            throw new RuntimeException(
                'Production projector factory requires a completed activation contract.'
            );
        }
        if ($this->database->driver() !== 'mysql') {
            throw new RuntimeException('Production projector factory requires MySQL/MariaDB.');
        }
        $modules = (array)($this->activationReport['enabled_modules'] ?? []);
        sort($modules, SORT_STRING);
        $expected = self::MODULES;
        sort($expected, SORT_STRING);
        if ($modules !== $expected) {
            throw new RuntimeException('Production projector factory requires all nine modules.');
        }
    }

    public function create(): RuntimePrimaryAllModuleProjector
    {
        $projectionConfig = $this->config;

        // The proven repository projectors are intentionally guarded as staging/local
        // compatibility components. The production activation contract above is the
        // authorization boundary; only their internal router receives a staging label.
        // The application config and request environment remain production.
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

        return (new RuntimePrimaryRepositoryProjectorFactory(
            $projectionConfig,
            $this->database
        ))->create();
    }
}
