<?php
declare(strict_types=1);

final class RuntimePrimaryStagingMutatingSmokeRollbackSignal extends RuntimeException
{
    public function __construct(private string $approvalId)
    {
        parent::__construct('Bounded staging mutating smoke requested mandatory transaction rollback.');
    }

    public function approvalId(): string
    {
        return $this->approvalId;
    }
}
