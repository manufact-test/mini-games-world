<?php
declare(strict_types=1);

final class BackupManager
{
    private const FORMAT_VERSION = 1;

    public function __construct(
        private string $projectRoot,
        private string $dataDir,
        private string $backupRoot,
        private ?string $externalDir = null,
        private int $retentionDays = 7,
        private int $retentionCount = 7,
        private bool $includeReleaseFiles = true
    ) {
        $this->projectRoot = $this->normalizePath($this->projectRoot);
        $this->dataDir = $this->normalizePath($this->dataDir);
        $this->backupRoot = $this->normalizePath($this->backupRoot);
        $this->externalDir = $this->externalDir !== null && trim($this->externalDir) !== ''
            ? $this->normalizePath($this->externalDir)
            : null;
        $this->retentionDays = max(1, $this->retentionDays);
        $this->retentionCount = max(1, $this->retentionCount);

        if ($this->pathsEqual($this->backupRoot, $this->dataDir)) {
            throw new RuntimeException('Backup root must be different from the live data directory.');
        }
        if ($this->isPathInside($this->backupRoot, $this->projectRoot)) {
            throw new RuntimeException('Backup root must be outside the deployed project directory.');
        }
        if ($this->externalDir !== null) {
            if ($this->pathsEqual($this->externalDir, $this->backupRoot)) {
                throw new RuntimeException('External backup directory must be different from the primary backup directory.');
            }
            if ($this->pathsEqual($this->externalDir, $this->dataDir)) {
                throw new RuntimeException('External backup directory must be different from the live data directory.');
            }
            if ($this->isPathInside($this->externalDir, $this->projectRoot)) {
                throw new RuntimeException('External backup directory must be outside the deployed project directory.');
            }
        }
    }

    public function create(string $environment, string $build): array
    {
        $this->ensureDirectory($this->backupRoot);
        if ($this->externalDir !== null) {
            $this->ensureDirectory($this->externalDir);
        }

        $backupId = gmdate('Ymd\THis\Z') . '-' . bin2hex(random_bytes(4));
        $temporaryDir = $this->backupRoot . '/.partial-' . $backupId;
        $finalDir = $this->backupRoot . '/' . $backupId;
        $startedAt = gmdate(DATE_ATOM);

        if (file_exists($temporaryDir) || file_exists($finalDir)) {
            throw new RuntimeException('Backup target already exists.');
        }

        $this->ensureDirectory($temporaryDir . '/data');

        try {
            $dataSummary = $this->copyDataSnapshot($temporaryDir . '/data');
            $releaseSummary = ['files' => 0, 'bytes' => 0];
            if ($this->includeReleaseFiles) {
                $this->ensureDirectory($temporaryDir . '/release');
                $releaseSummary = $this->copyReleaseSnapshot($temporaryDir . '/release');
            }

            $checksumEntries = $this->checksumEntries($temporaryDir, ['manifest.json', 'checksums.sha256', 'COMPLETE']);
            $checksumText = $this->renderChecksums($checksumEntries);
            $snapshotHash = hash('sha256', $checksumText);
            $this->writeFile($temporaryDir . '/checksums.sha256', $checksumText);

            $manifest = [
                'format_version' => self::FORMAT_VERSION,
                'backup_id' => $backupId,
                'created_at_utc' => gmdate(DATE_ATOM),
                'environment' => trim($environment) !== '' ? trim($environment) : 'unknown',
                'build' => trim($build) !== '' ? trim($build) : 'unknown',
                'snapshot_sha256' => $snapshotHash,
                'data' => $dataSummary,
                'release' => [
                    'included' => $this->includeReleaseFiles,
                    'files' => $releaseSummary['files'],
                    'bytes' => $releaseSummary['bytes'],
                ],
                'external_copy_requested' => $this->externalDir !== null,
            ];
            $manifestJson = $this->encodeJson($manifest);
            $this->writeFile($temporaryDir . '/manifest.json', $manifestJson . PHP_EOL);

            $complete = [
                'backup_id' => $backupId,
                'manifest_sha256' => hash('sha256', $manifestJson . PHP_EOL),
                'completed_at_utc' => gmdate(DATE_ATOM),
            ];
            $this->writeFile($temporaryDir . '/COMPLETE', $this->encodeJson($complete) . PHP_EOL);

            if (!rename($temporaryDir, $finalDir)) {
                throw new RuntimeException('Could not finalize the backup directory.');
            }

            $verified = $this->verify($finalDir);
            $externalResult = $this->copyToExternal($finalDir, $backupId);
            $this->applyRetention($this->backupRoot);
            if ($this->externalDir !== null) {
                $this->applyRetention($this->externalDir);
            }

            $result = [
                'ok' => true,
                'backup_id' => $backupId,
                'created_at_utc' => $manifest['created_at_utc'],
                'data_files' => $dataSummary['files'],
                'data_bytes' => $dataSummary['bytes'],
                'release_files' => $releaseSummary['files'],
                'release_bytes' => $releaseSummary['bytes'],
                'snapshot_sha256' => $verified['snapshot_sha256'],
                'external_copy' => $externalResult,
            ];
            $this->writeStatus($this->backupRoot, $result);
            if ($this->externalDir !== null && ($externalResult['copied'] ?? false) === true) {
                $this->writeStatus($this->externalDir, $result);
            }
            return $result;
        } catch (Throwable $e) {
            if (is_dir($temporaryDir)) {
                $this->removeDirectory($temporaryDir);
            }
            if (is_dir($finalDir)) {
                try {
                    $this->applyRetention($this->backupRoot);
                } catch (Throwable) {
                    // Preserve the original backup failure.
                }
            }
            $failure = [
                'ok' => false,
                'backup_id' => $backupId,
                'started_at_utc' => $startedAt,
                'failed_at_utc' => gmdate(DATE_ATOM),
                'error' => $e->getMessage(),
            ];
            try {
                $this->writeStatus($this->backupRoot, $failure);
            } catch (Throwable) {
                // Preserve the original backup failure.
            }
            throw $e;
        }
    }

