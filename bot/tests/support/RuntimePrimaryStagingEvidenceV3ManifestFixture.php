<?php
declare(strict_types=1);

final class RuntimePrimaryStagingEvidenceV3ManifestFixture
{
    public static function valid(
        string $projectRoot,
        string $repositoryCommit,
        ?string $databaseIdentityFingerprint = null
    ): array {
        $manifest = RuntimePrimaryStagingEvidenceV2ManifestFixture::valid(
            $projectRoot,
            $repositoryCommit,
            $databaseIdentityFingerprint
        );
        $manifest['manifest_version'] = RuntimePrimaryStagingEvidenceV3Verifier::MANIFEST_VERSION;
        $manifest['selector_evidence'] = RuntimePrimaryStagingSelectorEvidence::inspect($projectRoot);
        return $manifest;
    }
}
