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
require $projectRoot . '/bot/runtime/RuntimePrimaryStagingEvidenceV2Verifier.php';
require $projectRoot . '/bot/runtime/RuntimePrimaryStagingEntrypointSelectorConfig.php';
require $projectRoot . '/bot/runtime/RuntimePrimaryStagingSelectorEvidence.php';
require $projectRoot . '/bot/runtime/RuntimePrimaryStagingEvidenceV3Verifier.php';
require $projectRoot . '/bot/runtime/RuntimePrimaryStagingEvidenceV3Gate.php';
require $projectRoot . '/bot/runtime/RuntimePrimaryStagingEvidenceV2Gate.php';
require $projectRoot . '/bot/tests/support/RuntimePrimaryStagingEvidenceManifestFixture.php';
require $projectRoot . '/bot/tests/support/RuntimePrimaryStagingEvidenceV2ManifestFixture.php';
require $projectRoot . '/bot/tests/support/RuntimePrimaryStagingEvidenceV3ManifestFixture.php';

$assertions = 0;
$assertTrue = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

$commit = str_repeat('a', 40);
putenv('MGW_REHEARSAL_COMMIT_SHA=' . $commit);
try {
    $manifest = RuntimePrimaryStagingEvidenceV3ManifestFixture::valid($projectRoot, $commit);
    $report = (new RuntimePrimaryStagingEvidenceV2Gate($projectRoot))->verify($manifest);
    $assertTrue(($report['ok'] ?? false) === true, 'Legacy activation guard gate must accept valid evidence v3');
    $assertTrue(
        ($report['manifest_version'] ?? '') === RuntimePrimaryStagingEvidenceV3Verifier::MANIFEST_VERSION,
        'Legacy activation guard gate must preserve the v3 report'
    );
    $assertTrue(
        preg_match('/^[a-f0-9]{64}$/', (string)($report['selector_evidence_fingerprint'] ?? '')) === 1,
        'Legacy activation guard gate must expose selector evidence fingerprint'
    );
} finally {
    putenv('MGW_REHEARSAL_COMMIT_SHA');
}

fwrite(STDOUT, "RuntimePrimaryStagingEvidenceV2GateV3CompatibilityTest passed: {$assertions} assertions.\n");
