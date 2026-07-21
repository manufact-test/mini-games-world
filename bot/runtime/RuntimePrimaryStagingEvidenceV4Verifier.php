<?php
declare(strict_types=1);

require_once __DIR__ . '/RuntimePrimaryStagingEvidenceV3Verifier.php';
require_once __DIR__ . '/RuntimePrimaryStagingRequestLifecycleEvidence.php';

final class RuntimePrimaryStagingEvidenceV4Verifier
{
    public const MANIFEST_VERSION = 'v4-staging-db-primary-api-lifecycle-evidence';

    public function __construct(private string $projectRoot)
    {
        $this->projectRoot = rtrim(str_replace('\\', '/', trim($this->projectRoot)), '/');
        if ($this->projectRoot === '' || !is_dir($this->projectRoot)) {
            throw new InvalidArgumentException('Staging evidence v4 project root is unavailable.');
        }
    }

    public function verify(array $manifest): array
    {
        $blockers = [];
        if (($manifest['manifest_version'] ?? '') !== self::MANIFEST_VERSION) {
            $blockers[] = 'Staging evidence v4 manifest version is unsupported.';
        }
        if (!array_key_exists('request_session_evidence', $manifest)
            || !is_array($manifest['request_session_evidence'])
            || array_is_list($manifest['request_session_evidence'])) {
            $blockers[] = 'Staging evidence v4 request_session_evidence must be an object.';
        }

        $legacy = $manifest;
        $legacy['manifest_version'] = RuntimePrimaryStagingEvidenceV3Verifier::MANIFEST_VERSION;
        unset($legacy['request_session_evidence']);
        $legacyReport = (new RuntimePrimaryStagingEvidenceV3Verifier(
            $this->projectRoot
        ))->verify($legacy);
        foreach ((array)($legacyReport['blockers'] ?? []) as $blocker) {
            $blocker = trim((string)$blocker);
            if ($blocker !== '') $blockers[] = $blocker;
        }

        $lifecycle = is_array($manifest['request_session_evidence'] ?? null)
            ? $manifest['request_session_evidence']
            : [];
        $baseline = is_array($lifecycle['baseline'] ?? null)
            ? $lifecycle['baseline']
            : [];
        $current = [];
        try {
            $current = RuntimePrimaryStagingRequestLifecycleEvidence::inspect(
                $this->projectRoot,
                $baseline
            );
        } catch (Throwable $error) {
            $blockers[] = 'Current request lifecycle evidence could not be inspected: '
                . $error->getMessage();
        }
        $this->verifyLifecycleEvidence($lifecycle, $current, $manifest, $blockers);

        $blockers = array_values(array_unique(array_filter(array_map(
            static fn(mixed $value): string => trim((string)$value),
            $blockers
        ), static fn(string $value): bool => $value !== '')));

        return [
            'ok' => $blockers === [],
            'report_type' => 'mvp-14.8.6m-staging-evidence-v4-verification',
            'manifest_version' => self::MANIFEST_VERSION,
            'repository_commit' => strtolower(trim((string)(
                $manifest['repository_commit'] ?? ''
            ))),
            'database_identity_fingerprint' => strtolower(trim((string)(
                $manifest['database']['identity_fingerprint'] ?? ''
            ))),
            'selector_contract_version' => (string)(
                $manifest['selector_evidence']['contract_version'] ?? ''
            ),
            'selector_evidence_fingerprint' => hash(
                'sha256',
                $this->canonicalJson(is_array($manifest['selector_evidence'] ?? null)
                    ? $manifest['selector_evidence']
                    : [])
            ),
            'request_session_contract_version' => (string)(
                $lifecycle['contract_version'] ?? ''
            ),
            'request_session_evidence_fingerprint' => hash(
                'sha256',
                $this->canonicalJson($lifecycle)
            ),
            'baseline_state_revision' => (int)($baseline['state_revision'] ?? 0),
            'baseline_state_sha256' => strtolower(trim((string)(
                $baseline['state_sha256'] ?? ''
            ))),
            'baseline_json_sha256' => strtolower(trim((string)(
                $baseline['json_sha256'] ?? ''
            ))),
            'baseline_inventory_fingerprint' => strtolower(trim((string)(
                $baseline['inventory_fingerprint'] ?? ''
            ))),
            'evidence_fingerprint' => hash('sha256', $this->canonicalJson($manifest)),
            'required_modules' => array_values((array)(
                $legacyReport['required_modules'] ?? []
            )),
            'blocker_count' => count($blockers),
            'blockers' => $blockers,
            'api_only' => true,
            'webhook_allowed' => false,
            'application_entrypoints_changed' => false,
            'cron_changed' => false,
            'production_changed' => false,
            'sensitive_identifiers_exposed' => false,
            'generated_at_utc' => gmdate(DATE_ATOM),
        ];
    }

