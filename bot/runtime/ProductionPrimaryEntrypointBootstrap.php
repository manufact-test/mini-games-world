<?php
declare(strict_types=1);

require_once __DIR__ . '/../storage/contracts/StorageTransactionInterface.php';
require_once __DIR__ . '/../storage/contracts/StorageAdapterInterface.php';
require_once __DIR__ . '/../database/DatabaseConnectionInterface.php';
require_once __DIR__ . '/../database/DatabaseConfig.php';
require_once __DIR__ . '/../database/PdoDatabaseConnection.php';
require_once __DIR__ . '/../database/PdoConnectionFactory.php';
require_once __DIR__ . '/RuntimePrimaryStateSchemaInstaller.php';
require_once __DIR__ . '/RuntimePrimaryProjectionOutboxSchemaInstaller.php';
require_once __DIR__ . '/RuntimePrimaryProjectionOutboxWriter.php';
require_once __DIR__ . '/DatabasePrimaryStateStorageAdapter.php';
require_once __DIR__ . '/RuntimePrimaryProjectionWorkerInterface.php';
require_once __DIR__ . '/RuntimePrimaryProjectionAuditorInterface.php';
require_once __DIR__ . '/RuntimePrimaryProjectionProjectorInterface.php';
require_once __DIR__ . '/RuntimePrimaryModuleProjectorInterface.php';
require_once __DIR__ . '/RuntimePrimaryCallbackModuleProjector.php';
require_once __DIR__ . '/RuntimePrimaryAccountsModuleProjector.php';
require_once __DIR__ . '/RuntimePrimaryAllModuleProjector.php';
require_once __DIR__ . '/RuntimePrimaryRepositoryProjectorFactory.php';
require_once __DIR__ . '/RuntimePrimaryProjectionWorker.php';
require_once __DIR__ . '/RuntimePrimaryProjectionWorkerAdapter.php';
require_once __DIR__ . '/RuntimePrimaryProjectionAuditorAdapter.php';
require_once __DIR__ . '/ProductionPrimaryRuntimeActivationContract.php';
require_once __DIR__ . '/ProductionPrimaryRuntimeCoordinator.php';
require_once __DIR__ . '/ProductionPrimaryAtomicStorageAdapter.php';
require_once __DIR__ . '/ProductionPrimaryEntrypointStorageContext.php';
require_once __DIR__ . '/ProductionPrimaryProjectorFactory.php';

final class ProductionPrimaryEntrypointBootstrap
{
    private static array $failures = [];

