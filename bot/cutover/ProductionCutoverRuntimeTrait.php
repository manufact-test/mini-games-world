<?php
declare(strict_types=1);

trait ProductionCutoverRuntimeTrait
{
    private function maintenanceRuntime(array $runtime): array
    {
        $runtime['maintenance_mode'] = true;
        $runtime['maintenance_message'] = 'Идут технические работы. Mini Games World скоро вернётся.';
        $runtime['financial_read_only'] = true;
        $features = is_array($runtime['features'] ?? null) ? $runtime['features'] : [];
        foreach (['matchmaking', 'invitations', 'payments', 'shop'] as $feature) {
            $features[$feature] = false;
        }
        $runtime['features'] = $features;
        $runtime['database_runtime'] = [
            'enabled' => false,
            'modules' => [],
            'rollback_driver' => RuntimeStorageRouter::DRIVER_JSON,
        ];
        return $runtime;
    }

    private function activatedRuntime(array $runtime, string $planFingerprint, string $sourceFingerprint): array
    {
        $modules = array_fill_keys(self::MODULES, true);
        $runtime['database_runtime'] = [
            'enabled' => true,
            'production_activated' => true,
            'activation_build' => self::BUILD,
            'activation_plan_fingerprint' => $planFingerprint,
            'activation_source_fingerprint' => $sourceFingerprint,
            'activated_at_utc' => $this->nowUtc(),
            'rollback_driver' => RuntimeStorageRouter::DRIVER_JSON,
            'modules' => $modules,
        ];
        return $runtime;
    }

    private function releaseMaintenance(array $originalRuntime, array $activatedRuntime): array
    {
        $final = $originalRuntime;
        $final['database_runtime'] = $activatedRuntime['database_runtime'];
        return $final;
    }

    private function assertOriginalRuntimeSafe(array $runtime): void
    {
        $router = new RuntimeStorageRouter($this->configWithRuntime($runtime));
        if ($router->enabled()) {
            throw new RuntimeException('Production database runtime is already enabled before cutover.');
        }
        if (is_file($this->writeBlockFile)) {
            throw new RuntimeException('JSON write block is already active before cutover.');
        }
    }

    private function assertMaintenanceActive(array $config): void
    {
        $flags = new FeatureFlagService($config);
        if (!$flags->maintenanceEnabled() || !$flags->financialReadOnly()) {
            throw new RuntimeException('Production maintenance and financial read-only mode did not activate.');
        }
        if ($flags->featureEnabled('matchmaking')
            || $flags->featureEnabled('invitations')
            || $flags->featureEnabled('payments')
            || $flags->featureEnabled('shop')) {
            throw new RuntimeException('Production cutover write-producing features were not disabled.');
        }
    }

