<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/runtime/RuntimePrimaryStateSchemaInstaller.php';
require_once dirname(__DIR__) . '/runtime/RuntimePrimaryProjectionOutboxSchemaInstaller.php';
require_once dirname(__DIR__) . '/runtime/RuntimePrimaryProjectionOutboxWriter.php';
require_once dirname(__DIR__) . '/runtime/DatabasePrimaryStateStorageAdapter.php';
require_once dirname(__DIR__) . '/runtime/RuntimePrimaryProjectionProjectorInterface.php';
require_once dirname(__DIR__) . '/runtime/RuntimePrimaryModuleProjectorInterface.php';
require_once dirname(__DIR__) . '/runtime/RuntimePrimaryCallbackModuleProjector.php';
require_once dirname(__DIR__) . '/runtime/RuntimePrimaryAccountsModuleProjector.php';
require_once dirname(__DIR__) . '/runtime/RuntimePrimaryAllModuleProjector.php';
require_once dirname(__DIR__) . '/runtime/RuntimePrimaryRepositoryProjectorFactory.php';
require_once dirname(__DIR__) . '/runtime/RuntimePrimaryProjectionWorker.php';
require_once dirname(__DIR__) . '/runtime/RuntimePrimaryProjectionAuditorAdapter.php';

final class ProductionCutoverPrimaryStateSeeder
{
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

    public function __construct(
        private array $config,
        private DatabaseConnectionInterface $database
    ) {
        if (($this->config['environment'] ?? null) !== 'production') {
            throw new RuntimeException('Production DB-primary seeder requires production environment.');
        }
        if (($this->config['storage_driver'] ?? null) !== RuntimeStorageRouter::DRIVER_JSON) {
            throw new RuntimeException('Production DB-primary seeder requires JSON rollback driver.');
        }
        if ($this->database->driver() !== 'mysql') {
            throw new RuntimeException('Production DB-primary seeder requires MySQL/MariaDB.');
        }
    }

    public function seed(array $snapshot): array
    {
        $snapshotSha = hash('sha256', self::canonicalJson($snapshot));
        $stateSchema = (new RuntimePrimaryStateSchemaInstaller($this->database))->install();
        $outboxSchema = (new RuntimePrimaryProjectionOutboxSchemaInstaller($this->database))->install();
        if (($stateSchema['ok'] ?? false) !== true || ($outboxSchema['ok'] ?? false) !== true) {
            throw new RuntimeException('Production DB-primary state or outbox schema installation failed.');
        }

        $adapter = new DatabasePrimaryStateStorageAdapter(
            $this->database,
            new RuntimePrimaryProjectionOutboxWriter()
        );
        $seed = $adapter->initializeFromSnapshot($snapshot);
        $status = $adapter->status();
        $revision = (int)($status['revision'] ?? 0);
        $stateSha = strtolower(trim((string)($status['state_sha256'] ?? '')));
        if ($revision !== 1 || !hash_equals($snapshotSha, $stateSha)) {
            throw new RuntimeException(
                'Production DB-primary seed must produce exact revision 1 from the frozen JSON snapshot.'
            );
        }

        $event = $this->event(1);
        $workerTicks = 0;
        if (($event['status'] ?? '') === 'pending') {
            $projector = $this->projector();
            $worker = new RuntimePrimaryProjectionWorker($this->database, $projector, 120);
            $tick = $worker->runOnce();
            $workerTicks = 1;
            if (($tick['ok'] ?? false) !== true
                || ($tick['action'] ?? '') !== 'projection_completed'
                || ($tick['claimed'] ?? false) !== true
                || (int)($tick['state_revision'] ?? 0) !== 1
                || ($tick['parity_ok'] ?? false) !== true) {
                throw new RuntimeException(
                    'Production DB-primary initial projection did not complete exact revision 1.'
                );
            }
            $event = $this->event(1);
        }
        $this->assertCompletedEvent($event, $snapshotSha);
        $queue = $this->queueStatus();
        $outboxFingerprint = hash('sha256', self::canonicalJson([$event]));

        $projector = $this->projector();
        $audit = (new RuntimePrimaryProjectionAuditorAdapter($projector))->auditOnly(
            $snapshot,
            1,
            $snapshotSha
        );
        $this->assertAudit($audit, $snapshotSha);
        $projectedModules = array_values(array_map(
            'strval',
            (array)($audit['projected_modules'] ?? [])
        ));

        $finalStatus = $adapter->status();
        if ((int)($finalStatus['revision'] ?? 0) !== 1
            || !hash_equals($snapshotSha, (string)($finalStatus['state_sha256'] ?? ''))) {
            throw new RuntimeException('Production DB-primary state changed during initial projection audit.');
        }

        return [
            'ok' => true,
            'action' => ($seed['initialized'] ?? false) === true
                ? 'production_primary_state_initialized'
                : 'production_primary_state_reused_exactly',
            'state_revision' => 1,
            'state_sha256' => $snapshotSha,
            'projection_event_status' => 'completed',
            'projection_version' => RuntimePrimaryProjectionOutboxWriter::PROJECTION_VERSION,
            'worker_tick_count' => $workerTicks,
            'queue' => $queue,
            'outbox_fingerprint' => $outboxFingerprint,
            'projected_modules' => $projectedModules,
            'all_module_fingerprint' => (string)($audit['all_module_fingerprint'] ?? ''),
            'state_schema_fingerprint' => (string)($stateSchema['schema_fingerprint'] ?? ''),
            'outbox_schema_fingerprint' => (string)($outboxSchema['schema_fingerprint'] ?? ''),
            'json_rollback_source_changed' => false,
            'application_entrypoints_changed' => false,
            'webhook_changed' => false,
            'cron_changed' => false,
            'production_changed' => true,
            'sensitive_identifiers_exposed' => false,
        ];
    }

