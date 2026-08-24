<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/accounts/MgwIdGenerator.php';
require_once dirname(__DIR__) . '/accounts/MgwIdentityPolicy.php';

final class FriendGraphException extends RuntimeException
{
    public function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }
}

/**
 * Canonical server-side owner for the MGW friend/block pair state.
 *
 * One unordered row owns the current relation between two MGW accounts. This
 * makes reverse duplicate requests and request-vs-block races converge on the
 * same database lock instead of creating parallel directional owners.
 */
final class FriendGraphService
{
    private const STATUS_NONE = 'none';
    private const STATUS_PENDING = 'pending';
    private const STATUS_FRIENDS = 'friends';
    public const SEARCH_LIMIT = 8;
    private const SEARCH_CANDIDATE_LIMIT = 24;

    public function __construct(private DatabaseConnectionInterface $database) {}

    /** @return array{incoming:list<array<string,mixed>>,outgoing:list<array<string,mixed>>,friends:list<array<string,mixed>>,blocked:list<array<string,mixed>>,recent_opponents:list<array<string,mixed>>} */
    public function snapshot(string $actorMgwId): array
    {
        $actorMgwId = $this->requireActiveUser($actorMgwId);
        $incoming = [];
        $outgoing = [];
        $friends = [];
        $blocked = [];
        $hidden = [];

        foreach ($this->relationRows($actorMgwId) as $row) {
            $otherMgwId = $this->otherMgwId($row, $actorMgwId);
            if ($otherMgwId === '') continue;

            $ownBlocked = $this->ownBlocked($row, $actorMgwId);
            $otherBlocked = $this->otherBlocked($row, $actorMgwId);
            if ($ownBlocked || $otherBlocked) $hidden[$otherMgwId] = true;

            if ($ownBlocked) {
                $profile = $this->publicUser($otherMgwId);
                if ($profile !== null) $blocked[] = $profile + ['status' => 'blocked'];
                continue;
            }
            if ($otherBlocked) continue;

            $status = (string)($row['friend_status'] ?? self::STATUS_NONE);
            if ($status === self::STATUS_FRIENDS) {
                $profile = $this->publicUser($otherMgwId);
                if ($profile !== null) $friends[] = $profile + ['status' => 'friends'];
                continue;
            }
            if ($status !== self::STATUS_PENDING) continue;

            $requestedBy = trim((string)($row['requested_by_mgw_id'] ?? ''));
            $profile = $this->publicUser($otherMgwId);
            if ($profile === null) continue;
            $item = $profile + [
                'status' => $requestedBy === $actorMgwId ? 'outgoing' : 'incoming',
                'requested_at' => $this->nullableString($row['friend_requested_at_utc'] ?? null),
            ];
            if ($requestedBy === $actorMgwId) $outgoing[] = $item;
            else $incoming[] = $item;
        }

        return [
            'incoming' => $incoming,
            'outgoing' => $outgoing,
            'friends' => $friends,
            'blocked' => $blocked,
            'recent_opponents' => $this->recentOpponents($actorMgwId, $hidden),
        ];
    }

    public function lookupExact(string $actorMgwId, string $query): ?array
    {
        $actorMgwId = $this->requireActiveUser($actorMgwId);
        $query = trim($query);
        if ($query === '') throw new InvalidArgumentException('lookup_empty');

        $targetMgwId = MgwIdGenerator::fromPublic($query);
        if ($targetMgwId === null) {
            $rows = $this->database->fetchAll(
                'SELECT mgw_id FROM mgw_users WHERE status = :status AND nickname = :nickname',
                ['status' => 'active', 'nickname' => $query]
            );
            if (count($rows) !== 1) return null;
            $targetMgwId = trim((string)($rows[0]['mgw_id'] ?? ''));
        }

        $profile = $this->publicUser($targetMgwId);
        if ($profile === null) return null;
        if ($targetMgwId !== $actorMgwId && $this->pairIsBlocked($actorMgwId, $targetMgwId)) return null;
        return $profile;
    }

