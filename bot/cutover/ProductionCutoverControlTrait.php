<?php
declare(strict_types=1);

trait ProductionCutoverControlTrait
{
    public function status(): array
    {
        $this->assertControlEnvironmentAndBuild();
        $stateError = '';
        try {
            $state = $this->readState();
        } catch (Throwable $error) {
            $state = [];
            $stateError = $this->safeMessage($error->getMessage());
        }

        $runtime = [];
        $runtimeError = '';
        try {
            $runtime = $this->readRuntime();
        } catch (Throwable $error) {
            $runtimeError = $this->safeMessage($error->getMessage());
        }

        $routerEnabled = false;
        $enabledModules = [];
        $routerError = '';
        if ($runtimeError === '') {
            try {
                $router = new RuntimeStorageRouter($this->configWithRuntime($runtime));
                $routerEnabled = $router->enabled();
                $enabledModules = $router->enabledModules();
            } catch (Throwable $error) {
                $routerError = $this->safeMessage($error->getMessage());
            }
        }

        return [
            'ok' => $routerError === '' && $runtimeError === '' && $stateError === '',
            'report_type' => 'mvp-14.9-production-cutover',
            'action' => 'status',
            'environment' => 'production',
            'build' => self::BUILD,
            'state' => $stateError === '' ? (string)($state['state'] ?? 'not_started') : 'invalid',
            'state_error' => $stateError,
            'runtime_error' => $runtimeError,
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
        $this->assertControlEnvironmentAndBuild();
        return $this->rollbackInternal($reason);
    }

    public function rearm(): array
    {
        $this->assertControlEnvironmentAndBuild();
        if ($this->policy->enabled()) {
            throw new RuntimeException('Disable the private production cutover approval before rearming.');
        }

        $state = $this->readState();
        $stateName = strtolower(trim((string)($state['state'] ?? '')));
        if ($stateName !== 'rolled_back') {
            throw new RuntimeException('Production cutover can be rearmed only after a fully completed reviewed rollback.');
        }
        foreach ([
            'runtime_restored' => 'runtime restore',
            'json_write_block_removed' => 'JSON write-block removal',
            'database_runtime_disabled' => 'database runtime disablement',
        ] as $evidence => $label) {
            if (($state[$evidence] ?? false) !== true) {
                throw new RuntimeException('Production cutover rearm requires confirmed ' . $label . '.');
            }
        }
        if (!is_file($this->runtimeBackupFile)
            || !is_readable($this->runtimeBackupFile)
            || (int)filesize($this->runtimeBackupFile) <= 0) {
            throw new RuntimeException('Production cutover rearm requires the preserved exact runtime backup.');
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

        $archivedRuntimeBackup = $this->privateDir . '/production-cutover.runtime.backup.' . $suffix;
        if (!rename($this->runtimeBackupFile, $archivedRuntimeBackup)) {
            if (!rename($archivedState, $this->stateFile)) {
                throw new RuntimeException('Could not archive the runtime backup and could not restore the state file.');
            }
            throw new RuntimeException('Could not archive the reviewed production runtime backup.');
        }
        @chmod($archivedRuntimeBackup, 0600);

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
            'runtime_backup_archived' => true,
            'fresh_preflight_required' => true,
            'fresh_approval_required' => true,
            'production_changed' => false,
            'sensitive_identifiers_exposed' => false,
            'generated_at_utc' => $this->nowUtc(),
        ];
    }

    private function assertControlEnvironmentAndBuild(): void
    {
        $environment = strtolower(trim((string)($this->config['environment'] ?? 'production')));
        if ($environment !== 'production') {
            throw new RuntimeException('Production cutover controls are enabled only in production.');
        }
        if (FeatureFlagService::BUILD !== self::BUILD) {
            throw new RuntimeException('Unexpected application build for production cutover controls.');
        }
    }
}
