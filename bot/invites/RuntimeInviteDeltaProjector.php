<?php
declare(strict_types=1);

/**
 * Latency-critical invite mutations must not run the full JSON↔DB audit on the
 * request path. This projector mirrors only invite rows that actually changed
 * in the just-committed JSON transaction. RuntimeInviteRepository remains the
 * owner of full reconciliation/audit jobs.
 */
final class RuntimeInviteDeltaProjector
{
    private RuntimeStorageRouter $router;
    private ?DatabaseConnectionInterface $connection = null;
    private array $ownershipCache = [];

    public function __construct(
        private array $config,
        ?RuntimeStorageRouter $router = null
    ) {
        $this->router = $router ?? new RuntimeStorageRouter($config);
    }

    public function enabled(): bool
    {
        return $this->router->enabled()
            && $this->router->routeFor('accounts') === RuntimeStorageRouter::DRIVER_DATABASE
            && $this->router->routeFor('notifications') === RuntimeStorageRouter::DRIVER_DATABASE
            && $this->router->routeFor('invites') === RuntimeStorageRouter::DRIVER_DATABASE;
    }

    /** @param list<string> $tokens */
    public function synchronizeTokens(array $jsonData, array $tokens): array
    {
        if (!$this->enabled()) {
            return ['projected_count' => 0, 'created_count' => 0, 'updated_count' => 0, 'unchanged_count' => 0];
        }

        $tokens = $this->normalizeTokens($tokens);
        if ($tokens === []) {
            return ['projected_count' => 0, 'created_count' => 0, 'updated_count' => 0, 'unchanged_count' => 0];
        }

        $database = $this->database();
        $source = $this->sourceRowsForTokens($jsonData, $tokens, $database);

        return $this->withSynchronizationLock(
            $database,
            function (DatabaseConnectionInterface $db) use ($source): array {
                $store = new RealtimeDatabaseStore($db);
                $created = 0;
                $updated = 0;
                $unchanged = 0;

                foreach ($source as $inviteId => $expected) {
                    $existingRows = $db->fetchAll(
                        'SELECT * FROM mgw_invites WHERE invite_id = :invite_id',
                        ['invite_id' => $inviteId]
                    );
                    if (count($existingRows) > 1) {
                        throw new RuntimeException('Invite DB contains duplicate invite IDs.');
                    }

                    if ($existingRows === []) {
                        $stored = $store->upsertInvite($expected);
                        $this->assertRowMatches($expected, $stored);
                        $created++;
                        continue;
                    }

                    $existing = $this->normalizeDatabaseRow($existingRows[0]);
                    $this->assertImmutableIdentity($expected, $existing);
                    if ($existing === $expected) {
                        $unchanged++;
                        continue;
                    }
                    if ($this->timestampSortValue($existing['updated_at_utc'])
                        > $this->timestampSortValue($expected['updated_at_utc'])) {
                        throw new RuntimeException('Invite DB state is ahead of the JSON rollback source.');
                    }

                    $stored = $store->upsertInvite($expected);
                    $this->assertRowMatches($expected, $stored);
                    $updated++;
                }

                return [
                    'projected_count' => count($source),
                    'created_count' => $created,
                    'updated_count' => $updated,
                    'unchanged_count' => $unchanged,
                    'parity' => true,
                ];
            }
        );
    }

    /** @param list<string> $tokens */
    private function sourceRowsForTokens(
        array $jsonData,
        array $tokens,
        DatabaseConnectionInterface $database
    ): array {
        $wanted = array_fill_keys($tokens, true);
        $source = [];
        $seenTokens = [];

        foreach (($jsonData['invites'] ?? []) as $invite) {
            if (!is_array($invite)) continue;
            $token = trim((string)($invite['token'] ?? ''));
            if ($token === '' || !isset($wanted[$token])) continue;
            if (isset($seenTokens[$token])) {
                throw new RuntimeException('Invite JSON contains duplicate tokens.');
            }
            $seenTokens[$token] = true;
            $row = $this->sourceRow($invite, $database);
            $inviteId = (string)$row['invite_id'];
            if (isset($source[$inviteId])) {
                throw new RuntimeException('Invite JSON contains duplicate IDs.');
            }
            $source[$inviteId] = $row;
        }

        foreach ($tokens as $token) {
            if (!isset($seenTokens[$token])) {
                throw new RuntimeException('Changed invite is missing from the JSON rollback source.');
            }
        }
        ksort($source, SORT_STRING);
        return $source;
    }

