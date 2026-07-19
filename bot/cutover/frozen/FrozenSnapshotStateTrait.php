<?php
declare(strict_types=1);

trait FrozenSnapshotStateTrait
{
    private function assertSafeEnvironment(): string
    {
        $environment = strtolower(trim((string)($this->config['environment'] ?? 'production')));
        if (!in_array($environment, ['staging', 'local'], true)) {
            throw new RuntimeException('Frozen snapshot rehearsal is allowed only in staging or local.');
        }
        $driver = strtolower(trim((string)($this->config['storage_driver'] ?? 'json'))) ?: 'json';
        if ($driver !== 'json') {
            throw new RuntimeException('Global JSON storage must remain active during frozen snapshot rehearsal.');
        }
        return $environment;
    }

    private function readState(): array
    {
        if (!is_file($this->stateFile)) return [];
        $raw = file_get_contents($this->stateFile);
        if (!is_string($raw) || trim($raw) === '') throw new RuntimeException('Frozen snapshot state is empty.');
        $state = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($state)) throw new RuntimeException('Frozen snapshot state must be an object.');
        return $state;
    }

    private function writeState(array $state): void
    {
        $directory = dirname($this->stateFile);
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new RuntimeException('Could not create frozen snapshot state directory.');
        }
        $temporary = $this->stateFile . '.tmp-' . bin2hex(random_bytes(5));
        $encoded = json_encode(
            $state,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR
        ) . PHP_EOL;
        if (file_put_contents($temporary, $encoded, LOCK_EX) === false) throw new RuntimeException('Could not write frozen snapshot state.');
        @chmod($temporary, 0600);
        if (!rename($temporary, $this->stateFile)) {
            @unlink($temporary);
            throw new RuntimeException('Could not activate frozen snapshot state.');
        }
        @chmod($this->stateFile, 0600);
    }

    private function nowUtc(): string { return gmdate(DATE_ATOM); }

    private function withFingerprint(array $report): array
    {
        unset($report['report_fingerprint']);
        $json = json_encode($report, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $report['report_fingerprint'] = hash('sha256', $json);
        return $report;
    }
}
