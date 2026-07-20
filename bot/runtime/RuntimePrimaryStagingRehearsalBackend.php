<?php
declare(strict_types=1);

final class RuntimePrimaryStagingRehearsalBackend implements RuntimePrimaryRehearsalBackendInterface
{
    public function __construct(
        private array $config,
        private StorageAdapterInterface $jsonStorage,
        private DatabaseConnectionInterface $database,
        private int $workerLeaseSeconds = 120
    ) {
        $environment = strtolower(trim((string)($this->config['environment'] ?? '')));
        if (!in_array($environment, ['local', 'staging'], true)) {
            throw new RuntimeException('DB-primary rehearsal backend is local/staging-only.');
        }
    }

    public function installSchemas(): array
    {
        $state = (new RuntimePrimaryStateSchemaInstaller($this->database))->install();
        $outbox = (new RuntimePrimaryProjectionOutboxSchemaInstaller($this->database))->install();
        if (empty($state['ok']) || empty($outbox['ok'])) {
            throw new RuntimeException('DB-primary rehearsal schema installation failed.');
        }

        return [
            'ok' => true,
            'state_schema' => $this->compactSchema($state),
            'outbox_schema' => $this->compactSchema($outbox),
            'production_changed' => false,
            'sensitive_identifiers_exposed' => false,
        ];
    }

    public function synchronizeCurrentSnapshot(): array
    {
        $snapshot = $this->jsonStorage->readOnly(static fn(array $data): array => $data);
        if (!is_array($snapshot)) {
            throw new RuntimeException('DB-primary rehearsal JSON snapshot is unavailable.');
        }
        $snapshotJson = $this->canonicalJson($snapshot);
        $snapshotSha = hash('sha256', $snapshotJson);
        $adapter = new DatabasePrimaryStateStorageAdapter(
            $this->database,
            new RuntimePrimaryProjectionOutboxWriter()
        );

        $before = null;
        try {
            $before = $adapter->status();
        } catch (Throwable $error) {
            if (!str_contains(strtolower($error->getMessage()), 'not initialized')) {
                throw $error;
            }
        }

        if ($before === null) {
            $seed = $adapter->initializeFromSnapshot($snapshot);
            $action = 'snapshot_initialized';
        } elseif (hash_equals((string)$before['state_sha256'], $snapshotSha)) {
            $seed = $adapter->initializeFromSnapshot($snapshot);
            $action = 'snapshot_unchanged';
        } else {
            $adapter->transaction(static function (array &$state) use ($snapshot): void {
                $state = $snapshot;
            });
            $seed = [
                'ok' => true,
                'initialized' => false,
                'idempotent' => false,
                'projection_event_created' => true,
            ];
            $action = 'snapshot_revision_created';
        }

        $after = $adapter->status();
        if (!hash_equals($snapshotSha, (string)$after['state_sha256'])) {
            throw new RuntimeException('DB-primary rehearsal state does not match the current JSON snapshot.');
        }
        $event = $this->eventStatus((int)$after['revision']);
        if (($event['present'] ?? false) !== true
            || !hash_equals($snapshotSha, (string)($event['state_sha256'] ?? ''))) {
            throw new RuntimeException('DB-primary rehearsal projection event is missing or mismatched.');
        }

        return [
            'ok' => true,
            'action' => $action,
            'state_revision' => (int)$after['revision'],
            'state_sha256' => $snapshotSha,
            'projection_event_status' => (string)($event['status'] ?? ''),
            'projection_event_created' => !empty($seed['projection_event_created']),
            'snapshot_inventory' => $this->snapshotInventory($snapshot),
            'production_changed' => false,
            'sensitive_identifiers_exposed' => false,
        ];
    }

    public function runWorkerOnce(): array
    {
        $projector = (new RuntimePrimaryRepositoryProjectorFactory(
            $this->config,
            $this->database
        ))->create();
        $result = (new RuntimePrimaryProjectionWorker(
            $this->database,
            $projector,
            $this->workerLeaseSeconds
        ))->runOnce();

        return $this->compactWorkerResult($result);
    }

    public function status(): array
    {
        $state = null;
        $stateError = '';
        try {
            $state = (new DatabasePrimaryStateStorageAdapter($this->database))->status();
        } catch (Throwable $error) {
            $stateError = $this->safeMessage($error->getMessage());
        }

        $outbox = [];
        $outboxError = '';
        try {
            $rows = $this->database->fetchAll(
                'SELECT status, COUNT(*) AS event_count,
                        MIN(state_revision) AS min_revision,
                        MAX(state_revision) AS max_revision
                 FROM ' . RuntimePrimaryProjectionOutboxSchemaInstaller::TABLE . '
                 GROUP BY status ORDER BY status'
            );
            foreach ($rows as $row) {
                $status = strtolower(trim((string)($row['status'] ?? '')));
                if ($status === '') continue;
                $outbox[$status] = [
                    'event_count' => max(0, (int)($row['event_count'] ?? 0)),
                    'min_revision' => max(0, (int)($row['min_revision'] ?? 0)),
                    'max_revision' => max(0, (int)($row['max_revision'] ?? 0)),
                ];
            }
            ksort($outbox, SORT_STRING);
        } catch (Throwable $error) {
            $outboxError = $this->safeMessage($error->getMessage());
        }

        return [
            'ok' => $stateError === '' && $outboxError === '',
            'state' => is_array($state) ? [
                'initialized' => true,
                'revision' => (int)($state['revision'] ?? 0),
                'state_sha256' => (string)($state['state_sha256'] ?? ''),
                'updated_at_utc' => (string)($state['updated_at_utc'] ?? ''),
            ] : [
                'initialized' => false,
                'revision' => 0,
                'state_sha256' => '',
                'updated_at_utc' => '',
            ],
            'state_error' => $stateError,
            'outbox' => $outbox,
            'outbox_error' => $outboxError,
            'production_changed' => false,
            'sensitive_identifiers_exposed' => false,
        ];
    }

