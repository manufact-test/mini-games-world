<?php
declare(strict_types=1);

require_once __DIR__ . '/ProductionPrimaryRuntimeActivationContract.php';

final class ProductionPrimaryRuntimeCoordinator
{
    public const CONTRACT_VERSION = 'v2-db-primary-atomic-entrypoint-wiring';
    public const EXECUTION_ENABLED = false;
    public const ENTRYPOINT_WIRING_ENABLED = true;

    private ProductionPrimaryRuntimeActivationContract $activation;

    public function __construct(
        string $projectRoot,
        array $config,
        string $configFile
    ) {
        $this->activation = new ProductionPrimaryRuntimeActivationContract(
            $projectRoot,
            $config,
            $configFile
        );
    }

    public function inspect(): array
    {
        $activation = $this->activation->inspect();
        return $activation + [
            'coordinator_contract_version' => self::CONTRACT_VERSION,
            'execution_enabled' => self::EXECUTION_ENABLED,
            'entrypoint_wiring_enabled' => self::ENTRYPOINT_WIRING_ENABLED,
            'entrypoint_wiring_required' => true,
            'api_entrypoint_wired' => false,
            'webhook_entrypoint_wired' => false,
            'atomic_state_and_projections_required' => true,
            'rollback_requires_fresh_db_export' => true,
            'database_contacted' => false,
            'application_entrypoints_changed' => false,
            'production_changed' => false,
            'sensitive_identifiers_exposed' => false,
        ];
    }

    public function prepareEntrypointPlan(string $entrypoint): array
    {
        if (!in_array($entrypoint, ['api', 'webhook'], true)) {
            throw new InvalidArgumentException(
                'Production DB-primary coordinator supports only API and webhook entrypoints.'
            );
        }

        $activation = $this->activation->inspect();
        if (($activation['ready'] ?? false) !== true) {
            throw new RuntimeException(
                'Production DB-primary activation contract is not ready: '
                . implode('; ', array_map('strval', (array)($activation['blockers'] ?? [])))
            );
        }

        return [
            'ok' => true,
            'action' => 'production_primary_entrypoint_plan_prepared',
            'coordinator_contract_version' => self::CONTRACT_VERSION,
            'activation_contract_version' => ProductionPrimaryRuntimeActivationContract::CONTRACT_VERSION,
            'activation_contract_fingerprint' => (string)($activation['contract_fingerprint'] ?? ''),
            'activation_state' => (string)($activation['state'] ?? ''),
            'entrypoint' => $entrypoint,
            'storage_driver' => 'database',
            'rollback_driver' => 'json',
            'enabled_modules' => (array)($activation['enabled_modules'] ?? []),
            'projection_outbox_required' => true,
            'post_commit_request_finalizer_required' => false,
            'atomic_state_and_projections_required' => true,
            'legacy_json_bridges_must_be_suppressed' => true,
            'execution_enabled' => self::EXECUTION_ENABLED,
            'direct_execution_enabled' => self::EXECUTION_ENABLED,
            'entrypoint_wiring_enabled' => self::ENTRYPOINT_WIRING_ENABLED,
            'entrypoint_wiring_requires_completed_state' => true,
            'rollback_requires_fresh_db_export' => true,
            'database_contacted' => false,
            'application_entrypoints_changed' => false,
            'production_changed' => false,
            'sensitive_identifiers_exposed' => false,
        ];
    }

    public function executeApiRequest(array $payload): array
    {
        throw new RuntimeException(
            'Direct production API execution is forbidden and intentionally disabled; '
            . 'use the guarded atomic storage context.'
        );
    }

    public function executeWebhookMutation(array $update): void
    {
        throw new RuntimeException(
            'Direct production webhook execution is forbidden and intentionally disabled; '
            . 'use the guarded atomic storage context.'
        );
    }
}