    /** @return list<array<string,mixed>> */
    public function searchPlayers(string $actorMgwId, string $query): array
    {
        $actorMgwId = $this->requireActiveUser($actorMgwId);
        $query = trim($query);
        if ($query === '') throw new InvalidArgumentException('lookup_empty');

        $targetMgwId = MgwIdGenerator::fromPublic($query);
        if ($targetMgwId !== null) {
            if ($targetMgwId === $actorMgwId) return [];
            $profile = $this->publicUser($targetMgwId);
            if ($profile === null || $this->pairIsBlocked($actorMgwId, $targetMgwId)) return [];
            return [$profile];
        }

        $nickname = ltrim($query, '@');
        $length = function_exists('mb_strlen') ? mb_strlen($nickname, 'UTF-8') : strlen($nickname);
        if ($length < 2 || $length > 40) throw new InvalidArgumentException('lookup_invalid');

        $pattern = '%' . $this->escapeLike($nickname) . '%';
        if ($this->database->driver() === 'sqlite') {
            $rows = $this->database->fetchAll(
                "SELECT mgw_id FROM mgw_users
                 WHERE status = :status
                   AND mgw_id <> :actor_mgw_id
                   AND nickname COLLATE NOCASE LIKE :nickname_pattern ESCAPE '!'
                 ORDER BY CASE WHEN nickname COLLATE NOCASE = :nickname_exact THEN 0 ELSE 1 END,
                          last_seen_at_utc DESC, nickname ASC
                 LIMIT " . self::SEARCH_CANDIDATE_LIMIT,
                [
                    'status' => 'active',
                    'actor_mgw_id' => $actorMgwId,
                    'nickname_pattern' => $pattern,
                    'nickname_exact' => $nickname,
                ]
            );
        } else {
            $rows = $this->database->fetchAll(
                "SELECT mgw_id FROM mgw_users
                 WHERE status = :status
                   AND mgw_id <> :actor_mgw_id
                   AND nickname COLLATE utf8mb4_unicode_ci LIKE :nickname_pattern ESCAPE '!'
                 ORDER BY CASE WHEN nickname COLLATE utf8mb4_unicode_ci = :nickname_exact THEN 0 ELSE 1 END,
                          last_seen_at_utc DESC, nickname ASC
                 LIMIT " . self::SEARCH_CANDIDATE_LIMIT,
                [
                    'status' => 'active',
                    'actor_mgw_id' => $actorMgwId,
                    'nickname_pattern' => $pattern,
                    'nickname_exact' => $nickname,
                ]
            );
        }

        $result = [];
        foreach ($rows as $row) {
            $targetMgwId = trim((string)($row['mgw_id'] ?? ''));
            if ($targetMgwId === '' || $this->pairIsBlocked($actorMgwId, $targetMgwId)) continue;
            $profile = $this->publicUser($targetMgwId);
            if ($profile === null) continue;
            $result[] = $profile;
            if (count($result) >= self::SEARCH_LIMIT) break;
        }
        return $result;
    }

    /** @return array{status:string,changed:bool,target:array<string,mixed>} */
    public function requestFriend(string $actorMgwId, string $targetMgwId): array
    {
        return $this->database->transaction(function () use ($actorMgwId, $targetMgwId): array {
            [$actorMgwId, $targetMgwId, $row] = $this->lockedPair($actorMgwId, $targetMgwId);
            $this->assertNotBlocked($row);
            $status = (string)($row['friend_status'] ?? self::STATUS_NONE);
            $requestedBy = trim((string)($row['requested_by_mgw_id'] ?? ''));

            if ($status === self::STATUS_FRIENDS) {
                return $this->mutationResult('friends', false, $targetMgwId);
            }
            if ($status === self::STATUS_PENDING) {
                if ($requestedBy === $actorMgwId) return $this->mutationResult('outgoing', false, $targetMgwId);
                throw new FriendGraphException('incoming_request_exists', 'An incoming friend request already exists for this pair.');
            }

            $now = $this->timestamp();
            $this->updatePair($row, [
                'friend_status' => self::STATUS_PENDING,
                'requested_by_mgw_id' => $actorMgwId,
                'friend_requested_at_utc' => $now,
                'friend_resolved_at_utc' => null,
                'updated_at_utc' => $now,
            ]);
            return $this->mutationResult('outgoing', true, $targetMgwId) + ['event_at' => $now];
        });
    }

