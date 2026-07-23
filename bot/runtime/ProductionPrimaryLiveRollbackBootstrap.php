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
        if (!hash_equals(
            $loaded['runtime_config_fingerprint'],
            $runtimeWriter->fingerprint()
        ) || !hash_equals(
            $loaded['cutover_state_fingerprint'],
            $stateStore->cutoverFingerprint()
        )) {
            throw new RuntimeException(
                'Production live rollback inputs changed during bootstrap.'
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
        $result = $service->execute(
            $loaded['export_dir'],
            $loaded['live_data_dir'],
            $loaded['config'],
            $gate
        );

        return $result + [
            'gate_contract_version' => ProductionPrimaryLiveRollbackGate::CONTRACT_VERSION,
            'gate_passed' => true,
            'database_identity_verified' => true,
            'cutover_fingerprint_verified' => true,
            'webhook_changed' => false,
            'cron_changed' => false,
            'sensitive_identifiers_exposed' => false,
        ];
    }
}
