<?php
declare(strict_types=1);

trait ProductionCutoverRunTrait
{
    public function run(): array
    {
        if (!$this->storage instanceof StorageAdapterInterface
            || !$this->database instanceof DatabaseConnectionInterface
            || !$this->backupManager instanceof BackupManager) {
            throw new RuntimeException('Production cutover execution dependencies are unavailable.');
        }
        $this->assertEnvironmentAndBuild();

        try {
            $state = $this->readState();
        } catch (Throwable $error) {
            if ($this->recoveryArtifactsPresent()) {
                return $this->automaticRollbackReport(
                    $error,
                    'invalid_state_recovered',
                    'state_read_failed',
                    null,
                    null,
                    ''
                );
            }
            return $this->recoveryBlockedReport(
                $error,
                'state_read_failed_without_recovery_artifacts',
                'invalid'
            );
        }

        $stateName = strtolower(trim((string)($state['state'] ?? '')));
        if ($stateName === 'completed') return $this->completedNoop($state);
        if ($stateName === 'awaiting_release') return $this->awaitingReleaseNoop($state);
        if ($stateName === 'rolled_back') return $this->rolledBackNoop($state);

        if ($stateName === 'rollback_failed' || in_array($stateName, self::ACTIVE_STATES, true)) {
            $error = new RuntimeException('An interrupted or failed cutover state requires recovery.');
            if (!$this->recoveryArtifactsPresent()) {
                return $this->recoveryBlockedReport(
                    $error,
                    'active_state_without_recovery_artifacts',
                    $stateName,
                    (string)($state['started_at_utc'] ?? '')
                );
            }
            return $this->automaticRollbackReport(
                $error,
                'interrupted_cutover_recovered',
                'state_recovery',
                null,
                null,
                '',
                (string)($state['started_at_utc'] ?? '')
            );
        }
        if ($stateName !== '') {
            if ($this->recoveryArtifactsPresent()) {
                return $this->automaticRollbackReport(
                    new RuntimeException('Unknown cutover state recovered using the rollback artifacts.'),
                    'unknown_state_recovered',
                    'state_recovery',
                    null,
                    null,
                    '',
                    (string)($state['started_at_utc'] ?? '')
                );
            }
            return $this->recoveryBlockedReport(
                new RuntimeException('Unknown production cutover state requires manual review.'),
                'state_review_required_without_recovery_artifacts',
                $stateName,
                (string)($state['started_at_utc'] ?? '')
            );
        }
        if ($this->recoveryArtifactsPresent()) {
            return $this->automaticRollbackReport(
                new RuntimeException('Cutover recovery artifacts exist without a state record.'),
                'orphaned_artifacts_recovered',
                'state_recovery',
                null,
                null,
                ''
            );
        }

        $startedAt = $this->nowUtc();
        $preflight = null;
        $context = [
            'backup' => null,
            'frozen_source_fingerprint' => '',
            'mutation_stage' => 'none',
        ];

        try {
            $runtime = $this->readRuntime();
            $preflightConfig = $this->configWithRuntime($runtime);
            $preflight = (new ProductionPreflightRunner(
                $this->projectRoot,
                $preflightConfig,
                $this->configFile,
                $this->timestamp()
            ))->run();
            if (($preflight['technical_ready_for_window'] ?? false) !== true
                || !empty($preflight['blockers'])) {
                throw new RuntimeException('Production preflight is not clean for the cutover window.');
            }

            $preflightRuntime = is_array($preflight['runtime'] ?? null) ? $preflight['runtime'] : [];
            if (($preflightRuntime['maintenance_enabled'] ?? false) === true
                || ($preflightRuntime['financial_read_only'] ?? false) === true) {
                throw new RuntimeException(
                    'Production cutover requires maintenance and financial read-only modes to be disabled before the operation.'
                );
            }

            $planFingerprint = strtolower(trim((string)($preflight['cutover_plan_fingerprint'] ?? '')));
            $this->policy->assertApproved(self::BUILD, $planFingerprint, $this->timestamp());

            return $this->performApprovedCutover(
                $preflight,
                $planFingerprint,
                $startedAt,
                $context
            );
        } catch (Throwable $error) {
            $mutationStage = (string)($context['mutation_stage'] ?? 'none');
            if (!$this->recoveryArtifactsPresent() && $mutationStage === 'none') {
                return $this->blockedReport($error, $preflight, 'pre_mutation_gate', $startedAt);
            }
            return $this->automaticRollbackReport(
                $error,
                'automatic_rollback_after_failure',
                $mutationStage,
                $preflight,
                is_array($context['backup'] ?? null) ? $context['backup'] : null,
                (string)($context['frozen_source_fingerprint'] ?? ''),
                $startedAt
            );
        }
    }
}
