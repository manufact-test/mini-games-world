<?php
declare(strict_types=1);

final class RuntimePrimaryStagingActivationEvidenceLoader
{
    private const MAX_BYTES = 524288;

    public function __construct(
        private string $projectRoot,
        private string $privateDir
    ) {
        if ($this->projectRoot === ''
            || str_contains($this->projectRoot, '\\')
            || !str_starts_with($this->projectRoot, '/')
            || ($this->projectRoot !== '/' && str_ends_with($this->projectRoot, '/'))
            || is_link($this->projectRoot)) {
            throw new InvalidArgumentException(
                'Staging activation evidence project root must be an exact canonical Linux directory.'
            );
        }
        if ($this->privateDir === ''
            || str_contains($this->privateDir, '\\')
            || !str_starts_with($this->privateDir, '/')
            || ($this->privateDir !== '/' && str_ends_with($this->privateDir, '/'))
            || is_link($this->privateDir)) {
            throw new InvalidArgumentException(
                'Staging activation evidence private directory must be an exact canonical Linux directory.'
            );
        }

        $canonicalProject = realpath($this->projectRoot);
        $canonicalPrivate = realpath($this->privateDir);
        if (!is_string($canonicalProject)
            || !is_dir($canonicalProject)
            || !hash_equals($this->projectRoot, $canonicalProject)) {
            throw new InvalidArgumentException(
                'Staging activation evidence project root is unavailable or noncanonical.'
            );
        }
        if (!is_string($canonicalPrivate)
            || !is_dir($canonicalPrivate)
            || !hash_equals($this->privateDir, $canonicalPrivate)) {
            throw new InvalidArgumentException(
                'Staging activation evidence private directory is unavailable or noncanonical.'
            );
        }
        if ($this->privateDir === $this->projectRoot
            || str_starts_with($this->privateDir, $this->projectRoot . '/')) {
            throw new InvalidArgumentException(
                'Staging activation evidence private directory must remain outside the project.'
            );
        }
        clearstatcache(true, $this->privateDir);
        $privateMode = fileperms($this->privateDir);
        if (!is_int($privateMode) || ($privateMode & 0022) !== 0) {
            throw new InvalidArgumentException(
                'Staging activation evidence private directory must not be group/world writable.'
            );
        }
    }

    public function load(string $path): array
    {
        if ($path === ''
            || str_contains($path, '\\')
            || !str_starts_with($path, '/')
            || str_ends_with($path, '/')) {
            throw new RuntimeException(
                'Staging activation evidence path must be an exact absolute Linux file path.'
            );
        }
        if (is_link($path)) {
            throw new RuntimeException('Staging activation evidence file must not be a symbolic link.');
        }
        $canonical = realpath($path);
        if (!is_string($canonical) || !is_file($canonical) || !is_readable($canonical)) {
            throw new RuntimeException('Staging activation evidence file is unavailable or unreadable.');
        }
        if (!hash_equals($path, $canonical)) {
            throw new RuntimeException('Staging activation evidence file must use its exact canonical path.');
        }
        $parent = dirname($canonical);
        if (!hash_equals($this->privateDir, $parent)) {
            throw new RuntimeException('Staging activation evidence file must remain in the verified private directory.');
        }
        if ($canonical === $this->projectRoot || str_starts_with($canonical, $this->projectRoot . '/')) {
            throw new RuntimeException('Staging activation evidence file must remain outside the deployed project.');
        }

        clearstatcache(true, $canonical);
        $size = filesize($canonical);
        if (!is_int($size) || $size < 2 || $size > self::MAX_BYTES) {
            throw new RuntimeException('Staging activation evidence file size is invalid.');
        }
        $permissions = fileperms($canonical);
        if (!is_int($permissions) || ($permissions & 0777) !== 0600) {
            throw new RuntimeException('Staging activation evidence file permissions must be 0600.');
        }

        $raw = file_get_contents($canonical);
        if (!is_string($raw) || strlen($raw) !== $size) {
            throw new RuntimeException('Staging activation evidence file could not be read completely.');
        }
        try {
            $manifest = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $error) {
            throw new RuntimeException('Staging activation evidence JSON is invalid.', 0, $error);
        }
        if (!is_array($manifest) || array_is_list($manifest)) {
            throw new RuntimeException('Staging activation evidence must be a JSON object.');
        }

        return [
            'manifest' => $manifest,
            'file_sha256' => hash('sha256', $raw),
            'bytes' => $size,
            'permissions' => '0600',
            'path_exposed' => false,
            'production_changed' => false,
            'sensitive_identifiers_exposed' => false,
        ];
    }
}
