<?php
declare(strict_types=1);

final class RuntimePrimaryPrivateConfigGuard
{
    public static function assertExternal(string $configFile, string $projectRoot): array
    {
        if ($configFile === ''
            || str_contains($configFile, '\\')
            || !str_starts_with($configFile, '/')) {
            throw new RuntimeException('Active staging config path must be an exact absolute Linux path.');
        }
        if ($projectRoot === ''
            || str_contains($projectRoot, '\\')
            || !str_starts_with($projectRoot, '/')) {
            throw new RuntimeException('Staging project root must be an exact absolute Linux path.');
        }
        if (($configFile !== '/' && str_ends_with($configFile, '/'))
            || ($projectRoot !== '/' && str_ends_with($projectRoot, '/'))) {
            throw new RuntimeException('Staging config and project paths must not contain trailing separators.');
        }
        if (is_link($configFile) || is_link($projectRoot)) {
            throw new RuntimeException('Staging config and project root must not be symbolic links.');
        }

        $canonicalConfig = realpath($configFile);
        $canonicalProject = realpath($projectRoot);
        if (!is_string($canonicalConfig) || !is_file($canonicalConfig) || !is_readable($canonicalConfig)) {
            throw new RuntimeException('Active staging config is unavailable or unreadable.');
        }
        if (!is_string($canonicalProject) || !is_dir($canonicalProject)) {
            throw new RuntimeException('Staging project root is unavailable.');
        }
        if (!hash_equals($configFile, $canonicalConfig)) {
            throw new RuntimeException('Active staging config must use its exact canonical path.');
        }
        if (!hash_equals($projectRoot, $canonicalProject)) {
            throw new RuntimeException('Staging project root must use its exact canonical path.');
        }

        clearstatcache(true, $canonicalConfig);
        $configMode = fileperms($canonicalConfig);
        if (!is_int($configMode) || ($configMode & 0777) !== 0600) {
            throw new RuntimeException('Active staging config must have exact mode 0600.');
        }

        $privateDir = realpath(dirname($canonicalConfig));
        if (!is_string($privateDir) || !is_dir($privateDir) || is_link($privateDir)) {
            throw new RuntimeException('Active staging private config directory is unavailable or unsafe.');
        }
        if ($privateDir === '' || $privateDir === '/') {
            throw new RuntimeException('Active staging private config directory is unsafe.');
        }
        clearstatcache(true, $privateDir);
        $privateMode = fileperms($privateDir);
        if (!is_int($privateMode) || ($privateMode & 0022) !== 0) {
            throw new RuntimeException('Active staging private config directory must not be group/world writable.');
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
            'config_mode' => '0600',
            'private_dir_not_group_world_writable' => true,
            'path_exposed' => false,
        ];
    }
}
