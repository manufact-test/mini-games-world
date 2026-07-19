<?php
declare(strict_types=1);

trait SealedSnapshotControlReadTrait
{
    private function readControl(): array
    {
        if (!is_file($this->controlFile)) return [];
        $raw = file_get_contents($this->controlFile);
        if (!is_string($raw) || trim($raw) === '') {
            throw new RuntimeException('Cutover rehearsal control file is empty.');
        }
        $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($data)) {
            throw new RuntimeException('Cutover rehearsal control must be an object.');
        }
        return $data;
    }
}
