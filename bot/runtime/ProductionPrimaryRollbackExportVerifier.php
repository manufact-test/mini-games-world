<?php
declare(strict_types=1);

final class ProductionPrimaryRollbackExportVerifier
{
    public const FORMAT_VERSION = 1;

    private const DATA_FILES = [
        'users' => 'users.json',
        'games' => 'games.json',
        'queue' => 'queue.json',
        'transactions' => 'transactions.json',
        'support' => 'support.json',
        'shop_orders' => 'shop_orders.json',
        'payments' => 'payments.json',
        'notifications' => 'notifications.json',
        'invites' => 'invites.json',
        'system' => 'system.json',
    ];

    public function verify(string $snapshotDir): array
    {
        $snapshotDir = $this->canonicalDirectory($snapshotDir);
        $this->assertDirectoryMode($snapshotDir, 0700, 'Rollback export directory');
        $dataDir = $snapshotDir . '/data';
        if (is_link($dataDir) || !is_dir($dataDir)) {
            throw new RuntimeException('Rollback export data directory is unavailable.');
        }
        $canonicalData = realpath($dataDir);
        if (!is_string($canonicalData) || !hash_equals($dataDir, $canonicalData)) {
            throw new RuntimeException('Rollback export data directory is not canonical.');
        }
        $this->assertDirectoryMode($dataDir, 0700, 'Rollback export data directory');

        $required = [
            'manifest.json',
            'checksums.sha256',
            'COMPLETE',
            'rollback.json',
        ];
        foreach (self::DATA_FILES as $file) $required[] = 'data/' . $file;
        sort($required, SORT_STRING);

        $actual = $this->relativeFiles($snapshotDir);
        if ($actual !== $required) {
            throw new RuntimeException('Rollback export file set is not exact.');
        }
        foreach ($required as $relative) {
            $this->assertPrivateFile($snapshotDir . '/' . $relative, $relative);
        }

        $manifestRaw = $this->readExact($snapshotDir . '/manifest.json');
        $manifest = $this->decodeObject($manifestRaw, 'Rollback export manifest');
        if (($manifest['format_version'] ?? null) !== self::FORMAT_VERSION) {
            throw new RuntimeException('Rollback export manifest format is unsupported.');
        }
        if (($manifest['environment'] ?? null) !== 'production'
            || ($manifest['source'] ?? null) !== 'database_primary'
            || ($manifest['release']['included'] ?? null) !== false
            || ($manifest['external_copy_requested'] ?? null) !== false) {
            throw new RuntimeException('Rollback export manifest identity is invalid.');
        }

        $completeRaw = $this->readExact($snapshotDir . '/COMPLETE');
        $complete = $this->decodeObject($completeRaw, 'Rollback export COMPLETE marker');
        $manifestSha = $this->exactSha($complete['manifest_sha256'] ?? null);
        if ($manifestSha === '' || !hash_equals($manifestSha, hash('sha256', $manifestRaw))) {
            throw new RuntimeException('Rollback export manifest checksum mismatch.');
        }
        if (($complete['backup_id'] ?? null) !== ($manifest['backup_id'] ?? null)) {
            throw new RuntimeException('Rollback export COMPLETE identity mismatch.');
        }

        $checksumsRaw = $this->readExact($snapshotDir . '/checksums.sha256');
        $snapshotSha = $this->exactSha($manifest['snapshot_sha256'] ?? null);
        if ($snapshotSha === '' || !hash_equals($snapshotSha, hash('sha256', $checksumsRaw))) {
            throw new RuntimeException('Rollback export checksum index mismatch.');
        }
        $checksums = $this->parseChecksums($checksumsRaw);
        $expectedChecksumFiles = ['rollback.json'];
        foreach (self::DATA_FILES as $file) $expectedChecksumFiles[] = 'data/' . $file;
        sort($expectedChecksumFiles, SORT_STRING);
        if (array_keys($checksums) !== $expectedChecksumFiles) {
            throw new RuntimeException('Rollback export checksum file set is not exact.');
        }
        $verifiedBytes = 0;
        foreach ($checksums as $relative => $expectedSha) {
            $path = $snapshotDir . '/' . $relative;
            $actualSha = hash_file('sha256', $path);
            if (!is_string($actualSha) || !hash_equals($expectedSha, $actualSha)) {
                throw new RuntimeException('Rollback export checksum mismatch: ' . $relative . '.');
            }
            $verifiedBytes += (int)(filesize($path) ?: 0);
        }

        $rollbackRaw = $this->readExact($snapshotDir . '/rollback.json');
        $rollback = $this->decodeObject($rollbackRaw, 'Rollback export metadata');
        if (($rollback['contract_version'] ?? null)
                !== ProductionPrimaryRollbackExportGate::CONTRACT_VERSION
            || ($rollback['source'] ?? null) !== 'database_primary'
            || ($rollback['environment'] ?? null) !== 'production'
            || ($rollback['build'] ?? null)
                !== ProductionPrimaryRollbackExportGate::ACTIVATION_BUILD) {
            throw new RuntimeException('Rollback export metadata identity is invalid.');
        }

        $revision = filter_var($rollback['state_revision'] ?? null, FILTER_VALIDATE_INT);
        $stateSha = $this->exactSha($rollback['state_sha256'] ?? null);
        $allModuleFingerprint = $this->exactSha($rollback['all_module_fingerprint'] ?? null);
        if (!is_int($revision) || $revision < 1 || $stateSha === '' || $allModuleFingerprint === '') {
            throw new RuntimeException('Rollback export state identity is invalid.');
        }
        foreach ([
            'database_identity_fingerprint',
            'activation_plan_fingerprint',
            'activation_source_fingerprint',
            'authorization_fingerprint',
        ] as $field) {
            if ($this->exactSha($rollback[$field] ?? null) === '') {
                throw new RuntimeException('Rollback export metadata fingerprint is invalid: ' . $field . '.');
            }
        }
        if (($rollback['outbox']['completed_event_count'] ?? null) !== $revision
            || ($rollback['outbox']['min_revision'] ?? null) !== 1
            || ($rollback['outbox']['max_revision'] ?? null) !== $revision
            || ($rollback['outbox']['pending_event_count'] ?? null) !== 0
            || ($rollback['outbox']['processing_event_count'] ?? null) !== 0
            || ($rollback['outbox']['failed_event_count'] ?? null) !== 0) {
            throw new RuntimeException('Rollback export outbox evidence is invalid.');
        }
        if (($rollback['maintenance_enabled'] ?? null) !== true
            || ($rollback['financial_read_only'] ?? null) !== true
            || ($rollback['live_json_changed'] ?? null) !== false
            || ($rollback['production_config_changed'] ?? null) !== false) {
            throw new RuntimeException('Rollback export safety evidence is invalid.');
        }

        $snapshot = $this->readDataSnapshot($snapshotDir);
        $actualStateSha = hash('sha256', $this->canonicalJson($snapshot));
        if (!hash_equals($stateSha, $actualStateSha)) {
            throw new RuntimeException('Rollback export reconstructed state fingerprint mismatch.');
        }

        if (($manifest['data']['files'] ?? null) !== count(self::DATA_FILES)
            || ($manifest['rollback_export']['state_revision'] ?? null) !== $revision
            || ($manifest['rollback_export']['state_sha256'] ?? null) !== $stateSha
            || ($manifest['rollback_export']['contract_version'] ?? null)
                !== ProductionPrimaryRollbackExportGate::CONTRACT_VERSION) {
            throw new RuntimeException('Rollback export manifest state summary is invalid.');
        }

        return [
            'ok' => true,
            'backup_id' => (string)($manifest['backup_id'] ?? ''),
            'snapshot_sha256' => $snapshotSha,
            'state_revision' => $revision,
            'state_sha256' => $stateSha,
            'all_module_fingerprint' => $allModuleFingerprint,
            'verified_files' => count($required),
            'verified_bytes' => $verifiedBytes,
            'backup_manager_compatible' => true,
            'isolated_restore_required' => true,
            'live_json_changed' => false,
            'database_contacted' => false,
            'database_write_executed' => false,
            'production_config_changed' => false,
            'production_changed' => false,
            'sensitive_identifiers_exposed' => false,
        ];
    }

