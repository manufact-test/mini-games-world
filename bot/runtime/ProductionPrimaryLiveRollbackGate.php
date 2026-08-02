<?php
declare(strict_types=1);

final class ProductionPrimaryLiveRollbackGate
{
    public const CONTRACT_VERSION = 'v1-production-live-json-rollback';
    public const CONFIRMATION = 'ROLL BACK PRODUCTION TO VERIFIED JSON';

    private const MODULES = [
        'accounts', 'realtime', 'invites', 'notifications', 'economy',
        'history', 'shop', 'payments', 'weekly_bonus',
    ];

    public function inspect(
        array $config,
        array $cutoverState,
        array $authorization,
        array $artifact,
        string $liveDataDirectoryFingerprint,
        string $runtimeConfigFingerprint,
        int $now
    ): array {
        $checks = [];
        $blockers = [];
        $flags = is_array($config['feature_flags'] ?? null)
            ? $config['feature_flags']
            : [];
        $runtime = is_array($flags['database_runtime'] ?? null)
            ? $flags['database_runtime']
            : [];

        $plan = $this->exactSha($runtime['activation_plan_fingerprint'] ?? null);
        $source = $this->exactSha($runtime['activation_source_fingerprint'] ?? null);
        $liveDataDirectoryFingerprint = $this->exactSha($liveDataDirectoryFingerprint);
        $runtimeConfigFingerprint = $this->exactSha($runtimeConfigFingerprint);

        $checks['environment_exact'] = ($config['environment'] ?? null) === 'production';
        $checks['global_json_driver_exact'] = ($config['storage_driver'] ?? null) === 'json';
        $checks['maintenance_enabled_exact'] = ($flags['maintenance_mode'] ?? null) === true;
        $checks['financial_read_only_exact'] = ($flags['financial_read_only'] ?? null) === true;
        $checks['runtime_enabled_exact'] = ($runtime['enabled'] ?? null) === true;
        $checks['production_activated_exact'] = ($runtime['production_activated'] ?? null) === true;
        $checks['activation_build_exact'] = ($runtime['activation_build'] ?? null)
            === ProductionPrimaryRollbackExportGate::ACTIVATION_BUILD;
        $checks['activation_plan_valid'] = $plan !== '';
        $checks['activation_source_valid'] = $source !== '';
        $checks['rollback_driver_json'] = ($runtime['rollback_driver'] ?? null) === 'json';
        $checks['all_modules_enabled_exact'] = $this->exactModules($runtime['modules'] ?? null);

        $checks['cutover_completed_exact'] = ($cutoverState['state'] ?? null) === 'completed';
        $checks['cutover_build_exact'] = ($cutoverState['build'] ?? null)
            === ProductionPrimaryRollbackExportGate::ACTIVATION_BUILD;
        $checks['cutover_route_published'] = ($cutoverState['database_runtime_published'] ?? null) === true;
        $checks['cutover_write_block_released'] = ($cutoverState['json_write_block_active'] ?? null) === false;
        $checks['cutover_plan_matches'] = $plan !== ''
            && $this->exactSha($cutoverState['plan_fingerprint'] ?? null) !== ''
            && hash_equals($plan, (string)$cutoverState['plan_fingerprint']);
        $checks['cutover_source_matches'] = $source !== ''
            && $this->exactSha($cutoverState['source_fingerprint'] ?? null) !== ''
            && hash_equals($source, (string)$cutoverState['source_fingerprint']);

        $checks['artifact_verified'] = ($artifact['ok'] ?? null) === true;
        $checks['artifact_backup_compatible'] = ($artifact['backup_manager_compatible'] ?? null) === true;
        $checks['artifact_isolated_restore_required'] = ($artifact['isolated_restore_required'] ?? null) === true;
        $checks['artifact_revision_valid'] = is_int($artifact['state_revision'] ?? null)
            && $artifact['state_revision'] >= 1;
        $checks['artifact_state_sha_valid'] = $this->exactSha($artifact['state_sha256'] ?? null) !== '';
        $checks['artifact_snapshot_sha_valid'] = $this->exactSha($artifact['snapshot_sha256'] ?? null) !== '';
        $checks['artifact_request_id_valid'] = $this->exactRequestId($artifact['request_id'] ?? null) !== '';
        $checks['artifact_database_matches'] = $this->exactSha($artifact['database_identity_fingerprint'] ?? null) !== '';
        $checks['artifact_plan_matches'] = $plan !== ''
            && $this->exactSha($artifact['activation_plan_fingerprint'] ?? null) !== ''
            && hash_equals($plan, (string)$artifact['activation_plan_fingerprint']);
        $checks['artifact_source_matches'] = $source !== ''
            && $this->exactSha($artifact['activation_source_fingerprint'] ?? null) !== ''
            && hash_equals($source, (string)$artifact['activation_source_fingerprint']);

        $requestId = $this->exactRequestId($authorization['request_id'] ?? null);
        $requestedAt = $this->utcTimestamp($authorization['requested_at_utc'] ?? null);
        $expiresAt = $this->utcTimestamp($authorization['expires_at_utc'] ?? null);
        $reason = is_string($authorization['reason'] ?? null)
            ? trim((string)$authorization['reason'])
            : '';

        $checks['authorization_contract_exact'] = ($authorization['contract_version'] ?? null)
            === self::CONTRACT_VERSION;
        $checks['authorization_boolean_exact'] = ($authorization['authorized'] ?? null) === true;
        $checks['authorization_confirmation_exact'] = ($authorization['confirmation'] ?? null)
            === self::CONFIRMATION;
        $checks['authorization_environment_exact'] = ($authorization['environment'] ?? null) === 'production';
        $checks['authorization_build_exact'] = ($authorization['build'] ?? null)
            === ProductionPrimaryRollbackExportGate::ACTIVATION_BUILD;
        $checks['authorization_request_id_valid'] = $requestId !== '';
        $checks['authorization_requested_at_valid'] = $requestedAt !== null && $requestedAt <= $now;
        $checks['authorization_expiry_valid'] = $requestedAt !== null
            && $expiresAt !== null
            && $expiresAt > $now
            && ($expiresAt - $requestedAt) >= 60
            && ($expiresAt - $requestedAt) <= 900;
        $checks['authorization_reason_valid'] = $reason !== ''
            && strlen($reason) <= 200
            && preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $reason) !== 1;

