<?php
declare(strict_types=1);

trait SealedSnapshotEnvironmentTrait
{
    private function assertSafeEnvironment(): string
    {
        $environment = strtolower(trim((string)($this->config['environment'] ?? 'production')));
        if (!in_array($environment, ['staging', 'local'], true)) {
            throw new RuntimeException('Sealed snapshot control is allowed only in staging or local.');
        }
        $driver = strtolower(trim((string)($this->config['storage_driver'] ?? 'json'))) ?: 'json';
        if ($driver !== 'json') {
            throw new RuntimeException('Global JSON storage must remain active during the rehearsal.');
        }
        return $environment;
    }

    private function dataDirectory(): string
    {
        $directory = rtrim(str_replace('\\', '/', trim((string)($this->config['data_dir'] ?? ''))), '/');
        if ($directory === '' || !is_dir($directory)) {
            throw new RuntimeException('JSON data directory is unavailable.');
        }
        return $directory;
    }

    private function writeBlockFile(): string
    {
        return $this->dataDirectory() . '/.cutover-write-block';
    }
}
