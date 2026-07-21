<?php
declare(strict_types=1);

final class RuntimePrimarySyntheticRollback extends RuntimeException
{
    public function __construct(private array $scenarioReport)
    {
        parent::__construct('Synthetic staging transaction completed and must be rolled back.');
    }

    public function scenarioReport(): array
    {
        return $this->scenarioReport;
    }
}
