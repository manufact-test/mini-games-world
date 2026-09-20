<?php
declare(strict_types=1);

/**
 * MVP-20.7 canonical read owner for rating Profile history and public season archives.
 *
 * It never writes rating state. Current rating arithmetic remains owned by
 * PerGameRatingService; canonical ranking remains owned by LeaderboardService;
 * durable season placements remain owned by SeasonalAwardService.
 */
final class RatingArchiveService
{
    private const CONTROL_KEY = 'global';
    private const MAX_PROFILE_SEASONS = 12;

    public function __construct(
        private DatabaseConnectionInterface $database,
        private ?LeaderboardService $leaderboard = null
    ) {
        $this->leaderboard ??= new LeaderboardService($database);
    }

    public function profileSnapshot(string $mgwId): array
    {
        $mgwId = trim($mgwId);
        if ($mgwId === '' || strlen($mgwId) > 24) {
            throw new InvalidArgumentException('Rating archive user identity is invalid.');
        }

        $control = $this->competitionControl();
        $rules = $this->leaderboardControl();
        $currentSeason = $this->seasonOrNull($control['current_season_id']);
        $cards = $this->userCards($mgwId, $control['current_season_id'], $rules);

        $previousSeasons = $this->closedSeasons(self::MAX_PROFILE_SEASONS);
        $history = $this->userHistoryForSeasons($mgwId, $previousSeasons, $rules);

        $officialWinRows = $this->database->fetchAll(
            "SELECT COUNT(*) AS wins
             FROM mgw_game_rating_participation p
             INNER JOIN mgw_rating_seasons s ON s.season_id = p.season_id
             WHERE p.mgw_id = :mgw_id
               AND p.result_code = :win_code",
            ['mgw_id'=>$mgwId,'win_code'=>'win']
        );
        $officialWins = count($officialWinRows) === 1 && is_array($officialWinRows[0])
            ? max(0, (int)($officialWinRows[0]['wins'] ?? 0))
            : 0;

        return [
            'competition_state' => $control['competition_state'],
            'official' => $control['competition_state'] === PerGameRatingService::STATE_ACTIVE,
            'current_season_id' => $control['current_season_id'],
            'current_season' => $currentSeason === null ? null : $this->publicSeason($currentSeason),
            'current_cards' => $cards,
            'official_human_wins_all_seasons' => $officialWins,
            'previous_seasons' => $history,
            'eligibility' => [
                'min_rated_matches' => $rules['min_rated_matches'],
                'min_human_wins' => $rules['min_human_wins'],
            ],
        ];
    }

    public function publicOverview(): array
    {
        $control = $this->competitionControl();
        $seasons = array_map(
            fn(array $season): array => $this->publicSeason($season),
            $this->closedSeasons(40)
        );

        return [
            'competition_state' => $control['competition_state'],
            'official' => $control['competition_state'] === PerGameRatingService::STATE_ACTIVE,
            'current_season_id' => $control['current_season_id'],
            'seasons' => $seasons,
            'hall_of_fame' => $this->hallOfFame(),
            'tournaments' => [
                'available' => false,
                'entries' => [],
            ],
        ];
    }

    public function seasonArchive(string $seasonId, string $gameType): array
    {
        $season = $this->season($seasonId);
        if ((string)$season['season_state'] !== SeasonLifecycleService::SEASON_CLOSED) {
            throw new InvalidArgumentException('Only closed official seasons can be opened from the archive.');
        }

        $gameType = strtolower(trim($gameType));
        if (!in_array($gameType, LeaderboardService::gameTypes(), true)) {
            throw new InvalidArgumentException('Unsupported rating archive game type.');
        }

        $rows = $this->leaderboard->standingsForSeason(
            (string)$season['season_id'],
            $gameType,
            LeaderboardService::MAX_LIMIT
        );

        $entries = [];
        foreach ($rows as $row) {
            if (!is_array($row)) continue;
            $mgwId = trim((string)($row['mgw_id'] ?? ''));
            if ($mgwId === '') continue;
            $entries[] = [
                'rank' => max(1, (int)($row['rank'] ?? 1)),
                'public_mgw_id' => MgwIdGenerator::toPublic($mgwId),
                'nickname' => trim((string)($row['nickname'] ?? '')),
                'avatar_item_id' => trim((string)($row['avatar_item_id'] ?? '')),
                'points' => max(0, (int)($row['points'] ?? 0)),
                'rated_wins' => max(0, (int)($row['rated_wins'] ?? 0)),
                'rated_matches' => max(0, (int)($row['rated_matches'] ?? 0)),
                'human_wins' => max(0, (int)($row['human_wins'] ?? 0)),
            ];
        }

        return [
            'season' => $this->publicSeason($season),
            'game_type' => $gameType,
            'entries' => $entries,
            'top3' => array_slice($entries, 0, 3),
            'limit' => LeaderboardService::MAX_LIMIT,
        ];
    }

