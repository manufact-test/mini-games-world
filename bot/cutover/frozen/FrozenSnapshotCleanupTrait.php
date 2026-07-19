<?php
declare(strict_types=1);

trait FrozenSnapshotCleanupTrait
{
    private function removeRestoreTarget(string $path): void
    {
        $path = rtrim(str_replace('\\', '/', trim($path)), '/');
        if (!str_starts_with($path . '/', $this->restoreRoot . '/') || !str_starts_with(basename($path), 'restore-')) {
            throw new RuntimeException('Refusing to remove an unsafe restore rehearsal target.');
        }
        if (!is_dir($path)) return;
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $item) {
            if ($item->isLink()) throw new RuntimeException('Restore rehearsal target contains an unsafe symlink.');
            $ok = $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
            if (!$ok) throw new RuntimeException('Could not remove restore rehearsal target content.');
        }
        if (!rmdir($path)) throw new RuntimeException('Could not remove restore rehearsal target.');
    }

    private function safeName(string $value): string
    {
        $value = preg_replace('/[^A-Za-z0-9._-]+/', '-', trim($value)) ?? '';
        $value = trim($value, '-.');
        if ($value === '') throw new RuntimeException('Could not create safe restore rehearsal name.');
        return substr($value, 0, 100);
    }
}