    private function sourceRow(array $invite, DatabaseConnectionInterface $database): array
    {
        $inviterLegacyId = trim((string)($invite['inviter_id'] ?? ''));
        if ($inviterLegacyId === '') throw new RuntimeException('Invite JSON row has no inviter identity.');
        $inviter = $this->ownership($database, $inviterLegacyId);

        $inviteeLegacyId = trim((string)($invite['invitee_id'] ?? ''));
        $invitee = $inviteeLegacyId !== '' ? $this->ownership($database, $inviteeLegacyId) : null;
        $cancelledByLegacyId = trim((string)($invite['cancelled_by'] ?? ''));
        $cancelledBy = $cancelledByLegacyId !== '' ? $this->ownership($database, $cancelledByLegacyId) : null;

        return [
            'invite_id' => trim((string)($invite['id'] ?? '')),
            'token' => trim((string)($invite['token'] ?? '')),
            'status' => trim((string)($invite['status'] ?? 'pending')),
            'source' => trim((string)($invite['source'] ?? 'link')),
            'inviter_ref' => $inviter['account_ref'],
            'inviter_mgw_id' => $inviter['mgw_id'],
            'inviter_legacy_user_id' => $inviterLegacyId,
            'inviter_name' => trim((string)($invite['inviter_name'] ?? 'Игрок')),
            'invitee_ref' => $invitee['account_ref'] ?? null,
            'invitee_mgw_id' => $invitee['mgw_id'] ?? null,
            'invitee_legacy_user_id' => $inviteeLegacyId !== '' ? $inviteeLegacyId : null,
            'invitee_name' => $this->nullableText($invite['invitee_name'] ?? null),
            'game_type' => trim((string)($invite['game_type'] ?? 'tictactoe')),
            'game_title' => trim((string)($invite['game_title'] ?? 'Игра')),
            'room' => (string)($invite['room'] ?? 'match') === 'gold' ? 'gold' : 'match',
            'bet' => max(0, (int)($invite['bet'] ?? 0)),
            'board_size' => max(1, (int)($invite['board_size'] ?? 1)),
            'board_columns' => isset($invite['board_columns']) ? max(1, (int)$invite['board_columns']) : null,
            'board_rows' => isset($invite['board_rows']) ? max(1, (int)$invite['board_rows']) : null,
            'source_match_id' => $this->nullableText($invite['source_game_id'] ?? null),
            'match_id' => $this->nullableText($invite['game_id'] ?? null),
            'created_at_utc' => $this->requiredTimestamp($invite['created_at'] ?? null),
            'updated_at_utc' => $this->requiredTimestamp($invite['updated_at'] ?? $invite['created_at'] ?? null),
            'expires_at_utc' => $this->nullableTimestamp($invite['expires_at'] ?? null),
            'shared_at_utc' => $this->nullableTimestamp($invite['shared_at'] ?? null),
            'opened_at_utc' => $this->nullableTimestamp($invite['opened_at'] ?? null),
            'accepted_at_utc' => $this->nullableTimestamp($invite['accepted_at'] ?? null),
            'ready_deadline_at_utc' => $this->nullableTimestamp(
                $invite['ready_deadline_at'] ?? $invite['start_deadline_at'] ?? null
            ),
            'started_at_utc' => $this->nullableTimestamp($invite['started_at'] ?? null),
            'declined_at_utc' => $this->nullableTimestamp($invite['declined_at'] ?? null),
            'cancelled_at_utc' => $this->nullableTimestamp($invite['cancelled_at'] ?? null),
            'cancelled_by_ref' => $cancelledBy['account_ref'] ?? null,
        ];
    }

