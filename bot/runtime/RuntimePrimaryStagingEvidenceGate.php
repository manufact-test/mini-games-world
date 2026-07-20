<?php
declare(strict_types=1);

final class RuntimePrimaryStagingEvidenceGate
{
    public function __construct(private string $projectRoot)
    {
        $this->projectRoot = rtrim(str_replace('\\', '/', trim($this->projectRoot)), '/');
        if ($this->projectRoot === '' || !is_dir($this->projectRoot)) {
            throw new InvalidArgumentException('Staging evidence gate project root is unavailable.');
        }
    }

    public function verify(array $manifest): array
    {
        $currentCommit = RuntimePrimaryRepositoryCommitResolver::resolve($this->projectRoot);
        $report = (new RuntimePrimaryStagingEvidenceVerifier($this->projectRoot))->verify($manifest);
        $manifestCommit = strtolower(trim((string)($manifest['repository_commit'] ?? '')));
        $blockers = array_values((array)($report['blockers'] ?? []));
        if (!hash_equals($currentCommit, $manifestCommit)) {
            $blockers[] = 'Evidence repository commit does not match the current checkout.';
        }
        $blockers = array_values(array_unique(array_filter(array_map(
            static fn(mixed $value): string => trim((string)$value),
            $blockers
        ), static fn(string $value): bool => $value !== '')));

        $report['ok'] = $blockers === [];
        $report['current_repository_commit'] = $currentCommit;
        $report['repository_commit_matches'] = hash_equals($currentCommit, $manifestCommit);
        $report['blocker_count'] = count($blockers);
        $report['blockers'] = $blockers;
        return $report;
    }
}