    private function projector(): RuntimePrimaryAllModuleProjector
    {
        $projectionConfig = $this->config;
        $projectionConfig['environment'] = 'staging';
        $projectionConfig['storage_driver'] = RuntimeStorageRouter::DRIVER_JSON;
        if (!isset($projectionConfig['feature_flags'])
            || !is_array($projectionConfig['feature_flags'])) {
            $projectionConfig['feature_flags'] = [];
        }
        $projectionConfig['feature_flags']['database_runtime'] = [
            'enabled' => true,
            'modules' => array_fill_keys(self::MODULES, true),
        ];
        return (new RuntimePrimaryRepositoryProjectorFactory(
            $projectionConfig,
            $this->database
        ))->create();
    }

    private function event(int $revision): array
    {
        $rows = $this->database->fetchAll(
            'SELECT state_revision, state_sha256, projection_version, status,
                    attempt_count, lease_token, lease_expires_at_utc, last_error
             FROM ' . RuntimePrimaryProjectionOutboxSchemaInstaller::TABLE . '
             WHERE state_revision = :state_revision',
            ['state_revision' => $revision]
        );
        if (count($rows) !== 1 || !is_array($rows[0])) {
            throw new RuntimeException('Production DB-primary initial projection event is missing.');
        }
        return [
            'state_revision' => (int)($rows[0]['state_revision'] ?? 0),
            'projection_version' => (string)($rows[0]['projection_version'] ?? ''),
            'state_sha256' => strtolower(trim((string)($rows[0]['state_sha256'] ?? ''))),
            'status' => strtolower(trim((string)($rows[0]['status'] ?? ''))),
            'attempt_count' => max(0, (int)($rows[0]['attempt_count'] ?? 0)),
            'lease_token' => trim((string)($rows[0]['lease_token'] ?? '')),
            'lease_expires_at_utc' => trim((string)($rows[0]['lease_expires_at_utc'] ?? '')),
            'last_error' => trim((string)($rows[0]['last_error'] ?? '')),
        ];
    }

    private function assertCompletedEvent(array $event, string $sha): void
    {
        if ((int)($event['state_revision'] ?? 0) !== 1
            || !hash_equals($sha, (string)($event['state_sha256'] ?? ''))
            || ($event['projection_version'] ?? '')
                !== RuntimePrimaryProjectionOutboxWriter::PROJECTION_VERSION
            || ($event['status'] ?? '') !== 'completed'
            || (int)($event['attempt_count'] ?? 0) < 1
            || ($event['lease_token'] ?? '') !== ''
            || ($event['lease_expires_at_utc'] ?? '') !== ''
            || ($event['last_error'] ?? '') !== '') {
            throw new RuntimeException(
                'Production DB-primary initial projection event is not clean and completed.'
            );
        }
    }

    private function queueStatus(): array
    {
        $rows = $this->database->fetchAll(
            'SELECT status, COUNT(*) AS event_count,
                    MIN(state_revision) AS min_revision,
                    MAX(state_revision) AS max_revision
             FROM ' . RuntimePrimaryProjectionOutboxSchemaInstaller::TABLE . '
             GROUP BY status ORDER BY status'
        );
        if (count($rows) !== 1 || !is_array($rows[0])) {
            throw new RuntimeException('Production DB-primary outbox queue summary is invalid.');
        }
        $row = $rows[0];
        if (($row['status'] ?? '') !== 'completed'
            || (int)($row['event_count'] ?? 0) !== 1
            || (int)($row['min_revision'] ?? 0) !== 1
            || (int)($row['max_revision'] ?? 0) !== 1) {
            throw new RuntimeException(
                'Production DB-primary outbox must contain only completed revision 1 before route publication.'
            );
        }
        return [
            'completed_event_count' => 1,
            'min_revision' => 1,
            'max_revision' => 1,
            'pending_event_count' => 0,
            'processing_event_count' => 0,
            'failed_event_count' => 0,
        ];
    }

    private function assertAudit(array $audit, string $sha): void
    {
        $modules = array_values(array_unique(array_map(
            static fn(mixed $value): string => strtolower(trim((string)$value)),
            (array)($audit['projected_modules'] ?? [])
        )));
        sort($modules, SORT_STRING);
        $expected = self::MODULES;
        sort($expected, SORT_STRING);

        if (($audit['ok'] ?? false) !== true
            || ($audit['parity_ok'] ?? false) !== true
            || ($audit['read_only'] ?? false) !== true
            || (int)($audit['state_revision'] ?? 0) !== 1
            || !hash_equals($sha, (string)($audit['state_sha256'] ?? ''))
            || $modules !== $expected
            || preg_match(
                '/\A[a-f0-9]{64}\z/',
                (string)($audit['all_module_fingerprint'] ?? '')
            ) !== 1) {
            throw new RuntimeException('Production DB-primary all-module initial audit failed.');
        }
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
