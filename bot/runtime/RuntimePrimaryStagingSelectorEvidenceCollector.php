<?php
declare(strict_types=1);

final class RuntimePrimaryStagingSelectorEvidenceCollector
{
    public function __construct(
        private string $projectRoot,
        private RuntimePrimaryStagingEvidenceSourceInterface $source
    ) {
        $this->projectRoot = rtrim(str_replace('\\', '/', trim($this->projectRoot)), '/');
        if ($this->projectRoot === '' || !is_dir($this->projectRoot)) {
            throw new InvalidArgumentException('Selector evidence collector project root is unavailable.');
        }
    }

    public function collect(): array
    {
        $base = (new RuntimePrimaryStagingEvidenceCollector(
            $this->projectRoot,
            $this->source
        ))->collect();
        $manifest = is_array($base['manifest'] ?? null) ? $base['manifest'] : [];
        if ($manifest === []) {
            throw new RuntimeException('Base staging evidence manifest is empty.');
        }
        if (($manifest['manifest_version'] ?? '') !== RuntimePrimaryStagingEvidenceV2Verifier::MANIFEST_VERSION) {
            throw new RuntimeException('Selector evidence collector requires a verified v2 base manifest.');
        }

        $selectorEvidence = RuntimePrimaryStagingSelectorEvidence::inspect($this->projectRoot);
        if (($selectorEvidence['ready'] ?? false) !== true
            || (array)($selectorEvidence['blockers'] ?? ['missing']) !== []) {
            throw new RuntimeException(
                'Guarded staging selector sources are not ready: '
                . implode('; ', array_map('strval', (array)($selectorEvidence['blockers'] ?? [])))
            );
        }
        $manifest['manifest_version'] = RuntimePrimaryStagingEvidenceV3Verifier::MANIFEST_VERSION;
        $manifest['selector_evidence'] = $selectorEvidence;

        $verification = (new RuntimePrimaryStagingEvidenceV3Gate(
            $this->projectRoot
        ))->verify($manifest);
        if (($verification['ok'] ?? false) !== true) {
            throw new RuntimeException(
                'Collected selector-aware staging evidence v3 did not pass verification: '
                . implode('; ', array_map('strval', (array)($verification['blockers'] ?? [])))
            );
        }

        return [
            'ok' => true,
            'manifest' => $manifest,
            'verification' => $verification,
            'manifest_fingerprint' => (string)($verification['evidence_fingerprint'] ?? ''),
            'selector_evidence_fingerprint' => (string)($verification['selector_evidence_fingerprint'] ?? ''),
            'repository_commit' => (string)($base['repository_commit'] ?? ''),
            'database_identity_fingerprint' => (string)($base['database_identity_fingerprint'] ?? ''),
            'state_revision' => (int)($base['state_revision'] ?? 0),
            'state_sha256' => (string)($base['state_sha256'] ?? ''),
            'projected_modules' => array_values((array)($base['projected_modules'] ?? [])),
            'application_entrypoints_changed' => false,
            'cron_changed' => false,
            'production_changed' => false,
            'sensitive_identifiers_exposed' => false,
        ];
    }
}
