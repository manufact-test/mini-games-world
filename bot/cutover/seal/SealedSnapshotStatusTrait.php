<?php
declare(strict_types=1);

trait SealedSnapshotStatusTrait
{
    public function status(): array
    {
        $environment = $this->assertSafeEnvironment();
        $control = $this->readControl();
        $state = (string)($control['state'] ?? 'absent');
        $controlEnvironment = strtolower(trim((string)($control['environment'] ?? '')));
        $matches = $controlEnvironment === $environment;
        $freezeActive = in_array($state, ['frozen', 'sealed'], true) && $matches;
        $sealed = $state === 'sealed' && $matches;
        $snapshot = $this->storage->readOnly(static function (array $data): array {
            $activeGames = 0;
            foreach ($data['games'] ?? [] as $game) {
                if (is_array($game) && (string)($game['status'] ?? '') === 'active') $activeGames++;
            }
            $openInvites = 0;
            foreach ($data['invites'] ?? [] as $invite) {
                if (is_array($invite) && in_array((string)($invite['status'] ?? ''), ['draft', 'pending', 'awaiting_start', 'starting'], true)) $openInvites++;
            }
            $searching = 0;
            $playing = 0;
            foreach ($data['users'] ?? [] as $user) {
                if (!is_array($user)) continue;
                $status = (string)($user['status'] ?? 'idle');
                if ($status === 'searching') $searching++;
                if ($status === 'playing') $playing++;
            }
            return [
                'active_games' => $activeGames,
                'queue_entries' => count(is_array($data['queue'] ?? null) ? $data['queue'] : []),
                'open_invites' => $openInvites,
                'searching_users' => $searching,
                'playing_users' => $playing,
            ];
        });
        $blockers = [];
        if (!$freezeActive) $blockers[] = 'freeze is not active';
        if ($snapshot['active_games'] > 0) $blockers[] = 'active games must drain to zero';
        if ($snapshot['queue_entries'] > 0) $blockers[] = 'matchmaking queue must be empty';
        if ($snapshot['open_invites'] > 0) $blockers[] = 'open invitations must drain or expire';
        if ($snapshot['searching_users'] > 0) $blockers[] = 'searching users must be reset to idle';

        $marker = is_file($this->writeBlockFile());
        $consistencyBlockers = [];
        if (in_array($state, ['frozen', 'sealed'], true) && !$matches) {
            $consistencyBlockers[] = 'cutover control environment does not match runtime environment';
        }
        if ($sealed && !$marker) {
            $consistencyBlockers[] = 'sealed control is active without the JSON write block';
        }
        if (!$sealed && $marker) {
            $consistencyBlockers[] = 'JSON write block is active without sealed control';
        }

        $snapshotBlockers = $blockers;
        if (!$sealed) $snapshotBlockers[] = 'rehearsal is not sealed';
        foreach ($consistencyBlockers as $blocker) $snapshotBlockers[] = $blocker;

        return $this->withFingerprint([
            'ok' => $consistencyBlockers === [],
            'report_type' => 'mvp-14.8.4-sealed-snapshot-control',
            'environment' => $environment,
            'freeze' => [
                'active' => $freezeActive,
                'sealed' => $sealed,
                'state' => $state,
                'rehearsal_id' => (string)($control['rehearsal_id'] ?? ''),
                'started_at_utc' => (string)($control['started_at_utc'] ?? ''),
                'sealed_at_utc' => (string)($control['sealed_at_utc'] ?? ''),
                'released_at_utc' => (string)($control['released_at_utc'] ?? ''),
                'storage_write_block_active' => $marker,
            ],
            'drain' => $snapshot + ['ready' => $blockers === [], 'blockers' => $blockers],
            'frozen_snapshot' => ['ready' => $snapshotBlockers === [], 'blockers' => array_values(array_unique($snapshotBlockers))],
            'control_consistency' => [
                'ok' => $consistencyBlockers === [],
                'blockers' => $consistencyBlockers,
            ],
            'storage_driver' => 'json',
            'rollback_driver' => 'json',
            'production_changed' => false,
            'sensitive_identifiers_exposed' => false,
            'generated_at_utc' => $this->nowUtc(),
        ]);
    }
}
