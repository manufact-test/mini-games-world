<?php
declare(strict_types=1);

final class StagingE2eSourceFingerprint
{
    public function __construct(
        private string $rootDirectory,
        private string $manifestPath
    ) {}

    public function calculate(): array
    {
        $root = rtrim($this->rootDirectory, '/\\');
        if ($root === '' || !is_dir($root)) {
            throw new RuntimeException('Staging E2E fingerprint root is unavailable.');
        }
        if (!is_file($this->manifestPath)) {
            throw new RuntimeException('Staging E2E fingerprint manifest is unavailable.');
        }

        $lines = file($this->manifestPath, FILE_IGNORE_NEW_LINES);
        if (!is_array($lines)) {
            throw new RuntimeException('Staging E2E fingerprint manifest cannot be read.');
        }

        $hashes = [];
        foreach ($lines as $line) {
            $path = trim((string)$line);
            if ($path === '' || str_starts_with($path, '#')) continue;
            if (str_contains($path, '..')
                || str_starts_with($path, '/')
                || preg_match('/\A[A-Za-z0-9._\/-]+\z/', $path) !== 1) {
                throw new RuntimeException('Staging E2E fingerprint manifest contains an unsafe path.');
            }
            if (isset($hashes[$path])) {
                throw new RuntimeException('Staging E2E fingerprint manifest contains duplicate paths.');
            }

            $absolutePath = $root . '/' . $path;
            if (!is_file($absolutePath)) {
                throw new RuntimeException('Staging E2E runtime source is incomplete: ' . $path);
            }
            $hash = hash_file('sha256', $absolutePath);
            if (!is_string($hash) || preg_match('/\A[a-f0-9]{64}\z/', $hash) !== 1) {
                throw new RuntimeException('Staging E2E runtime source cannot be fingerprinted: ' . $path);
            }
            $hashes[$path] = $hash;
        }

        if ($hashes === []) {
            throw new RuntimeException('Staging E2E fingerprint manifest is empty.');
        }

        $parts = [];
        foreach ($hashes as $path => $hash) {
            $parts[] = $path . ':' . $hash;
        }

        return [
            'sha256' => hash('sha256', implode("\n", $parts)),
            'file_count' => count($hashes),
            'files' => $hashes,
        ];
    }
}