    /** @return array{status:string,changed:bool,target:array<string,mixed>} */
    public function acceptFriendRequest(string $actorMgwId, string $targetMgwId): array
    {
        return $this->database->transaction(function () use ($actorMgwId, $targetMgwId): array {
            [$actorMgwId, $targetMgwId, $row] = $this->lockedPair($actorMgwId, $targetMgwId);
            $this->assertNotBlocked($row);
            $status = (string)($row['friend_status'] ?? self::STATUS_NONE);
            if ($status === self::STATUS_FRIENDS) return $this->mutationResult('friends', false, $targetMgwId);
            if ($status !== self::STATUS_PENDING || trim((string)($row['requested_by_mgw_id'] ?? '')) === $actorMgwId) {
                throw new FriendGraphException('request_not_incoming', 'No incoming friend request exists for this pair.');
            }

            $now = $this->timestamp();
            $this->updatePair($row, [
                'friend_status' => self::STATUS_FRIENDS,
                'requested_by_mgw_id' => null,
                'friend_resolved_at_utc' => $now,
                'updated_at_utc' => $now,
            ]);
            return $this->mutationResult('friends', true, $targetMgwId) + ['event_at' => $now];
        });
    }

    /** @return array{status:string,changed:bool,target:array<string,mixed>} */
    public function declineFriendRequest(string $actorMgwId, string $targetMgwId): array
    {
        return $this->resolvePending($actorMgwId, $targetMgwId, false, 'request_not_incoming');
    }

    /** @return array{status:string,changed:bool,target:array<string,mixed>} */
    public function cancelFriendRequest(string $actorMgwId, string $targetMgwId): array
    {
        return $this->resolvePending($actorMgwId, $targetMgwId, true, 'request_not_outgoing');
    }

    /** @return array{status:string,changed:bool,target:array<string,mixed>} */
    public function removeFriend(string $actorMgwId, string $targetMgwId): array
    {
        return $this->database->transaction(function () use ($actorMgwId, $targetMgwId): array {
            [$actorMgwId, $targetMgwId, $row] = $this->lockedPair($actorMgwId, $targetMgwId);
            if ((string)($row['friend_status'] ?? self::STATUS_NONE) !== self::STATUS_FRIENDS) {
                return $this->mutationResult('none', false, $targetMgwId);
            }
            $now = $this->timestamp();
            $this->updatePair($row, [
                'friend_status' => self::STATUS_NONE,
                'requested_by_mgw_id' => null,
                'friend_requested_at_utc' => null,
                'friend_resolved_at_utc' => $now,
                'updated_at_utc' => $now,
            ]);
            return $this->mutationResult('none', true, $targetMgwId);
        });
    }

    /** @return array{status:string,changed:bool,target:array<string,mixed>} */
    public function block(string $actorMgwId, string $targetMgwId): array
    {
        return $this->database->transaction(function () use ($actorMgwId, $targetMgwId): array {
            [$actorMgwId, $targetMgwId, $row] = $this->lockedPair($actorMgwId, $targetMgwId);
            $actorIsLow = (string)$row['user_low_mgw_id'] === $actorMgwId;
            $column = $actorIsLow ? 'blocked_by_low' : 'blocked_by_high';
            $alreadyBlocked = (int)($row[$column] ?? 0) === 1;
            $now = $this->timestamp();
            $this->updatePair($row, [
                $column => 1,
                'friend_status' => self::STATUS_NONE,
                'requested_by_mgw_id' => null,
                'friend_requested_at_utc' => null,
                'friend_resolved_at_utc' => $now,
                'updated_at_utc' => $now,
            ]);
            return $this->mutationResult('blocked', !$alreadyBlocked, $targetMgwId);
        });
    }