    public function hallOfFame(): array
    {
        $rows = $this->database->fetchAll(
            "SELECT a.season_id, a.game_type, a.rank_position, a.badge_tier,
                    a.mgw_id, u.nickname, u.equipped_avatar_item_id,
                    s.calendar_year, s.quarter, s.calendar_end_at_utc
             FROM mgw_season_awards a
             INNER JOIN mgw_rating_seasons s ON s.season_id = a.season_id
             INNER JOIN mgw_users u ON u.mgw_id = a.mgw_id
             WHERE a.award_state = :award_state
               AND a.rank_position BETWEEN 1 AND 3
               AND s.season_state = :season_state
               AND u.status = :user_status
               AND NOT EXISTS (
                   SELECT 1
                   FROM mgw_identities dev_identity
                   WHERE dev_identity.mgw_id = a.mgw_id
                     AND dev_identity.provider = :development_provider
               )
             ORDER BY s.calendar_end_at_utc DESC,
                      a.game_type ASC,
                      a.rank_position ASC,
                      a.mgw_id ASC",
            [
                'award_state'=>SeasonalAwardService::AWARD_ACTIVE,
                'season_state'=>SeasonLifecycleService::SEASON_CLOSED,
                'user_status'=>'active',
                'development_provider'=>'development',
            ]
        );

        $entries = [];
        foreach ($rows as $row) {
            if (!is_array($row)) continue;
            $mgwId = trim((string)($row['mgw_id'] ?? ''));
            if ($mgwId === '') continue;
            $entries[] = [
                'season_id' => (string)($row['season_id'] ?? ''),
                'calendar_year' => (int)($row['calendar_year'] ?? 0),
                'quarter' => (int)($row['quarter'] ?? 0),
                'game_type' => (string)($row['game_type'] ?? ''),
                'rank' => max(1, (int)($row['rank_position'] ?? 1)),
                'badge_tier' => (string)($row['badge_tier'] ?? ''),
                'public_mgw_id' => MgwIdGenerator::toPublic($mgwId),
                'nickname' => trim((string)($row['nickname'] ?? '')),
                'avatar_item_id' => trim((string)($row['equipped_avatar_item_id'] ?? '')),
            ];
        }
        return $entries;
    }

    private function userCards(string $mgwId, string $seasonId, array $rules): array
    {
        $cards = [];
        foreach (LeaderboardService::gameTypes() as $gameType) {
            $cards[$gameType] = [
                'points'=>0,
                'rated_wins'=>0,
                'rated_matches'=>0,
                'human_wins'=>0,
                'eligible'=>false,
            ];
        }

        foreach ($this->database->fetchAll(
            'SELECT game_type, points, rated_wins
             FROM mgw_game_rating_scores
             WHERE season_id = :season_id AND mgw_id = :mgw_id',
            ['season_id'=>$seasonId,'mgw_id'=>$mgwId]
        ) as $row) {
            if (!is_array($row)) continue;
            $gameType = trim((string)($row['game_type'] ?? ''));
            if (!isset($cards[$gameType])) continue;
            $cards[$gameType]['points'] = max(0, (int)($row['points'] ?? 0));
            $cards[$gameType]['rated_wins'] = max(0, (int)($row['rated_wins'] ?? 0));
        }

        foreach ($this->database->fetchAll(
            "SELECT game_type,
                    COUNT(*) AS rated_matches,
                    SUM(CASE WHEN result_code = :win_code THEN 1 ELSE 0 END) AS human_wins
             FROM mgw_game_rating_participation
             WHERE season_id = :season_id
               AND mgw_id = :mgw_id
             GROUP BY game_type",
            ['win_code'=>'win','season_id'=>$seasonId,'mgw_id'=>$mgwId]
        ) as $row) {
            if (!is_array($row)) continue;
            $gameType = trim((string)($row['game_type'] ?? ''));
            if (!isset($cards[$gameType])) continue;
            $cards[$gameType]['rated_matches'] = max(0, (int)($row['rated_matches'] ?? 0));
            $cards[$gameType]['human_wins'] = max(0, (int)($row['human_wins'] ?? 0));
        }

        foreach ($cards as &$card) {
            $card['eligible'] = $card['rated_matches'] >= $rules['min_rated_matches']
                && $card['human_wins'] >= $rules['min_human_wins'];
        }
        unset($card);
        return $cards;
    }

