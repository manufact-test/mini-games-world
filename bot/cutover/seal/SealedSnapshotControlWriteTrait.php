<?php
declare(strict_types=1);

trait SealedSnapshotControlWriteTrait
{
    private function writeControl(array $control): void
    {
        $directory = dirname($this->controlFile);
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new RuntimeException('Could not create private cutover directory.');
        }
        $temporary = $this->controlFile . '.tmp-' . bin2hex(random_bytes(5));
        $encoded = json_encode(
            $control,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR
        ) . PHP_EOL;
        if (file_put_contents($temporary, $encoded, LOCK_EX) === false) {
            throw new RuntimeException('Could not write cutover rehearsal control.');
        }
        @chmod($temporary, 0600);
        if (!rename($temporary, $this->controlFile)) {
            @unlink($temporary);
            throw new RuntimeException('Could not activate cutover rehearsal control.');
        }
        @chmod($this->controlFile, 0600);
    }
}
