<?php
declare(strict_types=1);

final class RuntimePrimaryProjectionWorker
{
    private const MODULES = [
        'accounts', 'realtime', 'invites', 'notifications', 'economy',
        'history', 'shop', 'payments', 'weekly_bonus',
    ];

    public function __construct(
        private DatabaseConnectionInterface $database,
        private RuntimePrimaryProjectionProjectorInterface $projector,
        private int $leaseSeconds = 120,
        private ?int $now = null
    ) {
        if ($leaseSeconds < 30 || $leaseSeconds > 900) {
            throw new InvalidArgumentException('Projection worker lease must be between 30 and 900 seconds.');
        }
    }

    public function runOnce(): array
    {
        $claimed = $this->claimNext();
        if (($claimed['claimed'] ?? false) !== true) {
            return [
                'ok' => true,
                'action' => (string)($claimed['action'] ?? 'projection_noop'),
                'claimed' => false,
                'reason' => (string)($claimed['reason'] ?? ''),
                'state_revision' => (int)($claimed['state_revision'] ?? 0),
                'generated_at_utc' => $this->nowUtc(),
            ];
        }

        $revision = (int)$claimed['state_revision'];
        $fingerprint = (string)$claimed['state_sha256'];
        $leaseToken = (string)$claimed['lease_token'];

        try {
            $snapshot = $this->decodeAndVerifySnapshot(
                (string)$claimed['state_json'],
                $fingerprint
            );
            $result = $this->projector->project($snapshot, $revision, $fingerprint);
            $this->assertProjectionResult($result, $revision, $fingerprint);
            $this->complete($revision, $leaseToken);

            return [
                'ok' => true,
                'action' => 'projection_completed',
                'claimed' => true,
                'state_revision' => $revision,
                'state_sha256' => $fingerprint,
                'attempt_count' => (int)$claimed['attempt_count'],
                'projected_modules' => self::MODULES,
                'parity_ok' => true,
                'completed_at_utc' => $this->nowUtc(),
            ];
        } catch (Throwable $error) {
            $failed = $this->fail($revision, $leaseToken, $error);
            return [
                'ok' => false,
                'action' => 'projection_failed',
                'claimed' => true,
                'state_revision' => $revision,
                'state_sha256' => $fingerprint,
                'attempt_count' => (int)$claimed['attempt_count'],
                'retry_at_utc' => (string)($failed['available_at_utc'] ?? ''),
                'error_class' => get_class($error),
                'error_message' => $this->safeMessage($error->getMessage()),
                'failed_at_utc' => $this->nowUtc(),
            ];
        }
    }

