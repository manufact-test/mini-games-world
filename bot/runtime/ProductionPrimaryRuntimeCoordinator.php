<?php
declare(strict_types=1);

require_once __DIR__ . '/ProductionPrimaryRuntimeActivationContract.php';

final class ProductionPrimaryRuntimeCoordinator
{
    public const CONTRACT_VERSION = 'v1-db-primary-all-modules-foundation';
    public const EXECUTION_ENABLED = false;

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
            'entrypoint_wiring_required' => true,
            'api_entrypoint_wired' => false,
            'webhook_entrypoint_wired' => false,
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
            'request_finalizer_required' => true,
            'legacy_json_bridges_must_be_suppressed' => true,
            'execution_enabled' => false,
            'entrypoint_wiring_required' => true,
            'database_contacted' => false,
            'application_entrypoints_changed' => false,
            'production_changed' => false,
            'sensitive_identifiers_exposed' => false,
        ];
    }

    public function executeApiRequest(array $payload): array
    {
        throw new RuntimeException(
            'Production API execution is intentionally disabled in the coordinator foundation.'
        );
    }

    public function executeWebhookMutation(array $update): void
    {
        throw new RuntimeException(
            'Production webhook execution is intentionally disabled in the coordinator foundation.'
        );
    }
}
