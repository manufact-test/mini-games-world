<?php
declare(strict_types=1);

trait ProductionCutoverRecoveryPolicyTrait
{
    private const PRE_ROUTE_ABORT_STAGES = [
        'runtime_backup_created',
        'maintenance_published',
        'queue_drained',
        'json_sealed',
        'database_imported',
    ];

    public function rollback(string $reason = 'manual production rollback'): array
    {
        $this->assertControlEnvironmentAndBuild();
        $boundary = $this->recoveryBoundary();
        if (($boundary['db_route_published'] ?? null) === true
            || ($boundary['db_route_enabled'] ?? null) === true) {
            return $this->verifiedLiveRollbackRequiredReport(
                'manual_rollback_after_db_route_publication',
                (string)($boundary['state'] ?? 'unknown'),
                $reason
            );
        }
        if (($boundary['safe_pre_route_abort'] ?? false) !== true) {
            return $this->verifiedLiveRollbackRequiredReport(
                'manual_rollback_boundary_uncertain',
                (string)($boundary['state'] ?? 'unknown'),
                $reason
            );
        }

        $rollback = $this->rollbackInternal($reason);
        return $rollback + [
            'report_type' => 'mvp-14.10e-production-cutover-package',
            'recovery_mode' => 'pre_route_exact_runtime_abort',
            'verified_live_rollback_required' => false,
            'rollback_export_required' => false,
            'legacy_json_restore_allowed' => true,
            'webhook_changed' => false,
            'cron_changed' => false,
            'sensitive_identifiers_exposed' => false,
        ];
    }

    private function automaticRollbackReport(
        Throwable $error,
        string $reason,
        string $mutationStage,
        ?array $preflight,
        ?array $backup,
        string $frozenSourceFingerprint,
        ?string $startedAt = null
    ): array {
        $boundary = $this->recoveryBoundary();
        $stageAllowsAbort = in_array(
            $mutationStage,
            self::PRE_ROUTE_ABORT_STAGES,
            true
        );
        $safeAbort = $stageAllowsAbort
            && ($boundary['safe_pre_route_abort'] ?? false) === true
            && ($boundary['db_route_published'] ?? null) !== true
            && ($boundary['db_route_enabled'] ?? null) !== true;

        if ($safeAbort) {
            $rollback = $this->rollbackInternal(
                'automatic pre-route abort after cutover failure: ' . $reason
            );
            $rollbackSucceeded = ($rollback['ok'] ?? false) === true
                && ($rollback['runtime_restored'] ?? false) === true
                && ($rollback['json_write_block_removed'] ?? false) === true
                && ($rollback['database_runtime_disabled'] ?? false) === true;
            return [
                'ok' => false,
                'report_type' => 'mvp-14.10e-production-cutover-package',
                'action' => 'automatic_pre_route_abort',
                'reason' => $reason,
                'environment' => 'production',
                'build' => self::BUILD,
                'error_class' => get_class($error),
                'error_message' => $this->safeMessage($error->getMessage()),
                'preflight_ready' => is_array($preflight)
                    && ($preflight['technical_ready_for_window'] ?? false) === true,
                'backup' => is_array($backup) ? $this->compactBackup($backup) : null,
                'source_fingerprint' => $frozenSourceFingerprint,
                'mutation_stage' => $mutationStage,
                'rollback' => $rollback,
                'rollback_succeeded' => $rollbackSucceeded,
                'verified_live_rollback_required' => false,
                'rollback_export_required' => false,
                'legacy_json_restore_allowed' => true,
                'production_changed' => $mutationStage !== 'runtime_backup_created',
                'manual_review_required' => true,
                'immediate_operator_action_required' => !$rollbackSucceeded,
                'webhook_changed' => false,
                'cron_changed' => false,
                'sensitive_identifiers_exposed' => false,
                'started_at_utc' => $startedAt,
                'failed_at_utc' => $this->nowUtc(),
            ];
        }

        return [
            'ok' => false,
            'report_type' => 'mvp-14.10e-production-cutover-package',
            'action' => 'verified_live_rollback_required',
            'reason' => $reason,
            'state' => (string)($boundary['state'] ?? 'unknown'),
            'environment' => 'production',
            'build' => self::BUILD,
            'error_class' => get_class($error),
            'error_message' => $this->safeMessage($error->getMessage()),
            'preflight_ready' => is_array($preflight)
                && ($preflight['technical_ready_for_window'] ?? false) === true,
            'backup' => is_array($backup) ? $this->compactBackup($backup) : null,
            'source_fingerprint' => $frozenSourceFingerprint,
            'mutation_stage' => $mutationStage,
            'rollback' => [
                'attempted' => false,
                'ok' => false,
                'reason' => 'DB-primary may have accepted writes; stale JSON rollback is forbidden.',
            ],
            'rollback_succeeded' => false,
            'verified_live_rollback_required' => true,
            'rollback_export_required' => true,
            'rollback_export_command' => 'ops/runtime/run-production-primary-rollback-export.php',
            'live_rollback_command' => 'ops/runtime/run-production-primary-live-rollback.php',
            'legacy_json_restore_allowed' => false,
            'production_changed' => true,
            'maintenance_must_remain_enabled' => true,
            'financial_read_only_must_remain_enabled' => true,
            'json_write_block_must_remain_active_until_route_disabled' => true,
            'manual_review_required' => true,
            'immediate_operator_action_required' => true,
            'webhook_changed' => false,
            'cron_changed' => false,
            'sensitive_identifiers_exposed' => false,
            'started_at_utc' => $startedAt,
            'failed_at_utc' => $this->nowUtc(),
        ];
    }