    private function userHistoryForSeasons(string $mgwId, array $seasons, array $rules): array
    {
        if ($seasons === []) return [];
        $ids = array_values(array_map(static fn(array $season): string => (string)$season['season_id'], $seasons));
        [$inSql, $params] = $this->inParams('season', $ids);
        $params['mgw_id'] = $mgwId;

        $scores = [];
        foreach ($this->database->fetchAll(
            "SELECT season_id, game_type, points, rated_wins
             FROM mgw_game_rating_scores
             WHERE mgw_id = :mgw_id AND season_id IN ($inSql)",
            $params
        ) as $row) {
            if (!is_array($row)) continue;
            $key = (string)($row['season_id'] ?? '') . '|' . (string)($row['game_type'] ?? '');
            $scores[$key] = [
                'points'=>max(0,(int)($row['points'] ?? 0)),
                'rated_wins'=>max(0,(int)($row['rated_wins'] ?? 0)),
            ];
        }

        $participation = [];
        $participationParams = $params + ['win_code'=>'win'];
        foreach ($this->database->fetchAll(
            "SELECT season_id, game_type,
                    COUNT(*) AS rated_matches,
                    SUM(CASE WHEN result_code = :win_code THEN 1 ELSE 0 END) AS human_wins
             FROM mgw_game_rating_participation
             WHERE mgw_id = :mgw_id AND season_id IN ($inSql)
             GROUP BY season_id, game_type",
            $participationParams
        ) as $row) {
            if (!is_array($row)) continue;
            $key = (string)($row['season_id'] ?? '') . '|' . (string)($row['game_type'] ?? '');
            $participation[$key] = [
                'rated_matches'=>max(0,(int)($row['rated_matches'] ?? 0)),
                'human_wins'=>max(0,(int)($row['human_wins'] ?? 0)),
            ];
        }

        $awards = [];
        $awardParams = $params + ['award_state'=>SeasonalAwardService::AWARD_ACTIVE];
        foreach ($this->database->fetchAll(
            "SELECT season_id, game_type, rank_position, badge_tier
             FROM mgw_season_awards
             WHERE mgw_id = :mgw_id
               AND award_state = :award_state
               AND season_id IN ($inSql)",
            $awardParams
        ) as $row) {
            if (!is_array($row)) continue;
            $key = (string)($row['season_id'] ?? '') . '|' . (string)($row['game_type'] ?? '');
            $awards[$key] = [
                'rank'=>max(1,(int)($row['rank_position'] ?? 1)),
                'badge_tier'=>(string)($row['badge_tier'] ?? ''),
            ];
        }

        $history = [];
        foreach ($seasons as $season) {
            $games = [];
            foreach (LeaderboardService::gameTypes() as $gameType) {
                $key = (string)$season['season_id'] . '|' . $gameType;
                $score = $scores[$key] ?? ['points'=>0,'rated_wins'=>0];
                $part = $participation[$key] ?? ['rated_matches'=>0,'human_wins'=>0];
                $award = $awards[$key] ?? null;
                if ($score['points'] === 0 && $part['rated_matches'] === 0 && $award === null) continue;
                $games[$gameType] = [
                    'points'=>$score['points'],
                    'rated_wins'=>$score['rated_wins'],
                    'rated_matches'=>$part['rated_matches'],
                    'human_wins'=>$part['human_wins'],
                    'eligible'=>$part['rated_matches'] >= $rules['min_rated_matches']
                        && $part['human_wins'] >= $rules['min_human_wins'],
                    'rank'=>$award['rank'] ?? null,
                    'badge_tier'=>$award['badge_tier'] ?? null,
                ];
            }
            if ($games === []) continue;
            $history[] = $this->publicSeason($season) + ['games'=>$games];
        }
        return $history;
    }

