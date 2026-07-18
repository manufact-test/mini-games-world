<?php
declare(strict_types=1);

trait RuntimeRealtimeIdentityTrait
{
    private function ownership(DatabaseConnectionInterface $database, string $legacyUserId): array
    {
        if (isset($this->ownershipCache[$legacyUserId])) return $this->ownershipCache[$legacyUserId];
        $rows = $database->fetchAll(
            'SELECT account_ref, mgw_id, ownership_status FROM mgw_account_ownership
             WHERE legacy_user_id = :legacy_user_id',
            ['legacy_user_id' => $legacyUserId]
        );
        if (count($rows) !== 1) {
            throw new RuntimeException('Realtime runtime requires exactly one account ownership row.');
        }
        $row = $rows[0];
        $accountRef = trim((string)($row['account_ref'] ?? ''));
        $mgwId = trim((string)($row['mgw_id'] ?? ''));
        if ($accountRef === '' || $mgwId === '' || (string)($row['ownership_status'] ?? '') !== 'active') {
            throw new RuntimeException('Realtime account ownership is incomplete or inactive.');
        }
        return $this->ownershipCache[$legacyUserId] = [
            'account_ref' => $accountRef,
            'mgw_id' => $mgwId,
        ];
    }

    private function playerIdentity(DatabaseConnectionInterface $database, string $legacyUserId): array
    {
        if (str_starts_with($legacyUserId, 'bot_')) {
            return [
                'player_ref' => RealtimeDatabaseStore::playerReference(null, null, $legacyUserId),
                'mgw_id' => null,
                'legacy_user_id' => null,
                'player_type' => 'bot',
            ];
        }
        $owned = $this->ownership($database, $legacyUserId);
        return [
            'player_ref' => $owned['account_ref'],
            'mgw_id' => $owned['mgw_id'],
            'legacy_user_id' => $legacyUserId,
            'player_type' => 'human',
        ];
    }

    private function nullablePlayerReference(DatabaseConnectionInterface $database, mixed $value): ?string
    {
        $legacyUserId = trim((string)$value);
        return $legacyUserId === '' ? null : $this->playerIdentity($database, $legacyUserId)['player_ref'];
    }

    private function playerResult(string $legacyUserId, array $payload, string $status): ?string
    {
        if ($status !== 'finished') return null;
        $winner = trim((string)($payload['winner_id'] ?? ''));
        $loser = trim((string)($payload['loser_id'] ?? ''));
        if ($winner === '') return 'draw';
        if ($legacyUserId === $winner) return 'win';
        if ($legacyUserId === $loser) return 'loss';
        return null;
    }

    private function playerProjections(array $players): array
    {
        return array_map(static fn(array $row): array => [
            'seat' => (int)$row['seat'],
            'player_ref' => $row['player_ref'],
            'mgw_id' => $row['mgw_id'],
            'legacy_user_id' => $row['legacy_user_id'],
            'player_type' => $row['player_type'],
            'symbol' => $row['symbol'],
            'display_name' => $row['display_name'],
            'result' => $row['result'],
            'joined_at_utc' => $row['joined_at_utc'],
            'updated_at_utc' => $row['updated_at_utc'],
        ], $players);
    }

    private function assertImmutableGameIdentity(array $expected, array $actual): void
    {
        foreach (['match_id', 'game_type', 'room', 'created_at_utc', 'source_match_id'] as $key) {
            if (($expected[$key] ?? null) !== ($actual[$key] ?? null)) {
                throw new RuntimeException('Realtime match immutable identity differs from JSON.');
            }
        }
        $expectedPlayers = array_map(
            static fn(array $row): array => [$row['seat'], $row['player_ref']],
            $expected['players'] ?? []
        );
        $actualPlayers = array_map(
            static fn(array $row): array => [$row['seat'], $row['player_ref']],
            $actual['players'] ?? []
        );
        if ($expectedPlayers !== $actualPlayers) {
            throw new RuntimeException('Realtime match immutable player identity differs from JSON.');
        }
    }

    private function assertImmutableQueueIdentity(array $expected, array $actual): void
    {
        foreach (['queue_id', 'player_ref', 'mgw_id', 'legacy_user_id', 'created_at_utc'] as $key) {
            if (($expected[$key] ?? null) !== ($actual[$key] ?? null)) {
                throw new RuntimeException('Realtime queue immutable identity differs from JSON.');
            }
        }
    }
}
