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
require $projectRoot . '/bot/tests/support/RuntimePrimaryStagingEvidenceManifestFixture.php';
require $projectRoot . '/bot/tests/support/RuntimePrimaryStagingEvidenceV2ManifestFixture.php';

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
    $manifest = RuntimePrimaryStagingEvidenceV2ManifestFixture::valid($projectRoot, $commit);
    $verifier = new RuntimePrimaryStagingEvidenceV2Verifier($projectRoot);
    $valid = $verifier->verify($manifest);
    $assertTrue(($valid['ok'] ?? false) === true, 'Complete staging evidence v2 must pass');
    $assertTrue(($valid['manifest_version'] ?? '') === 'v2-staging-db-primary-evidence', 'V2 verifier must expose the exact manifest version');
    $assertTrue(
        hash_equals(str_repeat('5', 64), (string)($valid['database_identity_fingerprint'] ?? '')),
        'V2 verifier must preserve the exact database identity fingerprint'
    );
    $assertTrue(
        preg_match('/^[a-f0-9]{64}$/', (string)($valid['evidence_fingerprint'] ?? '')) === 1,
        'V2 verifier must expose a deterministic evidence fingerprint'
    );

    $gate = (new RuntimePrimaryStagingEvidenceV2Gate($projectRoot))->verify($manifest);
    $assertTrue(($gate['ok'] ?? false) === true, 'V2 gate must accept the exact checkout commit');
    $assertTrue(($gate['repository_commit_matches'] ?? false) === true, 'V2 gate must prove checkout binding');

    $v1 = $manifest;
    $v1['manifest_version'] = RuntimePrimaryStagingEvidenceVerifier::MANIFEST_VERSION;
    $report = $verifier->verify($v1);
    $assertTrue(
        ($report['ok'] ?? true) === false && $containsBlocker($report, 'v2 manifest version'),
        'Activation evidence verifier must reject v1 manifests'
    );

    $missingIdentity = $manifest;
    unset($missingIdentity['database']['identity_fingerprint']);
    $report = $verifier->verify($missingIdentity);
    $assertTrue(
        ($report['ok'] ?? true) === false && $containsBlocker($report, 'missing fields: identity_fingerprint'),
        'V2 verifier must reject a missing database identity'
    );

    $invalidIdentity = $manifest;
    $invalidIdentity['database']['identity_fingerprint'] = 'not-a-sha';
    $report = $verifier->verify($invalidIdentity);
    $assertTrue(
        ($report['ok'] ?? true) === false && $containsBlocker($report, 'identity fingerprint must be sha-256'),
        'V2 verifier must reject an invalid database identity'
    );

    $unexpected = $manifest;
    $unexpected['database']['database_name'] = 'secret';
    $report = $verifier->verify($unexpected);
    $assertTrue(
        ($report['ok'] ?? true) === false && $containsBlocker($report, 'unexpected fields: database_name'),
        'V2 verifier must reject raw database identifiers'
    );

    $otherCommit = $manifest;
    $otherCommit['repository_commit'] = str_repeat('b', 40);
    $report = (new RuntimePrimaryStagingEvidenceV2Gate($projectRoot))->verify($otherCommit);
    $assertTrue(
        ($report['ok'] ?? true) === false && $containsBlocker($report, 'does not match the current checkout'),
        'V2 gate must reject evidence from another checkout'
    );
} finally {
    putenv('MGW_REHEARSAL_COMMIT_SHA');
}

fwrite(STDOUT, "RuntimePrimaryStagingEvidenceV2VerifierTest passed: {$assertions} assertions.\n");
