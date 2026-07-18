<?php
declare(strict_types=1);

final class RuntimeInviteRepository
{
    private RuntimeStorageRouter $router;
    private ?DatabaseConnectionInterface $connection;
    private array $ownershipCache = [];

    public function __construct(
        private array $config,
        ?RuntimeStorageRouter $router = null,
        ?DatabaseConnectionInterface $database = null
    ) {
        $this->router = $router ?? new RuntimeStorageRouter($config);
        $this->connection = $database;
    }

    public function synchronize(array $jsonData): array
    {
        $this->assertDatabaseRoute();
        $database = $this->database();
        $store = new RealtimeDatabaseStore($database);
        $source = $this->sourceRows($jsonData, $database);
        $created = 0;
        $updated = 0;
        $unchanged = 0;

        foreach ($source as $inviteId => $expected) {
            $existingRows = $database->fetchAll(
                'SELECT * FROM mgw_invites WHERE invite_id = :invite_id',
                ['invite_id' => $inviteId]
            );

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

        $comparison = $this->compare($source, $this->databaseRows($database));
        if (!$comparison['ok']) {
            throw new RuntimeException(implode(' ', $comparison['blockers']));
        }

        return [
            'source_count' => count($source),
            'database_count' => $comparison['database_count'],
            'created_count' => $created,
            'updated_count' => $updated,
            'unchanged_count' => $unchanged,
            'source_fingerprint' => $comparison['source_fingerprint'],
            'database_fingerprint' => $comparison['database_fingerprint'],
            'parity' => true,
        ];
    }

    public function auditParity(array $jsonData): array
    {
        $this->assertDatabaseRoute();
        $database = $this->database();
        $source = $this->sourceRows($jsonData, $database);
        $comparison = $this->compare($source, $this->databaseRows($database));

        return [
            'ok' => $comparison['ok'],
            'read_only' => true,
            'source_count' => $comparison['source_count'],
            'database_count' => $comparison['database_count'],
            'source_fingerprint' => $comparison['source_fingerprint'],
            'database_fingerprint' => $comparison['database_fingerprint'],
            'blockers' => $comparison['blockers'],
        ];
    }

    private function assertDatabaseRoute(): void
    {
        if (!$this->router->enabled()
            || $this->router->routeFor('accounts') !== RuntimeStorageRouter::DRIVER_DATABASE
            || $this->router->routeFor('notifications') !== RuntimeStorageRouter::DRIVER_DATABASE
            || $this->router->routeFor('invites') !== RuntimeStorageRouter::DRIVER_DATABASE) {
            throw new RuntimeException(
                'Invite DB runtime requires accounts, notifications and invites routing.'
            );
        }
    }

    private function database(): DatabaseConnectionInterface
    {
        if ($this->connection !== null) return $this->connection;
        $databaseConfig = DatabaseConfig::fromApplicationConfig($this->config);
        if (!$databaseConfig->enabled()) {
            throw new RuntimeException('Invite DB runtime requires an enabled database.');
        }
        return $this->connection = PdoConnectionFactory::create($databaseConfig);
    }

    private function sourceRows(array $jsonData, DatabaseConnectionInterface $database): array
    {
        $rows = [];
        $tokens = [];
        foreach (($jsonData['invites'] ?? []) as $invite) {
            if (!is_array($invite)) {
                throw new RuntimeException('Invite JSON row is not an object.');
            }
            $inviteId = trim((string)($invite['id'] ?? ''));
            $token = trim((string)($invite['token'] ?? ''));
            if ($inviteId === '' || $token === '') {
                throw new RuntimeException('Invite JSON row has no stable ID or token.');
            }
            if (isset($rows[$inviteId]) || isset($tokens[$token])) {
                throw new RuntimeException('Invite JSON contains duplicate IDs or tokens.');
            }
            $tokens[$token] = true;
            $rows[$inviteId] = $this->sourceRow($invite, $database);
        }
        ksort($rows, SORT_STRING);
        return $rows;
    }

    private function sourceRow(array $invite, DatabaseConnectionInterface $database): array
    {
        $inviterLegacyId = trim((string)($invite['inviter_id'] ?? ''));
        if ($inviterLegacyId === '') {
            throw new RuntimeException('Invite JSON row has no inviter identity.');
        }
        $inviter = $this->ownership($database, $inviterLegacyId);

        $inviteeLegacyId = trim((string)($invite['invitee_id'] ?? ''));
        $invitee = $inviteeLegacyId !== ''
            ? $this->ownership($database, $inviteeLegacyId)
            : null;

        $cancelledByLegacyId = trim((string)($invite['cancelled_by'] ?? ''));
        $cancelledBy = $cancelledByLegacyId !== ''
            ? $this->ownership($database, $cancelledByLegacyId)
            : null;

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
        if (isset($this->ownershipCache[$legacyUserId])) {
            return $this->ownershipCache[$legacyUserId];
        }
        $rows = $database->fetchAll(
            'SELECT account_ref, mgw_id, ownership_status FROM mgw_account_ownership
             WHERE legacy_user_id = :legacy_user_id',
            ['legacy_user_id' => $legacyUserId]
        );
        if (count($rows) !== 1) {
            throw new RuntimeException('Invite runtime requires exactly one account ownership row.');
        }
        $row = $rows[0];
        $accountRef = trim((string)($row['account_ref'] ?? ''));
        $mgwId = trim((string)($row['mgw_id'] ?? ''));
        if ($accountRef === '' || $mgwId === '' || (string)($row['ownership_status'] ?? '') !== 'active') {
            throw new RuntimeException('Invite account ownership is incomplete or inactive.');
        }
        return $this->ownershipCache[$legacyUserId] = [
            'account_ref' => $accountRef,
            'mgw_id' => $mgwId,
        ];
    }

    private function databaseRows(DatabaseConnectionInterface $database): array
    {
        $rows = [];
        foreach ($database->fetchAll('SELECT * FROM mgw_invites ORDER BY invite_id') as $row) {
            $normalized = $this->normalizeDatabaseRow($row);
            $inviteId = $normalized['invite_id'];
            if ($inviteId === '' || isset($rows[$inviteId])) {
                throw new RuntimeException('Invite DB contains invalid or duplicate invite IDs.');
            }
            $rows[$inviteId] = $normalized;
        }
        ksort($rows, SORT_STRING);
        return $rows;
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
        foreach ([
            'invite_id', 'token', 'source', 'inviter_ref', 'inviter_mgw_id',
            'inviter_legacy_user_id', 'created_at_utc',
        ] as $key) {
            if (($expected[$key] ?? null) !== ($actual[$key] ?? null)) {
                throw new RuntimeException('Invite DB immutable identity conflicts with JSON rollback source.');
            }
        }
    }

    private function assertRowMatches(array $expected, array $stored): void
    {
        $actual = $this->normalizeDatabaseRow($stored);
        if ($actual !== $expected) {
            throw new RuntimeException('Stored invite DB row differs from the JSON rollback source.');
        }
    }

    private function compare(array $source, array $database): array
    {
        $sourceFingerprint = $this->fingerprint($source);
        $databaseFingerprint = $this->fingerprint($database);
        $blockers = [];
        if (count($source) !== count($database)) {
            $blockers[] = 'Invite JSON and DB counts differ.';
        }
        if (!hash_equals($sourceFingerprint, $databaseFingerprint)) {
            $blockers[] = 'Invite JSON and DB fingerprints differ.';
        }
        return [
            'ok' => $blockers === [],
            'source_count' => count($source),
            'database_count' => count($database),
            'source_fingerprint' => $sourceFingerprint,
            'database_fingerprint' => $databaseFingerprint,
            'blockers' => $blockers,
        ];
    }

    private function fingerprint(array $rows): string
    {
        ksort($rows, SORT_STRING);
        return hash('sha256', json_encode(
            $rows,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        ));
    }

    private function nullableText(mixed $value): ?string
    {
        $value = trim((string)$value);
        return $value === '' ? null : $value;
    }

    private function requiredTimestamp(mixed $value): string
    {
        $normalized = $this->nullableTimestamp($value);
        if ($normalized === null) {
            throw new RuntimeException('Invite row requires a valid timestamp.');
        }
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
}
