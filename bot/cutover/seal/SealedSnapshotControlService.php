<?php
declare(strict_types=1);

final class SealedSnapshotControlService
{
    public function __construct(
        private array $config,
        private StorageTransactionInterface $storage,
        private string $controlFile
    ) {}
}