    private function ownership(DatabaseConnectionInterface $database, string $legacyUserId): array
    {
        if (isset($this->ownershipCache[$legacyUserId])) return $this->ownershipCache[$legacyUserId];
        $rows = $database->fetchAll(
            'SELECT account_ref, mgw_id, ownership_status FROM mgw_account_ownership WHERE legacy_user_id = :legacy_user_id',
            ['legacy_user_id' => $legacyUserId]
        );
        if (count($rows) !== 1) throw new RuntimeException('Invite runtime requires exactly one account ownership row.');
        $row = $rows[0];
        $accountRef = trim((string)($row['account_ref'] ?? ''));
        $mgwId = trim((string)($row['mgw_id'] ?? ''));
        if ($accountRef === '' || $mgwId === '' || (string)($row['ownership_status'] ?? '') !== 'active') {
            throw new RuntimeException('Invite account ownership is incomplete or inactive.');
        }
        return $this->ownershipCache[$legacyUserId] = ['account_ref' => $accountRef, 'mgw_id' => $mgwId];
    }

    private function normalizeDatabaseRow(array $row): array
    {
        return [
            'invite_id' => trim((string)($row['invite_id'] ?? '')),
            'token' => trim((string)($row['token'] ?? '')),
            'status' => trim((string)($row['status'] ?? '')),
            'source' => trim((string)($row['source'] ?? '')),
            'inviter_ref' => trim((string)($row['inviter_ref'] ?? '')),
            'inviter_mgw_id' => $this->nullableText($row['inviter_mgw_id'] ?? null),
            'inviter_legacy_user_id' => $this->nullableText($row['inviter_legacy_user_id'] ?? null),
            'inviter_name' => trim((string)($row['inviter_name'] ?? '')),
            'invitee_ref' => $this->nullableText($row['invitee_ref'] ?? null),
            'invitee_mgw_id' => $this->nullableText($row['invitee_mgw_id'] ?? null),
            'invitee_legacy_user_id' => $this->nullableText($row['invitee_legacy_user_id'] ?? null),
            'invitee_name' => $this->nullableText($row['invitee_name'] ?? null),
            'game_type' => trim((string)($row['game_type'] ?? '')),
            'game_title' => trim((string)($row['game_title'] ?? '')),
            'room' => trim((string)($row['room'] ?? '')),
            'bet' => (int)($row['bet'] ?? 0),
            'board_size' => (int)($row['board_size'] ?? 0),
            'board_columns' => isset($row['board_columns']) ? (int)$row['board_columns'] : null,
            'board_rows' => isset($row['board_rows']) ? (int)$row['board_rows'] : null,
            'source_match_id' => $this->nullableText($row['source_match_id'] ?? null),
            'match_id' => $this->nullableText($row['match_id'] ?? null),
            'created_at_utc' => $this->requiredTimestamp($row['created_at_utc'] ?? null),
            'updated_at_utc' => $this->requiredTimestamp($row['updated_at_utc'] ?? null),
            'expires_at_utc' => $this->nullableTimestamp($row['expires_at_utc'] ?? null),
            'shared_at_utc' => $this->nullableTimestamp($row['shared_at_utc'] ?? null),
            'opened_at_utc' => $this->nullableTimestamp($row['opened_at_utc'] ?? null),
            'accepted_at_utc' => $this->nullableTimestamp($row['accepted_at_utc'] ?? null),
            'ready_deadline_at_utc' => $this->nullableTimestamp($row['ready_deadline_at_utc'] ?? null),
            'started_at_utc' => $this->nullableTimestamp($row['started_at_utc'] ?? null),
            'declined_at_utc' => $this->nullableTimestamp($row['declined_at_utc'] ?? null),
            'cancelled_at_utc' => $this->nullableTimestamp($row['cancelled_at_utc'] ?? null),
            'cancelled_by_ref' => $this->nullableText($row['cancelled_by_ref'] ?? null),
        ];
    }

