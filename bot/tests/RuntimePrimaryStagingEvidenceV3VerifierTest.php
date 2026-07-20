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
require $projectRoot . '/bot/runtime/RuntimePrimaryStagingEvidenceV2Gate.php';
require $projectRoot . '/bot/runtime/RuntimePrimaryStagingEntrypointSelectorConfig.php';
require $projectRoot . '/bot/runtime/RuntimePrimaryStagingSelectorEvidence.php';
require $projectRoot . '/bot/runtime/RuntimePrimaryStagingEvidenceV3Verifier.php';
require $projectRoot . '/bot/runtime/RuntimePrimaryStagingEvidenceV3Gate.php';
require $projectRoot . '/bot/tests/support/RuntimePrimaryStagingEvidenceManifestFixture.php';
require $projectRoot . '/bot/tests/support/RuntimePrimaryStagingEvidenceV2ManifestFixture.php';
require $projectRoot . '/bot/tests/support/RuntimePrimaryStagingEvidenceV3ManifestFixture.php';

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

$commit = str_repeat('a', 40);
putenv('MGW_REHEARSAL_COMMIT_SHA=' . $commit);
try {
    $manifest = RuntimePrimaryStagingEvidenceV3ManifestFixture::valid($projectRoot, $commit);
    $assertTrue(($manifest['selector_evidence']['ready'] ?? false) === true, 'Current selector evidence fixture must be ready');

    $verifier = new RuntimePrimaryStagingEvidenceV3Verifier($projectRoot);
    $valid = $verifier->verify($manifest);
    $assertTrue(($valid['ok'] ?? false) === true, 'Complete selector-aware evidence v3 must pass');
    $assertTrue(
        ($valid['manifest_version'] ?? '') === RuntimePrimaryStagingEvidenceV3Verifier::MANIFEST_VERSION,
        'V3 verifier must expose the exact manifest version'
    );
    $assertTrue(
        ($valid['selector_contract_version'] ?? '') === RuntimePrimaryStagingSelectorEvidence::CONTRACT_VERSION,
        'V3 verifier must preserve the exact selector contract version'
    );
    $assertTrue(
        preg_match('/^[a-f0-9]{64}$/', (string)($valid['selector_evidence_fingerprint'] ?? '')) === 1,
        'V3 verifier must fingerprint selector evidence'
    );

    $gate = (new RuntimePrimaryStagingEvidenceV3Gate($projectRoot))->verify($manifest);
    $assertTrue(($gate['ok'] ?? false) === true, 'V3 gate must accept the exact checkout');
    $assertTrue(($gate['repository_commit_matches'] ?? false) === true, 'V3 gate must prove checkout binding');

    $v2 = $manifest;
    $v2['manifest_version'] = RuntimePrimaryStagingEvidenceV2Verifier::MANIFEST_VERSION;
    unset($v2['selector_evidence']);
    $report = $verifier->verify($v2);
    $assertTrue(
        ($report['ok'] ?? true) === false && $containsBlocker($report, 'v3 manifest version'),
        'Real selector evidence verifier must reject v2 manifests'
    );

    $missing = $manifest;
    unset($missing['selector_evidence']);
    $report = $verifier->verify($missing);
    $assertTrue(
        ($report['ok'] ?? true) === false && $containsBlocker($report, 'selector_evidence must be an object'),
        'V3 verifier must reject missing selector evidence'
    );

    $tamperedSha = $manifest;
    $tamperedSha['selector_evidence']['sources']['storage_factory'] = str_repeat('0', 64);
    $report = $verifier->verify($tamperedSha);
    $assertTrue(
        ($report['ok'] ?? true) === false && $containsBlocker($report, 'does not match the current repository sources'),
        'V3 verifier must reject a changed selector source SHA'
    );

    $failedCheck = $manifest;
    $firstCheck = array_key_first($failedCheck['selector_evidence']['checks']);
    $failedCheck['selector_evidence']['checks'][$firstCheck] = false;
    $failedCheck['selector_evidence']['ready'] = false;
    $failedCheck['selector_evidence']['blockers'] = ['forced selector failure'];
    $report = $verifier->verify($failedCheck);
    $assertTrue(
        ($report['ok'] ?? true) === false && $containsBlocker($report, 'selector evidence contains blockers'),
        'V3 verifier must reject incomplete selector checks'
    );

    $unexpected = $manifest;
    $unexpected['selector_evidence']['private_path'] = '/secret/path';
    $report = $verifier->verify($unexpected);
    $assertTrue(
        ($report['ok'] ?? true) === false && $containsBlocker($report, 'unexpected fields: private_path'),
        'V3 verifier must reject unexpected selector evidence fields'
    );

    $otherCommit = $manifest;
    $otherCommit['repository_commit'] = str_repeat('b', 40);
    $report = (new RuntimePrimaryStagingEvidenceV3Gate($projectRoot))->verify($otherCommit);
    $assertTrue(
        ($report['ok'] ?? true) === false && $containsBlocker($report, 'does not match the current checkout'),
        'V3 gate must reject evidence from another checkout'
    );
} finally {
    putenv('MGW_REHEARSAL_COMMIT_SHA');
}

fwrite(STDOUT, "RuntimePrimaryStagingEvidenceV3VerifierTest passed: {$assertions} assertions.\n");
