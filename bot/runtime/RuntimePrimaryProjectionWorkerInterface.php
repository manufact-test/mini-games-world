<?php
declare(strict_types=1);

interface RuntimePrimaryProjectionWorkerInterface
{
    public function runOnce(): array;
}
