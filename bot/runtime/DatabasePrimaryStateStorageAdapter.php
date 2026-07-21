<?php
declare(strict_types=1);

final class DatabasePrimaryStateStorageAdapter implements StorageAdapterInterface
{
    public const DRIVER = 'database';

    public function __construct(
        private DatabaseConnectionInterface $database,
        private ?RuntimePrimaryProjectionOutboxWriter $projectionOutbox = null
    ) {}

    public function driver(): string
    {
        return self::DRIVER;
    }

    public function initializeFromSnapshot(array $snapshot): array
    {
        return $this->database->transaction(function (DatabaseConnectionInterface $database) use ($snapshot): array {
            $existing = $this->row($database, true);
            $encoded = $this->canonicalJson($snapshot);
            $fingerprint = hash('sha256', $encoded);

            if ($existing !== null) {
                $existingState = $this->decodeAndVerify($existing);
                $existingJson = $this->canonicalJson($existingState);
                $existingFingerprint = hash('sha256', $existingJson);
                if (!hash_equals($existingFingerprint, $fingerprint)) {
                    throw new RuntimeException(
                        'Runtime primary state is already initialized with a different snapshot.'
                    );
                }
                $revision = (int)($existing['revision'] ?? 0);
                $projection = $this->ensureProjectionEvent(
                    $database,
                    $revision,
                    $existingJson,
                    $existingFingerprint
                );
                return [
                    'ok' => true,
                    'initialized' => false,
                    'idempotent' => true,
                    'revision' => $revision,
                    'state_sha256' => $fingerprint,
                    'projection_outbox_enabled' => $this->projectionOutbox !== null,
                    'projection_event_created' => ($projection['created'] ?? false) === true,
                    'projection_event_id' => (string)($projection['event_id'] ?? ''),
                ];
            }

            $now = gmdate(DATE_ATOM);
            $inserted = $database->execute(
                'INSERT INTO ' . RuntimePrimaryStateSchemaInstaller::TABLE . '
                    (singleton_id, revision, state_json, state_sha256, created_at_utc, updated_at_utc)
                 VALUES
                    (1, :revision, :state_json, :state_sha256, :created_at_utc, :updated_at_utc)',
                [
                    'revision' => 1,
                    'state_json' => $encoded,
                    'state_sha256' => $fingerprint,
                    'created_at_utc' => $now,
                    'updated_at_utc' => $now,
                ]
            );
            if ($inserted !== 1) {
                throw new RuntimeException('Runtime primary state initialization did not insert exactly one row.');
            }

            $projection = $this->ensureProjectionEvent($database, 1, $encoded, $fingerprint);
            return [
                'ok' => true,
                'initialized' => true,
                'idempotent' => false,
                'revision' => 1,
                'state_sha256' => $fingerprint,
                'projection_outbox_enabled' => $this->projectionOutbox !== null,
                'projection_event_created' => ($projection['created'] ?? false) === true,
                'projection_event_id' => (string)($projection['event_id'] ?? ''),
            ];
        });
    }

    public function transaction(callable $callback): mixed
    {
        return $this->database->transaction(function (DatabaseConnectionInterface $database) use ($callback): mixed {
            $row = $this->requiredRow($database, true);
            $data = $this->decodeAndVerify($row);
            $before = $this->canonicalJson($data);
            $result = $callback($data);
            $after = $this->canonicalJson($data);

            if (hash_equals($before, $after)) {
                return $result;
            }

            $revision = (int)($row['revision'] ?? 0);
            if ($revision < 1) {
                throw new RuntimeException('Runtime primary state revision is invalid.');
            }
            $nextRevision = $revision + 1;
            $afterFingerprint = hash('sha256', $after);
            $updated = $database->execute(
                'UPDATE ' . RuntimePrimaryStateSchemaInstaller::TABLE . '
                 SET revision = :next_revision,
                     state_json = :state_json,
                     state_sha256 = :state_sha256,
                     updated_at_utc = :updated_at_utc
                 WHERE singleton_id = 1 AND revision = :expected_revision',
                [
                    'next_revision' => $nextRevision,
                    'state_json' => $after,
                    'state_sha256' => $afterFingerprint,
                    'updated_at_utc' => gmdate(DATE_ATOM),
                    'expected_revision' => $revision,
                ]
            );
            if ($updated !== 1) {
                throw new RuntimeException('Concurrent runtime primary state update was detected.');
            }

            $this->ensureProjectionEvent($database, $nextRevision, $after, $afterFingerprint);
            return $result;
        });
    }

