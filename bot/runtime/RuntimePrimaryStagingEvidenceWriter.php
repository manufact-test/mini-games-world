<?php
declare(strict_types=1);

final class RuntimePrimaryStagingEvidenceWriter
{
    public function __construct(private string $projectRoot)
    {
        $this->projectRoot = rtrim(str_replace('\\', '/', trim($this->projectRoot)), '/');
        if ($this->projectRoot === '' || !is_dir($this->projectRoot)) {
            throw new InvalidArgumentException('Staging evidence writer project root is unavailable.');
        }
    }

    public function validateOutputPath(string $outputPath): array
    {
        $outputPath = str_replace('\\', '/', trim($outputPath));
        if ($outputPath === '' || !str_starts_with($outputPath, '/')) {
            throw new InvalidArgumentException('Staging evidence output path must be absolute.');
        }
        if (basename($outputPath) === '' || in_array(basename($outputPath), ['.', '..'], true)) {
            throw new InvalidArgumentException('Staging evidence output file name is invalid.');
        }
        if (is_link($outputPath)) {
            throw new RuntimeException('Staging evidence output must not be a symbolic link.');
        }
        if (file_exists($outputPath)) {
            throw new RuntimeException('Staging evidence output already exists and will not be overwritten.');
        }

        $parent = realpath(dirname($outputPath));
        if (!is_string($parent) || !is_dir($parent)) {
            throw new RuntimeException('Staging evidence output directory is unavailable.');
        }
        $parent = rtrim(str_replace('\\', '/', $parent), '/');
        if ($parent === $this->projectRoot || str_starts_with($parent, $this->projectRoot . '/')) {
            throw new RuntimeException('Staging evidence output must remain outside the deployed project directory.');
        }
        if (!is_writable($parent)) {
            throw new RuntimeException('Staging evidence output directory is not writable.');
        }

        $canonicalOutput = $parent . '/' . basename($outputPath);
        if (!hash_equals($canonicalOutput, $outputPath)) {
            throw new RuntimeException('Staging evidence output path must be canonical.');
        }

        return [
            'output_path' => $canonicalOutput,
            'parent' => $parent,
            'path_exposed' => false,
        ];
    }

    public function write(string $outputPath, array $manifest): array
    {
        $validated = $this->validateOutputPath($outputPath);
        $outputPath = (string)$validated['output_path'];
        $parent = (string)$validated['parent'];

        $json = json_encode(
            $manifest,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        ) . PHP_EOL;
        if (strlen($json) > 512 * 1024) {
            throw new RuntimeException('Staging evidence manifest exceeds 512 KiB.');
        }
        $fingerprint = hash('sha256', $json);
        $temporary = $parent . '/.' . basename($outputPath) . '.tmp-' . bin2hex(random_bytes(12));
        $handle = null;
        $published = false;

        try {
            $previousUmask = umask(0077);
            try {
                $handle = fopen($temporary, 'x+b');
            } finally {
                umask($previousUmask);
            }
            if (!is_resource($handle)) {
                throw new RuntimeException('Staging evidence temporary file could not be created.');
            }
            if (!chmod($temporary, 0600)) {
                throw new RuntimeException('Staging evidence temporary file permissions could not be secured.');
            }

            $offset = 0;
            $length = strlen($json);
            while ($offset < $length) {
                $written = fwrite($handle, substr($json, $offset));
                if (!is_int($written) || $written < 1) {
                    throw new RuntimeException('Staging evidence temporary file write failed.');
                }
                $offset += $written;
            }
            if (!fflush($handle)) {
                throw new RuntimeException('Staging evidence temporary file flush failed.');
            }
            if (function_exists('fsync') && !fsync($handle)) {
                throw new RuntimeException('Staging evidence temporary file fsync failed.');
            }
            if (fseek($handle, 0) !== 0) {
                throw new RuntimeException('Staging evidence temporary file rewind failed.');
            }
            $readBack = stream_get_contents($handle);
            if (!is_string($readBack) || !hash_equals($fingerprint, hash('sha256', $readBack))) {
                throw new RuntimeException('Staging evidence temporary file verification failed.');
            }
            fclose($handle);
            $handle = null;

            clearstatcache(true, $outputPath);
            if (file_exists($outputPath) || is_link($outputPath)) {
                throw new RuntimeException('Staging evidence output appeared during atomic write.');
            }
            if (!function_exists('link')) {
                throw new RuntimeException('Atomic no-clobber evidence publication is unavailable.');
            }
            if (!link($temporary, $outputPath)) {
                throw new RuntimeException('Staging evidence atomic no-clobber publication failed.');
            }
            $published = true;

            if (!chmod($outputPath, 0600)) {
                @unlink($outputPath);
                $published = false;
                throw new RuntimeException('Staging evidence output permissions could not be secured.');
            }

            $final = file_get_contents($outputPath);
            if (!is_string($final) || !hash_equals($fingerprint, hash('sha256', $final))) {
                @unlink($outputPath);
                $published = false;
                throw new RuntimeException('Staging evidence output verification failed.');
            }
            $permissions = fileperms($outputPath);
            if (!is_int($permissions) || ($permissions & 0777) !== 0600) {
                @unlink($outputPath);
                $published = false;
                throw new RuntimeException('Staging evidence output permissions are not 0600.');
            }

            if (!unlink($temporary)) {
                @unlink($outputPath);
                $published = false;
                throw new RuntimeException('Staging evidence temporary publication link cleanup failed.');
            }

            return [
                'ok' => true,
                'written' => true,
                'bytes' => strlen($final),
                'file_sha256' => $fingerprint,
                'permissions' => '0600',
                'publish_mode' => 'atomic_no_clobber_link',
                'path_exposed' => false,
                'production_changed' => false,
                'sensitive_identifiers_exposed' => false,
            ];
        } finally {
            if (is_resource($handle)) fclose($handle);
            if (is_file($temporary)) @unlink($temporary);
            if ($published && (!is_file($outputPath) || is_link($outputPath))) {
                @unlink($outputPath);
            }
        }
    }
}
