<?php
declare(strict_types=1);

require_once __DIR__ . '/SealedSnapshotLifecycleTrait.php';
require_once __DIR__ . '/SealedSnapshotStatusTrait.php';
require_once __DIR__ . '/SealedSnapshotStorageTrait.php';

final class SealedSnapshotControlService
{
    use SealedSnapshotLifecycleTrait;
    use SealedSnapshotStatusTrait;
    use SealedSnapshotStorageTrait;

    public function __construct(
        private array $config,
        private StorageTransactionInterface $storage,
        private string $controlFile
    ) {
        $this->controlFile = trim($this->controlFile);
        if ($this->controlFile === '') throw new InvalidArgumentException('Cutover rehearsal control file is required.');
    }
}
