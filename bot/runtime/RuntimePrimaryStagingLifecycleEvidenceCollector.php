<?php
declare(strict_types=1);

final class RuntimePrimaryStagingLifecycleEvidenceCollector
{
    public function __construct(
        private string $projectRoot,
        private RuntimePrimaryStagingEvidenceSourceInterface $source
    ) {
        $this->projectRoot = rtrim(str_replace('\\', '/', trim($this->projectRoot)), '/');
        if ($this->projectRoot === '' || !is_dir($this->projectRoot)) {
            throw new InvalidArgumentException('Lifecycle evidence collector project root is unavailable.');
        }
    }

    public function collect(): array
    {
        $base = (new RuntimePrimaryStagingSelectorEvidenceCollector(
            $this->projectRoot,
            $this->source
        ))->collect();
        $manifest = is_array($base['manifest'] ?? null) ? $base['manifest'] : [];
        if (($manifest['manifest_version'] ?? '')
            !== RuntimePrimaryStagingEvidenceV3Verifier::MANIFEST_VERSION) {
            throw new RuntimeException('Lifecycle evidence collector requires a verified v3 selector manifest.');
        }

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
        $lifecycle = RuntimePrimaryStagingRequestLifecycleEvidence::inspect(
            $this->projectRoot,
            $baseline
        );
        if (($lifecycle['ready'] ?? false) !== true
            || (array)($lifecycle['blockers'] ?? ['missing']) !== []) {
            throw new RuntimeException(
                'API request lifecycle sources are not ready: '
                . implode('; ', array_map('strval', (array)($lifecycle['blockers'] ?? [])))
            );
        }

        $manifest['manifest_version'] = RuntimePrimaryStagingEvidenceV4Verifier::MANIFEST_VERSION;
        $manifest['request_session_evidence'] = $lifecycle;
        $verification = (new RuntimePrimaryStagingEvidenceV4Gate(
            $this->projectRoot
        ))->verify($manifest);
        if (($verification['ok'] ?? false) !== true) {
            throw new RuntimeException(
                'Collected API lifecycle evidence v4 did not pass verification: '
                . implode('; ', array_map('strval', (array)($verification['blockers'] ?? [])))
            );
        }

        return [
            'ok' => true,
            'manifest' => $manifest,
            'verification' => $verification,
            'manifest_fingerprint' => (string)(
                $verification['evidence_fingerprint'] ?? ''
            ),
            'selector_evidence_fingerprint' => (string)(
                $verification['selector_evidence_fingerprint'] ?? ''
            ),
            'request_session_evidence_fingerprint' => (string)(
                $verification['request_session_evidence_fingerprint'] ?? ''
            ),
            'repository_commit' => (string)($base['repository_commit'] ?? ''),
            'database_identity_fingerprint' => (string)(
                $base['database_identity_fingerprint'] ?? ''
            ),
            'baseline_state_revision' => (int)$baseline['state_revision'],
            'baseline_state_sha256' => (string)$baseline['state_sha256'],
            'baseline_json_sha256' => (string)$baseline['json_sha256'],
            'baseline_inventory_fingerprint' => (string)$baseline['inventory_fingerprint'],
            'projected_modules' => array_values((array)(
                $base['projected_modules'] ?? []
            )),
            'session_enabled_by_evidence' => false,
            'finalizer_registered_by_evidence' => false,
            'application_entrypoints_changed' => false,
            'cron_changed' => false,
            'production_changed' => false,
            'sensitive_identifiers_exposed' => false,
        ];
    }
}