    private function verifyLifecycleEvidence(
        array $evidence,
        array $current,
        array $manifest,
        array &$blockers
    ): void {
        $requiredKeys = [
            'ready',
            'contract_version',
            'baseline',
            'checks',
            'sources',
            'blockers',
            'session_enabled_by_evidence',
            'finalizer_registered_by_evidence',
            'api_only',
            'webhook_allowed',
            'production_allowed',
            'application_entrypoints_changed',
            'cron_changed',
            'production_changed',
            'sensitive_identifiers_exposed',
        ];
        $actualKeys = array_keys($evidence);
        sort($requiredKeys, SORT_STRING);
        sort($actualKeys, SORT_STRING);
        if ($actualKeys !== $requiredKeys) {
            $missing = array_values(array_diff($requiredKeys, $actualKeys));
            $unexpected = array_values(array_diff($actualKeys, $requiredKeys));
            if ($missing !== []) {
                $blockers[] = 'Request lifecycle evidence is missing fields: '
                    . implode(', ', $missing)
                    . '.';
            }
            if ($unexpected !== []) {
                $blockers[] = 'Request lifecycle evidence contains unexpected fields: '
                    . implode(', ', $unexpected)
                    . '.';
            }
        }

        if (($evidence['ready'] ?? false) !== true
            || ($evidence['contract_version'] ?? '')
                !== RuntimePrimaryStagingRequestLifecycleEvidence::CONTRACT_VERSION
            || ($evidence['session_enabled_by_evidence'] ?? true) !== false
            || ($evidence['finalizer_registered_by_evidence'] ?? true) !== false
            || ($evidence['api_only'] ?? false) !== true
            || ($evidence['webhook_allowed'] ?? true) !== false
            || ($evidence['production_allowed'] ?? true) !== false) {
            $blockers[] = 'Request lifecycle evidence does not preserve the inactive API-only safety contract.';
        }
        foreach ([
            'application_entrypoints_changed',
            'cron_changed',
            'production_changed',
            'sensitive_identifiers_exposed',
        ] as $field) {
            if (($evidence[$field] ?? null) !== false) {
                $blockers[] = 'Request lifecycle evidence violates the safety flag: '
                    . $field
                    . '.';
            }
        }
        if ((array)($evidence['blockers'] ?? ['missing']) !== []) {
            $blockers[] = 'Request lifecycle evidence contains blockers.';
        }

        $checks = is_array($evidence['checks'] ?? null)
            ? $evidence['checks']
            : [];
        if ($checks === [] || array_is_list($checks)) {
            $blockers[] = 'Request lifecycle evidence checks are missing.';
        }
        foreach ($checks as $name => $passed) {
            if (!is_string($name) || trim($name) === '' || $passed !== true) {
                $blockers[] = 'Request lifecycle evidence contains an incomplete check.';
                break;
            }
        }
        $sources = is_array($evidence['sources'] ?? null)
            ? $evidence['sources']
            : [];
        $requiredSources = array_keys(is_array($current['sources'] ?? null)
            ? $current['sources']
            : []);
        $actualSources = array_keys($sources);
        sort($requiredSources, SORT_STRING);
        sort($actualSources, SORT_STRING);
        if ($requiredSources === [] || $requiredSources !== $actualSources) {
            $blockers[] = 'Request lifecycle evidence source set is incomplete.';
        }
        foreach ($sources as $name => $sha) {
            if (!is_string($name)
                || preg_match('/^[a-f0-9]{64}$/', strtolower(trim((string)$sha))) !== 1) {
                $blockers[] = 'Request lifecycle evidence contains an invalid source fingerprint.';
                break;
            }
        }

        if ($current === [] || !hash_equals(
            hash('sha256', $this->canonicalJson($current)),
            hash('sha256', $this->canonicalJson($evidence))
        )) {
            $blockers[] = 'Request lifecycle evidence does not match the current repository sources.';
        }
        if (($current['ready'] ?? false) !== true
            || (array)($current['blockers'] ?? ['missing']) !== []) {
            $blockers[] = 'Current API request lifecycle contract is not ready.';
        }

        $baseline = is_array($evidence['baseline'] ?? null)
            ? $evidence['baseline']
            : [];
        $expected = [
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
        if ($baseline !== $expected) {
            $blockers[] = 'Request lifecycle evidence baseline does not match the verified staging rehearsal.';
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
