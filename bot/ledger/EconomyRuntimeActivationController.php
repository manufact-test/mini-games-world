<?php
declare(strict_types=1);

final class EconomyRuntimeActivationController
{
    private Closure $synchronize;
    private Closure $audit;

    public function __construct(
        private string $runtimeFile,
        private string $stateFile,
        private string $backupFile,
        callable $synchronize,
        callable $audit
    ) {
        foreach (['runtimeFile', 'stateFile', 'backupFile'] as $property) {
            $this->{$property} = trim($this->{$property});
            if ($this->{$property} === '') {
                throw new InvalidArgumentException('Economy runtime activation path is required: ' . $property . '.');
            }
        }
        $this->synchronize = Closure::fromCallable($synchronize);
        $this->audit = Closure::fromCallable($audit);
    }

    public function status(): array
    {
        $state = $this->readState();
        return $this->withFingerprint([
            'ok' => true,
            'report_type' => 'mvp-14.8.4d1-economy-runtime-activation',
            'state' => (string)($state['state'] ?? 'not_started'),
            'complete' => !empty($state['complete']),
            'economy_enabled' => $this->safeEconomyEnabled(),
            'recovery_backup_present' => is_file($this->backupFile),
            'production_changed' => false,
            'sensitive_identifiers_exposed' => false,
            'generated_at_utc' => $this->nowUtc(),
        ]);
    }

    public function run(bool $reset = false): array
    {
        if ($reset) {
            if (is_file($this->backupFile)) {
                throw new RuntimeException('Cannot reset while a recovery backup exists. Run without --reset to recover first.');
            }
            if (is_file($this->stateFile) && !unlink($this->stateFile)) {
                throw new RuntimeException('Could not reset economy runtime activation state.');
            }
        }

        $existing = $this->readState();
        $state = (string)($existing['state'] ?? '');
        if ($state === 'completed') {
            $existing['action'] = 'run_noop';
            $existing['idempotent'] = true;
            $existing['economy_enabled'] = $this->safeEconomyEnabled();
            $existing['generated_at_utc'] = $this->nowUtc();
            return $this->withFingerprint($existing);
        }
        if ($state === 'failed') {
            $existing['action'] = 'failed_noop';
            $existing['idempotent'] = true;
            $existing['economy_enabled'] = $this->safeEconomyEnabled();
            $existing['generated_at_utc'] = $this->nowUtc();
            return $this->withFingerprint($existing);
        }
        if ($state === 'activating' && is_file($this->backupFile)) {
            $restored = $this->restoreBackup();
            $report = $this->baseReport('recovered_interrupted', $restored) + [
                'complete' => false,
                'action' => 'recover',
                'idempotent' => false,
                'economy_enabled' => $this->safeEconomyEnabled(),
                'rollback' => ['attempted' => true, 'ok' => $restored],
            ];
            $report = $this->withFingerprint($report);
            $this->writeState($report);
            return $report;
        }

        $runtime = $this->readRuntime();
        $this->assertPrerequisites($runtime);
        $step = 'backup';

        try {
            $this->createBackup();
            $activating = $this->withFingerprint($this->baseReport('activating', true) + [
                'complete' => false,
                'action' => 'run',
                'idempotent' => false,
                'current_step' => 'runtime_config',
                'economy_enabled' => $this->economyEnabled($runtime),
            ]);
            $this->writeState($activating);

            $step = 'runtime_config';
            $activatedRuntime = $this->withEconomy($runtime, true);
            $this->writeRuntime($activatedRuntime);
            if (!$this->economyEnabled($this->readRuntime())) {
                throw new RuntimeException('Economy runtime flag was not activated.');
            }

            $step = 'synchronize';
            $synchronization = ($this->synchronize)($activatedRuntime);
            if (empty($synchronization['ok'])) {
                throw new RuntimeException('Economy runtime synchronization failed.');
            }

            $step = 'audit';
            $audit = ($this->audit)($activatedRuntime);
            if (empty($audit['ok']) || !empty($audit['blockers'])) {
                throw new RuntimeException('Economy runtime audit failed.');
            }

            if (is_file($this->backupFile) && !unlink($this->backupFile)) {
                throw new RuntimeException('Could not remove the economy runtime recovery backup.');
            }
            $report = $this->baseReport('completed', true) + [
                'complete' => true,
                'action' => 'run',
                'idempotent' => false,
                'current_step' => 'completed',
                'economy_enabled' => true,
                'synchronization' => $this->compactSynchronization($synchronization),
                'audit' => $this->compactAudit($audit),
                'rollback' => ['attempted' => false, 'ok' => true],
                'completed_at_utc' => $this->nowUtc(),
            ];
            $report = $this->withFingerprint($report);
            $this->writeState($report);
            return $report;
        } catch (Throwable $error) {
            $rollbackOk = $this->restoreBackup();
            $report = $this->baseReport('failed', false) + [
                'complete' => false,
                'action' => 'run',
                'idempotent' => false,
                'failed_step' => $step,
                'error_class' => get_class($error),
                'error_message' => $error->getMessage(),
                'economy_enabled' => $this->safeEconomyEnabled(),
                'rollback' => ['attempted' => true, 'ok' => $rollbackOk],
                'failed_at_utc' => $this->nowUtc(),
            ];
            $report = $this->withFingerprint($report);
            $this->writeState($report);
            return $report;
        }
    }

