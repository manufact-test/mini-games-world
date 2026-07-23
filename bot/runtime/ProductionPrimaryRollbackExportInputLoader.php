<?php
declare(strict_types=1);

require_once __DIR__ . '/../core/RuntimeConfigLoader.php';
require_once __DIR__ . '/../core/DatabaseConfigLoader.php';

final class ProductionPrimaryRollbackExportInputLoader
{
    private const MAX_JSON_BYTES = 262_144;

    public function __construct(private string $projectRoot)
    {
        $this->projectRoot = $this->canonicalDirectory(
            $projectRoot,
            'Production project root is unavailable.'
        );
    }

    public function load(
        string $configFile,
        string $cutoverFile,
        string $authorizationFile,
        string $outputRoot
    ): array {
        $configFile = $this->privateFile(
            $configFile,
            'Production private config is unavailable.'
        );
        $privateDir = dirname($configFile);
        $this->assertOutsideProject($privateDir, 'Production private directory');
        $this->assertDirectoryNotWritable($privateDir, 'Production private directory');

        $cutoverFile = $this->privateFile(
            $cutoverFile,
            'Production cutover state is unavailable.'
        );
        $authorizationFile = $this->privateFile(
            $authorizationFile,
            'Production rollback authorization is unavailable.'
        );
        if (dirname($cutoverFile) !== $privateDir
            || dirname($authorizationFile) !== $privateDir) {
            throw new RuntimeException(
                'Production rollback inputs must share the exact private config directory.'
            );
        }
        if (basename($cutoverFile) !== 'production-cutover.json') {
            throw new RuntimeException('Production cutover state filename is invalid.');
        }
        if (basename($authorizationFile)
            !== 'production-rollback-export-authorization.json') {
            throw new RuntimeException('Production rollback authorization filename is invalid.');
        }

        $outputRoot = $this->canonicalDirectory(
            $outputRoot,
            'Production rollback export root is unavailable.'
        );
        $this->assertOutsideProject($outputRoot, 'Production rollback export root');
        $this->assertDirectoryMode($outputRoot, 0700, 'Production rollback export root');
        if ($outputRoot === $privateDir) {
            throw new RuntimeException(
                'Production rollback export root must be a dedicated private directory.'
            );
        }

        $runtimeFile = $privateDir . '/runtime.php';
        if (file_exists($runtimeFile) || is_link($runtimeFile)) {
            $runtimeFile = $this->privateFile(
                $runtimeFile,
                'Production runtime overlay is unavailable.'
            );
        }

        $databaseOverride = trim((string)(getenv('MGW_DATABASE_CONFIG_FILE') ?: ''));
        $databaseFile = $databaseOverride !== ''
            ? $databaseOverride
            : $privateDir . '/database.php';
        if (file_exists($databaseFile) || is_link($databaseFile)) {
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
        } elseif ($databaseOverride !== '') {
            throw new RuntimeException('Production database config override is unavailable.');
        }

        $baseConfig = $this->requireArray($configFile, 'Production private config');
        $config = RuntimeConfigLoader::merge($baseConfig, $configFile);
        $config = DatabaseConfigLoader::merge($config, $configFile);
        if (!is_array($config)) {
            throw new RuntimeException('Production runtime config is invalid.');
        }

        $cutover = $this->readJsonObject($cutoverFile, 'Production cutover state');
        $authorization = $this->readJsonObject(
            $authorizationFile,
            'Production rollback authorization'
        );

        return [
            'config' => $config,
            'cutover' => $cutover,
            'authorization' => $authorization,
            'config_file' => $configFile,
            'cutover_file' => $cutoverFile,
            'authorization_file' => $authorizationFile,
            'private_dir' => $privateDir,
            'output_root' => $outputRoot,
            'output_root_fingerprint' => hash('sha256', $outputRoot),
            'config_fingerprint' => $this->fileSha($configFile),
            'cutover_fingerprint' => $this->fileSha($cutoverFile),
            'authorization_fingerprint' => $this->fileSha($authorizationFile),
            'database_config_loaded' => ($config['database_config_loaded'] ?? false) === true,
            'database_config_fingerprint' => is_file($privateDir . '/database.php')
                ? $this->fileSha($privateDir . '/database.php')
                : '',
            'paths_exposed' => false,
            'persistent_config_changed' => false,
            'production_changed' => false,
        ];
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
        $path = $this->exactAbsolutePath($path, $message);
        if (is_link($path) || !is_file($path) || !is_readable($path)) {
            throw new RuntimeException($message);
        }
        $canonical = realpath($path);
        if (!is_string($canonical) || !hash_equals($path, $canonical)) {
            throw new RuntimeException($message);
        }
        clearstatcache(true, $canonical);
        $mode = fileperms($canonical);
        if (!is_int($mode) || ($mode & 0777) !== 0600) {
            throw new RuntimeException('Production rollback input files must have exact mode 0600.');
        }
        return $canonical;
    }

    private function canonicalDirectory(string $path, string $message): string
    {
        $path = $this->exactAbsolutePath($path, $message);
        if (is_link($path) || !is_dir($path)) throw new RuntimeException($message);
        $canonical = realpath($path);
        if (!is_string($canonical) || !hash_equals($path, $canonical)) {
            throw new RuntimeException($message);
        }
        return $canonical;
    }

    private function exactAbsolutePath(string $path, string $message): string
    {
        if ($path === ''
            || str_contains($path, '\\')
            || !str_starts_with($path, '/')
            || ($path !== '/' && str_ends_with($path, '/'))) {
            throw new RuntimeException($message);
        }
        return $path;
    }

    private function assertOutsideProject(string $path, string $label): void
    {
        if ($path === $this->projectRoot
            || str_starts_with($path . '/', $this->projectRoot . '/')) {
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
            throw new RuntimeException('Production rollback input fingerprint is unavailable.');
        }
        return $sha;
    }
}
