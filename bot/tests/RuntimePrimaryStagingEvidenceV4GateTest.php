<?php
declare(strict_types=1);

$projectRoot = dirname(__DIR__, 2);
$source = file_get_contents(
    $projectRoot . '/bot/runtime/RuntimePrimaryStagingEvidenceV4Gate.php'
);
if (!is_string($source)) {
    throw new RuntimeException('Staging evidence v4 gate source is unavailable.');
}

$assertions = 0;
$assertTrue = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

$assertTrue(
    str_contains($source, "require_once __DIR__ . '/RuntimePrimaryRepositoryCommitResolver.php';")
        && str_contains($source, "require_once __DIR__ . '/RuntimePrimaryStagingEvidenceV4Verifier.php';"),
    'V4 gate must load the exact checkout resolver and lifecycle verifier dependencies'
);

$resolvePosition = strpos(
    $source,
    '$currentCommit = RuntimePrimaryRepositoryCommitResolver::resolve($this->projectRoot);'
);
$verifyPosition = strpos(
    $source,
    '$report = (new RuntimePrimaryStagingEvidenceV4Verifier('
);
$assertTrue(
    $resolvePosition !== false && $verifyPosition !== false && $resolvePosition < $verifyPosition,
    'V4 gate must resolve the current checkout before lifecycle verification'
);

$assertTrue(
    str_contains($source, 'preg_match(\'/^[a-f0-9]{40}$/\', $currentCommit) === 1')
        && str_contains($source, 'preg_match(\'/^[a-f0-9]{40}$/\', $manifestCommit) === 1')
        && str_contains($source, 'hash_equals($currentCommit, $manifestCommit)'),
    'V4 gate must bind verified evidence to an exact lowercase repository commit'
);

$assertTrue(
    str_contains($source, 'Evidence v4 repository commit does not match the current checkout.')
        && str_contains($source, '$blockers = array_values(array_unique(array_filter(array_map(')
        && str_contains($source, "$report['ok'] = $blockers === [];"),
    'V4 gate must merge checkout blockers without allowing false success'
);

foreach ([
    "current_repository_commit' => $currentCommit",
    "repository_commit_matches' => $matches",
    "blocker_count' => count($blockers)",
    "blockers' => $blockers",
] as $proof) {
    $assertTrue(
        str_contains($source, "$report['{$proof}"),
        'V4 gate report proof is missing: ' . $proof
    );
}

$assertTrue(
    !str_contains($source, 'StorageFactory')
        && !str_contains($source, 'PdoConnectionFactory')
        && !str_contains($source, 'DatabaseConfig')
        && !str_contains($source, 'file_put_contents(')
        && !str_contains($source, 'transaction(')
        && !str_contains($source, 'curl'),
    'V4 gate must remain offline, read-only and infrastructure-neutral'
);

fwrite(STDOUT, "RuntimePrimaryStagingEvidenceV4GateTest passed: {$assertions} assertions.\n");
