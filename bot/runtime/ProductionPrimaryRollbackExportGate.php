<?php
declare(strict_types=1);

final class ProductionPrimaryRollbackExportGate
{
    public const CONTRACT_VERSION = 'v1-production-db-to-json-rollback-export';
    public const ACTIVATION_BUILD = 'v103-mvp14-production-cutover';

    private const MODULES = [
        'accounts', 'realtime', 'invites', 'notifications', 'economy',
        'history', 'shop', 'payments', 'weekly_bonus',
    ];

    public function inspect(
        array $config,
        array $cutoverState,
        array $authorization,
        string $databaseIdentityFingerprint,
        string $outputRootFingerprint,
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
        $databaseIdentityFingerprint = $this->exactSha($databaseIdentityFingerprint);
        $outputRootFingerprint = $this->exactSha($outputRootFingerprint);

        $checks['environment_exact'] = ($config['environment'] ?? null) === 'production';
        $checks['global_json_rollback_driver'] = ($config['storage_driver'] ?? null) === 'json';
        $checks['maintenance_enabled_exact'] = ($flags['maintenance_mode'] ?? null) === true;
        $checks['financial_read_only_exact'] = ($flags['financial_read_only'] ?? null) === true;
        $checks['runtime_enabled_exact'] = ($runtime['enabled'] ?? null) === true;
        $checks['production_activated_exact'] = ($runtime['production_activated'] ?? null) === true;
        $checks['activation_build_exact'] = ($runtime['activation_build'] ?? null) === self::ACTIVATION_BUILD;
        $checks['activation_plan_valid'] = $plan !== '';
        $checks['activation_source_valid'] = $source !== '';
        $checks['rollback_driver_json'] = ($runtime['rollback_driver'] ?? null) === 'json';
        $checks['all_modules_enabled_exact'] = $this->exactModules($runtime['modules'] ?? null);
        $checks['database_identity_valid'] = $databaseIdentityFingerprint !== '';
        $checks['output_root_fingerprint_valid'] = $outputRootFingerprint !== '';

        $checks['cutover_completed_exact'] = ($cutoverState['state'] ?? null) === 'completed';
        $checks['cutover_build_exact'] = ($cutoverState['build'] ?? null) === self::ACTIVATION_BUILD;
        $checks['cutover_plan_matches'] = $plan !== ''
            && $this->exactSha($cutoverState['plan_fingerprint'] ?? null) !== ''
            && hash_equals($plan, (string)$cutoverState['plan_fingerprint']);
        $checks['cutover_source_matches'] = $source !== ''
            && $this->exactSha($cutoverState['source_fingerprint'] ?? null) !== ''
            && hash_equals($source, (string)$cutoverState['source_fingerprint']);
        $checks['runtime_backup_recorded'] = ($cutoverState['runtime_backup_present'] ?? null) === true;
        $checks['database_route_published'] = ($cutoverState['database_runtime_published'] ?? null) === true;
        $checks['json_write_block_released'] = ($cutoverState['json_write_block_active'] ?? null) === false;
        $checks['cutover_rollback_driver_json'] = ($cutoverState['rollback_driver'] ?? null) === 'json';

        $requestId = is_string($authorization['request_id'] ?? null)
            ? trim((string)$authorization['request_id'])
            : '';
        $expectedRevision = filter_var(
            $authorization['expected_state_revision'] ?? null,
            FILTER_VALIDATE_INT
        );
        $expectedSha = $this->exactSha($authorization['expected_state_sha256'] ?? null);
        $requestedAt = $this->utcTimestamp($authorization['requested_at_utc'] ?? null);
        $expiresAt = $this->utcTimestamp($authorization['expires_at_utc'] ?? null);
        $reason = is_string($authorization['reason'] ?? null)
            ? trim((string)$authorization['reason'])
            : '';

        $checks['authorization_contract_exact'] = ($authorization['contract_version'] ?? null)
            === self::CONTRACT_VERSION;
        $checks['authorization_boolean_exact'] = ($authorization['authorized'] ?? null) === true;
        $checks['authorization_environment_exact'] = ($authorization['environment'] ?? null) === 'production';
        $checks['authorization_build_exact'] = ($authorization['build'] ?? null) === self::ACTIVATION_BUILD;
        $checks['authorization_request_id_valid'] = preg_match('/\A[a-f0-9]{32}\z/', $requestId) === 1;
        $checks['authorization_expected_revision_valid'] = is_int($expectedRevision)
            && $expectedRevision >= 1;
        $checks['authorization_expected_sha_valid'] = $expectedSha !== '';
        $checks['authorization_database_identity_matches'] = $databaseIdentityFingerprint !== ''
            && $this->exactSha($authorization['database_identity_fingerprint'] ?? null) !== ''
            && hash_equals(
                $databaseIdentityFingerprint,
                (string)$authorization['database_identity_fingerprint']
            );
        $checks['authorization_plan_matches'] = $plan !== ''
            && $this->exactSha($authorization['activation_plan_fingerprint'] ?? null) !== ''
            && hash_equals($plan, (string)$authorization['activation_plan_fingerprint']);
        $checks['authorization_source_matches'] = $source !== ''
            && $this->exactSha($authorization['activation_source_fingerprint'] ?? null) !== ''
            && hash_equals($source, (string)$authorization['activation_source_fingerprint']);
        $checks['authorization_output_root_matches'] = $outputRootFingerprint !== ''
            && $this->exactSha($authorization['output_root_fingerprint'] ?? null) !== ''
            && hash_equals(
                $outputRootFingerprint,
                (string)$authorization['output_root_fingerprint']
            );
        $checks['authorization_requested_at_valid'] = $requestedAt !== null
            && $requestedAt <= $now;
        $checks['authorization_expiry_valid'] = $requestedAt !== null
            && $expiresAt !== null
            && $expiresAt > $now
            && ($expiresAt - $requestedAt) >= 60
            && ($expiresAt - $requestedAt) <= 900;
        $checks['authorization_reason_valid'] = $reason !== ''
            && strlen($reason) <= 200
            && preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $reason) !== 1;