    public function verify(string $snapshotDir): array
    {
        $snapshotDir = $this->normalizePath($snapshotDir);
        $manifestPath = $snapshotDir . '/manifest.json';
        $checksumsPath = $snapshotDir . '/checksums.sha256';
        $completePath = $snapshotDir . '/COMPLETE';

        foreach ([$manifestPath, $checksumsPath, $completePath] as $required) {
            if (!is_file($required)) {
                throw new RuntimeException('Backup is incomplete: missing ' . basename($required) . '.');
            }
        }

        $manifestRaw = $this->readFile($manifestPath);
        $manifest = json_decode($manifestRaw, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($manifest) || (int)($manifest['format_version'] ?? 0) !== self::FORMAT_VERSION) {
            throw new RuntimeException('Unsupported backup manifest format.');
        }

        $completeRaw = $this->readFile($completePath);
        $complete = json_decode($completeRaw, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($complete)) {
            throw new RuntimeException('Invalid COMPLETE marker.');
        }
        if (!hash_equals((string)($complete['manifest_sha256'] ?? ''), hash('sha256', $manifestRaw))) {
            throw new RuntimeException('Manifest checksum mismatch.');
        }

        $checksumsRaw = $this->readFile($checksumsPath);
        $expectedSnapshotHash = (string)($manifest['snapshot_sha256'] ?? '');
        if ($expectedSnapshotHash === '' || !hash_equals($expectedSnapshotHash, hash('sha256', $checksumsRaw))) {
            throw new RuntimeException('Snapshot checksum index mismatch.');
        }

        $verifiedFiles = 0;
        $verifiedBytes = 0;
        foreach (preg_split('/\R/', trim($checksumsRaw)) ?: [] as $line) {
            if ($line === '') {
                continue;
            }
            if (!preg_match('/^([a-f0-9]{64})  (.+)$/', $line, $matches)) {
                throw new RuntimeException('Invalid checksum line.');
            }
            $relativePath = $this->safeRelativePath($matches[2]);
            $path = $snapshotDir . '/' . $relativePath;
            if (!is_file($path) || is_link($path)) {
                throw new RuntimeException('Backup file is missing or unsafe: ' . $relativePath);
            }
            $actualHash = hash_file('sha256', $path);
            if (!is_string($actualHash) || !hash_equals($matches[1], $actualHash)) {
                throw new RuntimeException('Backup file checksum mismatch: ' . $relativePath);
            }
            if (str_starts_with($relativePath, 'data/') && str_ends_with($relativePath, '.json')) {
                $this->assertJsonArray($this->readFile($path), $relativePath);
            }
            $verifiedFiles++;
            $verifiedBytes += (int)(filesize($path) ?: 0);
        }

        return [
            'ok' => true,
            'backup_id' => (string)($manifest['backup_id'] ?? basename($snapshotDir)),
            'snapshot_sha256' => $expectedSnapshotHash,
            'verified_files' => $verifiedFiles,
            'verified_bytes' => $verifiedBytes,
            'manifest' => $manifest,
        ];
    }

