<?php
declare(strict_types=1);

final class ProductionPrimaryLiveRollbackStateStore
{
    private const MAX_JSON_BYTES = 262_144;

    private string $privateDir;
    private string $cutoverFile;
    private string $recoveryFile;

    public function __construct(string $privateDir, string $cutoverFile)
    {
        $this->privateDir = $this->canonicalDirectory($privateDir);
        $this->cutoverFile = $this->privateJsonFile(
            $cutoverFile,
            'Production cutover state is unavailable.'
        );
        if (dirname($this->cutoverFile) !== $this->privateDir
            || basename($this->cutoverFile) !== 'production-cutover.json') {
            throw new RuntimeException('Production cutover state path is invalid.');
        }
        $this->recoveryFile = $this->privateDir . '/production-live-rollback.json';
    }

    public function cutoverFingerprint(): string
    {
        return $this->fileSha($this->cutoverFile);
    }

    public function recovery(): array
    {
        if (!file_exists($this->recoveryFile) && !is_link($this->recoveryFile)) return [];
        return $this->readJsonObject(
            $this->privateJsonFile(
                $this->recoveryFile,
                'Production live rollback recovery state is invalid.'
            ),
            'Production live rollback recovery state'
        );
    }

    public function prepareCutoverBackup(string $requestId, string $expectedFingerprint): array
    {
        $requestId = $this->exactRequestId($requestId);
        $expectedFingerprint = $this->exactSha($expectedFingerprint);
        $current = $this->cutoverFingerprint();
        if ($expectedFingerprint === '' || !hash_equals($expectedFingerprint, $current)) {
            throw new RuntimeException('Production cutover state changed after rollback authorization.');
        }
        $backup = $this->cutoverBackupPath($requestId);
        if (file_exists($backup) || is_link($backup)) {
            $backup = $this->privateJsonFile(
                $backup,
                'Production cutover rollback backup is invalid.'
            );
            if (!hash_equals($expectedFingerprint, $this->fileSha($backup))) {
                throw new RuntimeException('Production cutover rollback backup fingerprint mismatch.');
            }
            return ['ok' => true, 'created' => false, 'backup_fingerprint' => $expectedFingerprint];
        }
        $raw = file_get_contents($this->cutoverFile);
        if (!is_string($raw) || $raw === '') {
            throw new RuntimeException('Production cutover state could not be backed up.');
        }
        $this->writeNewPrivateFile($backup, $raw);
        $this->readJsonObject($backup, 'Production cutover rollback backup');
        if (!hash_equals($expectedFingerprint, $this->fileSha($backup))) {
            @unlink($backup);
            throw new RuntimeException('Production cutover rollback backup verification failed.');
        }
        return ['ok' => true, 'created' => true, 'backup_fingerprint' => $expectedFingerprint];
    }

    public function writeRecovery(string $state, array $gateReport, array $extra = []): array
    {
        $allowed = [
            'prepared',
            'live_json_installed_db_active',
            'json_route_sealed',
            'completed',
            'failed_before_route_disable',
            'sealed_resume_required',
        ];
        if (!in_array($state, $allowed, true)) {
            throw new InvalidArgumentException('Production live rollback recovery state is invalid.');
        }
        $identity = $this->identity($gateReport);
        $current = $this->recovery();
        if ($current !== []) {
            foreach (['request_id', 'state_revision', 'state_sha256', 'snapshot_sha256'] as $field) {
                if (($current[$field] ?? null) !== ($identity[$field] ?? null)) {
                    throw new RuntimeException('Production live rollback recovery identity changed.');
                }
            }
        }
        $payload = $identity + [
            'state' => $state,
            'database_route_enabled' => in_array(
                $state,
                ['prepared', 'live_json_installed_db_active', 'failed_before_route_disable'],
                true
            ),
            'json_write_block_active' => in_array(
                $state,
                ['live_json_installed_db_active', 'json_route_sealed', 'sealed_resume_required'],
                true
            ),
            'maintenance_enabled' => $state !== 'completed',
            'financial_read_only' => $state !== 'completed',
            'updated_at_utc' => gmdate(DATE_ATOM),
        ] + $extra;
        $this->atomicJsonWrite($this->recoveryFile, $payload);
        return $this->recovery();
    }

