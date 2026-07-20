<?php
declare(strict_types=1);

final class RuntimePrimaryStagingConcurrencyProbe
{
    private const TABLE = 'mgw_runtime_primary_lease_probe';

    public function __construct(
        private DatabaseConnectionInterface $firstDatabase,
        private DatabaseConnectionInterface $secondDatabase,
        private string $lockPath,
        private int $leaseSeconds = 120
    ) {
        if ($this->firstDatabase->driver() !== 'mysql'
            || $this->secondDatabase->driver() !== 'mysql') {
            throw new RuntimeException('Staging concurrency probe requires two MySQL connections.');
        }
        $this->lockPath = str_replace('\\', '/', trim($this->lockPath));
        if ($this->lockPath === '' || !str_starts_with($this->lockPath, '/')) {
            throw new InvalidArgumentException('Staging concurrency probe lock path must be absolute.');
        }
        if ($this->leaseSeconds < 30 || $this->leaseSeconds > 900) {
            throw new InvalidArgumentException('Staging concurrency probe lease must be between 30 and 900 seconds.');
        }
    }

    public function run(): array
    {
        return [
            'cli_lock' => $this->probeCliLock(),
            'worker_lease' => $this->probeWorkerLease(),
        ];
    }

    private function probeCliLock(): array
    {
        $parent = dirname($this->lockPath);
        if (!is_dir($parent)) {
            throw new RuntimeException('Staging concurrency probe lock directory is unavailable.');
        }
        if (is_link($this->lockPath)) {
            throw new RuntimeException('Staging concurrency probe lock file must not be a symbolic link.');
        }

        $first = fopen($this->lockPath, 'c+');
        $second = null;
        if (!is_resource($first)) {
            throw new RuntimeException('Staging concurrency probe could not open the first lock handle.');
        }
        try {
            if (!flock($first, LOCK_EX | LOCK_NB)) {
                throw new RuntimeException('Staging concurrency probe could not acquire the first lock.');
            }
            $second = fopen($this->lockPath, 'c+');
            if (!is_resource($second)) {
                throw new RuntimeException('Staging concurrency probe could not open the second lock handle.');
            }
            $secondAcquired = flock($second, LOCK_EX | LOCK_NB);
            if ($secondAcquired) flock($second, LOCK_UN);

            return [
                'ok' => $secondAcquired === false,
                'second_exit_code' => $secondAcquired ? 0 : 2,
                'second_result' => $secondAcquired
                    ? 'rehearsal_lock_not_blocked'
                    : 'rehearsal_lock_blocked',
            ];
        } finally {
            if (is_resource($second)) fclose($second);
            flock($first, LOCK_UN);
            fclose($first);
            @unlink($this->lockPath);
        }
    }