    private function verifiedLiveRollbackRequiredReport(
        string $reason,
        string $state,
        string $operatorReason
    ): array {
        return [
            'ok' => false,
            'report_type' => 'mvp-14.10e-production-cutover-package',
            'action' => 'verified_live_rollback_required',
            'reason' => $reason,
            'state' => $state,
            'operator_reason_fingerprint' => hash(
                'sha256',
                trim(preg_replace('/\s+/u', ' ', $operatorReason) ?? '')
            ),
            'environment' => 'production',
            'build' => self::BUILD,
            'rollback_attempted' => false,
            'verified_live_rollback_required' => true,
            'rollback_export_required' => true,
            'rollback_export_command' => 'ops/runtime/run-production-primary-rollback-export.php',
            'live_rollback_command' => 'ops/runtime/run-production-primary-live-rollback.php',
            'legacy_json_restore_allowed' => false,
            'maintenance_must_remain_enabled' => true,
            'financial_read_only_must_remain_enabled' => true,
            'production_changed' => false,
            'manual_review_required' => true,
            'immediate_operator_action_required' => true,
            'webhook_changed' => false,
            'cron_changed' => false,
            'sensitive_identifiers_exposed' => false,
            'generated_at_utc' => $this->nowUtc(),
        ];
    }

    private function recoveryBoundary(): array
    {
        $state = [];
        $stateError = '';
        try {
            $state = $this->readState();
        } catch (Throwable $error) {
            $stateError = $this->safeMessage($error->getMessage());
        }
        $stateName = strtolower(trim((string)($state['state'] ?? '')));
        $published = ($state['database_runtime_published'] ?? null) === true
            || in_array($stateName, ['validating', 'awaiting_release', 'completed'], true);

        $routerEnabled = null;
        $routerError = '';
        try {
            $runtime = $this->readRuntime();
            $routerEnabled = (new RuntimeStorageRouter(
                $this->configWithRuntime($runtime)
            ))->enabled();
        } catch (Throwable $error) {
            $routerError = $this->safeMessage($error->getMessage());
        }

        $safePreRouteAbort = $stateError === ''
            && $routerError === ''
            && $published === false
            && $routerEnabled === false
            && $this->recoveryArtifactsPresent();

        return [
            'state' => $stateName !== '' ? $stateName : 'not_started',
            'db_route_published' => $published,
            'db_route_enabled' => $routerEnabled,
            'safe_pre_route_abort' => $safePreRouteAbort,
            'state_error' => $stateError,
            'router_error' => $routerError,
            'runtime_backup_present' => is_file($this->runtimeBackupFile),
            'json_write_block_active' => is_file($this->writeBlockFile),
        ];
    }
}
