<?php
declare(strict_types=1);

/**
 * Read-only public player profile/stat projection for social UI.
 * Identity remains owned by mgw_users; match results remain owned by the
 * existing DB-primary match/player tables.
 */
final class SocialPlayerProfileReader
{
    private const GAME_TYPES = [
        'tictactoe', 'four_in_a_row', 'battleship', 'checkers',
        'reversi', 'chess', 'go', 'domino',
    ];

    public function __construct(private DatabaseConnectionInterface $database) {}

    /** @return array<string,mixed> */
    public function read(string $mgwId): array
    {
        $mgwId = MgwIdGenerator::fromPublic($mgwId) ?? '';
        if ($mgwId === '') throw new FriendGraphException('user_unavailable', 'MGW account is unavailable.');

        $users = $this->database->fetchAll(
            'SELECT mgw_id, nickname, equipped_avatar_item_id, created_at_utc
             FROM mgw_users WHERE mgw_id = :mgw_id AND status = :status',
            ['mgw_id' => $mgwId, 'status' => 'active']
        );
        if (count($users) !== 1) throw new FriendGraphException('user_unavailable', 'MGW account is unavailable.');

        $row = $users[0];
        $nickname = trim((string)($row['nickname'] ?? ''));
        if ($nickname === '') throw new FriendGraphException('user_unavailable', 'MGW account is unavailable.');
        $avatar = trim((string)($row['equipped_avatar_item_id'] ?? ''));
        if ($avatar === '') $avatar = MgwIdentityPolicy::DEFAULT_AVATAR_ITEM_ID;

        $totals = ['games_played' => 0, 'wins' => 0, 'losses' => 0, 'draws' => 0];
        $byGame = [];
        foreach (self::GAME_TYPES as $gameType) {
            $byGame[$gameType] = ['games_played' => 0, 'wins' => 0, 'losses' => 0, 'draws' => 0];
        }

        $stats = $this->database->fetchAll(
            "SELECT matches.game_type, players.result, COUNT(*) AS result_count
             FROM mgw_match_players players
             INNER JOIN mgw_matches matches ON matches.match_id = players.match_id
             WHERE players.mgw_id = :mgw_id
               AND players.player_type = 'human'
               AND matches.status = 'finished'
             GROUP BY matches.game_type, players.result",
            ['mgw_id' => $mgwId]
        );

        foreach ($stats as $stat) {
            $gameType = trim((string)($stat['game_type'] ?? ''));
            if (!isset($byGame[$gameType])) continue;
            $count = max(0, (int)($stat['result_count'] ?? 0));
            $result = strtolower(trim((string)($stat['result'] ?? '')));
            $byGame[$gameType]['games_played'] += $count;
            $totals['games_played'] += $count;
            $bucket = match ($result) {
                'win', 'won', 'winner' => 'wins',
                'loss', 'lost', 'lose', 'loser' => 'losses',
                'draw', 'tie' => 'draws',
                default => null,
            };
            if ($bucket !== null) {
                $byGame[$gameType][$bucket] += $count;
                $totals[$bucket] += $count;
            }
        }

        return [
            'mgw_id' => (string)$row['mgw_id'],
            'public_mgw_id' => MgwIdGenerator::toPublic((string)$row['mgw_id']),
            'nickname' => $nickname,
            'display_name' => $nickname,
            'avatar' => ['item_id' => $avatar],
            'member_since' => $this->nullableString($row['created_at_utc'] ?? null),
            'stats' => $totals + ['by_game' => $byGame],
        ];
    }

    private function nullableString(mixed $value): ?string
    {
        $value = trim((string)($value ?? ''));
        return $value === '' ? null : $value;
    }
}