        $checks['authorization_artifact_request_matches'] = $requestId !== ''
            && ($artifact['request_id'] ?? null) === $requestId;
        foreach ([
            'state_revision' => 'expected_state_revision',
            'state_sha256' => 'expected_state_sha256',
            'snapshot_sha256' => 'expected_snapshot_sha256',
            'backup_id' => 'expected_backup_id',
            'database_identity_fingerprint' => 'database_identity_fingerprint',
            'activation_plan_fingerprint' => 'activation_plan_fingerprint',
            'activation_source_fingerprint' => 'activation_source_fingerprint',
            'authorization_fingerprint' => 'export_authorization_fingerprint',
            'export_directory_fingerprint' => 'export_directory_fingerprint',
        ] as $artifactField => $authorizationField) {
            $artifactValue = $artifact[$artifactField] ?? null;
            $authorizationValue = $authorization[$authorizationField] ?? null;
            if (str_ends_with($artifactField, '_sha256')
                || str_ends_with($artifactField, '_fingerprint')) {
                $checks['authorization_' . $artifactField . '_matches'] = $this->exactSha($artifactValue) !== ''
                    && $this->exactSha($authorizationValue) !== ''
                    && hash_equals((string)$artifactValue, (string)$authorizationValue);
            } else {
                $checks['authorization_' . $artifactField . '_matches'] = $artifactValue === $authorizationValue;
            }
        }

        $checks['live_data_directory_fingerprint_valid'] = $liveDataDirectoryFingerprint !== '';
        $checks['runtime_config_fingerprint_valid'] = $runtimeConfigFingerprint !== '';
        $checks['authorization_live_data_directory_matches'] = $liveDataDirectoryFingerprint !== ''
            && $this->exactSha($authorization['live_data_directory_fingerprint'] ?? null) !== ''
            && hash_equals(
                $liveDataDirectoryFingerprint,
                (string)$authorization['live_data_directory_fingerprint']
            );
        $checks['authorization_runtime_config_matches'] = $runtimeConfigFingerprint !== ''
            && $this->exactSha($authorization['runtime_config_fingerprint'] ?? null) !== ''
            && hash_equals(
                $runtimeConfigFingerprint,
                (string)$authorization['runtime_config_fingerprint']
            );

