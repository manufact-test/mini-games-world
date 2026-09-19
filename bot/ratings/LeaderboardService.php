<?php
declare(strict_types=1);

final class LeaderboardService
{
    public const MAX_LIMIT = 100;

    private const CONTROL_KEY = 'global';
    private const GAME_TYPES = [
        'tictactoe',
        'four_in_a_row',
        'battleship',
        'checkers',
        'reversi',
        'chess',
        'go',
        'domino',
    ];

    public function __construct(private DatabaseConnectionInterface $database) {}

    public function snapshot(string $gameType, string $viewerMgwId, int $limit = self::MAX_LIMIT): array
    {
        $gameType = strtolower(trim($gameType));
        $viewerMgwId = trim($viewerMgwId);
        if (!in_array($gameType, self::GAME_TYPES, true)) {
            throw new InvalidArgumentException('Unsupported leaderboard game type.');
        }
        if ($viewerMgwId === '') {
            throw new InvalidArgumentException('Leaderboard viewer identity is required.');
        }

        $limit = max(1, min(self::MAX_LIMIT, $limit));
        $competition = $this->competitionControl();
        $rules = $this->leaderboardControl();
        $seasonId = $competition['current_season_id'];

        $rows = $this->database->fetchAll(
            'SELECT s.mgw_id, s.points, s.rated_wins, s.updated_at_utc,
                    u.nickname, u.equipped_avatar_item_id,
                    p.rated_matches, p.human_wins
             FROM mgw_game_rating_scores s
             INNER JOIN mgw_users u ON u.mgw_id = s.mgw_id
             INNER JOIN (
                 SELECT mgw_id,
                        COUNT(*) AS rated_matches,
                        SUM(CASE WHEN result_code = :win_code_a THEN 1 ELSE 0 END) AS human_wins
                 FROM mgw_game_rating_participation
                 WHERE season_id = :participation_season
                   AND game_type = :participation_game
                 GROUP BY mgw_id
                 HAVING COUNT(*) >= :min_matches
                    AND SUM(CASE WHEN result_code = :win_code_b THEN 1 ELSE 0 END) >= :min_wins
             ) p ON p.mgw_id = s.mgw_id
             WHERE s.season_id = :score_season
               AND s.game_type = :score_game
               AND u.status = :active_status
             ORDER BY s.points DESC,
                      s.rated_wins DESC,
                      s.updated_at_utc ASC,
                      s.mgw_id ASC
             LIMIT ' . $limit,
            [
                'win_code_a' => 'win',
                'participation_season' => $seasonId,
                'participation_game' => $gameType,
                'min_matches' => $rules['min_rated_matches'],
                'win_code_b' => 'win',
                'min_wins' => $rules['min_human_wins'],
                'score_season' => $seasonId,
                'score_game' => $gameType,
                'active_status' => 'active',
            ]
        );

        $entries = [];
        $viewerRank = null;
        foreach ($rows as $index => $row) {
            if (!is_array($row)) continue;
            $mgwId = trim((string)($row['mgw_id'] ?? ''));
            if ($mgwId === '') continue;
            $rank = $index + 1;
            if ($mgwId === $viewerMgwId) $viewerRank = $rank;
            $entries[] = [
                'rank' => $rank,
                'public_mgw_id' => MgwIdGenerator::toPublic($mgwId),
                'nickname' => trim((string)($row['nickname'] ?? '')),
                'avatar_item_id' => trim((string)($row['equipped_avatar_item_id'] ?? '')),
                'points' => max(0, (int)($row['points'] ?? 0)),
                'rated_wins' => max(0, (int)($row['rated_wins'] ?? 0)),
                'rated_matches' => max(0, (int)($row['rated_matches'] ?? 0)),
                'human_wins' => max(0, (int)($row['human_wins'] ?? 0)),
            ];
        }

        $viewer = $this->viewerStatus($viewerMgwId, $seasonId, $gameType, $rules);
        $viewer['rank'] = $viewerRank;

        return [
            'competition_state' => $competition['competition_state'],
            'official' => $competition['competition_state'] === PerGameRatingService::STATE_ACTIVE,
            'season_id' => $seasonId,
            'game_type' => $gameType,
            'limit' => $limit,
            'entries' => $entries,
            'viewer' => $viewer,
            'eligibility' => [
                'min_rated_matches' => $rules['min_rated_matches'],
                'min_human_wins' => $rules['min_human_wins'],
            ],
            'anti_farming' => [
                'max_credited_wins_same_opponent_day' => $rules['max_credited_wins_same_opponent_day'],
                'day_timezone' => $rules['day_timezone'],
            ],
            'tie_break' => [
                'points_desc',
                'credited_wins_desc',
                'score_reached_at_asc',
                'mgw_id_asc',
            ],
        ];
    }

