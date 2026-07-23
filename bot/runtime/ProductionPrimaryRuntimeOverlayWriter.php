<?php
declare(strict_types=1);

final class ProductionPrimaryRuntimeOverlayWriter
{
    private const MODULES = [
        'accounts', 'realtime', 'invites', 'notifications', 'economy',
        'history', 'shop', 'payments', 'weekly_bonus',
    ];

    private string $runtimeFile;
    private string $privateDir;

    public function __construct(string $runtimeFile)
    {
        $this->runtimeFile = $this->privateRuntimeFile($runtimeFile);
        $this->privateDir = dirname($this->runtimeFile);
    }

    public function fingerprint(): string
    {
        return $this->fileSha($this->runtimeFile);
    }

    public function load(): array
    {
        return $this->requireArray($this->runtimeFile, 'Production runtime overlay');
    }

    public function backupPath(string $requestId): string
    {
        $requestId = $this->exactRequestId($requestId);
        return $this->privateDir . '/production-live-rollback.runtime.before-' . $requestId . '.php';
    }

    public function prepareBackup(string $requestId, string $expectedFingerprint): array
    {
        $requestId = $this->exactRequestId($requestId);
        $expectedFingerprint = $this->exactSha($expectedFingerprint);
        $currentFingerprint = $this->fingerprint();
        if ($expectedFingerprint === '' || !hash_equals($expectedFingerprint, $currentFingerprint)) {
            throw new RuntimeException('Production runtime overlay changed after rollback authorization.');
        }

        $backup = $this->backupPath($requestId);
        if (file_exists($backup) || is_link($backup)) {
            $backup = $this->privateExistingFile($backup, 'Production runtime rollback backup is invalid.');
            if (!hash_equals($expectedFingerprint, $this->fileSha($backup))) {
                throw new RuntimeException('Production runtime rollback backup does not match authorization.');
            }
            return [
                'ok' => true,
                'created' => false,
                'backup_fingerprint' => $expectedFingerprint,
            ];
        }

        $raw = file_get_contents($this->runtimeFile);
        if (!is_string($raw) || $raw === '') {
            throw new RuntimeException('Production runtime overlay could not be backed up.');
        }
        $this->writeNewPrivateFile($backup, $raw);
        if (!hash_equals($expectedFingerprint, $this->fileSha($backup))) {
            @unlink($backup);
            throw new RuntimeException('Production runtime rollback backup verification failed.');
        }

        return [
            'ok' => true,
            'created' => true,
            'backup_fingerprint' => $expectedFingerprint,
        ];
    }

    public function writeSealed(array $gateReport): array
    {
        return $this->writeRollbackState($gateReport, 'json_sealed', true, true);
    }

    public function writeReleased(array $gateReport): array
    {
        return $this->writeRollbackState($gateReport, 'completed', false, false);
    }

    public function restoreAuthorizedBackup(
        string $requestId,
        string $expectedBackupFingerprint
    ): array {
        $requestId = $this->exactRequestId($requestId);
        $expectedBackupFingerprint = $this->exactSha($expectedBackupFingerprint);
        $backup = $this->privateExistingFile(
            $this->backupPath($requestId),
            'Production runtime rollback backup is unavailable.'
        );
        if ($expectedBackupFingerprint === ''
            || !hash_equals($expectedBackupFingerprint, $this->fileSha($backup))) {
            throw new RuntimeException('Production runtime rollback backup fingerprint is invalid.');
        }
        $raw = file_get_contents($backup);
        if (!is_string($raw) || $raw === '') {
            throw new RuntimeException('Production runtime rollback backup could not be read.');
        }
        $value = $this->requireArray($backup, 'Production runtime rollback backup');
        $this->atomicWrite($value);
        if (!hash_equals($expectedBackupFingerprint, $this->fingerprint())) {
            throw new RuntimeException('Production runtime rollback backup restoration failed.');
        }
        return [
            'ok' => true,
            'runtime_restored' => true,
            'runtime_fingerprint' => $expectedBackupFingerprint,
        ];
    }