    public function restore(string $snapshotDir, string $targetDataDir): array
    {
        $snapshotDir = $this->normalizePath($snapshotDir);
        $targetDataDir = $this->normalizePath($targetDataDir);
        $verified = $this->verify($snapshotDir);

        if ($this->pathsEqual($targetDataDir, $this->dataDir)) {
            throw new RuntimeException('Restore target must not be the live data directory.');
        }
        if ($this->isPathInside($targetDataDir, $this->projectRoot)) {
            throw new RuntimeException('Restore target must be outside the deployed project directory.');
        }
        if ($this->isPathInside($targetDataDir, $snapshotDir) || $this->isPathInside($snapshotDir, $targetDataDir)) {
            throw new RuntimeException('Restore target must be separate from the backup snapshot.');
        }
        if (file_exists($targetDataDir)) {
            $entries = is_dir($targetDataDir) ? array_diff(scandir($targetDataDir) ?: [], ['.', '..']) : ['file'];
            if ($entries !== []) {
                throw new RuntimeException('Restore target must not exist or must be empty.');
            }
            if (!is_dir($targetDataDir)) {
                throw new RuntimeException('Restore target is not a directory.');
            }
            if (!rmdir($targetDataDir)) {
                throw new RuntimeException('Could not prepare the empty restore target.');
            }
        }

        $temporaryDir = dirname($targetDataDir) . '/.restore-partial-' . basename($targetDataDir) . '-' . bin2hex(random_bytes(3));
        if (file_exists($temporaryDir)) {
            throw new RuntimeException('Temporary restore directory already exists.');
        }

        try {
            $this->ensureDirectory($temporaryDir);
            $sourceDataDir = $snapshotDir . '/data';
            if (!is_dir($sourceDataDir)) {
                throw new RuntimeException('Backup does not contain a data directory.');
            }
            $this->copyDirectory($sourceDataDir, $temporaryDir);
            $this->writeFile($temporaryDir . '/app.lock', '');

            $restoredFiles = 0;
            $restoredBytes = 0;
            foreach (glob($temporaryDir . '/*.json') ?: [] as $jsonFile) {
                $this->assertJsonArray($this->readFile($jsonFile), basename($jsonFile));
                $restoredFiles++;
                $restoredBytes += (int)(filesize($jsonFile) ?: 0);
            }
            if ($restoredFiles === 0) {
                throw new RuntimeException('Restore produced no JSON files.');
            }

            $metadata = [
                'backup_id' => $verified['backup_id'],
                'snapshot_sha256' => $verified['snapshot_sha256'],
                'restored_at_utc' => gmdate(DATE_ATOM),
                'restored_files' => $restoredFiles,
                'restored_bytes' => $restoredBytes,
            ];
            $this->writeFile($temporaryDir . '/.restore.json', $this->encodeJson($metadata) . PHP_EOL);

            if (!rename($temporaryDir, $targetDataDir)) {
                throw new RuntimeException('Could not finalize the restore directory.');
            }

            return ['ok' => true, 'target_ready' => true] + $metadata;
        } catch (Throwable $e) {
            if (is_dir($temporaryDir)) {
                $this->removeDirectory($temporaryDir);
            }
            throw $e;
        }
    }

    public function latestSnapshot(string $root): string
    {
        $root = $this->normalizePath($root);
        $snapshots = $this->completedSnapshots($root);
        if ($snapshots === []) {
            throw new RuntimeException('No completed backups were found.');
        }
        return $snapshots[0]['path'];
    }

