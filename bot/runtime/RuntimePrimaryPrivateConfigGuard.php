<?php
declare(strict_types=1);

final class RuntimePrimaryPrivateConfigGuard
{
    public static function assertExternal(string $configFile, string $projectRoot): array
    {
        $configFile = str_replace('\\', '/', trim($configFile));
        $projectRoot = str_replace('\\', '/', trim($projectRoot));
        if ($configFile === '' || !str_starts_with($configFile, '/')) {
            throw new RuntimeException('Active staging config path must be absolute.');
        }
        if ($projectRoot === '' || !str_starts_with($projectRoot, '/')) {
            throw new RuntimeException('Staging project root path must be absolute.');
        }
        if (is_link($configFile)) {
            throw new RuntimeException('Active staging config must not be a symbolic link.');
        }

        $canonicalConfig = realpath($configFile);
        $canonicalProject = realpath($projectRoot);
        if (!is_string($canonicalConfig) || !is_file($canonicalConfig) || !is_readable($canonicalConfig)) {
            throw new RuntimeException('Active staging config is unavailable or unreadable.');
        }
        if (!is_string($canonicalProject) || !is_dir($canonicalProject)) {
            throw new RuntimeException('Staging project root is unavailable.');
        }

        $canonicalConfig = str_replace('\\', '/', $canonicalConfig);
        $canonicalProject = rtrim(str_replace('\\', '/', $canonicalProject), '/');
        $privateDir = realpath(dirname($canonicalConfig));
        if (!is_string($privateDir) || !is_dir($privateDir)) {
            throw new RuntimeException('Active staging private config directory is unavailable.');
        }
        $privateDir = rtrim(str_replace('\\', '/', $privateDir), '/');
        if ($privateDir === '' || $privateDir === '/') {
            throw new RuntimeException('Active staging private config directory is unsafe.');
        }
        if ($privateDir === $canonicalProject || str_starts_with($privateDir, $canonicalProject . '/')) {
            throw new RuntimeException('Staging evidence requires an external private config outside the deployed project.');
        }

        $fingerprint = hash_file('sha256', $canonicalConfig);
        if (!is_string($fingerprint) || preg_match('/^[a-f0-9]{64}$/', $fingerprint) !== 1) {
            throw new RuntimeException('Active staging config fingerprint is unavailable.');
        }

        return [
            'private_dir' => $privateDir,
            'config_fingerprint' => $fingerprint,
            'config_external' => true,
            'path_exposed' => false,
        ];
    }
}
