<?php
declare(strict_types=1);

final class RuntimePrimaryStagingEvidenceV2Verifier
{
    public const MANIFEST_VERSION = 'v2-staging-db-primary-evidence';

    public function __construct(private string $projectRoot)
    {
        $this->projectRoot = rtrim(str_replace('\\', '/', trim($this->projectRoot)), '/');
        if ($this->projectRoot === '' || !is_dir($this->projectRoot)) {
            throw new InvalidArgumentException('Staging evidence v2 project root is unavailable.');
        }
    }

    public function verify(array $manifest): array
    {
        $blockers = [];
        if (($manifest['manifest_version'] ?? '') !== self::MANIFEST_VERSION) {
            $blockers[] = 'Staging evidence v2 manifest version is unsupported.';
        }

        $database = is_array($manifest['database'] ?? null) ? $manifest['database'] : [];
        $actualDatabaseKeys = array_keys($database);
        sort($actualDatabaseKeys, SORT_STRING);
        $requiredDatabaseKeys = [
            'driver',
            'identity_fingerprint',
            'outbox_engine',
            'server_version',
            'state_engine',
        ];
        sort($requiredDatabaseKeys, SORT_STRING);
        if ($actualDatabaseKeys !== $requiredDatabaseKeys) {
            $missing = array_values(array_diff($requiredDatabaseKeys, $actualDatabaseKeys));
            $unexpected = array_values(array_diff($actualDatabaseKeys, $requiredDatabaseKeys));
            if ($missing !== []) {
                $blockers[] = 'Staging evidence v2 database is missing fields: ' . implode(', ', $missing) . '.';
            }
            if ($unexpected !== []) {
                $blockers[] = 'Staging evidence v2 database contains unexpected fields: ' . implode(', ', $unexpected) . '.';
            }
        }
        $databaseIdentity = strtolower(trim((string)($database['identity_fingerprint'] ?? '')));
        if (preg_match('/^[a-f0-9]{64}$/', $databaseIdentity) !== 1) {
            $blockers[] = 'Staging evidence v2 database identity fingerprint must be SHA-256.';
        }

        $legacy = $manifest;
        $legacy['manifest_version'] = RuntimePrimaryStagingEvidenceVerifier::MANIFEST_VERSION;
        if (isset($legacy['database']) && is_array($legacy['database'])) {
            unset($legacy['database']['identity_fingerprint']);
        }
        $legacyReport = (new RuntimePrimaryStagingEvidenceVerifier($this->projectRoot))->verify($legacy);
        foreach ((array)($legacyReport['blockers'] ?? []) as $blocker) {
            $blocker = trim((string)$blocker);
            if ($blocker !== '') $blockers[] = $blocker;
        }
        $blockers = array_values(array_unique($blockers));

        return [
            'ok' => $blockers === [],
            'report_type' => 'mvp-14.8.6h-staging-evidence-v2-verification',
            'manifest_version' => self::MANIFEST_VERSION,
            'repository_commit' => strtolower(trim((string)($manifest['repository_commit'] ?? ''))),
            'database_identity_fingerprint' => $databaseIdentity,
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