    private function claimNext(): array
    {
        return $this->database->transaction(function (DatabaseConnectionInterface $database): array {
            $sql = 'SELECT state_revision, event_id, projection_version, state_sha256, state_json,
                           status, attempt_count, lease_token, lease_expires_at_utc,
                           available_at_utc, created_at_utc, updated_at_utc
                    FROM ' . RuntimePrimaryProjectionOutboxSchemaInstaller::TABLE . "
                    WHERE status <> 'completed'
                    ORDER BY state_revision ASC
                    LIMIT 1";
            if ($database->driver() === 'mysql') {
                $sql .= ' FOR UPDATE';
            }
            $rows = $database->fetchAll($sql);
            if ($rows === []) {
                return ['claimed' => false, 'action' => 'projection_noop', 'reason' => 'queue_empty'];
            }
            if (count($rows) !== 1 || !is_array($rows[0])) {
                throw new RuntimeException('Projection outbox ordering contract is broken.');
            }

            $row = $rows[0];
            $this->assertEventRow($row);
            $revision = (int)$row['state_revision'];
            $status = strtolower(trim((string)$row['status']));
            $now = $this->timestamp();
            $availableAt = strtotime((string)$row['available_at_utc']);
            $leaseExpiresAt = trim((string)$row['lease_expires_at_utc']) !== ''
                ? strtotime((string)$row['lease_expires_at_utc'])
                : false;

            if ($status === 'processing' && $leaseExpiresAt !== false && $leaseExpiresAt > $now) {
                return [
                    'claimed' => false,
                    'action' => 'projection_busy',
                    'reason' => 'oldest_revision_is_leased',
                    'state_revision' => $revision,
                ];
            }
            if ($availableAt !== false && $availableAt > $now) {
                return [
                    'claimed' => false,
                    'action' => 'projection_delayed',
                    'reason' => 'oldest_revision_not_available_yet',
                    'state_revision' => $revision,
                ];
            }

            $leaseToken = bin2hex(random_bytes(24));
            $attemptCount = (int)$row['attempt_count'] + 1;
            $updated = $database->execute(
                'UPDATE ' . RuntimePrimaryProjectionOutboxSchemaInstaller::TABLE . "
                 SET status = 'processing',
                     attempt_count = :attempt_count,
                     lease_token = :lease_token,
                     lease_expires_at_utc = :lease_expires_at_utc,
                     last_error = '',
                     updated_at_utc = :updated_at_utc
                 WHERE state_revision = :state_revision
                   AND status = :expected_status
                   AND attempt_count = :expected_attempt_count",
                [
                    'attempt_count' => $attemptCount,
                    'lease_token' => $leaseToken,
                    'lease_expires_at_utc' => gmdate(DATE_ATOM, $now + $this->leaseSeconds),
                    'updated_at_utc' => gmdate(DATE_ATOM, $now),
                    'state_revision' => $revision,
                    'expected_status' => $status,
                    'expected_attempt_count' => (int)$row['attempt_count'],
                ]
            );
            if ($updated !== 1) {
                throw new RuntimeException('Projection event claim lost a concurrency race.');
            }

            return [
                'claimed' => true,
                'state_revision' => $revision,
                'state_sha256' => strtolower(trim((string)$row['state_sha256'])),
                'state_json' => (string)$row['state_json'],
                'lease_token' => $leaseToken,
                'attempt_count' => $attemptCount,
            ];
        });
    }

    private function complete(int $revision, string $leaseToken): void
    {
        $this->database->transaction(function (DatabaseConnectionInterface $database) use ($revision, $leaseToken): void {
            $updated = $database->execute(
                'UPDATE ' . RuntimePrimaryProjectionOutboxSchemaInstaller::TABLE . "
                 SET status = 'completed',
                     lease_token = '',
                     lease_expires_at_utc = '',
                     last_error = '',
                     updated_at_utc = :updated_at_utc
                 WHERE state_revision = :state_revision
                   AND status = 'processing'
                   AND lease_token = :lease_token",
                [
                    'updated_at_utc' => $this->nowUtc(),
                    'state_revision' => $revision,
                    'lease_token' => $leaseToken,
                ]
            );
            if ($updated !== 1) {
                throw new RuntimeException('Projection completion lease no longer matches.');
            }
        });
    }

    private function fail(int $revision, string $leaseToken, Throwable $error): array
    {
        return $this->database->transaction(function (DatabaseConnectionInterface $database) use ($revision, $leaseToken, $error): array {
            $rows = $database->fetchAll(
                'SELECT attempt_count FROM ' . RuntimePrimaryProjectionOutboxSchemaInstaller::TABLE . '
                 WHERE state_revision = :state_revision',
                ['state_revision' => $revision]
            );
            $attemptCount = (int)($rows[0]['attempt_count'] ?? 1);
            $delay = min(900, 15 * (2 ** min(6, max(0, $attemptCount - 1))));
            $availableAt = gmdate(DATE_ATOM, $this->timestamp() + $delay);
            $updated = $database->execute(
                'UPDATE ' . RuntimePrimaryProjectionOutboxSchemaInstaller::TABLE . "
                 SET status = 'failed',
                     lease_token = '',
                     lease_expires_at_utc = '',
                     last_error = :last_error,
                     available_at_utc = :available_at_utc,
                     updated_at_utc = :updated_at_utc
                 WHERE state_revision = :state_revision
                   AND status = 'processing'
                   AND lease_token = :lease_token",
                [
                    'last_error' => $this->safeMessage($error->getMessage()),
                    'available_at_utc' => $availableAt,
                    'updated_at_utc' => $this->nowUtc(),
                    'state_revision' => $revision,
                    'lease_token' => $leaseToken,
                ]
            );
            if ($updated !== 1) {
                throw new RuntimeException('Projection failure lease no longer matches.', 0, $error);
            }
            return ['available_at_utc' => $availableAt];
        });
    }