    public function disable(string $reason = 'manual staging rollback'): array
    {
        $runtime = $this->readRuntime();
        $this->writeRuntime($this->withEconomy($runtime, false));
        if (is_file($this->backupFile) && !unlink($this->backupFile)) {
            throw new RuntimeException('Could not remove the economy runtime recovery backup.');
        }

        $cleanReason = trim($reason);
        $report = $this->baseReport('disabled', true) + [
            'complete' => false,
            'action' => 'disable',
            'idempotent' => !$this->economyEnabled($runtime),
            'economy_enabled' => false,
            'reason' => $cleanReason !== '' ? substr($cleanReason, 0, 180) : 'manual staging rollback',
            'disabled_at_utc' => $this->nowUtc(),
        ];
        $report = $this->withFingerprint($report);
        $this->writeState($report);
        return $report;
    }

    private function assertPrerequisites(array $runtime): void
    {
        $databaseRuntime = $runtime['database_runtime'] ?? [];
        if (!is_array($databaseRuntime) || !$this->boolValue($databaseRuntime['enabled'] ?? false)) {
            throw new RuntimeException('Staging database runtime must already be enabled.');
        }
        $modules = $databaseRuntime['modules'] ?? [];
        if (!is_array($modules) || !$this->boolValue($modules['accounts'] ?? false)) {
            throw new RuntimeException('Accounts DB runtime must be enabled before economy activation.');
        }
    }

    private function withEconomy(array $runtime, bool $enabled): array
    {
        if (!isset($runtime['database_runtime']) || !is_array($runtime['database_runtime'])) {
            $runtime['database_runtime'] = [];
        }
        $runtime['database_runtime']['enabled'] = true;
        if (!isset($runtime['database_runtime']['modules']) || !is_array($runtime['database_runtime']['modules'])) {
            $runtime['database_runtime']['modules'] = [];
        }
        $runtime['database_runtime']['modules']['economy'] = $enabled;
        return $runtime;
    }

    private function economyEnabled(array $runtime): bool
    {
        $databaseRuntime = $runtime['database_runtime'] ?? [];
        if (!is_array($databaseRuntime)) return false;
        $modules = $databaseRuntime['modules'] ?? [];
        if (!is_array($modules)) return false;
        return $this->boolValue($modules['economy'] ?? false);
    }

    private function safeEconomyEnabled(): ?bool
    {
        try {
            return $this->economyEnabled($this->readRuntime());
        } catch (Throwable) {
            return null;
        }
    }

    private function readRuntime(): array
    {
        if (!is_file($this->runtimeFile)) return [];
        $loader = static fn(string $file): mixed => require $file;
        $runtime = $loader($this->runtimeFile);
        if (!is_array($runtime)) {
            throw new RuntimeException('Private runtime config must return an array.');
        }
        return $runtime;
    }

    private function writeRuntime(array $runtime): void
    {
        $directory = dirname($this->runtimeFile);
        if (!is_dir($directory)) {
            throw new RuntimeException('Private runtime config directory is unavailable.');
        }
        $temporary = $this->runtimeFile . '.tmp-' . bin2hex(random_bytes(6));
        $content = "<?php\ndeclare(strict_types=1);\n\nreturn " . var_export($runtime, true) . ";\n";
        if (file_put_contents($temporary, $content, LOCK_EX) === false) {
            throw new RuntimeException('Could not write private runtime config.');
        }
        @chmod($temporary, 0600);
        if (!rename($temporary, $this->runtimeFile)) {
            @unlink($temporary);
            throw new RuntimeException('Could not publish private runtime config.');
        }
        @chmod($this->runtimeFile, 0600);
    }

