<?php
declare(strict_types=1);

trait ProductionCutoverRunTrait
{
    public function run(): array
    {
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
            return $this->blockedReport($error, null, 'state_read_failed');
        }

        $stateName = strtolower(trim((string)($state['state'] ?? '')));
        if ($stateName === 'completed') return $this->completedNoop($state);
        if ($stateName === 'rolled_back') return $this->rolledBackNoop($state);

        if ($stateName === 'rollback_failed' || in_array($stateName, self::ACTIVE_STATES, true)) {
            return $this->automaticRollbackReport(
                new RuntimeException('An interrupted or failed cutover state requires recovery.'),
                'interrupted_cutover_recovered',
                'state_recovery',
                null,
                null,
                ''
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
                    ''
                );
            }
            return $this->blockedReport(
                new RuntimeException('Unknown production cutover state requires manual review.'),
                null,
                'state_review_required'
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
            $preflight = (new ProductionPreflightRunner(
                $this->projectRoot,
                $this->config,
                $this->configFile,
                $this->timestamp()
            ))->run();
            if (($preflight['technical_ready_for_window'] ?? false) !== true
                || !empty($preflight['blockers'])) {
                throw new RuntimeException('Production preflight is not clean for the cutover window.');
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