    private function assertImmutableIdentity(array $expected, array $actual): void
    {
        foreach (['invite_id', 'token', 'source', 'inviter_ref', 'inviter_mgw_id', 'inviter_legacy_user_id', 'created_at_utc'] as $key) {
            if (($expected[$key] ?? null) !== ($actual[$key] ?? null)) {
                throw new RuntimeException('Invite DB immutable identity conflicts with JSON rollback source.');
            }
        }
    }

    private function assertRowMatches(array $expected, array $stored): void
    {
        if ($this->normalizeDatabaseRow($stored) !== $expected) {
            throw new RuntimeException('Stored invite DB row differs from the JSON rollback source.');
        }
    }

    /** @return list<string> */
    private function normalizeTokens(array $tokens): array
    {
        $result = [];
        foreach ($tokens as $token) {
            $token = strtolower(trim((string)$token));
            if (!preg_match('/^[a-f0-9]{24}$/', $token)) continue;
            $result[$token] = true;
        }
        return array_keys($result);
    }

    private function nullableText(mixed $value): ?string
    {
        $value = trim((string)$value);
        return $value === '' ? null : $value;
    }

    private function requiredTimestamp(mixed $value): string
    {
        $normalized = $this->nullableTimestamp($value);
        if ($normalized === null) throw new RuntimeException('Invite row requires a valid timestamp.');
        return $normalized;
    }

    private function nullableTimestamp(mixed $value): ?string
    {
        $value = trim((string)$value);
        if ($value === '') return null;
        try {
            return (new DateTimeImmutable($value, new DateTimeZone('UTC')))
                ->setTimezone(new DateTimeZone('UTC'))
                ->format('Y-m-d H:i:s.u');
        } catch (Throwable) {
            throw new RuntimeException('Invite row contains an invalid timestamp.');
        }
    }

    private function timestampSortValue(?string $value): float
    {
        if ($value === null) return 0.0;
        $date = DateTimeImmutable::createFromFormat('Y-m-d H:i:s.u', $value, new DateTimeZone('UTC'));
        return $date instanceof DateTimeImmutable ? (float)$date->format('U.u') : 0.0;
    }

    private function database(): DatabaseConnectionInterface
    {
        if ($this->connection !== null) return $this->connection;
        $databaseConfig = DatabaseConfig::fromApplicationConfig($this->config);
        if (!$databaseConfig->enabled()) throw new RuntimeException('Invite DB runtime requires an enabled database.');
        return $this->connection = PdoConnectionFactory::create($databaseConfig);
    }

    private function withSynchronizationLock(
        DatabaseConnectionInterface $database,
        callable $callback
    ): mixed {
        $lockName = null;
        if ($database->driver() === 'mysql') {
            $scope = trim((string)($this->config['environment'] ?? ''))
                . '|'
                . trim((string)($this->config['database']['name'] ?? ''));
            $lockName = 'mgw_invites_sync_' . substr(hash('sha256', $scope), 0, 40);
            $acquired = $database->fetchValue('SELECT GET_LOCK(:lock_name, 10)', ['lock_name' => $lockName]);
            if ((int)$acquired !== 1) throw new RuntimeException('Invite DB synchronization lock is unavailable.');
        }

        try {
            return $database->transaction(
                static fn(DatabaseConnectionInterface $transaction): mixed => $callback($transaction)
            );
        } finally {
            if ($lockName !== null) {
                try {
                    $database->fetchValue('SELECT RELEASE_LOCK(:lock_name)', ['lock_name' => $lockName]);
                } catch (Throwable $error) {
                    error_log('Mini Games World invite DB lock release failed: ' . $error->getMessage());
                }
            }
        }
    }
}
