<?php
declare(strict_types=1);

trait SealedSnapshotWriteBlockTrait
{
    private function activateWriteBlock(array $control): void
    {
        $handle = fopen($this->dataDirectory() . '/app.lock', 'c+');
        if ($handle === false) throw new RuntimeException('Could not open JSON storage lock.');
        try {
            if (!flock($handle, LOCK_EX)) throw new RuntimeException('Could not lock JSON storage for sealing.');
            $payload = json_encode([
                'environment' => (string)($control['environment'] ?? ''),
                'rehearsal_id' => (string)($control['rehearsal_id'] ?? ''),
                'sealed_at_utc' => (string)($control['sealed_at_utc'] ?? $this->nowUtc()),
            ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
            if (file_put_contents($this->writeBlockFile(), $payload, LOCK_EX) === false) {
                throw new RuntimeException('Could not activate JSON write block.');
            }
            @chmod($this->writeBlockFile(), 0600);
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    private function removeWriteBlock(): void
    {
        $handle = fopen($this->dataDirectory() . '/app.lock', 'c+');
        if ($handle === false) throw new RuntimeException('Could not open JSON storage lock.');
        try {
            if (!flock($handle, LOCK_EX)) throw new RuntimeException('Could not lock JSON storage for release.');
            if (is_file($this->writeBlockFile()) && !unlink($this->writeBlockFile())) {
                throw new RuntimeException('Could not remove JSON write block.');
            }
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }
}
