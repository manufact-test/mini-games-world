<?php
declare(strict_types=1);

final class ProductionPrimaryLiveRollbackBootstrap
{
    public function __construct(private string $projectRoot)
    {
        $canonical = realpath($this->projectRoot);
        if (!is_string($canonical) || !hash_equals($this->projectRoot, $canonical)) {
            throw new RuntimeException('Production live rollback project root is invalid.');
        }
        $this->projectRoot = $canonical;
    }

    public function execute(array $loaded, int $now): array
    {
        foreach ([
            'config', 'cutover', 'authorization', 'artifact',
        ] as $field) {
            if (!is_array($loaded[$field] ?? null)) {
                throw new RuntimeException('Production live rollback loaded input is incomplete: ' . $field . '.');
            }
        }
        foreach ([
            'runtime_file', 'cutover_file', 'private_dir', 'export_dir', 'live_data_dir',
            'database_identity_fingerprint', 'cutover_state_fingerprint',
            'runtime_config_fingerprint', 'live_data_directory_fingerprint',
        ] as $field) {
            if (!is_string($loaded[$field] ?? null) || $loaded[$field] === '') {
                throw new RuntimeException('Production live rollback loaded path identity is incomplete: ' . $field . '.');
            }
        }

        $bootstrapLockPath = $loaded['private_dir'] . '/production-live-rollback.bootstrap.lock';
        if (is_link($bootstrapLockPath)) {
            throw new RuntimeException('Production live rollback bootstrap lock is unsafe.');
        }
        $bootstrapLock = fopen($bootstrapLockPath, 'c+');
        if ($bootstrapLock === false || !chmod($bootstrapLockPath, 0600)) {
            if (is_resource($bootstrapLock)) fclose($bootstrapLock);
            throw new RuntimeException('Production live rollback bootstrap lock is unavailable.');
        }
        if (!flock($bootstrapLock, LOCK_EX | LOCK_NB)) {
            fclose($bootstrapLock);
            throw new RuntimeException('Another production live rollback bootstrap is already running.');
        }

        try {
            return $this->executeLocked($loaded, $now);
        } finally {
            flock($bootstrapLock, LOCK_UN);
            fclose($bootstrapLock);
        }
    }

    private function executeLocked(array $loaded, int $now): array
    {
        $gate = (new ProductionPrimaryLiveRollbackGate())->inspect(
            $loaded['config'],
            $loaded['cutover'],
            $loaded['authorization'],
            $loaded['artifact'],
            $loaded['live_data_directory_fingerprint'],
            $loaded['runtime_config_fingerprint'],
            $now
        );
        if (($gate['ready'] ?? false) !== true) {
            throw new RuntimeException(
                'Production live rollback gate is blocked: '
                . implode('; ', array_map('strval', (array)($gate['blockers'] ?? [])))
            );
        }
        if (($loaded['artifact']['database_identity_fingerprint'] ?? null)
                !== $loaded['database_identity_fingerprint']
            || ($loaded['authorization']['database_identity_fingerprint'] ?? null)
                !== $loaded['database_identity_fingerprint']) {
            throw new RuntimeException('Production live rollback database identity is inconsistent.');
        }
        if (($loaded['authorization']['cutover_state_fingerprint'] ?? null)
                !== $loaded['cutover_state_fingerprint']) {
            throw new RuntimeException('Production live rollback cutover fingerprint changed.');
        }
        $gate['cutover_state_fingerprint'] = $loaded['cutover_state_fingerprint'];
        $gate['database_identity_fingerprint'] = $loaded['database_identity_fingerprint'];

        $databaseConfig = DatabaseConfig::fromApplicationConfig($loaded['config']);
        if (!$databaseConfig->enabled()) {
            throw new RuntimeException('Production live rollback database config is disabled.');
        }
        $database = PdoConnectionFactory::create($databaseConfig);
        if ((int)$database->fetchValue('SELECT 1') !== 1) {
            throw new RuntimeException('Production live rollback database readiness probe failed.');
        }

        $auditor = (new ProductionPrimaryLiveRollbackAuditorFactory(
            $loaded['config'],
            $database,
            $gate
        ))->create();
        $verifier = new ProductionPrimaryRollbackExportVerifier();
        $artifactIdentity = new ProductionPrimaryRollbackArtifactIdentity($verifier);
        $backupManager = new BackupManager(
            $this->projectRoot,
            $loaded['live_data_dir'],
            dirname($loaded['export_dir']),
            null,
            7,
            7,
            false
        );
        $restoreService = new ProductionPrimaryRollbackRestoreService(
            $backupManager,
            $verifier
        );
        $runtimeWriter = new ProductionPrimaryRuntimeOverlayWriter(
            $loaded['runtime_file']
        );
        $stateStore = new ProductionPrimaryLiveRollbackStateStore(
            $loaded['private_dir'],
            $loaded['cutover_file']
        );

        // The input loader ran before this process acquired the bootstrap lock.
        // Revalidate every mutable private identity while serialized, immediately
        // before creating the state machine.
        if (!hash_equals(
            $loaded['runtime_config_fingerprint'],
            $runtimeWriter->fingerprint()
        ) || !hash_equals(
            $loaded['cutover_state_fingerprint'],
            $stateStore->cutoverFingerprint()
        ) || !hash_equals(
            $loaded['live_data_directory_fingerprint'],
            hash('sha256', $loaded['live_data_dir'])
        ) || !hash_equals(
            $loaded['export_directory_fingerprint'],
            hash('sha256', $loaded['export_dir'])
        )) {
            throw new RuntimeException(
                'Production live rollback inputs changed before serialized execution.'
            );
        }

        $service = new ProductionPrimaryLiveRollbackService(
            $database,
            $auditor,
            $artifactIdentity,
            $verifier,
            $restoreService,
            $runtimeWriter,
            $stateStore,
            $loaded['private_dir']
        );

        $resumeLock = null;
        $recoveryState = (string)($stateStore->recovery()['state'] ?? '');
        if (in_array($recoveryState, ['json_route_sealed', 'sealed_resume_required'], true)) {
            $lockPath = $loaded['live_data_dir'] . '/app.lock';
            if (is_link($lockPath) || !is_file($lockPath)) {
                throw new RuntimeException('Production sealed rollback resume app lock is unavailable.');
            }
            clearstatcache(true, $lockPath);
            $mode = fileperms($lockPath);
            if (!is_int($mode) || ($mode & 0777) !== 0600) {
                throw new RuntimeException('Production sealed rollback resume app lock must have mode 0600.');
            }
            $resumeLock = fopen($lockPath, 'c+');
            if ($resumeLock === false || !flock($resumeLock, LOCK_EX)) {
                if (is_resource($resumeLock)) fclose($resumeLock);
                throw new RuntimeException('Production sealed rollback resume app lock could not be acquired.');
            }
        }

        try {
            $result = $service->execute(
                $loaded['export_dir'],
                $loaded['live_data_dir'],
                $loaded['config'],
                $gate
            );
        } finally {
            if (is_resource($resumeLock)) {
                flock($resumeLock, LOCK_UN);
                fclose($resumeLock);
            }
        }

        return $result + [
            'gate_contract_version' => ProductionPrimaryLiveRollbackGate::CONTRACT_VERSION,
            'gate_passed' => true,
            'database_identity_verified' => true,
            'cutover_fingerprint_verified' => true,
            'bootstrap_serialized' => true,
            'webhook_changed' => false,
            'cron_changed' => false,
            'sensitive_identifiers_exposed' => false,
        ];
    }
}
