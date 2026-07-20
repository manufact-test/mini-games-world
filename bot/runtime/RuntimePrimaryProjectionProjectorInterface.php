<?php
declare(strict_types=1);

interface RuntimePrimaryProjectionProjectorInterface
{
    /**
     * Project one exact committed compatibility-state revision into all normalized runtime modules.
     * The implementation must be idempotent for the same revision and fingerprint.
     */
    public function project(array $snapshot, int $stateRevision, string $stateSha256): array;
}