    public function verifiedSnapshot(string $snapshotDir): array
    {
        $this->verify($snapshotDir);
        return $this->readDataSnapshot($this->canonicalDirectory($snapshotDir));
    }

    public function verifyRestoredDataDirectory(
        string $targetDir,
        int $expectedRevision,
        string $expectedStateSha256
    ): array {
        $targetDir = $this->canonicalDirectory($targetDir);
        $this->assertDirectoryMode($targetDir, 0700, 'Rollback restore target');
        $files = [];
        foreach (self::DATA_FILES as $key => $file) {
            $path = $targetDir . '/' . $file;
            $this->assertPrivateFile($path, $file);
            $files[$key] = $this->decodeArray($this->readExact($path), $file);
        }
        $actualSha = hash('sha256', $this->canonicalJson($files));
        if ($expectedRevision < 1
            || $this->exactSha($expectedStateSha256) === ''
            || !hash_equals($expectedStateSha256, $actualSha)) {
            throw new RuntimeException('Rollback restore state fingerprint mismatch.');
        }
        return [
            'ok' => true,
            'state_revision' => $expectedRevision,
            'state_sha256' => $actualSha,
            'data_files' => count(self::DATA_FILES),
            'target_live' => false,
            'production_changed' => false,
        ];
    }

    private function readDataSnapshot(string $snapshotDir): array
    {
        $snapshot = [];
        foreach (self::DATA_FILES as $key => $file) {
            $snapshot[$key] = $this->decodeArray(
                $this->readExact($snapshotDir . '/data/' . $file),
                $file
            );
        }
        return $snapshot;
    }