    /** @return array{status:string,changed:bool,target:array<string,mixed>} */
    public function unblock(string $actorMgwId, string $targetMgwId): array
    {
        return $this->database->transaction(function () use ($actorMgwId, $targetMgwId): array {
            [$actorMgwId, $targetMgwId, $row] = $this->lockedPair($actorMgwId, $targetMgwId);
            $actorIsLow = (string)$row['user_low_mgw_id'] === $actorMgwId;
            $column = $actorIsLow ? 'blocked_by_low' : 'blocked_by_high';
            $alreadyUnblocked = (int)($row[$column] ?? 0) === 0;
            $this->updatePair($row, [$column => 0, 'updated_at_utc' => $this->timestamp()]);
            return $this->mutationResult('none', !$alreadyUnblocked, $targetMgwId);
        });
    }

    /** @return array{status:string,changed:bool,target:array<string,mixed>} */
    private function resolvePending(string $actorMgwId, string $targetMgwId, bool $actorMustBeRequester, string $reason): array
    {
        return $this->database->transaction(function () use ($actorMgwId, $targetMgwId, $actorMustBeRequester, $reason): array {
            [$actorMgwId, $targetMgwId, $row] = $this->lockedPair($actorMgwId, $targetMgwId);
            $requestedBy = trim((string)($row['requested_by_mgw_id'] ?? ''));
            $validDirection = $actorMustBeRequester ? $requestedBy === $actorMgwId : $requestedBy !== $actorMgwId;
            if ((string)($row['friend_status'] ?? self::STATUS_NONE) !== self::STATUS_PENDING || !$validDirection) {
                throw new FriendGraphException($reason, 'No matching pending friend request exists for this pair.');
            }
            $now = $this->timestamp();
            $this->updatePair($row, [
                'friend_status' => self::STATUS_NONE,
                'requested_by_mgw_id' => null,
                'friend_requested_at_utc' => null,
                'friend_resolved_at_utc' => $now,
                'updated_at_utc' => $now,
            ]);
            return $this->mutationResult('none', true, $targetMgwId);
        });
    }