    private function activateWriteBlock(string $planFingerprint): void
    {
        $payload = json_encode([
            'state' => 'sealed',
            'environment' => 'production',
            'build' => self::BUILD,
            'plan_fingerprint' => $planFingerprint,
            'activated_at_utc' => $this->nowUtc(),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
        if (file_put_contents($this->writeBlockFile, $payload, LOCK_EX) === false) {
            throw new RuntimeException('Could not activate the production JSON write block.');
        }
        @chmod($this->writeBlockFile, 0600);
    }

    private function removeWriteBlock(): void
    {
        if (is_file($this->writeBlockFile) && !@unlink($this->writeBlockFile)) {
            throw new RuntimeException('Could not remove the production JSON write block.');
        }
    }

    private function rollbackInternal(string $reason): array
    {
        $backupPresent = is_file($this->runtimeBackupFile);
        $writeBlockPresent = is_file($this->writeBlockFile);
        $routerInitiallyEnabled = false;
        $routerInitialError = '';
        try {
            $routerInitiallyEnabled = (new RuntimeStorageRouter(
                $this->configWithRuntime($this->readRuntime())
            ))->enabled();
        } catch (Throwable $error) {
            $routerInitialError = $this->safeMessage($error->getMessage());
        }

        if (!$backupPresent && !$writeBlockPresent && !$routerInitiallyEnabled && $routerInitialError === '') {
            return [
                'ok' => true,
                'action' => 'rollback_noop',
                'idempotent' => true,
                'state_written' => false,
                'runtime_restored' => true,
                'json_write_block_removed' => true,
                'database_runtime_disabled' => true,
                'storage_driver' => RuntimeStorageRouter::DRIVER_JSON,
                'rollback_driver' => RuntimeStorageRouter::DRIVER_JSON,
                'database_runtime_enabled' => false,
                'database_runtime_status' => 'disabled',
                'database_rows_preserved_for_analysis' => true,
                'error' => '',
                'rolled_back_at_utc' => $this->nowUtc(),
            ];
        }

        $runtimeRestored = false;
        $writeBlockRemoved = false;
        $error = $routerInitialError;

        try {
            if ($backupPresent) {
                $runtimeRestored = $this->restoreRuntimeBackup();
            } else {
                $error = trim($error . '; exact runtime backup is missing', '; ');
            }
        } catch (Throwable $restoreError) {
            $error = trim($error . '; ' . $this->safeMessage($restoreError->getMessage()), '; ');
        }
        try {
            $this->removeWriteBlock();
            $writeBlockRemoved = !is_file($this->writeBlockFile);
        } catch (Throwable $blockError) {
            $error = trim($error . '; ' . $this->safeMessage($blockError->getMessage()), '; ');
        }

        $routerDisabled = false;
        try {
            $runtime = $this->readRuntime();
            $routerDisabled = !(new RuntimeStorageRouter($this->configWithRuntime($runtime)))->enabled();
        } catch (Throwable $routerError) {
            $error = trim($error . '; ' . $this->safeMessage($routerError->getMessage()), '; ');
        }

        $ok = $runtimeRestored && $writeBlockRemoved && $routerDisabled;
        $state = [
            'state' => $ok ? 'rolled_back' : 'rollback_failed',
            'build' => self::BUILD,
            'rolled_back_at_utc' => $this->nowUtc(),
            'reason' => mb_substr(trim(preg_replace('/\s+/u', ' ', $reason) ?? ''), 0, 200),
            'runtime_restored' => $runtimeRestored,
            'json_write_block_removed' => $writeBlockRemoved,
            'database_runtime_disabled' => $routerDisabled,
            'error' => $error,
        ];
        $stateWritten = false;
        try {
            $this->writeState($state);
            $stateWritten = true;
        } catch (Throwable $stateError) {
            $error = trim($error . '; ' . $this->safeMessage($stateError->getMessage()), '; ');
            $ok = false;
        }

        return [
            'ok' => $ok,
            'action' => 'rollback_to_json',
            'state_written' => $stateWritten,
            'runtime_restored' => $runtimeRestored,
            'json_write_block_removed' => $writeBlockRemoved,
            'database_runtime_disabled' => $routerDisabled,
            'storage_driver' => $routerDisabled && $runtimeRestored
                ? RuntimeStorageRouter::DRIVER_JSON
                : 'unknown',
            'rollback_driver' => RuntimeStorageRouter::DRIVER_JSON,
            'database_runtime_enabled' => $routerDisabled ? false : null,
            'database_runtime_status' => $routerDisabled ? 'disabled' : 'unknown',
            'database_rows_preserved_for_analysis' => true,
            'error' => $error,
            'rolled_back_at_utc' => $this->nowUtc(),
        ];
    }

    private function createRuntimeBackup(): void
    {
        if (is_file($this->runtimeBackupFile)) {
            throw new RuntimeException('Production cutover runtime backup already exists.');
        }
        $payload = is_file($this->runtimeFile)
            ? file_get_contents($this->runtimeFile)
            : '__MGW_RUNTIME_ABSENT__';
        if (!is_string($payload) || $payload === '') {
            throw new RuntimeException('Production runtime backup source is unreadable.');
        }
        if (file_put_contents($this->runtimeBackupFile, $payload, LOCK_EX) === false) {
            throw new RuntimeException('Could not create the production runtime backup.');
        }
        @chmod($this->runtimeBackupFile, 0600);
    }

    private function restoreRuntimeBackup(): bool
    {
        if (!is_file($this->runtimeBackupFile)) return false;
        $payload = file_get_contents($this->runtimeBackupFile);
        if (!is_string($payload) || $payload === '') {
            throw new RuntimeException('Production runtime backup is unreadable.');
        }

        if ($payload === '__MGW_RUNTIME_ABSENT__') {
            if (is_file($this->runtimeFile) && !@unlink($this->runtimeFile)) {
                throw new RuntimeException('Could not remove the production runtime file during rollback.');
            }
            return true;
        }

        $temporary = $this->runtimeFile . '.restore-' . bin2hex(random_bytes(6));
        if (file_put_contents($temporary, $payload, LOCK_EX) === false) {
            throw new RuntimeException('Could not prepare the production runtime rollback.');
        }
        @chmod($temporary, 0600);
        if (!rename($temporary, $this->runtimeFile)) {
            @unlink($temporary);
            throw new RuntimeException('Could not publish the production runtime rollback.');
        }
        @chmod($this->runtimeFile, 0600);
        return true;
    }

    private function readRuntime(): array
    {
        if (!is_file($this->runtimeFile)) return [];
        $runtime = require $this->runtimeFile;
        if (!is_array($runtime)) {
            throw new RuntimeException('Production runtime config must return an array.');
        }
        return $runtime;
    }

    private function writeRuntime(array $runtime): void
    {
        $temporary = $this->runtimeFile . '.tmp-' . bin2hex(random_bytes(6));
        $content = "<?php\ndeclare(strict_types=1);\n\nreturn " . var_export($runtime, true) . ";\n";
        if (file_put_contents($temporary, $content, LOCK_EX) === false) {
            throw new RuntimeException('Could not write the production runtime config.');
        }
        @chmod($temporary, 0600);
        if (!rename($temporary, $this->runtimeFile)) {
            @unlink($temporary);
            throw new RuntimeException('Could not publish the production runtime config.');
        }
        @chmod($this->runtimeFile, 0600);
    }

    private function readState(): array
    {
        if (!is_file($this->stateFile)) return [];
        $raw = file_get_contents($this->stateFile);
        if (!is_string($raw) || trim($raw) === '') {
            throw new RuntimeException('Production cutover state is empty.');
        }
        $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) {
            throw new RuntimeException('Production cutover state must be an object.');
        }
        return $decoded;
    }

    private function writeState(array $state): void
    {
        $temporary = $this->stateFile . '.tmp-' . bin2hex(random_bytes(6));
        $encoded = json_encode(
            $state,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR
        ) . PHP_EOL;
        if (file_put_contents($temporary, $encoded, LOCK_EX) === false) {
            throw new RuntimeException('Could not write the production cutover state.');
        }
        @chmod($temporary, 0600);
        if (!rename($temporary, $this->stateFile)) {
            @unlink($temporary);
            throw new RuntimeException('Could not publish the production cutover state.');
        }
        @chmod($this->stateFile, 0600);
    }

    private function configWithRuntime(array $runtime): array
    {
        $config = $this->config;
        $flags = is_array($config['feature_flags'] ?? null) ? $config['feature_flags'] : [];
        unset($flags['database_runtime']);
        $config['feature_flags'] = array_replace_recursive($flags, $runtime);
        return $config;
    }

    private function assertEnvironmentAndBuild(): void
    {
        $environment = strtolower(trim((string)($this->config['environment'] ?? 'production')));
        if ($environment !== 'production') {
            throw new RuntimeException('Controlled production cutover is enabled only in production.');
        }
        if (FeatureFlagService::BUILD !== self::BUILD) {
            throw new RuntimeException('Unexpected application build for controlled production cutover.');
        }
        if ($this->storage->driver() !== RuntimeStorageRouter::DRIVER_JSON) {
            throw new RuntimeException('Global JSON rollback storage must remain active during production cutover.');
        }
        $migrationStatus = (new MigrationRunner(
            $this->database,
            $this->projectRoot . '/bot/database/migrations'
        ))->status();
        if ((int)($migrationStatus['pending_count'] ?? -1) !== 0) {
            throw new RuntimeException('Production database schema has pending migrations.');
        }
    }

    private function recoveryArtifactsPresent(): bool
    {
        return is_file($this->runtimeBackupFile) || is_file($this->writeBlockFile);
    }
}