    public function writeCutoverSealed(array $gateReport): array
    {
        $identity = $this->identity($gateReport);
        $cutover = $this->readJsonObject($this->cutoverFile, 'Production cutover state');
        $cutover['state'] = 'rolled_back_json_sealed';
        $cutover['database_runtime_published'] = false;
        $cutover['json_write_block_active'] = true;
        $cutover['maintenance_active'] = true;
        $cutover['financial_read_only_active'] = true;
        $cutover['rollback_driver'] = 'json';
        $cutover['live_rollback'] = $identity + [
            'state' => 'json_sealed',
            'updated_at_utc' => gmdate(DATE_ATOM),
        ];
        $this->atomicJsonWrite($this->cutoverFile, $cutover);
        return $this->readJsonObject($this->cutoverFile, 'Production cutover state');
    }

    public function writeCutoverCompleted(array $gateReport): array
    {
        $identity = $this->identity($gateReport);
        $cutover = $this->readJsonObject($this->cutoverFile, 'Production cutover state');
        $cutover['state'] = 'rolled_back';
        $cutover['database_runtime_published'] = false;
        $cutover['json_write_block_active'] = false;
        $cutover['maintenance_active'] = false;
        $cutover['financial_read_only_active'] = false;
        $cutover['rollback_driver'] = 'json';
        $cutover['rolled_back_at_utc'] = gmdate(DATE_ATOM);
        $cutover['live_rollback'] = $identity + [
            'state' => 'completed',
            'updated_at_utc' => gmdate(DATE_ATOM),
        ];
        $this->atomicJsonWrite($this->cutoverFile, $cutover);
        return $this->readJsonObject($this->cutoverFile, 'Production cutover state');
    }

    public function restoreAuthorizedCutoverBackup(
        string $requestId,
        string $expectedFingerprint
    ): array {
        $requestId = $this->exactRequestId($requestId);
        $expectedFingerprint = $this->exactSha($expectedFingerprint);
        $backup = $this->privateJsonFile(
            $this->cutoverBackupPath($requestId),
            'Production cutover rollback backup is unavailable.'
        );
        if ($expectedFingerprint === '' || !hash_equals($expectedFingerprint, $this->fileSha($backup))) {
            throw new RuntimeException('Production cutover rollback backup fingerprint is invalid.');
        }
        $value = $this->readJsonObject($backup, 'Production cutover rollback backup');
        $this->atomicJsonWrite($this->cutoverFile, $value);
        if (!hash_equals($expectedFingerprint, $this->cutoverFingerprint())) {
            throw new RuntimeException('Production cutover rollback backup restoration failed.');
        }
        return ['ok' => true, 'cutover_restored' => true];
    }

    private function identity(array $gateReport): array
    {
        if (($gateReport['ready'] ?? false) !== true
            || ($gateReport['contract_version'] ?? '')
                !== ProductionPrimaryLiveRollbackGate::CONTRACT_VERSION) {
            throw new RuntimeException('Production live rollback state requires a passed gate.');
        }
        $requestId = $this->exactRequestId($gateReport['request_id'] ?? null);
        $revision = filter_var(
            $gateReport['expected_state_revision'] ?? null,
            FILTER_VALIDATE_INT
        );
        $stateSha = $this->exactSha($gateReport['expected_state_sha256'] ?? null);
        $snapshotSha = $this->exactSha($gateReport['expected_snapshot_sha256'] ?? null);
        if (!is_int($revision) || $revision < 1 || $stateSha === '' || $snapshotSha === '') {
            throw new RuntimeException('Production live rollback state identity is invalid.');
        }
        return [
            'contract_version' => ProductionPrimaryLiveRollbackGate::CONTRACT_VERSION,
            'request_id' => $requestId,
            'backup_id' => (string)($gateReport['expected_backup_id'] ?? ''),
            'state_revision' => $revision,
            'state_sha256' => $stateSha,
            'snapshot_sha256' => $snapshotSha,
            'activation_plan_fingerprint' => (string)(
                $gateReport['activation_plan_fingerprint'] ?? ''
            ),
            'activation_source_fingerprint' => (string)(
                $gateReport['activation_source_fingerprint'] ?? ''
            ),
        ];
    }

    private function cutoverBackupPath(string $requestId): string
    {
        return $this->privateDir . '/production-live-rollback.cutover.before-' . $requestId . '.json';
    }

