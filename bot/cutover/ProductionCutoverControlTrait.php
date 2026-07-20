<?php
declare(strict_types=1);

trait ProductionCutoverControlTrait
{
    public function status(): array
    {
        $this->assertEnvironmentAndBuild();
        $stateError = '';
        try {
            $state = $this->readState();
        } catch (Throwable $error) {
            $state = [];
            $stateError = $this->safeMessage($error->getMessage());
        }
        $runtime = $this->readRuntime();
        $runtimeConfig = $this->configWithRuntime($runtime);

        $routerEnabled = false;
        $enabledModules = [];
        $routerError = '';
        try {
            $router = new RuntimeStorageRouter($runtimeConfig);
            $routerEnabled = $router->enabled();
            $enabledModules = $router->enabledModules();
        } catch (Throwable $error) {
            $routerError = $this->safeMessage($error->getMessage());
        }

        return [
            'ok' => $routerError === '' && $stateError === '',
            'report_type' => 'mvp-14.9-production-cutover',
            'action' => 'status',
            'environment' => 'production',
            'build' => self::BUILD,
            'state' => $stateError === '' ? (string)($state['state'] ?? 'not_started') : 'invalid',
            'state_error' => $stateError,
            'database_runtime_enabled' => $routerEnabled,
            'enabled_modules' => $enabledModules,
            'router_error' => $routerError,
            'runtime_backup_present' => is_file($this->runtimeBackupFile),
            'json_write_block_active' => is_file($this->writeBlockFile),
            'rollback_driver' => RuntimeStorageRouter::DRIVER_JSON,
            'state_summary' => $this->compactState($state),
            'approval' => $this->policy->safeSummary(),
            'sensitive_identifiers_exposed' => false,
            'generated_at_utc' => $this->nowUtc(),
        ];
    }

    public function rollback(string $reason = 'manual production rollback'): array
    {
        $this->assertEnvironmentAndBuild();
        return $this->rollbackInternal($reason);
    }

    public function rearm(): array
    {
        $this->assertEnvironmentAndBuild();
        if ($this->policy->enabled()) {
            throw new RuntimeException('Disable the private production cutover approval before rearming.');
        }

        $state = $this->readState();
        $stateName = strtolower(trim((string)($state['state'] ?? '')));
        if (!in_array($stateName, ['rolled_back', 'rollback_failed'], true)) {
            throw new RuntimeException('Production cutover can be rearmed only after an explicitly reviewed rollback.');
        }
        if (is_file($this->writeBlockFile)) {
            throw new RuntimeException('Production cutover cannot be rearmed while the JSON write block is active.');
        }

        $runtime = $this->readRuntime();
        $router = new RuntimeStorageRouter($this->configWithRuntime($runtime));
        if ($router->enabled()) {
            throw new RuntimeException('Production cutover cannot be rearmed while DB runtime routing is enabled.');
        }

        $suffix = gmdate('Ymd\\THis\\Z', $this->timestamp()) . '-' . bin2hex(random_bytes(4));
        $archivedState = $this->privateDir . '/production-cutover.incident-' . $suffix . '.json';
        if (!rename($this->stateFile, $archivedState)) {
            throw new RuntimeException('Could not archive the reviewed production cutover state.');
        }
        @chmod($archivedState, 0600);

        $archivedRuntimeBackup = null;
        if (is_file($this->runtimeBackupFile)) {
            $archivedRuntimeBackup = $this->privateDir . '/production-cutover.runtime.backup.' . $suffix;
            if (!rename($this->runtimeBackupFile, $archivedRuntimeBackup)) {
                if (!rename($archivedState, $this->stateFile)) {
                    throw new RuntimeException('Could not archive the runtime backup and could not restore the state file.');
                }
                throw new RuntimeException('Could not archive the reviewed production runtime backup.');
            }
            @chmod($archivedRuntimeBackup, 0600);
        }

        return [
            'ok' => true,
            'report_type' => 'mvp-14.9-production-cutover',
            'action' => 'rearmed',
            'environment' => 'production',
            'build' => self::BUILD,
            'previous_state' => $stateName,
            'database_runtime_enabled' => false,
            'json_write_block_active' => false,
            'state_archived' => true,
            'runtime_backup_archived' => $archivedRuntimeBackup !== null,
            'fresh_preflight_required' => true,
            'fresh_approval_required' => true,
            'production_changed' => false,
            'sensitive_identifiers_exposed' => false,
            'generated_at_utc' => $this->nowUtc(),
        ];
    }
}