        $messages = [
            'environment_exact' => 'Live rollback requires the exact production environment.',
            'global_json_driver_exact' => 'Live rollback requires JSON as the global rollback driver.',
            'maintenance_enabled_exact' => 'Live rollback requires maintenance mode.',
            'financial_read_only_exact' => 'Live rollback requires financial read-only mode.',
            'runtime_enabled_exact' => 'Live rollback requires the active DB-primary runtime.',
            'production_activated_exact' => 'Live rollback requires the exact production activation marker.',
            'activation_build_exact' => 'Live rollback activation build is invalid.',
            'activation_plan_valid' => 'Live rollback activation plan is invalid.',
            'activation_source_valid' => 'Live rollback activation source is invalid.',
            'rollback_driver_json' => 'Live rollback driver must remain JSON.',
            'all_modules_enabled_exact' => 'Live rollback requires all nine DB-primary modules.',
            'cutover_completed_exact' => 'Live rollback requires a completed cutover state.',
            'cutover_build_exact' => 'Live rollback cutover build is invalid.',
            'cutover_route_published' => 'Live rollback requires the published DB route.',
            'cutover_write_block_released' => 'Live rollback requires the prior cutover JSON seal to be released.',
            'cutover_plan_matches' => 'Live rollback cutover plan does not match runtime.',
            'cutover_source_matches' => 'Live rollback cutover source does not match runtime.',
            'artifact_verified' => 'Live rollback artifact is not verified.',
            'artifact_backup_compatible' => 'Live rollback artifact is not BackupManager-compatible.',
            'artifact_isolated_restore_required' => 'Live rollback artifact lacks the isolated-restore contract.',
            'artifact_revision_valid' => 'Live rollback artifact revision is invalid.',
            'artifact_state_sha_valid' => 'Live rollback artifact state fingerprint is invalid.',
            'artifact_snapshot_sha_valid' => 'Live rollback artifact snapshot fingerprint is invalid.',
            'artifact_request_id_valid' => 'Live rollback artifact request ID is invalid.',
            'artifact_database_matches' => 'Live rollback artifact database identity is invalid.',
            'artifact_plan_matches' => 'Live rollback artifact plan does not match runtime.',
            'artifact_source_matches' => 'Live rollback artifact source does not match runtime.',
            'authorization_contract_exact' => 'Live rollback authorization contract is invalid.',
            'authorization_boolean_exact' => 'Live rollback is not explicitly authorized.',
            'authorization_confirmation_exact' => 'Live rollback confirmation phrase is invalid.',
            'authorization_environment_exact' => 'Live rollback authorization environment is invalid.',
            'authorization_build_exact' => 'Live rollback authorization build is invalid.',
            'authorization_request_id_valid' => 'Live rollback authorization request ID is invalid.',
            'authorization_requested_at_valid' => 'Live rollback authorization time is invalid.',
            'authorization_expiry_valid' => 'Live rollback authorization is expired or has an invalid TTL.',
            'authorization_reason_valid' => 'Live rollback authorization reason is invalid.',
            'authorization_artifact_request_matches' => 'Live rollback authorization request does not match the export.',
            'live_data_directory_fingerprint_valid' => 'Live JSON directory fingerprint is invalid.',
            'runtime_config_fingerprint_valid' => 'Runtime config fingerprint is invalid.',
            'authorization_live_data_directory_matches' => 'Live JSON directory does not match authorization.',
            'authorization_runtime_config_matches' => 'Runtime config does not match authorization.',
        ];
        foreach ($checks as $name => $passed) {
            if ($passed === true) continue;
            $blockers[] = $messages[$name] ?? ('Live rollback check failed: ' . $name . '.');
        }

        ksort($checks, SORT_STRING);
        $blockers = array_values(array_unique($blockers));
        sort($blockers, SORT_STRING);

        return [
            'ready' => $blockers === [],
            'contract_version' => self::CONTRACT_VERSION,
            'activation_build' => ProductionPrimaryRollbackExportGate::ACTIVATION_BUILD,
            'request_id' => $requestId,
            'expected_state_revision' => (int)($artifact['state_revision'] ?? 0),
            'expected_state_sha256' => (string)($artifact['state_sha256'] ?? ''),
            'expected_snapshot_sha256' => (string)($artifact['snapshot_sha256'] ?? ''),
            'expected_backup_id' => (string)($artifact['backup_id'] ?? ''),
            'database_identity_fingerprint' => (string)(
                $artifact['database_identity_fingerprint'] ?? ''
            ),
            'activation_plan_fingerprint' => $plan,
            'activation_source_fingerprint' => $source,
            'export_directory_fingerprint' => (string)(
                $artifact['export_directory_fingerprint'] ?? ''
            ),
            'live_data_directory_fingerprint' => $liveDataDirectoryFingerprint,
            'runtime_config_fingerprint' => $runtimeConfigFingerprint,
            'authorization_expires_at_utc' => is_string($authorization['expires_at_utc'] ?? null)
                ? (string)$authorization['expires_at_utc']
                : '',
            'reason_fingerprint' => $reason !== '' ? hash('sha256', $reason) : '',
            'checks' => $checks,
            'blockers' => $blockers,
            'database_contacted' => false,
            'database_write_executed' => false,
            'live_json_changed' => false,
            'persistent_config_changed' => false,
            'production_changed' => false,
            'sensitive_identifiers_exposed' => false,
        ];
    }

    private function exactModules(mixed $modules): bool
    {
        if (!is_array($modules) || array_is_list($modules)) return false;
        $keys = array_keys($modules);
        sort($keys, SORT_STRING);
        $expected = self::MODULES;
        sort($expected, SORT_STRING);
        if ($keys !== $expected) return false;
        foreach (self::MODULES as $module) {
            if (($modules[$module] ?? null) !== true) return false;
        }
        return true;
    }

    private function exactSha(mixed $value): string
    {
        return is_string($value) && preg_match('/\A[a-f0-9]{64}\z/', $value) === 1
            ? $value
            : '';
    }

    private function exactRequestId(mixed $value): string
    {
        return is_string($value) && preg_match('/\A[a-f0-9]{32}\z/', $value) === 1
            ? $value
            : '';
    }

    private function utcTimestamp(mixed $value): ?int
    {
        if (!is_string($value)
            || preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:Z|\+00:00)\z/', $value) !== 1) {
            return null;
        }
        try {
            $date = new DateTimeImmutable($value);
        } catch (Throwable) {
            return null;
        }
        return $date->getOffset() === 0 ? $date->getTimestamp() : null;
    }
}
