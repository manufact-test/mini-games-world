<?php
declare(strict_types=1);

final class RuntimePrimaryStagingEvidenceManifestFixture
{
    public static function valid(string $projectRoot, string $repositoryCommit): array
    {
        $modules = [
            'accounts', 'realtime', 'economy', 'notifications', 'invites',
            'history', 'shop', 'payments', 'weekly_bonus',
        ];
        $snapshotSha = str_repeat('1', 64);
        return [
            'manifest_version' => RuntimePrimaryStagingEvidenceVerifier::MANIFEST_VERSION,
            'environment' => 'staging',
            'repository_commit' => $repositoryCommit,
            'generated_at_utc' => '2026-07-20T15:30:00+00:00',
            'php' => [
                'version' => '8.3.25',
                'version_id' => 80325,
                'sapi' => 'cli',
            ],
            'database' => [
                'driver' => 'mysql',
                'server_version' => '10.11.13-MariaDB',
                'state_engine' => 'innodb',
                'outbox_engine' => 'innodb',
            ],
            'schemas' => [
                'state' => [
                    'table' => RuntimePrimaryStateSchemaInstaller::TABLE,
                    'schema_fingerprint' => str_repeat('2', 64),
                ],
                'outbox' => [
                    'table' => RuntimePrimaryProjectionOutboxSchemaInstaller::TABLE,
                    'schema_fingerprint' => str_repeat('3', 64),
                ],
            ],
            'source_snapshot' => [
                'before_sha256' => $snapshotSha,
                'after_first_sha256' => $snapshotSha,
                'after_second_sha256' => $snapshotSha,
                'inventory_fingerprint' => str_repeat('4', 64),
            ],
            'first_rehearsal' => [
                'ok' => true,
                'action' => 'rehearsal_completed',
                'snapshot_action' => 'snapshot_initialized',
                'target_revision' => 1,
                'target_sha256' => $snapshotSha,
                'target_event_status' => 'completed',
                'target_event_completed' => true,
                'status_healthy' => true,
                'parity_completed' => true,
                'worker_tick_count' => 1,
                'projected_modules' => $modules,
                'application_entrypoints_changed' => false,
                'cron_changed' => false,
                'production_changed' => false,
                'sensitive_identifiers_exposed' => false,
            ],
            'repeated_rehearsal' => [
                'ok' => true,
                'action' => 'rehearsal_completed',
                'snapshot_action' => 'snapshot_unchanged',
                'target_revision' => 1,
                'target_sha256' => $snapshotSha,
                'target_event_status' => 'completed',
                'target_event_completed' => true,
                'status_healthy' => true,
                'parity_completed' => true,
                'worker_tick_count' => 0,
                'projected_modules' => $modules,
                'application_entrypoints_changed' => false,
                'cron_changed' => false,
                'production_changed' => false,
                'sensitive_identifiers_exposed' => false,
            ],
            'concurrency' => [
                'cli_lock' => [
                    'ok' => true,
                    'second_exit_code' => 2,
                    'second_result' => 'rehearsal_lock_blocked',
                ],
                'worker_lease' => [
                    'ok' => true,
                    'state_revision' => 1,
                    'first_claimed' => true,
                    'second_action' => 'projection_busy',
                    'second_state_revision' => 1,
                    'lease_seconds' => 120,
                ],
            ],
            'entrypoint_evidence' => RuntimePrimaryEntrypointEvidence::inspect($projectRoot),
        ];
    }
}