    private function copyDataSnapshot(string $targetDir): array
    {
        if (!is_dir($this->dataDir)) {
            throw new RuntimeException('Live data directory does not exist.');
        }

        $lockPath = $this->dataDir . '/app.lock';
        $lockHandle = fopen($lockPath, 'c+');
        if ($lockHandle === false) {
            throw new RuntimeException('Could not open the live data lock file.');
        }

        try {
            if (!flock($lockHandle, LOCK_SH)) {
                throw new RuntimeException('Could not acquire a shared storage lock.');
            }

            $files = glob($this->dataDir . '/*.json') ?: [];
            sort($files, SORT_STRING);
            if ($files === []) {
                throw new RuntimeException('Live data directory contains no JSON files.');
            }

            $count = 0;
            $bytes = 0;
            foreach ($files as $source) {
                if (!is_file($source) || is_link($source)) {
                    continue;
                }
                $raw = $this->readFile($source);
                $this->assertJsonArray($raw, basename($source));
                $target = $targetDir . '/' . basename($source);
                $this->writeFile($target, $raw);
                $count++;
                $bytes += strlen($raw);
            }

            if ($count === 0) {
                throw new RuntimeException('No safe JSON files were copied.');
            }

            flock($lockHandle, LOCK_UN);
            fclose($lockHandle);
            return ['files' => $count, 'bytes' => $bytes];
        } catch (Throwable $e) {
            flock($lockHandle, LOCK_UN);
            fclose($lockHandle);
            throw $e;
        }
    }

    private function copyReleaseSnapshot(string $targetDir): array
    {
        $files = 0;
        $bytes = 0;
        foreach (['app', 'bot', 'ops', 'site'] as $directory) {
            $source = $this->projectRoot . '/' . $directory;
            if (!is_dir($source)) {
                continue;
            }
            $summary = $this->copyReleaseDirectory($source, $targetDir . '/' . $directory, $directory);
            $files += $summary['files'];
            $bytes += $summary['bytes'];
        }

        foreach (['.htaccess', 'index.php', 'robots.txt'] as $file) {
            $source = $this->projectRoot . '/' . $file;
            if (!is_file($source) || is_link($source)) {
                continue;
            }
            $this->ensureDirectory(dirname($targetDir . '/' . $file));
            if (!copy($source, $targetDir . '/' . $file)) {
                throw new RuntimeException('Could not copy release file: ' . $file);
            }
            $files++;
            $bytes += (int)(filesize($source) ?: 0);
        }

        return ['files' => $files, 'bytes' => $bytes];
    }

    private function copyReleaseDirectory(string $sourceDir, string $targetDir, string $rootLabel): array
    {
        $files = 0;
        $bytes = 0;
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($sourceDir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::LEAVES_ONLY
        );

        foreach ($iterator as $item) {
            if (!$item->isFile() || $item->isLink()) {
                continue;
            }
            $sourcePath = $item->getPathname();
            $relativeWithinRoot = ltrim(str_replace('\\', '/', substr($sourcePath, strlen($sourceDir))), '/');
            $projectRelative = $rootLabel . '/' . $relativeWithinRoot;
            if ($this->releasePathIsExcluded($projectRelative)) {
                continue;
            }
            $targetPath = $targetDir . '/' . $relativeWithinRoot;
            $this->ensureDirectory(dirname($targetPath));
            if (!copy($sourcePath, $targetPath)) {
                throw new RuntimeException('Could not copy release file: ' . $projectRelative);
            }
            $files++;
            $bytes += (int)(filesize($sourcePath) ?: 0);
        }

        return ['files' => $files, 'bytes' => $bytes];
    }

    private function releasePathIsExcluded(string $relativePath): bool
    {
        $relativePath = str_replace('\\', '/', $relativePath);
        $excludedExact = [
            'bot/config/config.php',
            'bot/config/config.local.php',
            'bot/config/runtime.php',
        ];
        if (in_array($relativePath, $excludedExact, true)) {
            return true;
        }
        foreach (['bot/data/', '_private_mgw/', 'mgw_data/', 'mgw_staging_data/'] as $prefix) {
            if (str_starts_with($relativePath, $prefix)) {
                return true;
            }
        }
        return basename($relativePath) === '.env' || basename($relativePath) === '.htpasswd';
    }