    private function decodeAndVerifySnapshot(string $stateJson, string $stateSha256): array
    {
        try {
            $snapshot = json_decode($stateJson, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $error) {
            throw new RuntimeException('Projection event snapshot JSON is invalid.', 0, $error);
        }
        if (!is_array($snapshot)) {
            throw new RuntimeException('Projection event snapshot must decode to an array.');
        }
        $canonical = $this->canonicalJson($snapshot);
        if (!hash_equals($stateJson, $canonical)
            || !hash_equals($stateSha256, hash('sha256', $canonical))) {
            throw new RuntimeException('Projection event snapshot integrity check failed.');
        }
        return $snapshot;
    }

    private function assertProjectionResult(array $result, int $revision, string $fingerprint): void
    {
        if (($result['ok'] ?? false) !== true || ($result['parity_ok'] ?? false) !== true) {
            throw new RuntimeException('Projection result did not pass parity.');
        }
        if ((int)($result['state_revision'] ?? 0) !== $revision
            || !hash_equals($fingerprint, strtolower(trim((string)($result['state_sha256'] ?? ''))))) {
            throw new RuntimeException('Projection result does not match the claimed revision and fingerprint.');
        }
        $modules = array_values(array_unique(array_map('strval', (array)($result['projected_modules'] ?? []))));
        sort($modules, SORT_STRING);
        $required = self::MODULES;
        sort($required, SORT_STRING);
        if ($modules !== $required) {
            throw new RuntimeException('Projection result is missing required runtime modules.');
        }
    }

    private function assertEventRow(array $row): void
    {
        if ((int)($row['state_revision'] ?? 0) < 1) {
            throw new RuntimeException('Projection event revision is invalid.');
        }
        if ((string)($row['projection_version'] ?? '') !== RuntimePrimaryProjectionOutboxWriter::PROJECTION_VERSION) {
            throw new RuntimeException('Projection event version is unsupported.');
        }
        if (preg_match('/^[a-f0-9]{64}$/', strtolower(trim((string)($row['state_sha256'] ?? '')))) !== 1) {
            throw new RuntimeException('Projection event fingerprint is invalid.');
        }
        if (!in_array(strtolower(trim((string)($row['status'] ?? ''))), ['pending', 'processing', 'failed'], true)) {
            throw new RuntimeException('Projection event status is invalid.');
        }
        if ((int)($row['attempt_count'] ?? -1) < 0) {
            throw new RuntimeException('Projection event attempt count is invalid.');
        }
        foreach (['available_at_utc', 'created_at_utc', 'updated_at_utc'] as $field) {
            if (strtotime((string)($row[$field] ?? '')) === false) {
                throw new RuntimeException('Projection event timestamp is invalid: ' . $field . '.');
            }
        }
    }

    private function safeMessage(string $message): string
    {
        $message = preg_replace('~/(?:home|var|tmp|srv)/[^\s\'\"]+~', '[private-path]', $message) ?? $message;
        return mb_substr(trim($message), 0, 500);
    }

    private function canonicalJson(array $value): string
    {
        return json_encode($this->canonicalize($value), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    private function canonicalize(mixed $value): mixed
    {
        if (!is_array($value)) return $value;
        if (!array_is_list($value)) ksort($value, SORT_STRING);
        foreach ($value as $key => $item) $value[$key] = $this->canonicalize($item);
        return $value;
    }

    private function timestamp(): int { return $this->now ?? time(); }
    private function nowUtc(): string { return gmdate(DATE_ATOM, $this->timestamp()); }
}