    private function writeRollbackState(
        array $gateReport,
        string $state,
        bool $maintenance,
        bool $financialReadOnly
    ): array {
        if (($gateReport['ready'] ?? false) !== true
            || ($gateReport['contract_version'] ?? '')
                !== ProductionPrimaryLiveRollbackGate::CONTRACT_VERSION) {
            throw new RuntimeException('Production runtime rollback writer requires a passed gate.');
        }
        $requestId = $this->exactRequestId($gateReport['request_id'] ?? null);
        $stateSha = $this->exactSha($gateReport['expected_state_sha256'] ?? null);
        $snapshotSha = $this->exactSha($gateReport['expected_snapshot_sha256'] ?? null);
        $revision = filter_var(
            $gateReport['expected_state_revision'] ?? null,
            FILTER_VALIDATE_INT
        );
        if ($requestId === '' || $stateSha === '' || $snapshotSha === ''
            || !is_int($revision) || $revision < 1) {
            throw new RuntimeException('Production runtime rollback gate identity is invalid.');
        }

        $runtime = $this->load();
        $databaseRuntime = is_array($runtime['database_runtime'] ?? null)
            ? $runtime['database_runtime']
            : [];
        $databaseRuntime['enabled'] = false;
        $databaseRuntime['production_activated'] = false;
        $databaseRuntime['rollback_driver'] = 'json';
        $databaseRuntime['modules'] = array_fill_keys(self::MODULES, false);
        $databaseRuntime['last_rollback_request_id'] = $requestId;
        $databaseRuntime['last_rollback_state_revision'] = $revision;
        $databaseRuntime['last_rollback_state_sha256'] = $stateSha;
        $databaseRuntime['last_rollback_snapshot_sha256'] = $snapshotSha;
        $databaseRuntime['last_rollback_at_utc'] = gmdate(DATE_ATOM);

        $runtime['maintenance_mode'] = $maintenance;
        $runtime['financial_read_only'] = $financialReadOnly;
        $runtime['database_runtime'] = $databaseRuntime;
        $runtime['production_live_rollback'] = [
            'contract_version' => ProductionPrimaryLiveRollbackGate::CONTRACT_VERSION,
            'state' => $state,
            'request_id' => $requestId,
            'state_revision' => $revision,
            'state_sha256' => $stateSha,
            'snapshot_sha256' => $snapshotSha,
            'database_route_enabled' => false,
            'json_write_block_active' => $state === 'json_sealed',
            'maintenance_enabled' => $maintenance,
            'financial_read_only' => $financialReadOnly,
            'updated_at_utc' => gmdate(DATE_ATOM),
        ];

        $fingerprint = $this->atomicWrite($runtime);
        $verified = $this->load();
        $verifiedRuntime = is_array($verified['database_runtime'] ?? null)
            ? $verified['database_runtime']
            : [];
        if (($verified['maintenance_mode'] ?? null) !== $maintenance
            || ($verified['financial_read_only'] ?? null) !== $financialReadOnly
            || ($verifiedRuntime['enabled'] ?? null) !== false
            || ($verifiedRuntime['production_activated'] ?? null) !== false
            || ($verifiedRuntime['rollback_driver'] ?? null) !== 'json'
            || ($verified['production_live_rollback']['state'] ?? null) !== $state) {
            throw new RuntimeException('Production runtime rollback overlay verification failed.');
        }
        foreach (self::MODULES as $module) {
            if (($verifiedRuntime['modules'][$module] ?? null) !== false) {
                throw new RuntimeException('Production runtime rollback module disablement is incomplete.');
            }
        }

        return [
            'ok' => true,
            'state' => $state,
            'runtime_fingerprint' => $fingerprint,
            'database_route_enabled' => false,
            'maintenance_enabled' => $maintenance,
            'financial_read_only' => $financialReadOnly,
            'persistent_config_changed' => true,
            'production_changed' => true,
            'sensitive_identifiers_exposed' => false,
        ];
    }

