<?php
declare(strict_types=1);

final class RuntimePrimaryAllModuleProjector implements RuntimePrimaryProjectionProjectorInterface
{
    public const CONTRACT_VERSION = 'v1-normalized-all-modules';

    private const MODULES = [
        'accounts',
        'realtime',
        'economy',
        'notifications',
        'invites',
        'history',
        'shop',
        'payments',
        'weekly_bonus',
    ];

    /** @var array<string, RuntimePrimaryModuleProjectorInterface> */
    private array $projectors = [];

    /**
     * @param iterable<RuntimePrimaryModuleProjectorInterface> $projectors
     */
    public function __construct(iterable $projectors)
    {
        foreach ($projectors as $projector) {
            if (!$projector instanceof RuntimePrimaryModuleProjectorInterface) {
                throw new InvalidArgumentException('All-module projector received an invalid module projector.');
            }
            $module = strtolower(trim($projector->module()));
            if (!in_array($module, self::MODULES, true)) {
                throw new InvalidArgumentException('All-module projector received an unsupported module: ' . $module . '.');
            }
            if (isset($this->projectors[$module])) {
                throw new InvalidArgumentException('All-module projector received a duplicate module: ' . $module . '.');
            }
            $this->projectors[$module] = $projector;
        }

        $missing = array_values(array_diff(self::MODULES, array_keys($this->projectors)));
        if ($missing !== []) {
            throw new InvalidArgumentException(
                'All-module projector is missing required modules: ' . implode(', ', $missing) . '.'
            );
        }
    }

    public function project(array $snapshot, int $stateRevision, string $stateSha256): array
    {
        $stateSha256 = $this->assertSnapshot($snapshot, $stateRevision, $stateSha256);
        $projectReports = [];
        $auditReports = [];
        $moduleFingerprints = [];

        foreach (self::MODULES as $module) {
            $report = $this->projectors[$module]->project($snapshot, $stateRevision, $stateSha256);
            $projectReports[$module] = $this->assertModuleReport(
                $report,
                $module,
                $stateRevision,
                $stateSha256,
                false
            );
            $this->assertSnapshot($snapshot, $stateRevision, $stateSha256);
        }

        foreach (self::MODULES as $module) {
            $report = $this->projectors[$module]->audit($snapshot, $stateRevision, $stateSha256);
            $auditReports[$module] = $this->assertModuleReport(
                $report,
                $module,
                $stateRevision,
                $stateSha256,
                true
            );
            $moduleFingerprints[$module] = [
                'source_fingerprint' => $auditReports[$module]['source_fingerprint'],
                'database_fingerprint' => $auditReports[$module]['database_fingerprint'],
                'report_fingerprint' => $auditReports[$module]['report_fingerprint'],
            ];
            $this->assertSnapshot($snapshot, $stateRevision, $stateSha256);
        }

        return [
            'ok' => true,
            'parity_ok' => true,
            'projection_contract_version' => self::CONTRACT_VERSION,
            'state_revision' => $stateRevision,
            'state_sha256' => $stateSha256,
            'projected_modules' => self::MODULES,
            'project_reports' => $projectReports,
            'audit_reports' => $auditReports,
            'module_fingerprints' => $moduleFingerprints,
            'all_module_fingerprint' => hash('sha256', $this->canonicalJson($moduleFingerprints)),
            'audit_completed' => true,
            'production_changed' => false,
            'sensitive_identifiers_exposed' => false,
        ];
    }

    private function assertSnapshot(array $snapshot, int $stateRevision, string $stateSha256): string
    {
        if ($stateRevision < 1) {
            throw new InvalidArgumentException('All-module projection revision must be positive.');
        }
        $stateSha256 = strtolower(trim($stateSha256));
        if (preg_match('/^[a-f0-9]{64}$/', $stateSha256) !== 1) {
            throw new InvalidArgumentException('All-module projection fingerprint must be SHA-256.');
        }
        $actual = hash('sha256', $this->canonicalJson($snapshot));
        if (!hash_equals($stateSha256, $actual)) {
            throw new RuntimeException('All-module projection snapshot fingerprint mismatch.');
        }
        return $stateSha256;
    }

    private function assertModuleReport(
        array $report,
        string $module,
        int $stateRevision,
        string $stateSha256,
        bool $readOnly
    ): array {
        if (($report['ok'] ?? false) !== true || ($report['parity'] ?? false) !== true) {
            throw new RuntimeException('Runtime module projection did not pass parity: ' . $module . '.');
        }
        if (strtolower(trim((string)($report['module'] ?? ''))) !== $module) {
            throw new RuntimeException('Runtime module projection returned the wrong module: ' . $module . '.');
        }
        if ((int)($report['state_revision'] ?? 0) !== $stateRevision) {
            throw new RuntimeException('Runtime module projection returned the wrong revision: ' . $module . '.');
        }
        $reportStateSha = strtolower(trim((string)($report['state_sha256'] ?? '')));
        if (!hash_equals($stateSha256, $reportStateSha)) {
            throw new RuntimeException('Runtime module projection returned the wrong state fingerprint: ' . $module . '.');
        }
        if ($readOnly && ($report['read_only'] ?? false) !== true) {
            throw new RuntimeException('Runtime module audit is not read-only: ' . $module . '.');
        }
        if (!$readOnly && ($report['read_only'] ?? false) === true) {
            throw new RuntimeException('Runtime module project pass did not perform projection: ' . $module . '.');
        }

        $sourceFingerprint = strtolower(trim((string)($report['source_fingerprint'] ?? '')));
        $databaseFingerprint = strtolower(trim((string)($report['database_fingerprint'] ?? '')));
        foreach ([
            'source_fingerprint' => $sourceFingerprint,
            'database_fingerprint' => $databaseFingerprint,
        ] as $label => $fingerprint) {
            if (preg_match('/^[a-f0-9]{64}$/', $fingerprint) !== 1) {
                throw new RuntimeException('Runtime module report has an invalid ' . $label . ': ' . $module . '.');
            }
        }
        if (!hash_equals($sourceFingerprint, $databaseFingerprint)) {
            throw new RuntimeException('Runtime module source and database fingerprints differ: ' . $module . '.');
        }

        $blockers = array_values(array_filter(
            array_map('strval', (array)($report['blockers'] ?? [])),
            static fn(string $value): bool => trim($value) !== ''
        ));
        if ($blockers !== []) {
            throw new RuntimeException(
                'Runtime module report contains blockers: ' . $module . ': ' . implode('; ', $blockers)
            );
        }

        return [
            'ok' => true,
            'parity' => true,
            'read_only' => $readOnly,
            'module' => $module,
            'state_revision' => $stateRevision,
            'state_sha256' => $stateSha256,
            'source_fingerprint' => $sourceFingerprint,
            'database_fingerprint' => $databaseFingerprint,
            'report_fingerprint' => hash('sha256', $this->canonicalJson($report)),
            'summary' => is_array($report['summary'] ?? null) ? $report['summary'] : [],
            'blockers' => [],
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