    public function readOnly(callable $callback): mixed
    {
        return $this->database->transaction(function (DatabaseConnectionInterface $database) use ($callback): mixed {
            $row = $this->requiredRow($database, false);
            return $callback($this->decodeAndVerify($row));
        });
    }

    public function status(): array
    {
        $row = $this->requiredRow($this->database, false);
        $this->decodeAndVerify($row);
        return [
            'ok' => true,
            'driver' => self::DRIVER,
            'table' => RuntimePrimaryStateSchemaInstaller::TABLE,
            'revision' => (int)($row['revision'] ?? 0),
            'state_sha256' => strtolower(trim((string)($row['state_sha256'] ?? ''))),
            'updated_at_utc' => (string)($row['updated_at_utc'] ?? ''),
            'projection_outbox_enabled' => $this->projectionOutbox !== null,
        ];
    }

    private function ensureProjectionEvent(
        DatabaseConnectionInterface $database,
        int $revision,
        string $stateJson,
        string $stateSha256
    ): array {
        if ($this->projectionOutbox === null) {
            return ['created' => false, 'event_id' => ''];
        }
        return $this->projectionOutbox->ensurePending(
            $database,
            $revision,
            $stateJson,
            $stateSha256
        );
    }

    private function requiredRow(DatabaseConnectionInterface $database, bool $forUpdate): array
    {
        $row = $this->row($database, $forUpdate);
        if ($row === null) {
            throw new RuntimeException('Runtime primary state is not initialized.');
        }
        return $row;
    }

    private function row(DatabaseConnectionInterface $database, bool $forUpdate): ?array
    {
        $sql = 'SELECT singleton_id, revision, state_json, state_sha256, created_at_utc, updated_at_utc
                FROM ' . RuntimePrimaryStateSchemaInstaller::TABLE . '
                WHERE singleton_id = 1';
        if ($forUpdate && $database->driver() === 'mysql') {
            $sql .= ' FOR UPDATE';
        }
        $rows = $database->fetchAll($sql);
        if ($rows === []) return null;
        if (count($rows) !== 1 || !is_array($rows[0])) {
            throw new RuntimeException('Runtime primary state singleton contract is broken.');
        }
        return $rows[0];
    }

    private function decodeAndVerify(array $row): array
    {
        $this->assertRowValid($row);
        try {
            $decoded = json_decode((string)$row['state_json'], true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $error) {
            throw new RuntimeException('Runtime primary state JSON is invalid.', 0, $error);
        }
        if (!is_array($decoded)) {
            throw new RuntimeException('Runtime primary state must decode to an array.');
        }
        $actual = hash('sha256', $this->canonicalJson($decoded));
        $expected = strtolower(trim((string)$row['state_sha256']));
        if (!hash_equals($expected, $actual)) {
            throw new RuntimeException('Runtime primary state fingerprint mismatch.');
        }
        return $decoded;
    }

    private function assertRowValid(array $row): void
    {
        if ((int)($row['singleton_id'] ?? 0) !== 1) {
            throw new RuntimeException('Runtime primary state singleton ID is invalid.');
        }
        if ((int)($row['revision'] ?? 0) < 1) {
            throw new RuntimeException('Runtime primary state revision is invalid.');
        }
        if (preg_match('/^[a-f0-9]{64}$/', strtolower(trim((string)($row['state_sha256'] ?? '')))) !== 1) {
            throw new RuntimeException('Runtime primary state fingerprint is invalid.');
        }
        if (!is_string($row['state_json'] ?? null) || trim((string)$row['state_json']) === '') {
            throw new RuntimeException('Runtime primary state payload is empty.');
        }
        foreach (['created_at_utc', 'updated_at_utc'] as $field) {
            $timestamp = trim((string)($row[$field] ?? ''));
            if ($timestamp === ''
                || preg_match('/(?:Z|[+-]\d{2}:\d{2})$/', $timestamp) !== 1
                || strtotime($timestamp) === false) {
                throw new RuntimeException('Runtime primary state timestamp is invalid: ' . $field . '.');
            }
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
