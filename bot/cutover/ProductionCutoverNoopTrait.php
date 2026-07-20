<?php
declare(strict_types=1);

trait ProductionCutoverNoopTrait
{
    private function awaitingReleaseNoop(array $state): array
    {
        try {
            $runtime = $this->readRuntime();
            $router = new RuntimeStorageRouter($this->configWithRuntime($runtime));
        } catch (Throwable $error) {
            if ($this->recoveryArtifactsPresent()) {
                return $this->automaticRollbackReport(
                    $error,
                    'awaiting_release_runtime_validation_failed',
                    'awaiting_release',
                    null,
                    null,
                    (string)($state['source_fingerprint'] ?? ''),
                    (string)($state['started_at_utc'] ?? '')
                );
            }
            return $this->recoveryBlockedReport(
                $error,
                'awaiting_release_runtime_invalid_without_recovery_artifacts',
                'awaiting_release',
                (string)($state['started_at_utc'] ?? '')
            );
        }

        $contractError = $this->statusStateContractError(
            $state,
            $router->enabled(),
            $router->enabledModules(),
            $runtime
        );
        if ($contractError !== '') {
            $error = new RuntimeException($contractError);
            if ($this->recoveryArtifactsPresent()) {
                return $this->automaticRollbackReport(
                    $error,
                    'awaiting_release_contract_recovered',
                    'awaiting_release',
                    null,
                    null,
                    (string)($state['source_fingerprint'] ?? ''),
                    (string)($state['started_at_utc'] ?? '')
                );
            }
            return $this->recoveryBlockedReport(
                $error,
                'awaiting_release_contract_failed_without_recovery_artifacts',
                'awaiting_release',
                (string)($state['started_at_utc'] ?? '')
            );
        }

        return [
            'ok' => true,
            'report_type' => 'mvp-14.9-production-cutover',
            'action' => 'awaiting_release_noop',
            'idempotent' => true,
            'state' => 'awaiting_release',
            'environment' => 'production',
            'build' => self::BUILD,
            'runtime_route' => RuntimeStorageRouter::DRIVER_DATABASE,
            'enabled_modules' => $router->enabledModules(),
            'rollback_driver' => RuntimeStorageRouter::DRIVER_JSON,
            'runtime_backup_present' => true,
            'json_write_block_active' => true,
            'maintenance_released' => false,
            'financial_read_only_released' => false,
            'manual_smoke_required' => true,
            'release_required' => true,
            'state_summary' => $this->compactState($state),
            'sensitive_identifiers_exposed' => false,
            'generated_at_utc' => $this->nowUtc(),
            'next_step' => 'Use --release only after every read-only production smoke check passes; otherwise use --rollback.',
        ];
    }

    private function rolledBackNoop(array $state): array
    {
        try {
            $runtime = $this->readRuntime();
            $router = new RuntimeStorageRouter($this->configWithRuntime($runtime));
        } catch (Throwable $error) {
            if ($this->recoveryArtifactsPresent()) {
                return $this->automaticRollbackReport(
                    $error,
                    'rolled_back_state_runtime_validation_failed',
                    'state_recovery',
                    null,
                    null,
                    (string)($state['source_fingerprint'] ?? ''),
                    (string)($state['started_at_utc'] ?? '')
                );
            }
            return $this->recoveryBlockedReport(
                $error,
                'rolled_back_state_runtime_invalid_without_recovery_artifacts',
                'rolled_back',
                (string)($state['started_at_utc'] ?? '')
            );
        }

        $contractError = $this->statusStateContractError(
            $state,
            $router->enabled(),
            $router->enabledModules(),
            $runtime
        );
        if ($contractError !== '') {
            $error = new RuntimeException($contractError);
            if ($this->recoveryArtifactsPresent()) {
                return $this->automaticRollbackReport(
                    $error,
                    'rolled_back_state_contract_recovered',
                    'state_recovery',
                    null,
                    null,
                    (string)($state['source_fingerprint'] ?? ''),
                    (string)($state['started_at_utc'] ?? '')
                );
            }
            return $this->recoveryBlockedReport(
                $error,
                'rolled_back_state_contract_failed_without_recovery_artifacts',
                'rolled_back',
                (string)($state['started_at_utc'] ?? '')
            );
        }

        return [
            'ok' => true,
            'report_type' => 'mvp-14.9-production-cutover',
            'action' => 'rollback_noop',
            'idempotent' => true,
            'state' => 'rolled_back',
            'environment' => 'production',
            'build' => self::BUILD,
            'runtime_route' => RuntimeStorageRouter::DRIVER_JSON,
            'rollback_driver' => RuntimeStorageRouter::DRIVER_JSON,
            'runtime_backup_present' => true,
            'json_write_block_active' => false,
            'router_error' => '',
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
                    (string)($state['source_fingerprint'] ?? ''),
                    (string)($state['started_at_utc'] ?? '')
                );
            }
            return $this->recoveryBlockedReport(
                $error,
                'completed_state_runtime_invalid_without_recovery_artifacts',
                'completed',
                (string)($state['started_at_utc'] ?? '')
            );
        }

        $contractError = $this->statusStateContractError(
            $state,
            $router->enabled(),
            $router->enabledModules(),
            $runtime
        );
        if ($contractError !== '') {
            $error = new RuntimeException($contractError);
            if ($this->recoveryArtifactsPresent()) {
                return $this->automaticRollbackReport(
                    $error,
                    'completed_state_contract_recovered',
                    'database_route_published',
                    null,
                    null,
                    (string)($state['source_fingerprint'] ?? ''),
                    (string)($state['started_at_utc'] ?? '')
                );
            }
            return $this->recoveryBlockedReport(
                $error,
                'completed_state_contract_failed_without_recovery_artifacts',
                'completed',
                (string)($state['started_at_utc'] ?? '')
            );
        }

        return [
            'ok' => true,
            'report_type' => 'mvp-14.9-production-cutover',
            'action' => 'cutover_noop',
            'idempotent' => true,
            'state' => 'completed',
            'environment' => 'production',
            'build' => self::BUILD,
            'runtime_route' => RuntimeStorageRouter::DRIVER_DATABASE,
            'enabled_modules' => $router->enabledModules(),
            'rollback_driver' => RuntimeStorageRouter::DRIVER_JSON,
            'runtime_backup_present' => true,
            'json_write_block_active' => false,
            'state_summary' => $this->compactState($state),
            'sensitive_identifiers_exposed' => false,
            'generated_at_utc' => $this->nowUtc(),
        ];
    }
}
