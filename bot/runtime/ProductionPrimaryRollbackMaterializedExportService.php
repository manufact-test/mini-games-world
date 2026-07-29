<?php
declare(strict_types=1);

final class ProductionPrimaryRollbackMaterializedExportService
{
    private const DATA_FILES = [
        'users.json',
        'games.json',
        'queue.json',
        'transactions.json',
        'support.json',
        'shop_orders.json',
        'payments.json',
        'notifications.json',
        'invites.json',
        'system.json',
    ];

    public function __construct(
        private array $config,
        private DatabaseConnectionInterface $database,
        private ProductionPrimaryRollbackExportVerifier $verifier
    ) {
        if (($this->config['environment'] ?? null) !== 'production') {
            throw new RuntimeException('Materialized rollback export requires production config.');
        }
        if ($this->database->driver() !== 'mysql') {
            throw new RuntimeException('Materialized rollback export requires MySQL/MariaDB.');
        }
    }

    public function export(
        string $projectRoot,
        string $outputRoot,
        array $gateReport
    ): array {
        if (($gateReport['ready'] ?? false) !== true
            || ($gateReport['contract_version'] ?? null)
                !== ProductionPrimaryRollbackExportGate::CONTRACT_VERSION
            || ($gateReport['activation_build'] ?? null)
                !== ProductionPrimaryRollbackExportGate::ACTIVATION_BUILD) {
            throw new RuntimeException('Materialized rollback export gate is not ready.');
        }

        $source = $this->sourceState($gateReport);
        $materialization = (new ProductionPrimaryRollbackSnapshotMaterializer(
            $this->database
        ))->materialize(
            $source['snapshot'],
            $source['revision'],
            $source['state_sha256']
        );
        $materializedSha = $this->exactSha(
            $materialization['materialized_state_sha256'] ?? null
        );
        if ($materializedSha === '') {
            throw new RuntimeException('Materialized rollback state SHA is unavailable.');
        }

        $connection = new ProductionPrimaryRollbackMaterializedStateConnection(
            $this->database,
            $materialization
        );
        $effectiveGate = $gateReport;
        $effectiveGate['expected_state_sha256'] = $materializedSha;
        $auditor = (new ProductionPrimaryRollbackAuditorFactory(
            $this->config,
            $connection,
            $effectiveGate
        ))->create();
        $authorizationFingerprint = $this->authorizationFingerprint($gateReport);
        $artifactDir = '';

        try {
            $result = (new ProductionPrimaryRollbackExportService(
                $connection,
                $auditor,
                $this->verifier
            ))->export($projectRoot, $outputRoot, $effectiveGate);

            if (!$connection->sourceLockVerified()
                || $connection->stateSubstitutionCount() !== 1
                || !hash_equals(
                    $materializedSha,
                    strtolower(trim((string)($result['state_sha256'] ?? '')))
                )) {
                throw new RuntimeException('Materialized rollback state lock evidence is invalid.');
            }

            $outputRoot = $this->canonicalDirectory($outputRoot, 'Rollback export root');
            $backupId = trim((string)($result['backup_id'] ?? ''));
            if (preg_match('/\Arollback-[a-f0-9]{32}\z/', $backupId) !== 1) {
                throw new RuntimeException('Materialized rollback artifact identity is invalid.');
            }
            $artifactDir = $outputRoot . '/' . $backupId;
            $verified = $this->enrichArtifact(
                $outputRoot,
                $artifactDir,
                $source,
                $materialization,
                $authorizationFingerprint
            );

            return $result + [
                'source_state_revision' => $source['revision'],
                'source_state_sha256' => $source['state_sha256'],
                'materialized_state_sha256' => $materializedSha,
                'materialization_contract_version'
                    => ProductionPrimaryRollbackSnapshotMaterializer::CONTRACT_VERSION,
                'materialization_applied' => ($materialization['applied'] ?? false) === true,
                'materialized_user_count' => (int)($materialization['changed_user_count'] ?? 0),
                'materialized_field_count' => (int)($materialization['changed_field_count'] ?? 0),
                'materialization_read_only' => true,
                'source_state_row_locked' => true,
                'artifact_materialization_metadata_verified' => true,
                'authorization_fingerprint' => $authorizationFingerprint,
                'snapshot_sha256' => (string)($verified['snapshot_sha256'] ?? ''),
                'database_write_executed' => false,
                'production_changed' => false,
            ];
        } catch (Throwable $error) {
            if ($artifactDir !== '' && is_dir($artifactDir) && !is_link($artifactDir)) {
                $this->removeDirectory($artifactDir);
            }
            throw $error;
        }
    }