    private function createBackup(): void
    {
        $payload = is_file($this->runtimeFile)
            ? file_get_contents($this->runtimeFile)
            : '__MGW_RUNTIME_ABSENT__';
        if (!is_string($payload) || $payload === '') {
            throw new RuntimeException('Private runtime config backup source is unreadable.');
        }
        if (file_put_contents($this->backupFile, $payload, LOCK_EX) === false) {
            throw new RuntimeException('Could not create private runtime config backup.');
        }
        @chmod($this->backupFile, 0600);
    }

    private function restoreBackup(): bool
    {
        if (!is_file($this->backupFile)) return false;
        $payload = file_get_contents($this->backupFile);
        if (!is_string($payload) || $payload === '') return false;

        if ($payload === '__MGW_RUNTIME_ABSENT__') {
            $ok = !is_file($this->runtimeFile) || unlink($this->runtimeFile);
        } else {
            $temporary = $this->runtimeFile . '.restore-' . bin2hex(random_bytes(6));
            $written = file_put_contents($temporary, $payload, LOCK_EX) !== false;
            if ($written) @chmod($temporary, 0600);
            $ok = $written && rename($temporary, $this->runtimeFile);
            if (is_file($temporary)) @unlink($temporary);
            if ($ok) @chmod($this->runtimeFile, 0600);
        }
        if ($ok) @unlink($this->backupFile);
        return $ok;
    }

    private function readState(): array
    {
        if (!is_file($this->stateFile)) return [];
        $raw = file_get_contents($this->stateFile);
        if (!is_string($raw) || trim($raw) === '') {
            throw new RuntimeException('Economy runtime activation state is unreadable.');
        }
        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new RuntimeException('Economy runtime activation state is invalid.');
        }
        if (!is_array($decoded)) throw new RuntimeException('Economy runtime activation state has an invalid root.');
        return $decoded;
    }

    private function writeState(array $state): void
    {
        $directory = dirname($this->stateFile);
        if (!is_dir($directory)) throw new RuntimeException('Private activation state directory is unavailable.');
        $temporary = $this->stateFile . '.tmp-' . bin2hex(random_bytes(6));
        $json = json_encode(
            $state,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR
        ) . PHP_EOL;
        if (file_put_contents($temporary, $json, LOCK_EX) === false) {
            throw new RuntimeException('Could not write economy runtime activation state.');
        }
        @chmod($temporary, 0600);
        if (!rename($temporary, $this->stateFile)) {
            @unlink($temporary);
            throw new RuntimeException('Could not publish economy runtime activation state.');
        }
        @chmod($this->stateFile, 0600);
    }

    private function compactSynchronization(array $report): array
    {
        return [
            'ok' => !empty($report['ok']),
            'shadow' => $report['shadow'] ?? [],
            'balance_bootstrap' => $report['balance_bootstrap'] ?? [],
            'delta' => $report['delta'] ?? [],
            'reconciliation' => $report['reconciliation'] ?? [],
        ];
    }

    private function compactAudit(array $report): array
    {
        return [
            'ok' => !empty($report['ok']),
            'read_only' => !empty($report['read_only']),
            'shadow_delta_count' => (int)($report['shadow_delta_count'] ?? 0),
            'reconciliation' => $report['reconciliation'] ?? [],
            'blockers' => array_values((array)($report['blockers'] ?? [])),
        ];
    }

    private function baseReport(string $state, bool $ok): array
    {
        return [
            'ok' => $ok,
            'report_type' => 'mvp-14.8.4d1-economy-runtime-activation',
            'state' => $state,
            'production_changed' => false,
            'sensitive_identifiers_exposed' => false,
            'generated_at_utc' => $this->nowUtc(),
        ];
    }

    private function withFingerprint(array $report): array
    {
        unset($report['report_fingerprint']);
        $report['report_fingerprint'] = hash('sha256', LedgerIntegrity::canonicalJson($report));
        return $report;
    }

    private function boolValue(mixed $value): bool
    {
        if (is_bool($value)) return $value;
        if (is_int($value)) return $value !== 0;
        if (is_string($value)) {
            return in_array(strtolower(trim($value)), ['1', 'true', 'yes', 'on', 'enabled'], true);
        }
        return false;
    }

    private function nowUtc(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DateTimeInterface::ATOM);
    }
}
