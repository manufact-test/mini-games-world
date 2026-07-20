<?php
declare(strict_types=1);

final class RuntimePrimaryStateSchemaInstaller
{
    public const TABLE = 'mgw_runtime_primary_state';
}
final class RuntimePrimaryProjectionOutboxSchemaInstaller
{
    public const TABLE = 'mgw_runtime_primary_projection_outbox';
}

$projectRoot = dirname(__DIR__, 2);
require $projectRoot . '/bot/runtime/RuntimePrimaryEntrypointEvidence.php';
require $projectRoot . '/bot/runtime/RuntimePrimaryRepositoryCommitResolver.php';
require $projectRoot . '/bot/runtime/RuntimePrimaryStagingEvidenceVerifier.php';
require $projectRoot . '/bot/runtime/RuntimePrimaryStagingEvidenceGate.php';
require $projectRoot . '/bot/tests/support/RuntimePrimaryStagingEvidenceManifestFixture.php';

$assertions = 0;
$assertTrue = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};
$containsBlocker = static function (array $report, string $part): bool {
    foreach ((array)($report['blockers'] ?? []) as $blocker) {
        if (str_contains(strtolower((string)$blocker), strtolower($part))) return true;
    }
    return false;
};

$currentCommit = str_repeat('a', 40);
putenv('MGW_REHEARSAL_COMMIT_SHA=' . $currentCommit);
try {
    $gate = new RuntimePrimaryStagingEvidenceGate($projectRoot);
    $matching = $gate->verify(
        RuntimePrimaryStagingEvidenceManifestFixture::valid($projectRoot, $currentCommit)
    );
    $assertTrue(($matching['ok'] ?? false) === true, 'Evidence matching the current checkout must pass');
    $assertTrue(($matching['repository_commit_matches'] ?? false) === true, 'Matching commit flag must be true');
    $assertTrue(($matching['current_repository_commit'] ?? '') === $currentCommit, 'Current commit must remain explicit');

    $differentCommit = str_repeat('b', 40);
    $mismatch = $gate->verify(
        RuntimePrimaryStagingEvidenceManifestFixture::valid($projectRoot, $differentCommit)
    );
    $assertTrue(($mismatch['ok'] ?? true) === false, 'Evidence from another checkout must fail');
    $assertTrue(($mismatch['repository_commit_matches'] ?? true) === false, 'Mismatched commit flag must be false');
    $assertTrue(
        $containsBlocker($mismatch, 'does not match the current checkout'),
        'Mismatched checkout blocker must remain explicit'
    );
} finally {
    putenv('MGW_REHEARSAL_COMMIT_SHA');
}

fwrite(STDOUT, "RuntimePrimaryStagingEvidenceGateTest passed: {$assertions} assertions.\n");