    private function sourceState(array $gateReport): array
    {
        $rows = $this->database->fetchAll(
            'SELECT singleton_id, revision, state_json, state_sha256,
                    created_at_utc, updated_at_utc
             FROM ' . RuntimePrimaryStateSchemaInstaller::TABLE . '
             WHERE singleton_id = 1'
        );
        if (count($rows) !== 1 || !is_array($rows[0])) {
            throw new RuntimeException('Materialized rollback source state is unavailable.');
        }
        $row = $rows[0];
        $revision = (int)($row['revision'] ?? 0);
        $stateSha = $this->exactSha($row['state_sha256'] ?? null);
        $expectedRevision = (int)($gateReport['expected_state_revision'] ?? 0);
        $expectedSha = $this->exactSha($gateReport['expected_state_sha256'] ?? null);
        if ((int)($row['singleton_id'] ?? 0) !== 1
            || $revision < 1
            || $stateSha === ''
            || $revision !== $expectedRevision
            || $expectedSha === ''
            || !hash_equals($expectedSha, $stateSha)) {
            throw new RuntimeException('Materialized rollback source does not match authorization.');
        }
        try {
            $snapshot = json_decode((string)($row['state_json'] ?? ''), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $error) {
            throw new RuntimeException('Materialized rollback source JSON is invalid.', 0, $error);
        }
        if (!is_array($snapshot)
            || array_is_list($snapshot)
            || !hash_equals($stateSha, hash('sha256', $this->canonicalJson($snapshot)))) {
            throw new RuntimeException('Materialized rollback source fingerprint mismatch.');
        }

        return [
            'revision' => $revision,
            'state_sha256' => $stateSha,
            'snapshot' => $snapshot,
        ];
    }

    private function enrichArtifact(
        string $outputRoot,
        string $artifactDir,
        array $source,
        array $materialization,
        string $authorizationFingerprint
    ): array {
        $artifactDir = $this->canonicalDirectory($artifactDir, 'Materialized rollback artifact');
        $lockPath = $outputRoot . '/.rollback-export.lock';
        if (is_link($lockPath)) {
            throw new RuntimeException('Materialized rollback lock must not be a symbolic link.');
        }
        $lock = fopen($lockPath, 'c+');
        if (!is_resource($lock)) {
            throw new RuntimeException('Materialized rollback lock is unavailable.');
        }
        if (!chmod($lockPath, 0600) || !flock($lock, LOCK_EX)) {
            fclose($lock);
            throw new RuntimeException('Materialized rollback lock could not be acquired.');
        }

        try {
            $rollback = $this->readObject($artifactDir . '/rollback.json');
            $manifest = $this->readObject($artifactDir . '/manifest.json');
            $materializedSha = $this->exactSha(
                $materialization['materialized_state_sha256'] ?? null
            );
            $sourceSha = $this->exactSha($source['state_sha256'] ?? null);
            $sourceRevision = (int)($source['revision'] ?? 0);
            if ($materializedSha === '' || $sourceSha === '' || $sourceRevision < 1) {
                throw new RuntimeException('Materialized rollback metadata identity is incomplete.');
            }

            $materializationEvidence = [
                'contract_version' => ProductionPrimaryRollbackSnapshotMaterializer::CONTRACT_VERSION,
                'applied' => ($materialization['applied'] ?? false) === true,
                'changed_user_count' => (int)($materialization['changed_user_count'] ?? 0),
                'changed_field_count' => (int)($materialization['changed_field_count'] ?? 0),
                'read_only' => true,
                'database_write_executed' => false,
            ];
            $metadata = [
                'source_state_revision' => $sourceRevision,
                'source_state_sha256' => $sourceSha,
                'materialized_state_sha256' => $materializedSha,
                'materialization' => $materializationEvidence,
            ];

            $rollback['authorization_fingerprint'] = $authorizationFingerprint;
            foreach ($metadata as $key => $value) $rollback[$key] = $value;
            $rollbackRaw = $this->prettyJson($rollback) . "\n";
            $this->writePrivateFile($artifactDir . '/rollback.json', $rollbackRaw);

            $checksums = [];
            foreach (self::DATA_FILES as $file) {
                $checksums['data/' . $file] = $this->fileSha($artifactDir . '/data/' . $file);
            }
            $checksums['rollback.json'] = hash('sha256', $rollbackRaw);
            ksort($checksums, SORT_STRING);
            $checksumLines = [];
            foreach ($checksums as $relative => $sha) {
                $checksumLines[] = $sha . '  ' . $relative;
            }
            $checksumsRaw = implode("\n", $checksumLines) . "\n";
            $this->writePrivateFile($artifactDir . '/checksums.sha256', $checksumsRaw);

            if (!isset($manifest['rollback_export']) || !is_array($manifest['rollback_export'])) {
                throw new RuntimeException('Materialized rollback manifest summary is unavailable.');
            }
            $manifest['snapshot_sha256'] = hash('sha256', $checksumsRaw);
            $manifest['rollback_export']['authorization_fingerprint'] = $authorizationFingerprint;
            foreach ($metadata as $key => $value) {
                $manifest['rollback_export'][$key] = $value;
            }
            $manifestRaw = $this->prettyJson($manifest) . "\n";
            $this->writePrivateFile($artifactDir . '/manifest.json', $manifestRaw);

            $complete = $this->readObject($artifactDir . '/COMPLETE');
            $complete['manifest_sha256'] = hash('sha256', $manifestRaw);
            $complete['completed_at_utc'] = gmdate(DATE_ATOM);
            $this->writePrivateFile(
                $artifactDir . '/COMPLETE',
                $this->prettyJson($complete) . "\n"
            );

            $verified = $this->verifier->verify($artifactDir);
            $verifiedRollback = $this->readObject($artifactDir . '/rollback.json');
            $verifiedManifest = $this->readObject($artifactDir . '/manifest.json');
            foreach ([$verifiedRollback, $verifiedManifest['rollback_export'] ?? null] as $evidence) {
                if (!is_array($evidence)
                    || (int)($evidence['source_state_revision'] ?? 0) !== $sourceRevision
                    || !hash_equals($sourceSha, (string)($evidence['source_state_sha256'] ?? ''))
                    || !hash_equals(
                        $materializedSha,
                        (string)($evidence['materialized_state_sha256'] ?? '')
                    )
                    || ($evidence['materialization']['read_only'] ?? null) !== true
                    || ($evidence['materialization']['database_write_executed'] ?? null) !== false
                    || !hash_equals(
                        $authorizationFingerprint,
                        (string)($evidence['authorization_fingerprint'] ?? '')
                    )) {
                    throw new RuntimeException('Materialized rollback artifact evidence verification failed.');
                }
            }
            if (!hash_equals(
                $materializedSha,
                strtolower(trim((string)($verified['state_sha256'] ?? '')))
            )) {
                throw new RuntimeException('Materialized rollback verifier returned the wrong state SHA.');
            }
            return $verified;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function authorizationFingerprint(array $gateReport): string
    {
        $identity = [
            'contract_version' => ProductionPrimaryRollbackExportGate::CONTRACT_VERSION,
            'request_id' => strtolower(trim((string)($gateReport['request_id'] ?? ''))),
            'expected_state_revision' => (int)($gateReport['expected_state_revision'] ?? 0),
            'expected_state_sha256' => $this->exactSha(
                $gateReport['expected_state_sha256'] ?? null
            ),
            'database_identity_fingerprint' => $this->exactSha(
                $gateReport['database_identity_fingerprint'] ?? null
            ),
            'activation_plan_fingerprint' => $this->exactSha(
                $gateReport['activation_plan_fingerprint'] ?? null
            ),
            'activation_source_fingerprint' => $this->exactSha(
                $gateReport['activation_source_fingerprint'] ?? null
            ),
            'output_root_fingerprint' => $this->exactSha(
                $gateReport['output_root_fingerprint'] ?? null
            ),
            'reason_fingerprint' => $this->exactSha(
                $gateReport['reason_fingerprint'] ?? null
            ),
            'authorization_expires_at_utc' => trim((string)(
                $gateReport['authorization_expires_at_utc'] ?? ''
            )),
        ];
        if (preg_match('/\A[a-f0-9]{32}\z/', $identity['request_id']) !== 1
            || $identity['expected_state_revision'] < 1
            || $identity['authorization_expires_at_utc'] === '') {
            throw new RuntimeException('Materialized rollback authorization identity is incomplete.');
        }
        foreach ([
            'expected_state_sha256',
            'database_identity_fingerprint',
            'activation_plan_fingerprint',
            'activation_source_fingerprint',
            'output_root_fingerprint',
            'reason_fingerprint',
        ] as $field) {
            if ($identity[$field] === '') {
                throw new RuntimeException('Materialized rollback authorization fingerprint is incomplete.');
            }
        }
        return hash('sha256', $this->canonicalJson($identity));
    }

    private function readObject(string $path): array
    {
        if (is_link($path) || !is_file($path)) {
            throw new RuntimeException('Materialized rollback artifact file is unavailable.');
        }
        try {
            $value = json_decode((string)file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $error) {
            throw new RuntimeException('Materialized rollback artifact JSON is invalid.', 0, $error);
        }
        if (!is_array($value) || array_is_list($value)) {
            throw new RuntimeException('Materialized rollback artifact JSON must be an object.');
        }
        return $value;
    }

    private function writePrivateFile(string $path, string $raw): void
    {
        $temporary = $path . '.tmp-' . bin2hex(random_bytes(8));
        try {
            if (file_put_contents($temporary, $raw, LOCK_EX) !== strlen($raw)
                || !chmod($temporary, 0600)
                || !rename($temporary, $path)) {
                throw new RuntimeException('Materialized rollback artifact file could not be written.');
            }
        } finally {
            if (is_file($temporary) && !is_link($temporary)) @unlink($temporary);
        }
    }

    private function fileSha(string $path): string
    {
        if (is_link($path) || !is_file($path)) {
            throw new RuntimeException('Materialized rollback data file is unavailable.');
        }
        $sha = hash_file('sha256', $path);
        if (!is_string($sha) || preg_match('/\A[a-f0-9]{64}\z/', $sha) !== 1) {
            throw new RuntimeException('Materialized rollback data checksum is unavailable.');
        }
        return $sha;
    }

    private function canonicalDirectory(string $path, string $label): string
    {
        if ($path === ''
            || str_contains($path, '\\')
            || !str_starts_with($path, '/')
            || ($path !== '/' && str_ends_with($path, '/'))
            || is_link($path)
            || !is_dir($path)) {
            throw new RuntimeException($label . ' must be an exact absolute directory.');
        }
        $canonical = realpath($path);
        if (!is_string($canonical) || !hash_equals($path, $canonical)) {
            throw new RuntimeException($label . ' must use its exact canonical value.');
        }
        return $canonical;
    }

    private function exactSha(mixed $value): string
    {
        $value = strtolower(trim((string)$value));
        return preg_match('/\A[a-f0-9]{64}\z/', $value) === 1 ? $value : '';
    }

    private function prettyJson(array $value): string
    {
        return json_encode(
            $value,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        );
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

    private function removeDirectory(string $path): void
    {
        if (!is_dir($path) || is_link($path)) return;
        $items = scandir($path);
        if (!is_array($items)) return;
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') continue;
            $child = $path . '/' . $item;
            if (is_dir($child) && !is_link($child)) {
                $this->removeDirectory($child);
            } elseif (is_file($child) && !is_link($child)) {
                @unlink($child);
            }
        }
        @rmdir($path);
    }
}
