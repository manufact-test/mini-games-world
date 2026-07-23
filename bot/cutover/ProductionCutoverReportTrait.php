<?php
declare(strict_types=1);

trait ProductionCutoverReportTrait
{
    private function blockedReport(
        Throwable $error,
        ?array $preflight,
        string $reason,
        ?string $startedAt = null
    ): array {
        return [
            'ok' => false,
            'report_type' => 'mvp-14.9-production-cutover',
            'action' => 'cutover_blocked',
            'reason' => $reason,
            'environment' => 'production',
            'build' => self::BUILD,
            'error_class' => get_class($error),
            'error_message' => $this->safeMessage($error->getMessage()),
            'preflight_ready' => is_array($preflight)
                && ($preflight['technical_ready_for_window'] ?? false) === true,
            'rollback' => [
                'attempted' => false,
                'ok' => true,
                'reason' => 'no production mutation started',
            ],
            'storage_driver' => RuntimeStorageRouter::DRIVER_JSON,
            'rollback_driver' => RuntimeStorageRouter::DRIVER_JSON,
            'production_db_runtime_enabled' => false,
            'production_changed' => false,
            'manual_review_required' => true,
            'sensitive_identifiers_exposed' => false,
            'started_at_utc' => $startedAt,
            'failed_at_utc' => $this->nowUtc(),
        ];
    }