    private function atomicWrite(array $value): string
    {
        $raw = "<?php\ndeclare(strict_types=1);\n\nreturn "
            . var_export($value, true)
            . ";\n";
        $temporary = $this->privateDir . '/.runtime-live-rollback-' . bin2hex(random_bytes(8)) . '.tmp';
        if (file_exists($temporary) || is_link($temporary)) {
            throw new RuntimeException('Production runtime rollback temporary file already exists.');
        }

        try {
            $handle = fopen($temporary, 'xb');
            if ($handle === false) {
                throw new RuntimeException('Production runtime rollback temporary file could not be created.');
            }
            try {
                if (!chmod($temporary, 0600)) {
                    throw new RuntimeException('Production runtime rollback temporary permissions failed.');
                }
                $written = fwrite($handle, $raw);
                if (!is_int($written) || $written !== strlen($raw)) {
                    throw new RuntimeException('Production runtime rollback temporary write was incomplete.');
                }
                if (!fflush($handle)) {
                    throw new RuntimeException('Production runtime rollback temporary flush failed.');
                }
                if (function_exists('fsync') && !fsync($handle)) {
                    throw new RuntimeException('Production runtime rollback temporary sync failed.');
                }
            } finally {
                fclose($handle);
            }

            $decoded = $this->requireArray($temporary, 'Production runtime rollback temporary overlay');
            if (!hash_equals($this->canonicalJson($value), $this->canonicalJson($decoded))) {
                throw new RuntimeException('Production runtime rollback temporary overlay changed during serialization.');
            }
            if (!rename($temporary, $this->runtimeFile)) {
                throw new RuntimeException('Production runtime rollback overlay could not be published atomically.');
            }
            clearstatcache(true, $this->runtimeFile);
            if (!chmod($this->runtimeFile, 0600)) {
                throw new RuntimeException('Production runtime rollback overlay permissions failed.');
            }
            return $this->fingerprint();
        } catch (Throwable $error) {
            if (is_file($temporary) || is_link($temporary)) @unlink($temporary);
            throw $error;
        }
    }

    private function writeNewPrivateFile(string $path, string $raw): void
    {
        $handle = fopen($path, 'xb');
        if ($handle === false) {
            throw new RuntimeException('Production runtime rollback backup could not be created.');
        }
        try {
            if (!chmod($path, 0600)) {
                throw new RuntimeException('Production runtime rollback backup permissions failed.');
            }
            $written = fwrite($handle, $raw);
            if (!is_int($written) || $written !== strlen($raw) || !fflush($handle)) {
                throw new RuntimeException('Production runtime rollback backup write failed.');
            }
            if (function_exists('fsync') && !fsync($handle)) {
                throw new RuntimeException('Production runtime rollback backup sync failed.');
            }
        } finally {
            fclose($handle);
        }
    }

    private function privateRuntimeFile(string $path): string
    {
        $path = $this->privateExistingFile($path, 'Production runtime overlay is unavailable.');
        if (basename($path) !== 'runtime.php') {
            throw new RuntimeException('Production runtime overlay filename is invalid.');
        }
        return $path;
    }

    private function privateExistingFile(string $path, string $message): string
    {
        if ($path === '' || str_contains($path, '\\') || !str_starts_with($path, '/')
            || is_link($path) || !is_file($path)) {
            throw new RuntimeException($message);
        }
        $canonical = realpath($path);
        if (!is_string($canonical) || !hash_equals($path, $canonical)) {
            throw new RuntimeException($message);
        }
        clearstatcache(true, $canonical);
        $mode = fileperms($canonical);
        if (!is_int($mode) || ($mode & 0777) !== 0600) {
            throw new RuntimeException('Production runtime rollback files must have exact mode 0600.');
        }
        return $canonical;
    }

    private function requireArray(string $path, string $label): array
    {
        try {
            $value = require $path;
        } catch (Throwable $error) {
            throw new RuntimeException($label . ' could not be loaded.', 0, $error);
        }
        if (!is_array($value)) {
            throw new RuntimeException($label . ' must return an array.');
        }
        return $value;
    }

    private function fileSha(string $path): string
    {
        $sha = hash_file('sha256', $path);
        if (!is_string($sha) || preg_match('/\A[a-f0-9]{64}\z/', $sha) !== 1) {
            throw new RuntimeException('Production runtime rollback file fingerprint is unavailable.');
        }
        return $sha;
    }

    private function exactRequestId(mixed $value): string
    {
        if (!is_string($value) || preg_match('/\A[a-f0-9]{32}\z/', $value) !== 1) {
            throw new RuntimeException('Production runtime rollback request ID is invalid.');
        }
        return $value;
    }

    private function exactSha(mixed $value): string
    {
        return is_string($value) && preg_match('/\A[a-f0-9]{64}\z/', $value) === 1
            ? $value
            : '';
    }

    private function canonicalJson(array $value): string
    {
        $canonicalize = static function (mixed $item) use (&$canonicalize): mixed {
            if (!is_array($item)) return $item;
            if (!array_is_list($item)) ksort($item, SORT_STRING);
            foreach ($item as $key => $child) $item[$key] = $canonicalize($child);
            return $item;
        };
        return json_encode(
            $canonicalize($value),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        );
    }
}