        $messages = [
            'environment_exact' => 'Rollback export requires the exact production environment.',
            'global_json_rollback_driver' => 'Global rollback driver must remain JSON.',
            'maintenance_enabled_exact' => 'Rollback export requires maintenance mode.',
            'financial_read_only_exact' => 'Rollback export requires financial read-only mode.',
            'runtime_enabled_exact' => 'Production DB-primary runtime is not explicitly enabled.',
            'production_activated_exact' => 'Production DB-primary activation marker is not exact.',
            'activation_build_exact' => 'Production activation belongs to another build.',
            'activation_plan_valid' => 'Production activation plan fingerprint is invalid.',
            'activation_source_valid' => 'Production activation source fingerprint is invalid.',
            'rollback_driver_json' => 'Production runtime rollback driver must be JSON.',
            'all_modules_enabled_exact' => 'Rollback export requires exactly all nine DB-primary modules.',
            'database_identity_valid' => 'Production database identity fingerprint is invalid.',
            'output_root_fingerprint_valid' => 'Rollback export root fingerprint is invalid.',
            'cutover_completed_exact' => 'Rollback export requires a completed production cutover.',
            'cutover_build_exact' => 'Cutover state belongs to another build.',
            'cutover_plan_matches' => 'Cutover plan fingerprint does not match runtime.',
            'cutover_source_matches' => 'Cutover source fingerprint does not match runtime.',
            'runtime_backup_recorded' => 'Cutover state does not record the runtime backup.',
            'database_route_published' => 'Cutover state does not confirm the database route.',
            'json_write_block_released' => 'Rollback export requires the completed cutover JSON seal to be released.',
            'cutover_rollback_driver_json' => 'Cutover rollback driver must be JSON.',
            'authorization_contract_exact' => 'Rollback export authorization contract is invalid.',
            'authorization_boolean_exact' => 'Rollback export is not explicitly authorized.',
            'authorization_environment_exact' => 'Rollback export authorization environment is invalid.',
            'authorization_build_exact' => 'Rollback export authorization build is invalid.',
            'authorization_request_id_valid' => 'Rollback export request ID is invalid.',
            'authorization_expected_revision_valid' => 'Rollback export expected revision is invalid.',
            'authorization_expected_sha_valid' => 'Rollback export expected state fingerprint is invalid.',
            'authorization_database_identity_matches' => 'Rollback export database identity does not match.',
            'authorization_plan_matches' => 'Rollback export plan fingerprint does not match.',
            'authorization_source_matches' => 'Rollback export source fingerprint does not match.',
            'authorization_output_root_matches' => 'Rollback export output root does not match authorization.',
            'authorization_requested_at_valid' => 'Rollback export authorization request time is invalid.',
            'authorization_expiry_valid' => 'Rollback export authorization is expired or has an invalid TTL.',
            'authorization_reason_valid' => 'Rollback export authorization reason is invalid.',
        ];
        foreach ($messages as $check => $message) {
            if (($checks[$check] ?? false) !== true) $blockers[] = $message;
        }

        ksort($checks, SORT_STRING);
        $blockers = array_values(array_unique($blockers));
        sort($blockers, SORT_STRING);

        return [
            'ready' => $blockers === [],
            'contract_version' => self::CONTRACT_VERSION,
            'activation_build' => self::ACTIVATION_BUILD,
            'request_id' => $requestId,
            'expected_state_revision' => is_int($expectedRevision) ? $expectedRevision : 0,
            'expected_state_sha256' => $expectedSha,
            'database_identity_fingerprint' => $databaseIdentityFingerprint,
            'activation_plan_fingerprint' => $plan,
            'activation_source_fingerprint' => $source,
            'output_root_fingerprint' => $outputRootFingerprint,
            'authorization_expires_at_utc' => is_string($authorization['expires_at_utc'] ?? null)
                ? (string)$authorization['expires_at_utc']
                : '',
            'reason_fingerprint' => $reason !== '' ? hash('sha256', $reason) : '',
            'checks' => $checks,
            'blockers' => $blockers,
            'database_contacted' => false,
            'database_write_executed' => false,
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
