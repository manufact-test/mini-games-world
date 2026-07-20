<?php
declare(strict_types=1);

final class RuntimePrimaryStagingEvidenceV2Gate
{
    public function __construct(private string $projectRoot)
    {
        $this->projectRoot = rtrim(str_replace('\\', '/', trim($this->projectRoot)), '/');
        if ($this->projectRoot === '' || !is_dir($this->projectRoot)) {
            throw new InvalidArgumentException('Staging evidence v2 gate project root is unavailable.');
        }
    }

    public function verify(array $manifest): array
    {
        $currentCommit = RuntimePrimaryRepositoryCommitResolver::resolve($this->projectRoot);
        $report = (new RuntimePrimaryStagingEvidenceV2Verifier($this->projectRoot))->verify($manifest);
        $manifestCommit = strtolower(trim((string)($manifest['repository_commit'] ?? '')));
        $matches = preg_match('/^[a-f0-9]{40}$/', $currentCommit) === 1
            && preg_match('/^[a-f0-9]{40}$/', $manifestCommit) === 1
            && hash_equals($currentCommit, $manifestCommit);
        $blockers = array_values((array)($report['blockers'] ?? []));
        if (!$matches) {
            $blockers[] = 'Evidence v2 repository commit does not match the current checkout.';
        }
        $blockers = array_values(array_unique(array_filter(array_map(
            static fn(mixed $value): string => trim((string)$value),
            $blockers
        ), static fn(string $value): bool => $value !== '')));

        $report['ok'] = $blockers === [];
        $report['current_repository_commit'] = $currentCommit;
        $report['repository_commit_matches'] = $matches;
        $report['blocker_count'] = count($blockers);
        $report['blockers'] = $blockers;
        return $report;
    }
}
