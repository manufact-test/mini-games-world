<?php
declare(strict_types=1);

require_once __DIR__ . '/RuntimePrimaryStagingEvidenceV2Verifier.php';
require_once __DIR__ . '/RuntimePrimaryStagingEntrypointSelectorConfig.php';
require_once __DIR__ . '/RuntimePrimaryStagingSelectorEvidence.php';

final class RuntimePrimaryStagingEvidenceV3Verifier
{
    public const MANIFEST_VERSION = 'v3-staging-db-primary-selector-evidence';

    public function __construct(private string $projectRoot)
    {
        $this->projectRoot = rtrim(str_replace('\\', '/', trim($this->projectRoot)), '/');
        if ($this->projectRoot === '' || !is_dir($this->projectRoot)) {
            throw new InvalidArgumentException('Staging evidence v3 project root is unavailable.');
        }
    }

    public function verify(array $manifest): array
    {
        $blockers = [];
        if (($manifest['manifest_version'] ?? '') !== self::MANIFEST_VERSION) {
            $blockers[] = 'Staging evidence v3 manifest version is unsupported.';
        }
        if (!array_key_exists('selector_evidence', $manifest)
            || !is_array($manifest['selector_evidence'])
            || array_is_list($manifest['selector_evidence'])) {
            $blockers[] = 'Staging evidence v3 selector_evidence must be an object.';
        }

        $legacy = $manifest;
        $legacy['manifest_version'] = RuntimePrimaryStagingEvidenceV2Verifier::MANIFEST_VERSION;
        unset($legacy['selector_evidence']);
        $legacyReport = (new RuntimePrimaryStagingEvidenceV2Verifier($this->projectRoot))->verify($legacy);
        foreach ((array)($legacyReport['blockers'] ?? []) as $blocker) {
            $blocker = trim((string)$blocker);
            if ($blocker !== '') $blockers[] = $blocker;
        }

        $selectorEvidence = is_array($manifest['selector_evidence'] ?? null)
            ? $manifest['selector_evidence']
            : [];
        $currentSelectorEvidence = RuntimePrimaryStagingSelectorEvidence::inspect($this->projectRoot);
        $this->verifySelectorEvidence($selectorEvidence, $currentSelectorEvidence, $blockers);

        $blockers = array_values(array_unique(array_filter(array_map(
            static fn(mixed $value): string => trim((string)$value),
            $blockers
        ), static fn(string $value): bool => $value !== '')));

        return [
            'ok' => $blockers === [],
            'report_type' => 'mvp-14.8.6k-staging-evidence-v3-verification',
            'manifest_version' => self::MANIFEST_VERSION,
            'repository_commit' => strtolower(trim((string)($manifest['repository_commit'] ?? ''))),
            'database_identity_fingerprint' => strtolower(trim((string)($manifest['database']['identity_fingerprint'] ?? ''))),
            'selector_contract_version' => (string)($selectorEvidence['contract_version'] ?? ''),
            'selector_evidence_fingerprint' => hash('sha256', $this->canonicalJson($selectorEvidence)),
            'evidence_fingerprint' => hash('sha256', $this->canonicalJson($manifest)),
            'required_modules' => array_values((array)($legacyReport['required_modules'] ?? [])),
            'blocker_count' => count($blockers),
            'blockers' => $blockers,
            'application_entrypoints_changed' => false,
            'cron_changed' => false,
            'production_changed' => false,
            'sensitive_identifiers_exposed' => false,
            'generated_at_utc' => gmdate(DATE_ATOM),
        ];
    }

    private function verifySelectorEvidence(
        array $evidence,
        array $current,
        array &$blockers
    ): void {
        $requiredKeys = [
            'ready',
            'contract_version',
            'checks',
            'sources',
            'blockers',
            'selector_enabled_by_evidence',
            'default_storage_driver',
            'staging_selector_available',
            'production_selector_allowed',
            'application_entrypoints_changed',
            'cron_changed',
            'production_changed',
            'sensitive_identifiers_exposed',
        ];
        $actualKeys = array_keys($evidence);
        sort($actualKeys, SORT_STRING);
        sort($requiredKeys, SORT_STRING);
        if ($actualKeys !== $requiredKeys) {
            $missing = array_values(array_diff($requiredKeys, $actualKeys));
            $unexpected = array_values(array_diff($actualKeys, $requiredKeys));
            if ($missing !== []) {
                $blockers[] = 'Selector evidence is missing fields: ' . implode(', ', $missing) . '.';
            }
            if ($unexpected !== []) {
                $blockers[] = 'Selector evidence contains unexpected fields: ' . implode(', ', $unexpected) . '.';
            }
        }

        if (($evidence['ready'] ?? false) !== true
            || ($evidence['contract_version'] ?? '') !== RuntimePrimaryStagingSelectorEvidence::CONTRACT_VERSION
            || ($evidence['selector_enabled_by_evidence'] ?? true) !== false
            || ($evidence['default_storage_driver'] ?? '') !== 'json'
            || ($evidence['staging_selector_available'] ?? false) !== true
            || ($evidence['production_selector_allowed'] ?? true) !== false) {
            $blockers[] = 'Selector evidence does not preserve the guarded staging-only JSON-default contract.';
        }
        foreach ([
            'application_entrypoints_changed',
            'cron_changed',
            'production_changed',
            'sensitive_identifiers_exposed',
        ] as $field) {
            if (($evidence[$field] ?? null) !== false) {
                $blockers[] = 'Selector evidence violates the safety flag: ' . $field . '.';
            }
        }
        if ((array)($evidence['blockers'] ?? ['missing']) !== []) {
            $blockers[] = 'Selector evidence contains blockers.';
        }

        $checks = is_array($evidence['checks'] ?? null) ? $evidence['checks'] : [];
        if ($checks === [] || array_is_list($checks)) {
            $blockers[] = 'Selector evidence checks are missing.';
        }
        foreach ($checks as $name => $passed) {
            if (!is_string($name) || trim($name) === '' || $passed !== true) {
                $blockers[] = 'Selector evidence contains an incomplete check.';
                break;
            }
        }

        $sources = is_array($evidence['sources'] ?? null) ? $evidence['sources'] : [];
        $requiredSources = array_keys(is_array($current['sources'] ?? null)
            ? $current['sources']
            : []);
        $actualSources = array_keys($sources);
        sort($actualSources, SORT_STRING);
        sort($requiredSources, SORT_STRING);
        if ($requiredSources === [] || $actualSources !== $requiredSources) {
            $blockers[] = 'Selector evidence source set is incomplete.';
        }
        foreach ($sources as $name => $sha) {
            if (!is_string($name)
                || preg_match('/^[a-f0-9]{64}$/', strtolower(trim((string)$sha))) !== 1) {
                $blockers[] = 'Selector evidence contains an invalid source fingerprint.';
                break;
            }
        }

        if (!hash_equals(
            hash('sha256', $this->canonicalJson($current)),
            hash('sha256', $this->canonicalJson($evidence))
        )) {
            $blockers[] = 'Selector evidence does not match the current repository sources.';
        }
        if (($current['ready'] ?? false) !== true || (array)($current['blockers'] ?? ['missing']) !== []) {
            $blockers[] = 'Current guarded staging selector contract is not ready.';
        }
    }

    private function canonicalJson(array $value): string
    {
        return json_encode(
            $this->canonicalize($value),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        );
    }

    private function canonicalize(mixed $value): mixed
    {
        if (!is_array($value)) return $value;
        if (!array_is_list($value)) ksort($value, SORT_STRING);
        foreach ($value as $key => $item) $value[$key] = $this->canonicalize($item);
        return $value;
    }
}
