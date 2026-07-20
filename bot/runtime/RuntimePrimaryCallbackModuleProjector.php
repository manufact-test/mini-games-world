<?php
declare(strict_types=1);

final class RuntimePrimaryCallbackModuleProjector implements RuntimePrimaryModuleProjectorInterface
{
    private Closure $projectCallback;
    private Closure $auditCallback;

    public function __construct(
        private string $moduleName,
        callable $projectCallback,
        callable $auditCallback
    ) {
        $this->moduleName = strtolower(trim($this->moduleName));
        if ($this->moduleName === '') {
            throw new InvalidArgumentException('Runtime module projector name is required.');
        }
        $this->projectCallback = Closure::fromCallable($projectCallback);
        $this->auditCallback = Closure::fromCallable($auditCallback);
    }

    public function module(): string
    {
        return $this->moduleName;
    }

    public function project(array $snapshot, int $stateRevision, string $stateSha256): array
    {
        $report = ($this->projectCallback)($snapshot, $stateRevision, $stateSha256);
        if (!is_array($report)) {
            throw new RuntimeException('Runtime module project callback must return an array: ' . $this->moduleName . '.');
        }
        return $report;
    }

    public function audit(array $snapshot, int $stateRevision, string $stateSha256): array
    {
        $report = ($this->auditCallback)($snapshot, $stateRevision, $stateSha256);
        if (!is_array($report)) {
            throw new RuntimeException('Runtime module audit callback must return an array: ' . $this->moduleName . '.');
        }
        return $report;
    }
}