    public function eventStatus(int $stateRevision): array
    {
        if ($stateRevision < 1) {
            throw new InvalidArgumentException('Rehearsal event revision must be positive.');
        }
        $rows = $this->database->fetchAll(
            'SELECT state_revision, state_sha256, projection_version, status,
                    attempt_count, lease_expires_at_utc, last_error,
                    available_at_utc, created_at_utc, updated_at_utc
             FROM ' . RuntimePrimaryProjectionOutboxSchemaInstaller::TABLE . '
             WHERE state_revision = :state_revision',
            ['state_revision' => $stateRevision]
        );
        if ($rows === []) {
            return [
                'present' => false,
                'state_revision' => $stateRevision,
                'status' => 'missing',
            ];
        }
        if (count($rows) !== 1 || !is_array($rows[0])) {
            throw new RuntimeException('Rehearsal outbox revision is ambiguous.');
        }
        $row = $rows[0];
        return [
            'present' => true,
            'state_revision' => (int)($row['state_revision'] ?? 0),
            'state_sha256' => strtolower(trim((string)($row['state_sha256'] ?? ''))),
            'projection_version' => (string)($row['projection_version'] ?? ''),
            'status' => strtolower(trim((string)($row['status'] ?? ''))),
            'attempt_count' => max(0, (int)($row['attempt_count'] ?? 0)),
            'lease_expires_at_utc' => (string)($row['lease_expires_at_utc'] ?? ''),
            'last_error' => $this->safeMessage((string)($row['last_error'] ?? '')),
            'available_at_utc' => (string)($row['available_at_utc'] ?? ''),
            'created_at_utc' => (string)($row['created_at_utc'] ?? ''),
            'updated_at_utc' => (string)($row['updated_at_utc'] ?? ''),
        ];
    }

    private function compactSchema(array $schema): array
    {
        return [
            'ok' => !empty($schema['ok']),
            'table' => (string)($schema['table'] ?? ''),
            'driver' => (string)($schema['driver'] ?? ''),
            'engine' => (string)($schema['engine'] ?? ''),
            'schema_fingerprint' => (string)($schema['schema_fingerprint'] ?? ''),
        ];
    }

    private function compactWorkerResult(array $result): array
    {
        return [
            'ok' => !empty($result['ok']),
            'action' => (string)($result['action'] ?? ''),
            'claimed' => !empty($result['claimed']),
            'reason' => (string)($result['reason'] ?? ''),
            'state_revision' => (int)($result['state_revision'] ?? 0),
            'state_sha256' => (string)($result['state_sha256'] ?? ''),
            'attempt_count' => max(0, (int)($result['attempt_count'] ?? 0)),
            'projected_modules' => array_values(array_map(
                'strval',
                (array)($result['projected_modules'] ?? [])
            )),
            'parity_ok' => !empty($result['parity_ok']),
            'retry_at_utc' => (string)($result['retry_at_utc'] ?? ''),
            'error_class' => (string)($result['error_class'] ?? ''),
            'error_message' => $this->safeMessage((string)($result['error_message'] ?? '')),
            'generated_at_utc' => (string)(
                $result['completed_at_utc']
                ?? $result['failed_at_utc']
                ?? $result['generated_at_utc']
                ?? ''
            ),
            'production_changed' => false,
            'sensitive_identifiers_exposed' => false,
        ];
    }

    private function snapshotInventory(array $snapshot): array
    {
        $inventory = [];
        foreach ([
            'users', 'games', 'queue', 'invites', 'notifications',
            'transactions', 'shop_orders', 'payments',
        ] as $section) {
            $inventory[$section . '_count'] = count(
                is_array($snapshot[$section] ?? null) ? $snapshot[$section] : []
            );
        }
        return $inventory;
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

    private function safeMessage(string $message): string
    {
        $message = preg_replace('~/(?:home|var|tmp|srv)/[^\s\'\"]+~', '[private-path]', $message) ?? $message;
        $message = trim($message);
        return function_exists('mb_substr')
            ? mb_substr($message, 0, 500)
            : substr($message, 0, 500);
    }
}
