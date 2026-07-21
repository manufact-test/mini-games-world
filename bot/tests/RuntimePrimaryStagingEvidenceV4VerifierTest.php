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
require $projectRoot . '/bot/runtime/RuntimePrimaryStagingRequestSessionConfig.php';
require $projectRoot . '/bot/runtime/RuntimePrimaryStagingRequestLifecycleEvidence.php';
require $projectRoot . '/bot/runtime/RuntimePrimaryStagingEvidenceV4Verifier.php';
require $projectRoot . '/bot/runtime/RuntimePrimaryStagingEvidenceV4Gate.php';
require $projectRoot . '/bot/runtime/RuntimePrimaryStagingEvidenceGate.php';
require $projectRoot . '/bot/tests/support/RuntimePrimaryStagingEvidenceManifestFixture.php';
require $projectRoot . '/bot/tests/support/RuntimePrimaryStagingEvidenceV2ManifestFixture.php';
require $projectRoot . '/bot/tests/support/RuntimePrimaryStagingEvidenceV3ManifestFixture.php';
require $projectRoot . '/bot/tests/support/RuntimePrimaryStagingEvidenceV4ManifestFixture.php';

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
    $manifest = RuntimePrimaryStagingEvidenceV4ManifestFixture::valid(
        $projectRoot,
        $commit
    );
    $verifier = new RuntimePrimaryStagingEvidenceV4Verifier($projectRoot);
    $valid = $verifier->verify($manifest);
    $assertTrue(($valid['ok'] ?? false) === true, 'Complete lifecycle evidence v4 must pass');
    $assertTrue(
        ($valid['manifest_version'] ?? '') === RuntimePrimaryStagingEvidenceV4Verifier::MANIFEST_VERSION,
        'V4 verifier must expose exact manifest version'
    );
    $assertTrue(
        ($valid['request_session_contract_version'] ?? '')
            === RuntimePrimaryStagingRequestLifecycleEvidence::CONTRACT_VERSION,
        'V4 verifier must expose exact lifecycle contract'
    );
    $assertTrue(
        preg_match('/^[a-f0-9]{64}$/', (string)(
            $valid['request_session_evidence_fingerprint'] ?? ''
        )) === 1,
        'V4 verifier must fingerprint lifecycle evidence'
    );
    $assertTrue(
        ($valid['baseline_state_revision'] ?? 0)
            === ($manifest['first_rehearsal']['target_revision'] ?? -1),
        'V4 verifier must preserve exact baseline revision'
    );

    $gate = (new RuntimePrimaryStagingEvidenceV4Gate($projectRoot))->verify($manifest);
    $assertTrue(($gate['ok'] ?? false) === true, 'V4 checkout gate must accept exact commit');
    $assertTrue(($gate['repository_commit_matches'] ?? false) === true, 'V4 gate must prove checkout binding');

    $generic = (new RuntimePrimaryStagingEvidenceGate($projectRoot))->verify($manifest);
    $assertTrue(($generic['ok'] ?? false) === true, 'Generic evidence gate must route lifecycle v4');
    $assertTrue(
        ($generic['report_type'] ?? '') === 'mvp-14.8.6m-staging-evidence-v4-verification',
        'Generic evidence gate must preserve the v4 verification report'
    );
    $assertTrue(
        preg_match('/^[a-f0-9]{64}$/', (string)(
            $generic['request_session_evidence_fingerprint'] ?? ''
        )) === 1,
        'Generic evidence gate must expose lifecycle fingerprint'
    );

    $v3 = $manifest;
    $v3['manifest_version'] = RuntimePrimaryStagingEvidenceV3Verifier::MANIFEST_VERSION;
    unset($v3['request_session_evidence']);
    $report = $verifier->verify($v3);
    $assertTrue(
        ($report['ok'] ?? true) === false
            && $containsBlocker($report, 'v4 manifest version'),
        'Lifecycle verifier must reject v3 manifest'
    );

    $tamperedBaseline = $manifest;
    $tamperedBaseline['request_session_evidence']['baseline']['state_revision']++;
    $report = $verifier->verify($tamperedBaseline);
    $assertTrue(
        ($report['ok'] ?? true) === false
            && $containsBlocker($report, 'baseline does not match'),
        'V4 verifier must reject changed lifecycle baseline'
    );

    $tamperedSha = $manifest;
    $tamperedSha['request_session_evidence']['sources']['response_helper'] = str_repeat('0', 64);
    $report = $verifier->verify($tamperedSha);
    $assertTrue(
        ($report['ok'] ?? true) === false
            && $containsBlocker($report, 'does not match the current repository sources'),
        'V4 verifier must reject changed lifecycle source SHA'
    );

    $failedCheck = $manifest;
    $firstCheck = array_key_first($failedCheck['request_session_evidence']['checks']);
    $failedCheck['request_session_evidence']['checks'][$firstCheck] = false;
    $failedCheck['request_session_evidence']['ready'] = false;
    $failedCheck['request_session_evidence']['blockers'] = ['forced lifecycle failure'];
    $report = $verifier->verify($failedCheck);
    $assertTrue(
        ($report['ok'] ?? true) === false
            && $containsBlocker($report, 'lifecycle evidence contains blockers'),
        'V4 verifier must reject incomplete lifecycle checks'
    );

    $unexpected = $manifest;
    $unexpected['request_session_evidence']['private_path'] = '/secret/path';
    $report = $verifier->verify($unexpected);
    $assertTrue(
        ($report['ok'] ?? true) === false
            && $containsBlocker($report, 'unexpected fields: private_path'),
        'V4 verifier must reject unexpected lifecycle fields'
    );

    $otherCommit = $manifest;
    $otherCommit['repository_commit'] = str_repeat('b', 40);
    $report = (new RuntimePrimaryStagingEvidenceV4Gate($projectRoot))->verify($otherCommit);
    $assertTrue(
        ($report['ok'] ?? true) === false
            && $containsBlocker($report, 'does not match the current checkout'),
        'V4 gate must reject evidence from another checkout'
    );
} finally {
    putenv('MGW_REHEARSAL_COMMIT_SHA');
}

fwrite(STDOUT, "RuntimePrimaryStagingEvidenceV4VerifierTest passed: {$assertions} assertions.\n");
