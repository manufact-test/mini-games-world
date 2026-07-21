<?php
declare(strict_types=1);

final class RuntimePrimaryStagingEvidenceV4ManifestFixture
{
    public static function valid(
        string $projectRoot,
        string $repositoryCommit,
        ?string $databaseIdentityFingerprint = null
    ): array {
        $manifest = RuntimePrimaryStagingEvidenceV3ManifestFixture::valid(
            $projectRoot,
            $repositoryCommit,
            $databaseIdentityFingerprint
        );
        $manifest['manifest_version'] = RuntimePrimaryStagingEvidenceV4Verifier::MANIFEST_VERSION;
        $baseline = [
            'state_revision' => (int)(
                $manifest['first_rehearsal']['target_revision'] ?? 0
            ),
            'state_sha256' => strtolower(trim((string)(
                $manifest['first_rehearsal']['target_sha256'] ?? ''
            ))),
            'json_sha256' => strtolower(trim((string)(
                $manifest['source_snapshot']['before_sha256'] ?? ''
            ))),
            'inventory_fingerprint' => strtolower(trim((string)(
                $manifest['source_snapshot']['inventory_fingerprint'] ?? ''
            ))),
        ];
        $manifest['request_session_evidence'] = RuntimePrimaryStagingRequestLifecycleEvidence::inspect(
            $projectRoot,
            $baseline
        );
        return $manifest;
    }
}