    private function copyToExternal(string $snapshotDir, string $backupId): array
    {
        if ($this->externalDir === null) {
            return ['requested' => false, 'copied' => false];
        }

        $temporaryDir = $this->externalDir . '/.partial-' . $backupId;
        $finalDir = $this->externalDir . '/' . $backupId;
        if (file_exists($temporaryDir) || file_exists($finalDir)) {
            throw new RuntimeException('External backup target already exists.');
        }

        try {
            $this->ensureDirectory($temporaryDir);
            $this->copyDirectory($snapshotDir, $temporaryDir);
            if (!rename($temporaryDir, $finalDir)) {
                throw new RuntimeException('Could not finalize the external backup copy.');
            }
            $verified = $this->verify($finalDir);
            return [
                'requested' => true,
                'copied' => true,
                'snapshot_sha256' => $verified['snapshot_sha256'],
            ];
        } catch (Throwable $e) {
            foreach ([$temporaryDir, $finalDir] as $failedDir) {
                if (!is_dir($failedDir)) {
                    continue;
                }
                try {
                    $this->removeDirectory($failedDir);
                } catch (Throwable) {
                    // Preserve the original external-copy failure.
                }
            }
            throw $e;
        }
    }

    private function checksumEntries(string $root, array $excludedBasenames): array
    {
        $entries = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::LEAVES_ONLY
        );
        foreach ($iterator as $item) {
            if (!$item->isFile() || $item->isLink()) {
                continue;
            }
            $relative = ltrim(str_replace('\\', '/', substr($item->getPathname(), strlen($root))), '/');
            if (in_array(basename($relative), $excludedBasenames, true)) {
                continue;
            }
            $hash = hash_file('sha256', $item->getPathname());
            if (!is_string($hash)) {
                throw new RuntimeException('Could not hash backup file: ' . $relative);
            }
            $entries[$relative] = $hash;
        }
        ksort($entries, SORT_STRING);
        return $entries;
    }

    private function renderChecksums(array $entries): string
    {
        $lines = [];
        foreach ($entries as $path => $hash) {
            $lines[] = $hash . '  ' . $this->safeRelativePath((string)$path);
        }
        return implode("\n", $lines) . "\n";
    }

    private function applyRetention(string $root): void
    {
        $snapshots = $this->completedSnapshots($root);
        $now = time();
        foreach ($snapshots as $index => $snapshot) {
            if ($index === 0) {
                continue;
            }
            $tooMany = $index >= $this->retentionCount;
            $tooOld = ($now - $snapshot['timestamp']) > ($this->retentionDays * 86400);
            if ($tooMany || $tooOld) {
                $this->removeDirectory($snapshot['path']);
            }
        }
    }

    private function completedSnapshots(string $root): array
    {
        if (!is_dir($root)) {
            return [];
        }
        $snapshots = [];
        foreach (scandir($root) ?: [] as $name) {
            if ($name === '.' || $name === '..' || str_starts_with($name, '.')) {
                continue;
            }
            $path = $root . '/' . $name;
            if (!is_dir($path) || !is_file($path . '/COMPLETE') || !is_file($path . '/manifest.json')) {
                continue;
            }
            $manifest = json_decode($this->readFile($path . '/manifest.json'), true);
            $created = is_array($manifest) ? strtotime((string)($manifest['created_at_utc'] ?? '')) : false;
            $snapshots[] = [
                'path' => $path,
                'name' => $name,
                'timestamp' => $created !== false ? $created : (filemtime($path) ?: 0),
            ];
        }
        usort($snapshots, static fn(array $a, array $b): int => $b['timestamp'] <=> $a['timestamp'] ?: strcmp($b['name'], $a['name']));
        return $snapshots;
    }

    private function writeStatus(string $root, array $status): void
    {
        $this->ensureDirectory($root);
        $statusJson = $this->encodeJson($status) . PHP_EOL;
        $this->writeFile($root . '/backup-status.json', $statusJson);
        if (file_put_contents($root . '/backup-history.jsonl', $statusJson, FILE_APPEND | LOCK_EX) === false) {
            throw new RuntimeException('Could not append backup history.');
        }
    }

    private function copyDirectory(string $source, string $target): void
    {
        if (!is_dir($source)) {
            throw new RuntimeException('Source directory does not exist.');
        }
        $this->ensureDirectory($target);
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($iterator as $item) {
            $relative = ltrim(str_replace('\\', '/', substr($item->getPathname(), strlen($source))), '/');
            $destination = $target . '/' . $relative;
            if ($item->isLink()) {
                throw new RuntimeException('Symlinks are not allowed in backup snapshots.');
            }
            if ($item->isDir()) {
                $this->ensureDirectory($destination);
                continue;
            }
            $this->ensureDirectory(dirname($destination));
            if (!copy($item->getPathname(), $destination)) {
                throw new RuntimeException('Could not copy file: ' . $relative);
            }
        }
    }

    private function removeDirectory(string $path): void
    {
        if (!is_dir($path) || is_link($path)) {
            return;
        }
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $item) {
            if ($item->isDir() && !$item->isLink()) {
                if (!rmdir($item->getPathname())) {
                    throw new RuntimeException('Could not remove backup directory during cleanup.');
                }
            } else {
                if (!unlink($item->getPathname())) {
                    throw new RuntimeException('Could not remove backup file during cleanup.');
                }
            }
        }
        if (!rmdir($path)) {
            throw new RuntimeException('Could not remove backup directory during cleanup.');
        }
    }

    private function ensureDirectory(string $path): void
    {
        if (is_dir($path)) {
            return;
        }
        if (!mkdir($path, 0750, true) && !is_dir($path)) {
            throw new RuntimeException('Could not create directory: ' . basename($path));
        }
    }

    private function writeFile(string $path, string $content): void
    {
        $this->ensureDirectory(dirname($path));
        if (file_put_contents($path, $content, LOCK_EX) === false) {
            throw new RuntimeException('Could not write file: ' . basename($path));
        }
        @chmod($path, 0640);
    }

    private function readFile(string $path): string
    {
        $raw = file_get_contents($path);
        if ($raw === false) {
            throw new RuntimeException('Could not read file: ' . basename($path));
        }
        return $raw;
    }

    private function assertJsonArray(string $raw, string $label): void
    {
        $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) {
            throw new RuntimeException('JSON data file must contain an array: ' . $label);
        }
    }

    private function encodeJson(array $value): string
    {
        return json_encode(
            $value,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR
        );
    }

    private function safeRelativePath(string $path): string
    {
        $path = str_replace('\\', '/', trim($path));
        if ($path === '' || str_starts_with($path, '/') || preg_match('/^[A-Za-z]:\//', $path)) {
            throw new RuntimeException('Unsafe backup path.');
        }
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                throw new RuntimeException('Unsafe backup path segment.');
            }
        }
        return $path;
    }

    private function normalizePath(string $path): string
    {
        $path = trim($path);
        if ($path === '') {
            throw new RuntimeException('Filesystem path must not be empty.');
        }
        $path = str_replace('\\', '/', $path);
        if (!str_starts_with($path, '/') && !preg_match('/^[A-Za-z]:\//', $path)) {
            $path = str_replace('\\', '/', getcwd() . '/' . $path);
        }
        $parts = [];
        $prefix = str_starts_with($path, '/') ? '/' : substr($path, 0, 3);
        $tail = str_starts_with($path, '/') ? substr($path, 1) : substr($path, 3);
        foreach (explode('/', $tail) as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }
            if ($part === '..') {
                array_pop($parts);
                continue;
            }
            $parts[] = $part;
        }
        return rtrim($prefix . implode('/', $parts), '/');
    }

    private function pathsEqual(string $a, string $b): bool
    {
        return rtrim($a, '/') === rtrim($b, '/');
    }

    private function isPathInside(string $candidate, string $parent): bool
    {
        $candidate = rtrim($candidate, '/') . '/';
        $parent = rtrim($parent, '/') . '/';
        return str_starts_with($candidate, $parent);
    }
}
