<?php
declare(strict_types=1);

final class RuntimePrimaryStagingActivationEvidenceLoader
{
    private const MAX_BYTES = 524288;

    public function __construct(
        private string $projectRoot,
        private string $privateDir
    ) {
        $this->projectRoot = rtrim(str_replace('\\', '/', trim($this->projectRoot)), '/');
        $this->privateDir = rtrim(str_replace('\\', '/', trim($this->privateDir)), '/');
        if ($this->projectRoot === '' || !is_dir($this->projectRoot)) {
            throw new InvalidArgumentException('Staging activation evidence project root is unavailable.');
        }
        if ($this->privateDir === '' || !is_dir($this->privateDir)) {
            throw new InvalidArgumentException('Staging activation evidence private directory is unavailable.');
        }
    }

    public function load(string $path): array
    {
        $path = str_replace('\\', '/', trim($path));
        if ($path === '' || !str_starts_with($path, '/')) {
            throw new RuntimeException('Staging activation evidence path must be absolute.');
        }
        if (is_link($path)) {
            throw new RuntimeException('Staging activation evidence file must not be a symbolic link.');
        }
        $canonical = realpath($path);
        if (!is_string($canonical) || !is_file($canonical) || !is_readable($canonical)) {
            throw new RuntimeException('Staging activation evidence file is unavailable or unreadable.');
        }
        $canonical = str_replace('\\', '/', $canonical);
        $parent = rtrim(str_replace('\\', '/', dirname($canonical)), '/');
        if (!hash_equals($this->privateDir, $parent)) {
            throw new RuntimeException('Staging activation evidence file must remain in the verified private directory.');
        }
        if ($canonical === $this->projectRoot || str_starts_with($canonical, $this->projectRoot . '/')) {
            throw new RuntimeException('Staging activation evidence file must remain outside the deployed project.');
        }

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