    public static function installIfEnabled(
        string $projectRoot,
        array $config,
        string $configFile,
        string $entrypoint
    ): array {
        if (!in_array($entrypoint, ['api', 'webhook'], true)) {
            throw new InvalidArgumentException(
                'Production DB-primary bootstrap supports only API and webhook.'
            );
        }
        if (($config['environment'] ?? null) !== 'production') {
            return self::disabledReport($entrypoint, 'environment_not_production');
        }

        $flags = $config['feature_flags'] ?? [];
        if (!is_array($flags)) {
            throw new RuntimeException('Production feature flags must be an array.');
        }
        $settings = $flags['database_runtime'] ?? [];
        if (!is_array($settings)) {
            throw new RuntimeException('Production database runtime settings must be an array.');
        }

        $enabled = $settings['enabled'] ?? null;
        $activated = $settings['production_activated'] ?? null;
        if ($enabled !== true && $activated !== true) {
            return self::disabledReport($entrypoint, 'production_activation_not_requested');
        }
        if ($enabled !== true || $activated !== true) {
            throw new RuntimeException(
                'Production DB-primary enablement and activation markers are inconsistent.'
            );
        }

        if (isset(self::$failures[$entrypoint])) {
            throw new RuntimeException(
                'Production DB-primary bootstrap previously failed in this request.',
                0,
                self::$failures[$entrypoint]
            );
        }

        if (ProductionPrimaryEntrypointStorageContext::installed()) {
            $report = ProductionPrimaryEntrypointStorageContext::safeReport();
            if (($report['entrypoint'] ?? '') !== $entrypoint) {
                throw new RuntimeException(
                    'Production DB-primary context is installed for another entrypoint.'
                );
            }
            return $report + ['idempotent' => true];
        }

        try {
            $activation = (new ProductionPrimaryRuntimeActivationContract(
                $projectRoot,
                $config,
                $configFile
            ))->inspect();
            if (($activation['ready'] ?? false) !== true) {
                throw new RuntimeException(
                    'Production DB-primary activation contract is blocked: '
                    . implode('; ', array_map('strval', (array)($activation['blockers'] ?? [])))
                );
            }
            if (($activation['state'] ?? '') !== 'completed'
                || ($activation['json_write_block_active'] ?? true) !== false) {
                throw new RuntimeException(
                    'Production application entrypoints require a completed released cutover state.'
                );
            }

            $coordinator = new ProductionPrimaryRuntimeCoordinator(
                $projectRoot,
                $config,
                $configFile
            );
            $plan = $coordinator->prepareEntrypointPlan($entrypoint);
            if (($plan['ok'] ?? false) !== true
                || ($plan['entrypoint'] ?? '') !== $entrypoint
                || ($plan['storage_driver'] ?? '') !== 'database') {
                throw new RuntimeException(
                    'Production DB-primary coordinator returned an invalid entrypoint plan.'
                );
            }

            $databaseConfig = DatabaseConfig::fromApplicationConfig($config);
            if (!$databaseConfig->enabled()) {
                throw new RuntimeException(
                    'Production DB-primary entrypoint requires an enabled database.'
                );
            }
            $databaseIdentity = $databaseConfig->identityFingerprint();
            if (!hash_equals(
                (string)($activation['database_identity_fingerprint'] ?? ''),
                $databaseIdentity
            )) {
                throw new RuntimeException(
                    'Production DB-primary database identity does not match activation evidence.'
                );
            }

            $database = PdoConnectionFactory::create($databaseConfig);
            if ((int)$database->fetchValue('SELECT 1') !== 1) {
                throw new RuntimeException('Production DB-primary database readiness probe failed.');
            }

            $projector = (new ProductionPrimaryProjectorFactory(
                $config,
                $database,
                $activation
            ))->create();
            $outbox = new RuntimePrimaryProjectionOutboxWriter();
            $stateStorage = new DatabasePrimaryStateStorageAdapter($database, $outbox);
            $worker = new RuntimePrimaryProjectionWorkerAdapter(
                new RuntimePrimaryProjectionWorker($database, $projector, 120)
            );
            $auditor = new RuntimePrimaryProjectionAuditorAdapter($projector);
            $storage = new ProductionPrimaryAtomicStorageAdapter(
                $database,
                $stateStorage,
                $worker,
                $auditor
            );

            ProductionPrimaryEntrypointStorageContext::install(
                $storage,
                $entrypoint,
                $activation
            );

            return ProductionPrimaryEntrypointStorageContext::safeReport() + [
                'ok' => true,
                'action' => 'production_primary_entrypoint_installed',
                'coordinator_plan_fingerprint' => (string)(
                    $plan['activation_contract_fingerprint'] ?? ''
                ),
                'database_contacted' => true,
                'database_write_executed' => false,
                'persistent_config_changed' => false,
                'webhook_changed' => false,
                'cron_changed' => false,
                'production_changed' => false,
            ];
        } catch (Throwable $error) {
            self::$failures[$entrypoint] = $error;
            throw $error;
        }
    }

    private static function disabledReport(string $entrypoint, string $reason): array
    {
        return [
            'ok' => true,
            'action' => 'production_primary_entrypoint_disabled',
            'installed' => false,
            'entrypoint' => $entrypoint,
            'storage_driver' => 'json',
            'reason' => $reason,
            'database_contacted' => false,
            'database_write_executed' => false,
            'persistent_config_changed' => false,
            'webhook_changed' => false,
            'cron_changed' => false,
            'production_changed' => false,
            'sensitive_identifiers_exposed' => false,
        ];
    }
}
