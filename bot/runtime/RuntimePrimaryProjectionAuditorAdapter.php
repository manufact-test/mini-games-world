<?php
declare(strict_types=1);

final class RuntimePrimaryProjectionAuditorAdapter implements RuntimePrimaryProjectionAuditorInterface
{
    public function __construct(private RuntimePrimaryAllModuleProjector $projector) {}

    public function auditOnly(array $snapshot, int $stateRevision, string $stateSha256): array
    {
        return $this->projector->auditOnly($snapshot, $stateRevision, $stateSha256);
    }
}
