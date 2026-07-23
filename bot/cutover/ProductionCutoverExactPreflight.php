<?php
declare(strict_types=1);

final class ProductionCutoverExactPreflight
{
    public function __construct(
        private string $projectRoot,
        private array $config,
        private string $configFile,
        private ProductionCutoverConfig $policy,
        private ?int $now = null
    ) {}

    public function run(): array
    {
        $manifest = (new ProductionCutoverPackageManifest($this->projectRoot))->inspect();
        $this->policy->assertPackage($manifest);

        $runtimeContract = ProductionRuntimePrimaryContract::inspect($this->projectRoot);
        $base = (new ProductionPreflightRunner(
            $this->projectRoot,
            $this->config,
            $this->configFile,
            $this->now
        ))->run();

        $blockers = array_values(array_map(
            'strval',
            is_array($base['blockers'] ?? null) ? $base['blockers'] : []
        ));
        if (($manifest['ready'] ?? false) !== true) {
            $blockers[] = 'exact production cutover package manifest is not ready';
        }
        if (($runtimeContract['ready'] ?? false) !== true) {
            foreach ((array)($runtimeContract['blockers'] ?? []) as $blocker) {
                $blockers[] = 'runtime primary contract: ' . (string)$blocker;
            }
        }
        if (($base['production_switch_allowed'] ?? true) !== false) {
            $blockers[] = 'base preflight must never authorize the production switch';
        }

        $runtime = is_array($base['runtime'] ?? null) ? $base['runtime'] : [];
        $inventory = is_array($base['source_inventory'] ?? null)
            ? $base['source_inventory']
            : [];
        $backups = is_array($base['backups'] ?? null) ? $base['backups'] : [];
        $primaryBackup = is_array($backups['primary'] ?? null) ? $backups['primary'] : [];
        $externalBackup = is_array($backups['external'] ?? null) ? $backups['external'] : [];

        $identity = [
            'package_version' => (string)($manifest['package_version'] ?? ''),
            'build' => (string)($manifest['build'] ?? ''),
            'release_commit' => (string)($manifest['release_commit'] ?? ''),
            'package_fingerprint' => (string)($manifest['package_fingerprint'] ?? ''),
            'runtime_contract_version' => (string)($runtimeContract['contract_version'] ?? ''),
            'runtime_contract_fingerprint' => (string)($runtimeContract['contract_fingerprint'] ?? ''),
            'base_plan_fingerprint' => (string)($base['cutover_plan_fingerprint'] ?? ''),
            'source_fingerprint' => (string)($inventory['source_fingerprint'] ?? ''),
            'database_identity_fingerprint' => (string)(
                $runtime['database']['identity_fingerprint'] ?? ''
            ),
            'migration_plan_fingerprint' => (string)(
                $runtime['migration_plan_fingerprint'] ?? ''
            ),
            'primary_backup_id' => (string)($primaryBackup['backup_id'] ?? ''),
            'primary_backup_snapshot_sha256' => (string)(
                $primaryBackup['snapshot_sha256'] ?? ''
            ),
            'external_backup_id' => (string)($externalBackup['backup_id'] ?? ''),
            'external_backup_snapshot_sha256' => (string)(
                $externalBackup['snapshot_sha256'] ?? ''
            ),
        ];
        foreach ([
            'release_commit' => 40,
            'package_fingerprint' => 64,
            'runtime_contract_fingerprint' => 64,
            'base_plan_fingerprint' => 64,
            'source_fingerprint' => 64,
            'database_identity_fingerprint' => 64,
            'migration_plan_fingerprint' => 64,
            'primary_backup_snapshot_sha256' => 64,
        ] as $field => $length) {
            $pattern = $length === 40 ? '/\A[a-f0-9]{40}\z/' : '/\A[a-f0-9]{64}\z/';
            if (preg_match($pattern, (string)($identity[$field] ?? '')) !== 1) {
                $blockers[] = 'exact preflight identity is invalid: ' . $field;
            }
        }
        if ($this->policy->requireExternalCopy()
            && preg_match(
                '/\A[a-f0-9]{64}\z/',
                $identity['external_backup_snapshot_sha256']
            ) !== 1) {
            $blockers[] = 'exact preflight external backup identity is invalid';
        }

        $blockers = array_values(array_unique(array_filter(array_map(
            static fn(string $value): string => trim($value),
            $blockers
        ), static fn(string $value): bool => $value !== '')));
        sort($blockers, SORT_STRING);
        ksort($identity, SORT_STRING);
        $planFingerprint = hash('sha256', self::canonicalJson($identity));

        $base['report_version'] = 'v2-exact-production-cutover-preflight';
        $base['technical_ready_for_window'] = ($base['technical_ready_for_window'] ?? false) === true
            && $blockers === [];
        $base['production_switch_allowed'] = false;
        $base['manual_approval_required'] = true;
        $base['cutover_plan_fingerprint'] = $planFingerprint;
        $base['exact_identity'] = $identity;
        $base['package'] = [
            'ready' => ($manifest['ready'] ?? false) === true,
            'package_version' => (string)($manifest['package_version'] ?? ''),
            'build' => (string)($manifest['build'] ?? ''),
            'release_commit' => (string)($manifest['release_commit'] ?? ''),
            'package_fingerprint' => (string)($manifest['package_fingerprint'] ?? ''),
            'critical_file_count' => (int)($manifest['critical_file_count'] ?? 0),
        ];
        $base['runtime_primary_contract'] = [
            'ready' => ($runtimeContract['ready'] ?? false) === true,
            'contract_version' => (string)($runtimeContract['contract_version'] ?? ''),
            'contract_fingerprint' => (string)(
                $runtimeContract['contract_fingerprint'] ?? ''
            ),
        ];
        $base['blockers'] = $blockers;
        $base['execution_mode'] = 'read-only-exact-package-preflight';
        $base['next_step'] = $base['technical_ready_for_window']
            ? 'Issue a separate short-lived run authorization for this exact plan fingerprint.'
            : 'Resolve every blocker and repeat the exact read-only preflight.';
        $base['database_write_executed'] = false;
        $base['persistent_config_changed'] = false;
        $base['webhook_changed'] = false;
        $base['cron_changed'] = false;
        $base['production_changed'] = false;
        $base['sensitive_identifiers_exposed'] = false;
        return $base;
    }

    private static function canonicalJson(array $value): string
    {
        $canonicalize = static function (mixed $item) use (&$canonicalize): mixed {
            if (!is_array($item)) return $item;
            if (!array_is_list($item)) ksort($item, SORT_STRING);
            foreach ($item as $key => $child) $item[$key] = $canonicalize($child);
            return $item;
        };
        return json_encode(
            $canonicalize($value),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        );
    }
}