    private function closedSeasons(int $limit): array
    {
        $limit = max(1, min(40, $limit));
        return $this->database->fetchAll(
            "SELECT season_id, calendar_year, quarter, timezone,
                    calendar_start_at_utc, calendar_end_at_utc, official_start_at_utc,
                    season_state, standings_frozen_at_utc, finalized_at_utc
             FROM mgw_rating_seasons
             WHERE season_state = :state
             ORDER BY calendar_end_at_utc DESC, season_id DESC
             LIMIT $limit",
            ['state'=>SeasonLifecycleService::SEASON_CLOSED]
        );
    }

    private function season(string $seasonId): array
    {
        $season = $this->seasonOrNull($seasonId);
        if ($season === null) throw new InvalidArgumentException('Rating archive season was not found.');
        return $season;
    }

    private function seasonOrNull(string $seasonId): ?array
    {
        $seasonId = strtolower(trim($seasonId));
        if ($seasonId === '' || $seasonId === PerGameRatingService::PRESEASON_ID) return null;
        $rows = $this->database->fetchAll(
            "SELECT season_id, calendar_year, quarter, timezone,
                    calendar_start_at_utc, calendar_end_at_utc, official_start_at_utc,
                    season_state, standings_frozen_at_utc, finalized_at_utc
             FROM mgw_rating_seasons
             WHERE season_id = :season_id",
            ['season_id'=>$seasonId]
        );
        if ($rows === []) return null;
        if (count($rows) !== 1 || !is_array($rows[0])) {
            throw new RuntimeException('Rating archive season state is invalid.');
        }
        return $rows[0];
    }

    private function publicSeason(array $season): array
    {
        return [
            'season_id'=>(string)($season['season_id'] ?? ''),
            'calendar_year'=>(int)($season['calendar_year'] ?? 0),
            'quarter'=>(int)($season['quarter'] ?? 0),
            'timezone'=>(string)($season['timezone'] ?? 'Europe/Moscow'),
            'start_at_utc'=>(string)($season['official_start_at_utc'] ?? $season['calendar_start_at_utc'] ?? ''),
            'end_at_utc'=>(string)($season['calendar_end_at_utc'] ?? ''),
            'state'=>(string)($season['season_state'] ?? ''),
            'finalized_at_utc'=>$this->nullableText($season['finalized_at_utc'] ?? null),
        ];
    }

    private function competitionControl(): array
    {
        $rows = $this->database->fetchAll(
            'SELECT competition_state, current_season_id
             FROM mgw_rating_control
             WHERE control_key = :control_key',
            ['control_key'=>self::CONTROL_KEY]
        );
        if (count($rows) !== 1 || !is_array($rows[0])) {
            throw new RuntimeException('Rating archive competition control is unavailable.');
        }
        return [
            'competition_state'=>strtolower(trim((string)($rows[0]['competition_state'] ?? ''))),
            'current_season_id'=>trim((string)($rows[0]['current_season_id'] ?? '')),
        ];
    }

    private function leaderboardControl(): array
    {
        $rows = $this->database->fetchAll(
            'SELECT min_rated_matches, min_human_wins
             FROM mgw_leaderboard_control
             WHERE control_key = :control_key',
            ['control_key'=>self::CONTROL_KEY]
        );
        if (count($rows) !== 1 || !is_array($rows[0])) {
            throw new RuntimeException('Rating archive leaderboard control is unavailable.');
        }
        return [
            'min_rated_matches'=>max(1,(int)($rows[0]['min_rated_matches'] ?? 5)),
            'min_human_wins'=>max(1,(int)($rows[0]['min_human_wins'] ?? 1)),
        ];
    }

    private function inParams(string $prefix, array $values): array
    {
        if ($values === []) throw new InvalidArgumentException('Archive season list is empty.');
        $params = [];
        $placeholders = [];
        foreach (array_values($values) as $index => $value) {
            $key = $prefix . '_' . $index;
            $placeholders[] = ':' . $key;
            $params[$key] = (string)$value;
        }
        return [implode(', ', $placeholders), $params];
    }

    private function nullableText(mixed $value): ?string
    {
        if ($value === null) return null;
        $text = trim((string)$value);
        return $text === '' ? null : $text;
    }
}