    private function parseChecksums(string $raw): array
    {
        $entries = [];
        foreach (preg_split('/\R/', trim($raw)) ?: [] as $line) {
            if ($line === '') continue;
            if (preg_match('/\A([a-f0-9]{64})  ([A-Za-z0-9._\/-]+)\z/', $line, $matches) !== 1) {
                throw new RuntimeException('Rollback export checksum line is invalid.');
            }
            $relative = $this->safeRelativePath($matches[2]);
            if (isset($entries[$relative])) {
                throw new RuntimeException('Rollback export checksum path is duplicated.');
            }
            $entries[$relative] = $matches[1];
        }
        ksort($entries, SORT_STRING);
        return $entries;
    }

    private function relativeFiles(string $root): array
    {
        $files = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::LEAVES_ONLY
        );
        foreach ($iterator as $item) {
            if (!$item->isFile() || $item->isLink()) {
                throw new RuntimeException('Rollback export contains an unsafe filesystem entry.');
            }
            $relative = ltrim(str_replace('\\', '/', substr($item->getPathname(), strlen($root))), '/');
            $files[] = $this->safeRelativePath($relative);
        }
        sort($files, SORT_STRING);
        return $files;
    }

    private function canonicalDirectory(string $path): string
    {
        if ($path === ''
            || str_contains($path, '\\')
            || !str_starts_with($path, '/')
            || ($path !== '/' && str_ends_with($path, '/'))
            || is_link($path)
            || !is_dir($path)) {
            throw new RuntimeException('Rollback export path must be an exact absolute directory.');
        }
        $canonical = realpath($path);
        if (!is_string($canonical) || !hash_equals($path, $canonical)) {
            throw new RuntimeException('Rollback export path must use its exact canonical value.');
        }
        return $canonical;
    }

    private function assertDirectoryMode(string $path, int $expected, string $label): void
    {
        clearstatcache(true, $path);
        $mode = fileperms($path);
        if (!is_int($mode) || ($mode & 0777) !== $expected) {
            throw new RuntimeException($label . ' must have exact mode ' . sprintf('%04o', $expected) . '.');
        }
    }

    private function assertPrivateFile(string $path, string $label): void
    {
        if (is_link($path) || !is_file($path)) {
            throw new RuntimeException('Rollback export file is unavailable: ' . $label . '.');
        }
        $canonical = realpath($path);
        if (!is_string($canonical) || !hash_equals($path, $canonical)) {
            throw new RuntimeException('Rollback export file is not canonical: ' . $label . '.');
        }
        clearstatcache(true, $path);
        $mode = fileperms($path);
        if (!is_int($mode) || ($mode & 0777) !== 0600) {
            throw new RuntimeException('Rollback export file must have exact mode 0600: ' . $label . '.');
        }
    }

    private function safeRelativePath(string $path): string
    {
        $path = str_replace('\\', '/', trim($path));
        if ($path === ''
            || str_starts_with($path, '/')
            || str_contains($path, '../')
            || str_contains($path, '/..')
            || str_contains($path, './')
            || str_contains($path, "\0")) {
            throw new RuntimeException('Rollback export relative path is unsafe.');
        }
        return $path;
    }

    private function readExact(string $path): string
    {
        $raw = file_get_contents($path);
        if (!is_string($raw) || $raw === '') {
            throw new RuntimeException('Rollback export file is empty or unreadable: ' . basename($path) . '.');
        }
        return $raw;
    }

    private function decodeObject(string $raw, string $label): array
    {
        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $error) {
            throw new RuntimeException($label . ' is invalid JSON.', 0, $error);
        }
        if (!is_array($decoded) || array_is_list($decoded)) {
            throw new RuntimeException($label . ' must be a JSON object.');
        }
        return $decoded;
    }

    private function decodeArray(string $raw, string $label): array
    {
        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $error) {
            throw new RuntimeException($label . ' is invalid JSON.', 0, $error);
        }
        if (!is_array($decoded)) {
            throw new RuntimeException($label . ' must decode to an array.');
        }
        return $decoded;
    }

    private function exactSha(mixed $value): string
    {
        return is_string($value) && preg_match('/\A[a-f0-9]{64}\z/', $value) === 1
            ? $value
            : '';
    }

    private function canonicalJson(array $value): string
    {
        return json_encode(
            $this->canonicalize($value),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        );
    }

    private function canonicalize(mixed $value): mixed
    {
        if (!is_array($value)) return $value;
        if (!array_is_list($value)) ksort($value, SORT_STRING);
        foreach ($value as $key => $item) $value[$key] = $this->canonicalize($item);
        return $value;
    }
}
