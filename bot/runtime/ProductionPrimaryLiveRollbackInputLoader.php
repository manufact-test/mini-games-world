<?php
declare(strict_types=1);

require_once __DIR__ . '/../core/RuntimeConfigLoader.php';
require_once __DIR__ . '/../core/DatabaseConfigLoader.php';
require_once __DIR__ . '/../database/DatabaseConfig.php';

final class ProductionPrimaryLiveRollbackInputLoader
{
    private const MAX_JSON_BYTES = 262_144;
    private const DATA_FILES = [
        'users.json', 'games.json', 'queue.json', 'transactions.json',
        'support.json', 'shop_orders.json', 'payments.json',
        'notifications.json', 'invites.json', 'system.json',
    ];

    private string $projectRoot;

    public function __construct(
        string $projectRoot,
        private ProductionPrimaryRollbackArtifactIdentity $artifactIdentity
    ) {
        $this->projectRoot = $this->canonicalDirectory(
            $projectRoot,
            'Production project root is unavailable.'
        );
    }

    public function load(
        string $configFile,
        string $cutoverFile,
        string $authorizationFile,
        string $exportDir,
        string $liveDataDir
    ): array {
        $configFile = $this->privateFile(
            $configFile,
            'Production private config is unavailable.'
        );
        $privateDir = dirname($configFile);
        $this->assertOutsideProject($privateDir, 'Production private directory');
        $this->assertDirectoryNotWritable($privateDir, 'Production private directory');

        $runtimeFile = $this->privateFile(
            $privateDir . '/runtime.php',
            'Production runtime overlay is unavailable.'
        );
        $cutoverFile = $this->privateFile(
            $cutoverFile,
            'Production cutover state is unavailable.'
        );
        $authorizationFile = $this->privateFile(
            $authorizationFile,
            'Production live rollback authorization is unavailable.'
        );
        if (dirname($runtimeFile) !== $privateDir
            || dirname($cutoverFile) !== $privateDir
            || dirname($authorizationFile) !== $privateDir
            || basename($runtimeFile) !== 'runtime.php'
            || basename($cutoverFile) !== 'production-cutover.json'
            || basename($authorizationFile)
                !== 'production-live-rollback-authorization.json') {
            throw new RuntimeException(
                'Production live rollback inputs must use exact private filenames.'
            );
        }

        $databaseOverride = trim((string)(getenv('MGW_DATABASE_CONFIG_FILE') ?: ''));
        $databaseFile = $databaseOverride !== ''
            ? $databaseOverride
            : $privateDir . '/database.php';
        $databaseFile = $this->privateFile(
            $databaseFile,
            'Production database config is unavailable.'
        );
        if (dirname($databaseFile) !== $privateDir
            || basename($databaseFile) !== 'database.php') {
            throw new RuntimeException(
                'Production database config must be the exact private database.php file.'
            );
        }

        $exportDir = $this->canonicalDirectory(
            $exportDir,
            'Production rollback export is unavailable.'
        );
        $this->assertDirectoryMode($exportDir, 0700, 'Production rollback export');
        $this->assertOutsideProject($exportDir, 'Production rollback export');

        $liveDataDir = $this->canonicalDirectory(
            $liveDataDir,
            'Production live JSON directory is unavailable.'
        );
        $this->assertDirectoryMode($liveDataDir, 0700, 'Production live JSON directory');
        $this->assertOutsideProject($liveDataDir, 'Production live JSON directory');
        if ($liveDataDir === $privateDir
            || $exportDir === $privateDir
            || $exportDir === $liveDataDir
            || $this->isInside($exportDir, $liveDataDir)
            || $this->isInside($liveDataDir, $exportDir)) {
            throw new RuntimeException(
                'Production live rollback directories must remain separate.'
            );
        }
        $this->assertLiveDataDirectory($liveDataDir);

        $baseConfig = $this->requireArray($configFile, 'Production private config');
        $config = RuntimeConfigLoader::merge($baseConfig, $configFile);
        $config = DatabaseConfigLoader::merge($config, $configFile);
        $databaseConfig = DatabaseConfig::fromApplicationConfig($config);
        if (!$databaseConfig->enabled()) {
            throw new RuntimeException('Production live rollback requires an enabled database config.');
        }
        $databaseIdentity = $databaseConfig->identityFingerprint();

        $cutover = $this->readJsonObject($cutoverFile, 'Production cutover state');
        $authorization = $this->readJsonObject(
            $authorizationFile,
            'Production live rollback authorization'
        );
        $artifact = $this->artifactIdentity->inspect($exportDir);
        if (($artifact['database_identity_fingerprint'] ?? '') !== $databaseIdentity) {
            throw new RuntimeException(
                'Production rollback export belongs to another database identity.'
            );
        }

        $cutoverFingerprint = $this->fileSha($cutoverFile);
        $runtimeFingerprint = $this->fileSha($runtimeFile);
        $liveFingerprint = hash('sha256', $liveDataDir);
        $exportFingerprint = hash('sha256', $exportDir);
        foreach ([
            'cutover_state_fingerprint' => $cutoverFingerprint,
            'runtime_config_fingerprint' => $runtimeFingerprint,
            'live_data_directory_fingerprint' => $liveFingerprint,
            'export_directory_fingerprint' => $exportFingerprint,
            'database_identity_fingerprint' => $databaseIdentity,
        ] as $field => $expected) {
            $actual = $authorization[$field] ?? null;
            if (!is_string($actual)
                || preg_match('/\A[a-f0-9]{64}\z/', $actual) !== 1
                || !hash_equals($expected, $actual)) {
                throw new RuntimeException(
                    'Production live rollback authorization fingerprint mismatch: ' . $field . '.'
                );
            }
        }

        return [
            'config' => $config,
            'cutover' => $cutover,
            'authorization' => $authorization,
            'artifact' => $artifact,
            'config_file' => $configFile,
            'runtime_file' => $runtimeFile,
            'database_file' => $databaseFile,
            'cutover_file' => $cutoverFile,
            'authorization_file' => $authorizationFile,
            'private_dir' => $privateDir,
            'export_dir' => $exportDir,
            'live_data_dir' => $liveDataDir,
            'database_identity_fingerprint' => $databaseIdentity,
            'cutover_state_fingerprint' => $cutoverFingerprint,
            'runtime_config_fingerprint' => $runtimeFingerprint,
            'live_data_directory_fingerprint' => $liveFingerprint,
            'export_directory_fingerprint' => $exportFingerprint,
            'config_fingerprint' => $this->fileSha($configFile),
            'database_config_fingerprint' => $this->fileSha($databaseFile),
            'authorization_fingerprint' => $this->fileSha($authorizationFile),
            'paths_exposed' => false,
            'database_contacted' => false,
            'database_write_executed' => false,
            'persistent_config_changed' => false,
            'production_changed' => false,
        ];
    }