    private function atomicJsonWrite(string $path, array $value): void
    {
        if (dirname($path) !== $this->privateDir) {
            throw new RuntimeException('Production live rollback state target is outside the private directory.');
        }
        $raw = json_encode(
            $this->canonicalize($value),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        ) . "\n";
        $temporary = $this->privateDir . '/.live-rollback-state-' . bin2hex(random_bytes(8)) . '.tmp';
        try {
            $this->writeNewPrivateFile($temporary, $raw);
            $this->readJsonObject($temporary, 'Production live rollback temporary state');
            if (!rename($temporary, $path)) {
                throw new RuntimeException('Production live rollback state could not be published atomically.');
            }
            if (!chmod($path, 0600)) {
                throw new RuntimeException('Production live rollback state permissions failed.');
            }
        } catch (Throwable $error) {
            if (is_file($temporary) || is_link($temporary)) @unlink($temporary);
            throw $error;
        }
    }

    private function writeNewPrivateFile(string $path, string $raw): void
    {
        $handle = fopen($path, 'xb');
        if ($handle === false) {
            throw new RuntimeException('Production live rollback private file could not be created.');
        }
        try {
            if (!chmod($path, 0600)) {
                throw new RuntimeException('Production live rollback private file permissions failed.');
            }
            $written = fwrite($handle, $raw);
            if (!is_int($written) || $written !== strlen($raw) || !fflush($handle)) {
                throw new RuntimeException('Production live rollback private file write failed.');
            }
            if (function_exists('fsync') && !fsync($handle)) {
                throw new RuntimeException('Production live rollback private file sync failed.');
            }
        } finally {
            fclose($handle);
        }
    }

    private function readJsonObject(string $path, string $label): array
    {
        clearstatcache(true, $path);
        $size = filesize($path);
        if (!is_int($size) || $size < 2 || $size > self::MAX_JSON_BYTES) {
            throw new RuntimeException($label . ' size is invalid.');
        }
        $raw = file_get_contents($path);
        if (!is_string($raw) || strlen($raw) !== $size) {
            throw new RuntimeException($label . ' could not be read exactly.');
        }
        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $error) {
            throw new RuntimeException($label . ' is invalid JSON.', 0, $error);
        }
        if (!is_array($decoded) || array_is_list($decoded)) {
            throw new RuntimeException($label . ' must be an object.');
        }
        return $decoded;
    }

    private function privateJsonFile(string $path, string $message): string
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
            throw new RuntimeException('Production live rollback state files must have exact mode 0600.');
        }
        return $canonical;
    }

    private function canonicalDirectory(string $path): string
    {
        if ($path === '' || str_contains($path, '\\') || !str_starts_with($path, '/')
            || is_link($path) || !is_dir($path)) {
            throw new RuntimeException('Production live rollback private directory is invalid.');
        }
        $canonical = realpath($path);
        if (!is_string($canonical) || !hash_equals($path, $canonical)) {
            throw new RuntimeException('Production live rollback private directory is not canonical.');
        }
        clearstatcache(true, $canonical);
        $mode = fileperms($canonical);
        if (!is_int($mode) || ($mode & 0022) !== 0) {
            throw new RuntimeException('Production live rollback private directory is writable by group/world.');
        }
        return $canonical;
    }

    private function fileSha(string $path): string
    {
        $sha = hash_file('sha256', $path);
        if (!is_string($sha) || preg_match('/\A[a-f0-9]{64}\z/', $sha) !== 1) {
            throw new RuntimeException('Production live rollback file fingerprint is unavailable.');
        }
        return $sha;
    }

    private function exactRequestId(mixed $value): string
    {
        if (!is_string($value) || preg_match('/\A[a-f0-9]{32}\z/', $value) !== 1) {
            throw new RuntimeException('Production live rollback request ID is invalid.');
        }
        return $value;
    }

    private function exactSha(mixed $value): string
    {
        return is_string($value) && preg_match('/\A[a-f0-9]{64}\z/', $value) === 1
            ? $value
            : '';
    }

    private function canonicalize(mixed $value): mixed
    {
        if (!is_array($value)) return $value;
        if (!array_is_list($value)) ksort($value, SORT_STRING);
        foreach ($value as $key => $child) $value[$key] = $this->canonicalize($child);
        return $value;
    }
}