    private function recoveryBlockedReport(
        Throwable $error,
        string $reason,
        string $state = 'unknown',
        ?string $startedAt = null
    ): array {
        return [
            'ok' => false,
            'report_type' => 'mvp-14.9-production-cutover',
            'action' => 'recovery_blocked',
            'reason' => $reason,
            'state' => $state,
            'environment' => 'production',
            'build' => self::BUILD,
            'error_class' => get_class($error),
            'error_message' => $this->safeMessage($error->getMessage()),
            'rollback' => [
                'attempted' => false,
                'ok' => false,
                'reason' => 'exact recovery artifacts are unavailable',
            ],
            'storage_driver' => 'unknown',
            'rollback_driver' => RuntimeStorageRouter::DRIVER_JSON,
            'production_db_runtime_enabled' => null,
            'database_runtime_status' => 'unknown',
            'production_changed' => null,
            'production_change_status' => 'unknown',
            'manual_review_required' => true,
            'immediate_operator_action_required' => true,
            'sensitive_identifiers_exposed' => false,
            'started_at_utc' => $startedAt,
            'failed_at_utc' => $this->nowUtc(),
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
        $rollback = $this->rollbackInternal('automatic rollback after cutover failure: ' . $reason);
        $rollbackAction = (string)($rollback['action'] ?? '');
        $runtimeRestored = ($rollback['runtime_restored'] ?? false) === true;
        $writeBlockRemoved = ($rollback['json_write_block_removed'] ?? false) === true;
        $databaseRuntimeDisabled = ($rollback['database_runtime_disabled'] ?? false) === true;
        $statePersisted = ($rollback['state_written'] ?? false) === true;
        $rollbackSucceeded = ($rollback['ok'] ?? false) === true
            && $rollbackAction === 'rollback_to_json'
            && $runtimeRestored
            && $writeBlockRemoved
            && $databaseRuntimeDisabled
            && $statePersisted;

        return [
            'ok' => false,
            'report_type' => 'mvp-14.9-production-cutover',
            'action' => 'automatic_rollback',
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
            'storage_driver' => $rollbackSucceeded
                ? RuntimeStorageRouter::DRIVER_JSON
                : 'unknown',
            'rollback_driver' => RuntimeStorageRouter::DRIVER_JSON,
            'production_db_runtime_enabled' => $databaseRuntimeDisabled ? false : null,
            'database_runtime_status' => $databaseRuntimeDisabled ? 'disabled' : 'unknown',
            'production_changed' => $mutationStage !== 'none' && $mutationStage !== 'runtime_backup_created',
            'manual_review_required' => true,
            'immediate_operator_action_required' => !$rollbackSucceeded,
            'sensitive_identifiers_exposed' => false,
            'started_at_utc' => $startedAt,
            'failed_at_utc' => $this->nowUtc(),
        ];
    }

    private function compactBackup(array $backup): array
    {
        return [
            'ok' => !empty($backup['ok']),
            'backup_id' => (string)($backup['backup_id'] ?? ''),
            'snapshot_sha256' => (string)($backup['snapshot_sha256'] ?? ''),
            'external_copy' => [
                'copied' => !empty($backup['external_copy']['copied']),
                'snapshot_matches' => isset($backup['external_copy']['snapshot_sha256'])
                    && hash_equals(
                        (string)($backup['snapshot_sha256'] ?? ''),
                        (string)$backup['external_copy']['snapshot_sha256']
                    ),
            ],
        ];
    }

    private function compactImport(array $report, array $integerKeys): array
    {
        $compact = [
            'ok' => !empty($report['ok']),
            'status' => (string)($report['status'] ?? ''),
        ];
        foreach ($integerKeys as $key) $compact[$key] = (int)($report[$key] ?? 0);
        return $compact;
    }

    private function compactShadow(array $report): array
    {
        $sections = [];
        foreach (is_array($report['sections'] ?? null) ? $report['sections'] : [] as $name => $section) {
            if (!is_array($section)) continue;
            $sections[(string)$name] = [
                'source_count' => (int)($section['source_count'] ?? 0),
                'database_count' => (int)($section['database_count'] ?? 0),
                'inserted_count' => (int)($section['inserted_count'] ?? 0),
                'updated_count' => (int)($section['updated_count'] ?? 0),
                'repair_count' => (int)($section['repair_count'] ?? 0),
                'deleted_count' => (int)($section['deleted_count'] ?? 0),
            ];
        }
        return ['ok' => !empty($report['ok']), 'sections' => $sections];
    }

    private function compactFinancialAudit(array $report, string $sourceKey, string $databaseKey): array
    {
        $audit = is_array($report['audit'] ?? null) ? $report['audit'] : $report;
        return [
            'ok' => !empty($report['ok']),
            'source_count' => (int)($audit[$sourceKey] ?? 0),
            'database_count' => (int)($audit[$databaseKey] ?? 0),
            'mismatch_count' => (int)($audit['mismatch_count'] ?? 0),
        ];
    }

    private function compactRegression(array $report): array
    {
        return [
            'ok' => !empty($report['ok']),
            'enabled_modules' => $report['enabled_modules'] ?? [],
            'missing_required_modules' => $report['missing_required_modules'] ?? [],
            'accounts_ok' => !empty($report['accounts']['ok']),
            'realtime_ok' => !empty($report['realtime']['ok']),
            'invites_ok' => !empty($report['invites']['ok']),
            'notifications_ok' => !empty($report['notifications']['ok']),
            'history_ok' => !empty($report['history']['ok']),
            'economy_ok' => !empty($report['economy']['ok']),
            'shop_ok' => !empty($report['shop']['ok']),
            'payments_ok' => !empty($report['payments']['ok']),
            'weekly_bonus_ok' => !empty($report['weekly_bonus']['ok']),
            'blockers' => array_values((array)($report['blockers'] ?? [])),
        ];
    }

    private function compactState(array $state): array
    {
        return [
            'state' => (string)($state['state'] ?? 'not_started'),
            'build' => (string)($state['build'] ?? ''),
            'started_at_utc' => (string)($state['started_at_utc'] ?? ''),
            'completed_at_utc' => (string)($state['completed_at_utc'] ?? ''),
            'rolled_back_at_utc' => (string)($state['rolled_back_at_utc'] ?? ''),
            'plan_fingerprint' => (string)($state['plan_fingerprint'] ?? ''),
            'source_fingerprint' => (string)($state['source_fingerprint'] ?? ''),
            'backup_id' => (string)($state['backup_id'] ?? ''),
            'backup_snapshot_sha256' => (string)($state['backup_snapshot_sha256'] ?? ''),
            'runtime_backup_present' => !empty($state['runtime_backup_present']),
            'database_runtime_published' => !empty($state['database_runtime_published']),
            'json_write_block_active' => !empty($state['json_write_block_active']),
        ];
    }

    private function safeMessage(string $message): string
    {
        $message = preg_replace('~/(?:home|var|tmp|srv)/[^\s\'\"]+~', '[private-path]', $message) ?? $message;
        $message = preg_replace('/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i', '[redacted-email]', $message) ?? $message;
        return mb_substr(trim($message), 0, 500);
    }

    private function isInside(string $path, string $parent): bool
    {
        $path = rtrim(str_replace('\\', '/', trim($path)), '/');
        $parent = rtrim(str_replace('\\', '/', trim($parent)), '/');
        return $path === $parent || str_starts_with($path . '/', $parent . '/');
    }

    private function timestamp(): int
    {
        return $this->now ?? time();
    }

    private function nowUtc(): string
    {
        return gmdate(DATE_ATOM, $this->timestamp());
    }
}
