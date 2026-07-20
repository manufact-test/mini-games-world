<?php
declare(strict_types=1);

final class RuntimePrimaryProjectionWorkerAdapter implements RuntimePrimaryProjectionWorkerInterface
{
    public function __construct(private RuntimePrimaryProjectionWorker $worker) {}

    public function runOnce(): array
    {
        return $this->worker->runOnce();
    }
}