    private function viewerStatus(
        string $mgwId,
        string $seasonId,
        string $gameType,
        array $rules
    ): array {
        $scoreRows = $this->database->fetchAll(
            'SELECT points, rated_wins, updated_at_utc
             FROM mgw_game_rating_scores
             WHERE season_id = :season_id
               AND mgw_id = :mgw_id
               AND game_type = :game_type',
            [
                'season_id' => $seasonId,
                'mgw_id' => $mgwId,
                'game_type' => $gameType,
            ]
        );
        $score = count($scoreRows) === 1 && is_array($scoreRows[0]) ? $scoreRows[0] : [];

        $participationRows = $this->database->fetchAll(
            'SELECT COUNT(*) AS rated_matches,
                    SUM(CASE WHEN result_code = :win_code THEN 1 ELSE 0 END) AS human_wins
             FROM mgw_game_rating_participation
             WHERE season_id = :season_id
               AND mgw_id = :mgw_id
               AND game_type = :game_type',
            [
                'win_code' => 'win',
                'season_id' => $seasonId,
                'mgw_id' => $mgwId,
                'game_type' => $gameType,
            ]
        );
        $participation = count($participationRows) === 1 && is_array($participationRows[0])
            ? $participationRows[0]
            : [];

        $ratedMatches = max(0, (int)($participation['rated_matches'] ?? 0));
        $humanWins = max(0, (int)($participation['human_wins'] ?? 0));

        return [
            'points' => max(0, (int)($score['points'] ?? 0)),
            'rated_wins' => max(0, (int)($score['rated_wins'] ?? 0)),
            'rated_matches' => $ratedMatches,
            'human_wins' => $humanWins,
            'eligible' => $ratedMatches >= $rules['min_rated_matches']
                && $humanWins >= $rules['min_human_wins'],
        ];
    }

    private function competitionControl(): array
    {
        $rows = $this->database->fetchAll(
            'SELECT competition_state, current_season_id
             FROM mgw_rating_control
             WHERE control_key = :control_key',
            ['control_key' => self::CONTROL_KEY]
        );
        if (count($rows) !== 1 || !is_array($rows[0])) {
            throw new RuntimeException('MVP-20 competition control is unavailable.');
        }

        $state = strtolower(trim((string)($rows[0]['competition_state'] ?? '')));
        $seasonId = trim((string)($rows[0]['current_season_id'] ?? ''));
        if (!in_array($state, [
            PerGameRatingService::STATE_OFF,
            PerGameRatingService::STATE_PRESEASON,
            PerGameRatingService::STATE_ACTIVE,
        ], true) || $seasonId === '') {
            throw new RuntimeException('MVP-20 competition control is invalid.');
        }

        return [
            'competition_state' => $state,
            'current_season_id' => $seasonId,
        ];
    }

    private function leaderboardControl(): array
    {
        $rows = $this->database->fetchAll(
            'SELECT day_timezone, min_rated_matches, min_human_wins,
                    max_credited_wins_same_opponent_day
             FROM mgw_leaderboard_control
             WHERE control_key = :control_key',
            ['control_key' => self::CONTROL_KEY]
        );
        if (count($rows) !== 1 || !is_array($rows[0])) {
            throw new RuntimeException('MVP-20.3 leaderboard control is unavailable.');
        }

        $row = $rows[0];
        return [
            'day_timezone' => trim((string)($row['day_timezone'] ?? 'Europe/Moscow')),
            'min_rated_matches' => max(1, (int)($row['min_rated_matches'] ?? 5)),
            'min_human_wins' => max(1, (int)($row['min_human_wins'] ?? 1)),
            'max_credited_wins_same_opponent_day' => max(
                1,
                (int)($row['max_credited_wins_same_opponent_day'] ?? 3)
            ),
        ];
    }
}