    private function probeWorkerLease(): array
    {
        $this->installProbeTable();
        $probeId = hash('sha256', random_bytes(32));
        $revision = max(1, (int)floor(microtime(true) * 1000));
        $now = time();
        $createdAt = gmdate(DATE_ATOM, $now);
        $leaseToken = bin2hex(random_bytes(24));
        $leaseExpiresAt = gmdate(DATE_ATOM, $now + $this->leaseSeconds);

        try {
            $inserted = $this->firstDatabase->execute(
                'INSERT INTO ' . self::TABLE . ' (
                    probe_id, state_revision, status, lease_token,
                    lease_expires_at_utc, created_at_utc, updated_at_utc
                 ) VALUES (
                    :probe_id, :state_revision, :status, :lease_token,
                    :lease_expires_at_utc, :created_at_utc, :updated_at_utc
                 )',
                [
                    'probe_id' => $probeId,
                    'state_revision' => $revision,
                    'status' => 'pending',
                    'lease_token' => '',
                    'lease_expires_at_utc' => '',
                    'created_at_utc' => $createdAt,
                    'updated_at_utc' => $createdAt,
                ]
            );
            if ($inserted !== 1) {
                throw new RuntimeException('Staging worker lease probe did not create exactly one row.');
            }

            $firstClaimed = $this->firstDatabase->transaction(function (DatabaseConnectionInterface $database) use (
                $probeId,
                $leaseToken,
                $leaseExpiresAt,
                $createdAt
            ): bool {
                $rows = $database->fetchAll(
                    'SELECT state_revision, status, lease_token, lease_expires_at_utc
                     FROM ' . self::TABLE . '
                     WHERE probe_id = :probe_id FOR UPDATE',
                    ['probe_id' => $probeId]
                );
                if (count($rows) !== 1 || (string)($rows[0]['status'] ?? '') !== 'pending') {
                    throw new RuntimeException('Staging worker lease probe first claim row is invalid.');
                }
                $updated = $database->execute(
                    'UPDATE ' . self::TABLE . '
                     SET status = :status,
                         lease_token = :lease_token,
                         lease_expires_at_utc = :lease_expires_at_utc,
                         updated_at_utc = :updated_at_utc
                     WHERE probe_id = :probe_id AND status = :expected_status',
                    [
                        'status' => 'processing',
                        'lease_token' => $leaseToken,
                        'lease_expires_at_utc' => $leaseExpiresAt,
                        'updated_at_utc' => $createdAt,
                        'probe_id' => $probeId,
                        'expected_status' => 'pending',
                    ]
                );
                return $updated === 1;
            });

            $secondRows = $this->secondDatabase->fetchAll(
                'SELECT state_revision, status, lease_token, lease_expires_at_utc
                 FROM ' . self::TABLE . ' WHERE probe_id = :probe_id',
                ['probe_id' => $probeId]
            );
            if (count($secondRows) !== 1) {
                throw new RuntimeException('Staging worker lease probe second connection cannot read the row.');
            }
            $second = $secondRows[0];
            $secondRevision = (int)($second['state_revision'] ?? 0);
            $secondStatus = strtolower(trim((string)($second['status'] ?? '')));
            $secondToken = (string)($second['lease_token'] ?? '');
            $secondExpires = strtotime((string)($second['lease_expires_at_utc'] ?? ''));
            $secondBusy = $secondStatus === 'processing'
                && hash_equals($leaseToken, $secondToken)
                && $secondExpires !== false
                && $secondExpires > time();
            $lostClaim = $this->secondDatabase->execute(
                'UPDATE ' . self::TABLE . '
                 SET status = :status, updated_at_utc = :updated_at_utc
                 WHERE probe_id = :probe_id AND status = :expected_status',
                [
                    'status' => 'processing',
                    'updated_at_utc' => gmdate(DATE_ATOM),
                    'probe_id' => $probeId,
                    'expected_status' => 'pending',
                ]
            );

            return [
                'ok' => $firstClaimed
                    && $secondBusy
                    && $lostClaim === 0
                    && $secondRevision === $revision,
                'state_revision' => $revision,
                'first_claimed' => $firstClaimed,
                'second_action' => $secondBusy ? 'projection_busy' : 'projection_not_busy',
                'second_state_revision' => $secondRevision,
                'lease_seconds' => $this->leaseSeconds,
            ];
        } finally {
            try {
                $this->firstDatabase->execute(
                    'DELETE FROM ' . self::TABLE . ' WHERE probe_id = :probe_id',
                    ['probe_id' => $probeId]
                );
            } catch (Throwable) {
            }
        }
    }

    private function installProbeTable(): void
    {
        $this->firstDatabase->execute(
            'CREATE TABLE IF NOT EXISTS ' . self::TABLE . ' (
                probe_id CHAR(64) NOT NULL,
                state_revision BIGINT UNSIGNED NOT NULL,
                status VARCHAR(16) NOT NULL,
                lease_token VARCHAR(64) NOT NULL,
                lease_expires_at_utc VARCHAR(40) NOT NULL,
                created_at_utc VARCHAR(40) NOT NULL,
                updated_at_utc VARCHAR(40) NOT NULL,
                PRIMARY KEY (probe_id),
                KEY idx_mgw_runtime_lease_probe_revision (state_revision)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
        $rows = $this->firstDatabase->fetchAll(
            "SHOW TABLE STATUS LIKE '" . self::TABLE . "'"
        );
        if (count($rows) !== 1
            || strtolower(trim((string)($rows[0]['Engine'] ?? ''))) !== 'innodb') {
            throw new RuntimeException('Staging worker lease probe table must use InnoDB.');
        }
    }
}
