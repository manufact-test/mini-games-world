<?php
declare(strict_types=1);

interface RuntimePrimaryModuleProjectorInterface
{
    public function module(): string;

    /**
     * Mutate one normalized runtime module to match the exact compatibility-state snapshot.
     */
    public function project(array $snapshot, int $stateRevision, string $stateSha256): array;

    /**
     * Verify the normalized runtime module without changing it.
     */
    public function audit(array $snapshot, int $stateRevision, string $stateSha256): array;
}