    /** @return array{0:string,1:string,2:array<string,mixed>} */
    private function lockedPair(string $actorMgwId, string $targetMgwId): array
    {
        $actorMgwId = $this->requireActiveUser($actorMgwId);
        $targetMgwId = $this->requireActiveUser($targetMgwId);
        if ($actorMgwId === $targetMgwId) throw new FriendGraphException('self_relation', 'A social relation cannot target the same MGW account.');

        [$low, $high] = strcmp($actorMgwId, $targetMgwId) < 0
            ? [$actorMgwId, $targetMgwId]
            : [$targetMgwId, $actorMgwId];
        $now = $this->timestamp();
        if ($this->database->driver() === 'sqlite') {
            $this->database->execute(
                'INSERT OR IGNORE INTO mgw_social_relations (
                    user_low_mgw_id, user_high_mgw_id, friend_status, requested_by_mgw_id,
                    blocked_by_low, blocked_by_high, friend_requested_at_utc, friend_resolved_at_utc,
                    created_at_utc, updated_at_utc
                 ) VALUES (
                    :low, :high, :status, NULL, 0, 0, NULL, NULL, :created_at, :updated_at
                 )',
                ['low' => $low, 'high' => $high, 'status' => self::STATUS_NONE, 'created_at' => $now, 'updated_at' => $now]
            );
        } else {
            $this->database->execute(
                'INSERT INTO mgw_social_relations (
                    user_low_mgw_id, user_high_mgw_id, friend_status, requested_by_mgw_id,
                    blocked_by_low, blocked_by_high, friend_requested_at_utc, friend_resolved_at_utc,
                    created_at_utc, updated_at_utc
                 ) VALUES (
                    :low, :high, :status, NULL, 0, 0, NULL, NULL, :created_at, :updated_at
                 ) ON DUPLICATE KEY UPDATE user_low_mgw_id = user_low_mgw_id',
                ['low' => $low, 'high' => $high, 'status' => self::STATUS_NONE, 'created_at' => $now, 'updated_at' => $now]
            );
        }

        $rows = $this->database->fetchAll(
            'SELECT * FROM mgw_social_relations
             WHERE user_low_mgw_id = :low AND user_high_mgw_id = :high' . $this->forUpdate(),
            ['low' => $low, 'high' => $high]
        );
        if (count($rows) !== 1) throw new RuntimeException('Canonical social pair row is unavailable.');
        return [$actorMgwId, $targetMgwId, $rows[0]];
    }

    /** @param array<string,mixed> $row @param array<string,mixed> $changes */
    private function updatePair(array $row, array $changes): void
    {
        $allowed = [
            'friend_status', 'requested_by_mgw_id', 'blocked_by_low', 'blocked_by_high',
            'friend_requested_at_utc', 'friend_resolved_at_utc', 'updated_at_utc',
        ];
        $sets = [];
        $parameters = [
            'low' => (string)$row['user_low_mgw_id'],
            'high' => (string)$row['user_high_mgw_id'],
        ];
        foreach ($changes as $column => $value) {
            if (!in_array($column, $allowed, true)) throw new LogicException('Unsupported social relation column update.');
            $sets[] = $column . ' = :' . $column;
            $parameters[$column] = $value;
        }
        if ($sets === []) return;
        $this->database->execute(
            'UPDATE mgw_social_relations SET ' . implode(', ', $sets) . '
             WHERE user_low_mgw_id = :low AND user_high_mgw_id = :high',
            $parameters
        );
    }

    /** @return list<array<string,mixed>> */
    private function relationRows(string $actorMgwId): array
    {
        return $this->database->fetchAll(
            'SELECT * FROM mgw_social_relations
             WHERE user_low_mgw_id = :actor_low OR user_high_mgw_id = :actor_high
             ORDER BY updated_at_utc DESC',
            ['actor_low' => $actorMgwId, 'actor_high' => $actorMgwId]
        );
    }

    private function pairIsBlocked(string $actorMgwId, string $targetMgwId): bool
    {
        if ($actorMgwId === $targetMgwId) return false;
        [$low, $high] = strcmp($actorMgwId, $targetMgwId) < 0
            ? [$actorMgwId, $targetMgwId]
            : [$targetMgwId, $actorMgwId];
        $rows = $this->database->fetchAll(
            'SELECT blocked_by_low, blocked_by_high FROM mgw_social_relations
             WHERE user_low_mgw_id = :low AND user_high_mgw_id = :high',
            ['low' => $low, 'high' => $high]
        );
        if ($rows === []) return false;
        return (int)($rows[0]['blocked_by_low'] ?? 0) === 1 || (int)($rows[0]['blocked_by_high'] ?? 0) === 1;
    }

    /** @param array<string,mixed> $row */
    private function assertNotBlocked(array $row): void
    {
        if ((int)($row['blocked_by_low'] ?? 0) === 1 || (int)($row['blocked_by_high'] ?? 0) === 1) {
            throw new FriendGraphException('request_unavailable', 'The requested social action is unavailable for this pair.');
        }
    }

    /** @param array<string,mixed> $row */
    private function otherMgwId(array $row, string $actorMgwId): string
    {
        return (string)$row['user_low_mgw_id'] === $actorMgwId
            ? trim((string)($row['user_high_mgw_id'] ?? ''))
            : trim((string)($row['user_low_mgw_id'] ?? ''));
    }

    /** @param array<string,mixed> $row */
    private function ownBlocked(array $row, string $actorMgwId): bool
    {
        return (string)$row['user_low_mgw_id'] === $actorMgwId
            ? (int)($row['blocked_by_low'] ?? 0) === 1
            : (int)($row['blocked_by_high'] ?? 0) === 1;
    }

    /** @param array<string,mixed> $row */
    private function otherBlocked(array $row, string $actorMgwId): bool
    {
        return (string)$row['user_low_mgw_id'] === $actorMgwId
            ? (int)($row['blocked_by_high'] ?? 0) === 1
            : (int)($row['blocked_by_low'] ?? 0) === 1;
    }

    /** @param array<string,bool> $hidden @return list<array<string,mixed>> */
    private function recentOpponents(string $actorMgwId, array $hidden): array
    {
        $rows = $this->database->fetchAll(
            "SELECT opponent.mgw_id, MAX(COALESCE(matches.finished_at_utc, matches.updated_at_utc, matches.created_at_utc)) AS last_match_at
             FROM mgw_match_players self_player
             INNER JOIN mgw_match_players opponent
                ON opponent.match_id = self_player.match_id
               AND opponent.mgw_id IS NOT NULL
               AND opponent.mgw_id <> self_player.mgw_id
             INNER JOIN mgw_matches matches ON matches.match_id = self_player.match_id
             WHERE self_player.mgw_id = :mgw_id
               AND self_player.player_type = 'human'
               AND opponent.player_type = 'human'
               AND matches.status = 'finished'
             GROUP BY opponent.mgw_id
             ORDER BY last_match_at DESC",
            ['mgw_id' => $actorMgwId]
        );

        $result = [];
        foreach ($rows as $row) {
            $otherMgwId = trim((string)($row['mgw_id'] ?? ''));
            if ($otherMgwId === '' || isset($hidden[$otherMgwId])) continue;
            $profile = $this->publicUser($otherMgwId);
            if ($profile === null) continue;
            $result[] = $profile + ['last_match_at' => $this->nullableString($row['last_match_at'] ?? null)];
        }
        return $result;
    }

    private function requireActiveUser(string $mgwId): string
    {
        $mgwId = MgwIdGenerator::fromPublic($mgwId) ?? '';
        if ($mgwId === '') throw new FriendGraphException('user_unavailable', 'MGW account is unavailable.');
        $rows = $this->database->fetchAll(
            'SELECT mgw_id FROM mgw_users WHERE mgw_id = :mgw_id AND status = :status',
            ['mgw_id' => $mgwId, 'status' => 'active']
        );
        if (count($rows) !== 1) throw new FriendGraphException('user_unavailable', 'MGW account is unavailable.');
        return $mgwId;
    }

    /** @return array<string,mixed>|null */
    private function publicUser(string $mgwId): ?array
    {
        $rows = $this->database->fetchAll(
            'SELECT mgw_id, nickname, equipped_avatar_item_id FROM mgw_users
             WHERE mgw_id = :mgw_id AND status = :status',
            ['mgw_id' => $mgwId, 'status' => 'active']
        );
        if (count($rows) !== 1) return null;
        $row = $rows[0];
        $nickname = trim((string)($row['nickname'] ?? ''));
        if ($nickname === '') return null;
        $avatar = trim((string)($row['equipped_avatar_item_id'] ?? ''));
        if (!in_array($avatar, MgwIdentityPolicy::STARTER_AVATAR_ITEM_IDS, true)) {
            $avatar = MgwIdentityPolicy::DEFAULT_AVATAR_ITEM_ID;
        }
        $internalMgwId = (string)$row['mgw_id'];
        return [
            'mgw_id' => $internalMgwId,
            'public_mgw_id' => MgwIdGenerator::toPublic($internalMgwId),
            'nickname' => $nickname,
            'display_name' => $nickname,
            'avatar' => ['item_id' => $avatar],
        ];
    }

    /** @return array{status:string,changed:bool,target:array<string,mixed>} */
    private function mutationResult(string $status, bool $changed, string $targetMgwId): array
    {
        $target = $this->publicUser($targetMgwId);
        if ($target === null) throw new FriendGraphException('user_unavailable', 'MGW account is unavailable.');
        return ['status' => $status, 'changed' => $changed, 'target' => $target];
    }

    private function forUpdate(): string
    {
        return $this->database->driver() === 'sqlite' ? '' : ' FOR UPDATE';
    }

    private function nullableString(mixed $value): ?string
    {
        $value = trim((string)($value ?? ''));
        return $value === '' ? null : $value;
    }

    private function escapeLike(string $value): string
    {
        return str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $value);
    }

    private function timestamp(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');
    }
}
