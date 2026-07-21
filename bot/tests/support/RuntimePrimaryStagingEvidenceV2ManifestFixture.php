<?php
declare(strict_types=1);

final class RuntimePrimaryStagingEvidenceV2ManifestFixture
{
    public static function valid(
        string $projectRoot,
        string $repositoryCommit,
        ?string $databaseIdentityFingerprint = null
    ): array {
        $manifest = RuntimePrimaryStagingEvidenceManifestFixture::valid(
            $projectRoot,
            $repositoryCommit
        );
        $manifest['manifest_version'] = RuntimePrimaryStagingEvidenceV2Verifier::MANIFEST_VERSION;
        $manifest['database']['identity_fingerprint'] = $databaseIdentityFingerprint
            ?? str_repeat('5', 64);
        return $manifest;
    }
}
