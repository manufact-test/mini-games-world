<?php
declare(strict_types=1);

trait FrozenSnapshotPairTrait
{
    private function verifyPair(string $environment, string $build, array $primary, array $external): array
    {
        $pm = is_array($primary['manifest'] ?? null) ? $primary['manifest'] : [];
        $em = is_array($external['manifest'] ?? null) ? $external['manifest'] : [];
        $id = (string)($primary['backup_id'] ?? '');
        $hash = (string)($primary['snapshot_sha256'] ?? '');
        $result = [
            'ok' => ($primary['ok'] ?? false) === true && ($external['ok'] ?? false) === true,
            'backup_id' => $id,
            'snapshot_sha256' => $hash,
            'same_backup_id' => $id !== '' && hash_equals($id, (string)($external['backup_id'] ?? '')),
            'same_snapshot_sha256' => $hash !== '' && hash_equals($hash, (string)($external['snapshot_sha256'] ?? '')),
            'primary_environment_matches' => strtolower(trim((string)($pm['environment'] ?? ''))) === $environment,
            'external_environment_matches' => strtolower(trim((string)($em['environment'] ?? ''))) === $environment,
            'primary_build_matches' => (string)($pm['build'] ?? '') === $build,
            'external_build_matches' => (string)($em['build'] ?? '') === $build,
            'primary_verified_files' => (int)($primary['verified_files'] ?? 0),
            'external_verified_files' => (int)($external['verified_files'] ?? 0),
        ];
        $result['same_verified_snapshot'] = $result['same_backup_id'] && $result['same_snapshot_sha256'];
        foreach (['same_verified_snapshot', 'primary_environment_matches', 'external_environment_matches', 'primary_build_matches', 'external_build_matches'] as $key) {
            if (($result[$key] ?? false) !== true) {
                throw new RuntimeException('Frozen snapshot pair verification failed: ' . $key . '.');
            }
        }
        return $result;
    }
}