    private function assertLiveDataDirectory(string $directory): void
    {
        $actual = [];
        foreach (new DirectoryIterator($directory) as $item) {
            if ($item->isDot()) continue;
            $name = $item->getFilename();
            if ($item->isLink() || (!$item->isFile() && !$item->isDir())) {
                throw new RuntimeException('Production live JSON directory contains an unsafe entry.');
            }
            $actual[] = $name;
        }
        foreach (self::DATA_FILES as $file) {
            $this->assertPrivateDataFile($directory . '/' . $file, $file);
        }
        $this->assertPrivateDataFile($directory . '/app.lock', 'app.lock');
        if (is_file($directory . '/.cutover-write-block')
            || is_link($directory . '/.cutover-write-block')) {
            throw new RuntimeException(
                'Production live JSON must not be sealed before live rollback starts.'
            );
        }
    }

    private function assertPrivateDataFile(string $path, string $label): void
    {
        if (is_link($path) || !is_file($path)) {
            throw new RuntimeException('Production live JSON file is unavailable: ' . $label . '.');
        }
        $canonical = realpath($path);
        if (!is_string($canonical) || !hash_equals($path, $canonical)) {
            throw new RuntimeException('Production live JSON file is not canonical: ' . $label . '.');
        }
        clearstatcache(true, $path);
        $mode = fileperms($path);
        if (!is_int($mode) || ($mode & 0777) !== 0600) {
            throw new RuntimeException(
                'Production live JSON file must have exact mode 0600: ' . $label . '.'
            );
        }
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
            throw new RuntimeException($label . ' must be a JSON object.');
        }
        return $decoded;
    }

    private function privateFile(string $path, string $message): string
    {
        if ($path === '' || str_contains($path, '\\') || !str_starts_with($path, '/')
            || is_link($path) || !is_file($path) || !is_readable($path)) {
            throw new RuntimeException($message);
        }
        $canonical = realpath($path);
        if (!is_string($canonical) || !hash_equals($path, $canonical)) {
            throw new RuntimeException($message);
        }
        clearstatcache(true, $canonical);
        $mode = fileperms($canonical);
        if (!is_int($mode) || ($mode & 0777) !== 0600) {
            throw new RuntimeException('Production live rollback input files must have exact mode 0600.');
        }
        return $canonical;
    }

    private function canonicalDirectory(string $path, string $message): string
    {
        if ($path === '' || str_contains($path, '\\') || !str_starts_with($path, '/')
            || is_link($path) || !is_dir($path)) {
            throw new RuntimeException($message);
        }
        $canonical = realpath($path);
        if (!is_string($canonical) || !hash_equals($path, $canonical)) {
            throw new RuntimeException($message);
        }
        return $canonical;
    }

    private function assertOutsideProject(string $path, string $label): void
    {
        if ($this->isInside($path, $this->projectRoot)) {
            throw new RuntimeException($label . ' must remain outside the deployed project.');
        }
    }

    private function assertDirectoryNotWritable(string $path, string $label): void
    {
        clearstatcache(true, $path);
        $mode = fileperms($path);
        if (!is_int($mode) || ($mode & 0022) !== 0) {
            throw new RuntimeException($label . ' must not be group/world writable.');
        }
    }

    private function assertDirectoryMode(string $path, int $expected, string $label): void
    {
        clearstatcache(true, $path);
        $mode = fileperms($path);
        if (!is_int($mode) || ($mode & 0777) !== $expected) {
            throw new RuntimeException(
                $label . ' must have exact mode ' . sprintf('%04o', $expected) . '.'
            );
        }
    }

    private function fileSha(string $path): string
    {
        $sha = hash_file('sha256', $path);
        if (!is_string($sha) || preg_match('/\A[a-f0-9]{64}\z/', $sha) !== 1) {
            throw new RuntimeException('Production live rollback input fingerprint is unavailable.');
        }
        return $sha;
    }

    private function isInside(string $path, string $parent): bool
    {
        return $path === $parent || str_starts_with($path . '/', $parent . '/');
    }
}
