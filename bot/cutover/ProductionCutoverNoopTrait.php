<?php
declare(strict_types=1);

trait ProductionCutoverNoopTrait
{
    private function rolledBackNoop(array $state): array
    {
        $runtime = $this->readRuntime();
        $routerEnabled = false;
        $routerError = '';
        try {
            $routerEnabled = (new RuntimeStorageRouter($this->configWithRuntime($runtime)))->enabled();
        } catch (Throwable $error) {
            $routerError = $this->safeMessage($error->getMessage());
        }
        return [
            'ok' => !$routerEnabled && !is_file($this->writeBlockFile) && $routerError === '',
            'report_type' => 'mvp-14.9-production-cutover',
            'action' => 'rollback_noop',
            'idempotent' => true,
            'state' => 'rolled_back',
            'environment' => 'production',
            'build' => self::BUILD,
            'runtime_route' => RuntimeStorageRouter::DRIVER_JSON,
            'rollback_driver' => RuntimeStorageRouter::DRIVER_JSON,
            'runtime_backup_present' => is_file($this->runtimeBackupFile),
            'json_write_block_active' => is_file($this->writeBlockFile),
            'router_error' => $routerError,
            'state_summary' => $this->compactState($state),
            'manual_review_required' => true,
            'rearm_available_after_approval_is_disabled' => !$this->policy->enabled(),
            'sensitive_identifiers_exposed' => false,
            'generated_at_utc' => $this->nowUtc(),
        ];
    }

    private function completedNoop(array $state): array
    {
        try {
            $runtime = $this->readRuntime();
            $router = new RuntimeStorageRouter($this->configWithRuntime($runtime));
        } catch (Throwable $error) {
            if ($this->recoveryArtifactsPresent()) {
                return $this->automaticRollbackReport(
                    $error,
                    'completed_state_runtime_validation_failed',
                    'database_route_published',
                    null,
                    null,
                    '',
                    (string)($state['started_at_utc'] ?? '')
                );
            }
            return $this->blockedReport($error, null, 'completed_state_runtime_validation_failed');
        }
        return [
            'ok' => $router->enabled() && !is_file($this->writeBlockFile),
            'report_type' => 'mvp-14.9-production-cutover',
            'action' => 'cutover_noop',
            'idempotent' => true,
            'state' => 'completed',
            'environment' => 'production',
            'build' => self::BUILD,
            'runtime_route' => $router->enabled() ? RuntimeStorageRouter::DRIVER_DATABASE : RuntimeStorageRouter::DRIVER_JSON,
            'enabled_modules' => $router->enabledModules(),
            'rollback_driver' => RuntimeStorageRouter::DRIVER_JSON,
            'runtime_backup_present' => is_file($this->runtimeBackupFile),
            'json_write_block_active' => is_file($this->writeBlockFile),
            'state_summary' => $this->compactState($state),
            'sensitive_identifiers_exposed' => false,
            'generated_at_utc' => $this->nowUtc(),
        ];
    }
}
