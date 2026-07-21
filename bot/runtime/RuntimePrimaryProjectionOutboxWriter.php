<?php
declare(strict_types=1);

final class RuntimePrimaryProjectionOutboxWriter
{
    public const PROJECTION_VERSION = 'v1-normalized-all-modules';

    public function ensurePending(
        DatabaseConnectionInterface $database,
        int $revision,
        string $stateJson,
        string $stateSha256
    ): array {
        if ($revision < 1) {
            throw new InvalidArgumentException('Projection revision must be positive.');
        }
        $stateSha256 = strtolower(trim($stateSha256));
        if (preg_match('/^[a-f0-9]{64}$/', $stateSha256) !== 1) {
            throw new InvalidArgumentException('Projection fingerprint must be SHA-256.');
        }

        try {
            $decoded = json_decode($stateJson, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $error) {
            throw new RuntimeException('Projection snapshot JSON is invalid.', 0, $error);
        }
        if (!is_array($decoded)) {
            throw new RuntimeException('Projection snapshot must decode to an array.');
        }
        $canonical = $this->canonicalJson($decoded);
        if (!hash_equals($stateJson, $canonical)) {
            throw new RuntimeException('Projection snapshot JSON must be canonical.');
        }
        if (!hash_equals($stateSha256, hash('sha256', $canonical))) {
            throw new RuntimeException('Projection snapshot fingerprint mismatch.');
        }

        $eventId = hash('sha256', self::PROJECTION_VERSION . '|' . $revision . '|' . $stateSha256);
        $existing = $database->fetchAll(
            'SELECT state_revision, event_id, projection_version, state_sha256, state_json,
                    status, attempt_count, created_at_utc
             FROM ' . RuntimePrimaryProjectionOutboxSchemaInstaller::TABLE . '
             WHERE state_revision = :state_revision',
            ['state_revision' => $revision]
        );
        if ($existing !== []) {
            if (count($existing) !== 1 || !is_array($existing[0])) {
                throw new RuntimeException('Projection outbox revision contract is broken.');
            }
            $row = $existing[0];
            if (!hash_equals($eventId, strtolower(trim((string)($row['event_id'] ?? ''))))
                || (string)($row['projection_version'] ?? '') !== self::PROJECTION_VERSION
                || !hash_equals($stateSha256, strtolower(trim((string)($row['state_sha256'] ?? ''))))
                || !hash_equals($canonical, (string)($row['state_json'] ?? ''))) {
                throw new RuntimeException('Existing projection event does not match the committed state.');
            }
            return [
                'ok' => true,
                'created' => false,
                'idempotent' => true,
                'event_id' => $eventId,
                'state_revision' => $revision,
                'status' => (string)($row['status'] ?? ''),
            ];
        }

        $now = gmdate(DATE_ATOM);
        $inserted = $database->execute(
            'INSERT INTO ' . RuntimePrimaryProjectionOutboxSchemaInstaller::TABLE . '
                (state_revision, event_id, projection_version, state_sha256, state_json,
                 status, attempt_count, lease_token, lease_expires_at_utc, last_error,
                 available_at_utc, created_at_utc, updated_at_utc)
             VALUES
                (:state_revision, :event_id, :projection_version, :state_sha256, :state_json,
                 :status, 0, :lease_token, :lease_expires_at_utc, :last_error,
                 :available_at_utc, :created_at_utc, :updated_at_utc)',
            [
                'state_revision' => $revision,
                'event_id' => $eventId,
                'projection_version' => self::PROJECTION_VERSION,
                'state_sha256' => $stateSha256,
                'state_json' => $canonical,
                'status' => 'pending',
                'lease_token' => '',
                'lease_expires_at_utc' => '',
                'last_error' => '',
                'available_at_utc' => $now,
                'created_at_utc' => $now,
                'updated_at_utc' => $now,
            ]
        );
        if ($inserted !== 1) {
            throw new RuntimeException('Projection outbox did not insert exactly one event.');
        }

        return [
            'ok' => true,
            'created' => true,
            'idempotent' => false,
            'event_id' => $eventId,
            'state_revision' => $revision,
            'status' => 'pending',
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
