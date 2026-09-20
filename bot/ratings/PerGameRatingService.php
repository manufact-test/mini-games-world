<?php
declare(strict_types=1);

final class PerGameRatingService
{
    public const STATE_OFF = 'off';
    public const STATE_PRESEASON = 'preseason';
    public const STATE_ACTIVE = 'active';
    public const PRESEASON_ID = 'preseason';
    public const TOURNAMENT_MATCH_SOURCE = 'tournament';

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

    public function processPendingFinishedMatches(int $limit = 100): array
    {
        $limit = max(1, min(500, $limit));
        $control = $this->control();
        $leaderboard = $this->leaderboardControl();

        $matches = $this->database->fetchAll(
            'SELECT m.match_id, m.game_type, m.status, m.match_source, m.winner_player_ref,
                    m.finish_reason, m.started_at_utc, m.finished_at_utc
             FROM mgw_matches m
             WHERE m.status = :status
               AND m.finished_at_utc IS NOT NULL
               AND m.finished_at_utc >= :tracking_started_at
               AND NOT EXISTS (
                   SELECT 1 FROM mgw_game_rating_outcomes r WHERE r.match_id = m.match_id
               )
             ORDER BY m.finished_at_utc ASC, m.match_id ASC
             LIMIT ' . $limit,
            [
                'status' => 'finished',
                'tracking_started_at' => $control['tracking_started_at'],
            ]
        );

        $summary = [
            'scanned' => count($matches),
            'recorded' => 0,
            'rated' => 0,
            'points_awarded' => 0,
            'anti_farming_limited' => 0,
            'duplicates' => 0,
            'deferred' => 0,
        ];

        foreach ($matches as $match) {
            if (!is_array($match)) continue;
            $result = $this->processMatch($match, $control, $leaderboard);
            if ($result['status'] === 'deferred') {
                $summary['deferred']++;
                continue;
            }
            if ($result['status'] === 'duplicate') {
                $summary['duplicates']++;
                continue;
            }
            $summary['recorded']++;
            $points = (int)$result['points_delta'];
            if ($points > 0) {
                $summary['rated']++;
                $summary['points_awarded'] += $points;
            }
            if (!empty($result['anti_farming_limited'])) {
                $summary['anti_farming_limited']++;
            }
        }

        return $summary + [
            'competition_state' => $control['competition_state'],
            'season_id' => $control['current_season_id'],
        ];
    }

    public function snapshot(string $mgwId): array
    {
        $mgwId = trim($mgwId);
        if ($mgwId === '') throw new InvalidArgumentException('MGW rating profile id is required.');

        $control = $this->control();
        $byGame = [];
        foreach (self::GAME_TYPES as $gameType) {
            $byGame[$gameType] = ['points' => 0, 'rated_wins' => 0];
        }

        foreach ($this->database->fetchAll(
            'SELECT game_type, points, rated_wins
             FROM mgw_game_rating_scores
             WHERE season_id = :season_id AND mgw_id = :mgw_id',
            [
                'season_id' => $control['current_season_id'],
                'mgw_id' => $mgwId,
            ]
        ) as $row) {
            if (!is_array($row)) continue;
            $gameType = trim((string)($row['game_type'] ?? ''));
            if (!isset($byGame[$gameType])) continue;
            $byGame[$gameType] = [
                'points' => max(0, (int)($row['points'] ?? 0)),
                'rated_wins' => max(0, (int)($row['rated_wins'] ?? 0)),
            ];
        }

        return [
            'competition_state' => $control['competition_state'],
            'season_id' => $control['current_season_id'],
            'official' => $control['competition_state'] === self::STATE_ACTIVE,
            'tracking_started_at' => $control['tracking_started_at'],
            'activated_at' => $control['activated_at'],
            'by_game' => $byGame,
        ];
    }

    private function processMatch(array $match, array $control, array $leaderboard): array
    {
        $matchId = trim((string)($match['match_id'] ?? ''));
        if ($matchId === '') return ['status' => 'deferred', 'points_delta' => 0];

        $gameType = trim((string)($match['game_type'] ?? ''));
        $matchSource = $this->nullableText($match['match_source'] ?? null);
        $finishReason = $this->nullableText($match['finish_reason'] ?? null);
        $startedAt = $this->nullableText($match['started_at_utc'] ?? null);
        $finishedAt = $this->nullableText($match['finished_at_utc'] ?? null);
        $winnerPlayerRef = $this->nullableText($match['winner_player_ref'] ?? null);

        $assignment = $this->seasonAssignment($finishedAt, $control);
        $effectiveState = $assignment['competition_state'];
        $seasonId = $assignment['season_id'];

        $players = $this->database->fetchAll(
            'SELECT seat, player_ref, mgw_id, player_type
             FROM mgw_match_players
             WHERE match_id = :match_id
             ORDER BY seat ASC',
            ['match_id' => $matchId]
        );

        $decision = $this->decision(
            $gameType,
            $matchSource,
            $finishReason,
            $winnerPlayerRef,
            $players,
            $effectiveState
        );

        if (!$decision['final']) {
            return ['status' => 'deferred', 'points_delta' => 0];
        }

        $pointsRequested = (int)$decision['points_delta'];
        $winnerMgwId = $decision['winner_mgw_id'];
        $opponentMgwId = $decision['opponent_mgw_id'];
        $ratedMatch = !empty($decision['rated_match']);
        $daySource = $startedAt ?? $finishedAt;
        $ratingDay = $ratedMatch && $daySource !== null
            ? $this->ratingDay($daySource, $leaderboard['day_timezone'])
            : null;

        return $this->database->transaction(function (DatabaseConnectionInterface $database) use (
            $matchId,
            $seasonId,
            $gameType,
            $effectiveState,
            $winnerMgwId,
            $opponentMgwId,
            $pointsRequested,
            $decision,
            $matchSource,
            $finishReason,
            $startedAt,
            $finishedAt,
            $ratingDay,
            $ratedMatch,
            $leaderboard
        ): array {
            $antiFarmingLimited = false;
            $pointsDelta = $pointsRequested;
            $outcomeCode = (string)$decision['outcome_code'];

            if ($pointsRequested > 0
                && is_string($winnerMgwId) && $winnerMgwId !== ''
                && is_string($opponentMgwId) && $opponentMgwId !== ''
                && is_string($ratingDay) && $ratingDay !== ''
                && $this->antiFarmingApplies($startedAt ?? $finishedAt, $leaderboard['anti_farming_started_at'])) {
                $slot = $this->reserveDailyWinSlot(
                    $database,
                    $ratingDay,
                    $gameType,
                    $winnerMgwId,
                    $opponentMgwId,
                    $leaderboard['max_credited_wins_same_opponent_day'],
                    $matchId
                );

                if ($slot === 'duplicate') {
                    return [
                        'status' => 'duplicate',
                        'points_delta' => 0,
                        'anti_farming_limited' => false,
                    ];
                }

                if ($slot === 'limited') {
                    $antiFarmingLimited = true;
                    $pointsDelta = 0;
                    $outcomeCode = 'anti_farming_cap';
                }
            }

            $inserted = $this->insertOutcomeIfNew($database, [
                'match_id' => $matchId,
                'season_id' => $seasonId,
                'game_type' => $gameType !== '' ? $gameType : 'unknown',
                'competition_state' => $effectiveState,
                'winner_mgw_id' => $winnerMgwId,
                'opponent_mgw_id' => $opponentMgwId,
                'points_delta' => $pointsDelta,
                'points_requested' => $pointsRequested,
                'anti_farming_limited' => $antiFarmingLimited ? 1 : 0,
                'outcome_code' => $outcomeCode,
                'match_source' => $matchSource,
                'finish_reason' => $finishReason,
                'rating_day_moscow' => $ratingDay,
                'finished_at_utc' => $finishedAt,
                'processed_at_utc' => $this->timestamp(),
            ]);
            if (!$inserted) {
                if ($pointsRequested > 0
                    && !$antiFarmingLimited
                    && is_string($winnerMgwId) && $winnerMgwId !== ''
                    && is_string($opponentMgwId) && $opponentMgwId !== ''
                    && is_string($ratingDay) && $ratingDay !== ''
                    && $this->antiFarmingApplies($startedAt ?? $finishedAt, $leaderboard['anti_farming_started_at'])) {
                    $this->releaseDailyWinSlot(
                        $database,
                        $ratingDay,
                        $gameType,
                        $winnerMgwId,
                        $opponentMgwId
                    );
                }
                return [
                    'status' => 'duplicate',
                    'points_delta' => 0,
                    'anti_farming_limited' => false,
                ];
            }

            if ($ratedMatch && count($decision['participants']) === 2 && is_string($ratingDay)) {
                $this->recordParticipation(
                    $database,
                    $matchId,
                    $seasonId,
                    $gameType,
                    $decision['participants'],
                    $winnerMgwId,
                    $pointsDelta,
                    $ratingDay,
                    $startedAt,
                    $finishedAt
                );
            }

            if ($pointsDelta > 0 && is_string($winnerMgwId) && $winnerMgwId !== '') {
                $this->incrementScore(
                    $database,
                    $seasonId,
                    $winnerMgwId,
                    $gameType,
                    $pointsDelta
                );
            }

            return [
                'status' => 'recorded',
                'points_delta' => $pointsDelta,
                'anti_farming_limited' => $antiFarmingLimited,
            ];
        });
    }

    private function decision(
        string $gameType,
        ?string $matchSource,
        ?string $finishReason,
        ?string $winnerPlayerRef,
        array $players,
        string $competitionState
    ): array {
        if (!in_array($gameType, self::GAME_TYPES, true)) {
            return $this->finalDecision(0, 'unsupported_game');
        }
        if ($competitionState === self::STATE_OFF) {
            return $this->finalDecision(0, 'competition_off');
        }
        if ($finishReason !== 'normal_win' && $finishReason !== 'draw') {
            return $this->finalDecision(0, 'technical_result');
        }
        if (count($players) !== 2) {
            return $this->finalDecision(0, 'invalid_players');
        }

        $participants = [];
        foreach ($players as $player) {
            if (!is_array($player)) return $this->finalDecision(0, 'invalid_players');
            if (strtolower(trim((string)($player['player_type'] ?? 'human'))) !== 'human') {
                return $this->finalDecision(0, 'bot_game');
            }
            $playerRef = trim((string)($player['player_ref'] ?? ''));
            $mgwId = trim((string)($player['mgw_id'] ?? ''));
            if ($playerRef === '' || $mgwId === '') {
                return [
                    'final' => false,
                    'points_delta' => 0,
                    'outcome_code' => 'pending_player_identity',
                    'winner_mgw_id' => null,
                    'opponent_mgw_id' => null,
                    'rated_match' => false,
                    'participants' => [],
                ];
            }
            $participants[] = ['player_ref' => $playerRef, 'mgw_id' => $mgwId];
        }

        if ($participants[0]['mgw_id'] === $participants[1]['mgw_id']) {
            return $this->finalDecision(0, 'invalid_players');
        }

        if ($finishReason === 'draw') {
            return [
                'final' => true,
                'points_delta' => 0,
                'outcome_code' => 'draw',
                'winner_mgw_id' => null,
                'opponent_mgw_id' => null,
                'rated_match' => true,
                'participants' => $participants,
            ];
        }
        if ($winnerPlayerRef === null) {
            return $this->finalDecision(0, 'invalid_winner');
        }

        $winner = null;
        $opponent = null;
        foreach ($participants as $participant) {
            if ($participant['player_ref'] === $winnerPlayerRef) $winner = $participant;
            else $opponent = $participant;
        }
        if (!is_array($winner) || !is_array($opponent)) {
            return $this->finalDecision(0, 'invalid_winner');
        }

        $isTournament = $matchSource === self::TOURNAMENT_MATCH_SOURCE;
        return [
            'final' => true,
            'points_delta' => $isTournament ? 2 : 1,
            'outcome_code' => $isTournament ? 'rated_tournament_win' : 'rated_normal_win',
            'winner_mgw_id' => $winner['mgw_id'],
            'opponent_mgw_id' => $opponent['mgw_id'],
            'rated_match' => true,
            'participants' => $participants,
        ];
    }

    private function finalDecision(int $points, string $code): array
    {
        return [
            'final' => true,
            'points_delta' => $points,
            'outcome_code' => $code,
            'winner_mgw_id' => null,
            'opponent_mgw_id' => null,
            'rated_match' => false,
            'participants' => [],
        ];
    }

    private function reserveDailyWinSlot(
        DatabaseConnectionInterface $database,
        string $ratingDay,
        string $gameType,
        string $winnerMgwId,
        string $opponentMgwId,
        int $maxWins,
        string $matchId
    ): string {
        $params = [
            'rating_day' => $ratingDay,
            'game_type' => $gameType,
            'winner_mgw_id' => $winnerMgwId,
            'opponent_mgw_id' => $opponentMgwId,
            'updated_at' => $this->timestamp(),
        ];

        if ($database->driver() === 'sqlite') {
            $database->execute(
                'INSERT OR IGNORE INTO mgw_rating_daily_pair_wins (
                    rating_day_moscow, game_type, winner_mgw_id,
                    opponent_mgw_id, credited_wins, updated_at_utc
                 ) VALUES (
                    :rating_day, :game_type, :winner_mgw_id,
                    :opponent_mgw_id, 0, :updated_at
                 )',
                $params
            );
        } else {
            $database->execute(
                'INSERT IGNORE INTO mgw_rating_daily_pair_wins (
                    rating_day_moscow, game_type, winner_mgw_id,
                    opponent_mgw_id, credited_wins, updated_at_utc
                 ) VALUES (
                    :rating_day, :game_type, :winner_mgw_id,
                    :opponent_mgw_id, 0, :updated_at
                 )',
                $params
            );
        }

        $select = 'SELECT credited_wins
                   FROM mgw_rating_daily_pair_wins
                   WHERE rating_day_moscow = :rating_day
                     AND game_type = :game_type
                     AND winner_mgw_id = :winner_mgw_id
                     AND opponent_mgw_id = :opponent_mgw_id';
        if ($database->driver() !== 'sqlite') $select .= ' FOR UPDATE';

        $rows = $database->fetchAll($select, [
            'rating_day' => $ratingDay,
            'game_type' => $gameType,
            'winner_mgw_id' => $winnerMgwId,
            'opponent_mgw_id' => $opponentMgwId,
        ]);
        if (count($rows) !== 1 || !is_array($rows[0])) {
            throw new RuntimeException('MVP-20.3 anti-farming counter is unavailable.');
        }

        if ((int)$database->fetchValue(
            'SELECT COUNT(*) FROM mgw_game_rating_outcomes WHERE match_id = :match_id',
            ['match_id' => $matchId]
        ) > 0) {
            return 'duplicate';
        }

        $creditedWins = max(0, (int)($rows[0]['credited_wins'] ?? 0));
        if ($creditedWins >= $maxWins) return 'limited';

        $database->execute(
            'UPDATE mgw_rating_daily_pair_wins
             SET credited_wins = :credited_wins, updated_at_utc = :updated_at
             WHERE rating_day_moscow = :rating_day
               AND game_type = :game_type
               AND winner_mgw_id = :winner_mgw_id
               AND opponent_mgw_id = :opponent_mgw_id',
            [
                'credited_wins' => $creditedWins + 1,
                'updated_at' => $this->timestamp(),
                'rating_day' => $ratingDay,
                'game_type' => $gameType,
                'winner_mgw_id' => $winnerMgwId,
                'opponent_mgw_id' => $opponentMgwId,
            ]
        );

        return 'credited';
    }

    private function releaseDailyWinSlot(
        DatabaseConnectionInterface $database,
        string $ratingDay,
        string $gameType,
        string $winnerMgwId,
        string $opponentMgwId
    ): void {
        $database->execute(
            'UPDATE mgw_rating_daily_pair_wins
             SET credited_wins = CASE WHEN credited_wins > 0 THEN credited_wins - 1 ELSE 0 END,
                 updated_at_utc = :updated_at
             WHERE rating_day_moscow = :rating_day
               AND game_type = :game_type
               AND winner_mgw_id = :winner_mgw_id
               AND opponent_mgw_id = :opponent_mgw_id',
            [
                'updated_at' => $this->timestamp(),
                'rating_day' => $ratingDay,
                'game_type' => $gameType,
                'winner_mgw_id' => $winnerMgwId,
                'opponent_mgw_id' => $opponentMgwId,
            ]
        );
    }

    private function recordParticipation(
        DatabaseConnectionInterface $database,
        string $matchId,
        string $seasonId,
        string $gameType,
        array $participants,
        ?string $winnerMgwId,
        int $pointsDelta,
        string $ratingDay,
        ?string $startedAt,
        ?string $finishedAt
    ): void {
        foreach ($participants as $index => $participant) {
            if (!is_array($participant)) continue;
            $mgwId = trim((string)($participant['mgw_id'] ?? ''));
            $opponent = $participants[1 - $index] ?? null;
            $opponentMgwId = is_array($opponent) ? trim((string)($opponent['mgw_id'] ?? '')) : '';
            if ($mgwId === '' || $opponentMgwId === '') continue;

            $resultCode = $winnerMgwId === null
                ? 'draw'
                : ($mgwId === $winnerMgwId ? 'win' : 'loss');
            $pointsAwarded = $mgwId === $winnerMgwId ? max(0, $pointsDelta) : 0;

            $columns = '(match_id, mgw_id, season_id, game_type, opponent_mgw_id,
                         result_code, points_awarded, rating_day_moscow,
                         match_started_at_utc, match_finished_at_utc, created_at_utc)';
            $values = '(:match_id, :mgw_id, :season_id, :game_type, :opponent_mgw_id,
                        :result_code, :points_awarded, :rating_day_moscow,
                        :match_started_at_utc, :match_finished_at_utc, :created_at_utc)';
            $sql = $database->driver() === 'sqlite'
                ? 'INSERT OR IGNORE INTO mgw_game_rating_participation ' . $columns . ' VALUES ' . $values
                : 'INSERT IGNORE INTO mgw_game_rating_participation ' . $columns . ' VALUES ' . $values;
            $database->execute($sql, [
                'match_id' => $matchId,
                'mgw_id' => $mgwId,
                'season_id' => $seasonId,
                'game_type' => $gameType,
                'opponent_mgw_id' => $opponentMgwId,
                'result_code' => $resultCode,
                'points_awarded' => $pointsAwarded,
                'rating_day_moscow' => $ratingDay,
                'match_started_at_utc' => $startedAt,
                'match_finished_at_utc' => $finishedAt,
                'created_at_utc' => $this->timestamp(),
            ]);
        }
    }

    private function insertOutcomeIfNew(DatabaseConnectionInterface $database, array $row): bool
    {
        $columns = '(match_id, season_id, game_type, competition_state, winner_mgw_id,
                     opponent_mgw_id, points_delta, points_requested, anti_farming_limited,
                     outcome_code, match_source, finish_reason, rating_day_moscow,
                     finished_at_utc, processed_at_utc)';
        $values = '(:match_id, :season_id, :game_type, :competition_state, :winner_mgw_id,
                    :opponent_mgw_id, :points_delta, :points_requested, :anti_farming_limited,
                    :outcome_code, :match_source, :finish_reason, :rating_day_moscow,
                    :finished_at_utc, :processed_at_utc)';

        $sql = $database->driver() === 'sqlite'
            ? 'INSERT OR IGNORE INTO mgw_game_rating_outcomes ' . $columns . ' VALUES ' . $values
            : 'INSERT IGNORE INTO mgw_game_rating_outcomes ' . $columns . ' VALUES ' . $values;

        return $database->execute($sql, $row) === 1;
    }

    private function incrementScore(
        DatabaseConnectionInterface $database,
        string $seasonId,
        string $mgwId,
        string $gameType,
        int $pointsDelta
    ): void {
        $timestamp = $this->timestamp();
        $parameters = [
            'season_id' => $seasonId,
            'mgw_id' => $mgwId,
            'game_type' => $gameType,
            'points' => $pointsDelta,
            'rated_wins' => 1,
            'updated_at' => $timestamp,
        ];

        if ($database->driver() === 'sqlite') {
            $database->execute(
                'INSERT INTO mgw_game_rating_scores (
                    season_id, mgw_id, game_type, points, rated_wins, updated_at_utc
                 ) VALUES (
                    :season_id, :mgw_id, :game_type, :points, :rated_wins, :updated_at
                 )
                 ON CONFLICT(season_id, mgw_id, game_type) DO UPDATE SET
                    points = points + excluded.points,
                    rated_wins = rated_wins + excluded.rated_wins,
                    updated_at_utc = excluded.updated_at_utc',
                $parameters
            );
            return;
        }

        $database->execute(
            'INSERT INTO mgw_game_rating_scores (
                season_id, mgw_id, game_type, points, rated_wins, updated_at_utc
             ) VALUES (
                :season_id, :mgw_id, :game_type, :points, :rated_wins, :updated_at
             )
             ON DUPLICATE KEY UPDATE
                points = points + :add_points,
                rated_wins = rated_wins + :add_wins,
                updated_at_utc = :updated_at_again',
            $parameters + [
                'add_points' => $pointsDelta,
                'add_wins' => 1,
                'updated_at_again' => $timestamp,
            ]
        );
    }

    private function leaderboardControl(): array
    {
        $rows = $this->database->fetchAll(
            'SELECT anti_farming_started_at_utc, day_timezone,
                    min_rated_matches, min_human_wins,
                    max_credited_wins_same_opponent_day
             FROM mgw_leaderboard_control
             WHERE control_key = :control_key',
            ['control_key' => self::CONTROL_KEY]
        );
        if (count($rows) !== 1 || !is_array($rows[0])) {
            throw new RuntimeException('MVP-20.3 leaderboard control is unavailable.');
        }

        $row = $rows[0];
        $startedAt = trim((string)($row['anti_farming_started_at_utc'] ?? ''));
        $timezone = trim((string)($row['day_timezone'] ?? ''));
        $maxWins = max(1, (int)($row['max_credited_wins_same_opponent_day'] ?? 3));
        if ($startedAt === '' || $timezone === '') {
            throw new RuntimeException('MVP-20.3 leaderboard control is invalid.');
        }

        try {
            new DateTimeZone($timezone);
        } catch (Exception $error) {
            throw new RuntimeException('MVP-20.3 leaderboard timezone is invalid.', 0, $error);
        }

        return [
            'anti_farming_started_at' => $startedAt,
            'day_timezone' => $timezone,
            'min_rated_matches' => max(1, (int)($row['min_rated_matches'] ?? 5)),
            'min_human_wins' => max(1, (int)($row['min_human_wins'] ?? 1)),
            'max_credited_wins_same_opponent_day' => $maxWins,
        ];
    }

    private function control(): array
    {
        $rows = $this->database->fetchAll(
            'SELECT competition_state, current_season_id, tracking_started_at_utc,
                    activated_at_utc, updated_at_utc
             FROM mgw_rating_control
             WHERE control_key = :control_key',
            ['control_key' => self::CONTROL_KEY]
        );
        if (count($rows) !== 1 || !is_array($rows[0])) {
            throw new RuntimeException('MVP-20.1 rating control is unavailable.');
        }

        $row = $rows[0];
        $state = strtolower(trim((string)($row['competition_state'] ?? '')));
        if (!in_array($state, [self::STATE_OFF, self::STATE_PRESEASON, self::STATE_ACTIVE], true)) {
            throw new RuntimeException('MVP-20.1 competition state is invalid.');
        }

        $seasonId = trim((string)($row['current_season_id'] ?? ''));
        $trackingStartedAt = trim((string)($row['tracking_started_at_utc'] ?? ''));
        $activatedAt = $this->nullableText($row['activated_at_utc'] ?? null);
        if ($seasonId === '' || $trackingStartedAt === '') {
            throw new RuntimeException('MVP-20.1 rating control is incomplete.');
        }
        if ($state === self::STATE_ACTIVE && $activatedAt === null) {
            throw new RuntimeException('ACTIVE rating state requires an activation boundary.');
        }

        return [
            'competition_state' => $state,
            'current_season_id' => $seasonId,
            'tracking_started_at' => $trackingStartedAt,
            'activated_at' => $activatedAt,
        ];
    }

    private function seasonAssignment(?string $finishedAt, array $control): array
    {
        if (class_exists('SeasonAssignmentResolver')) {
            return (new SeasonAssignmentResolver($this->database))->resolve($finishedAt, $control);
        }

        $effectiveState = $control['competition_state'];
        $seasonId = $control['current_season_id'];
        if ($effectiveState === self::STATE_ACTIVE
            && $control['activated_at'] !== null
            && $finishedAt !== null
            && $this->before($finishedAt, $control['activated_at'])) {
            $effectiveState = self::STATE_PRESEASON;
            $seasonId = self::PRESEASON_ID;
        }
        return ['competition_state' => $effectiveState, 'season_id' => $seasonId];
    }

    private function antiFarmingApplies(?string $matchTime, string $startedAt): bool
    {
        if ($matchTime === null || trim($matchTime) === '') return false;
        $matchTs = strtotime($matchTime);
        $startTs = strtotime($startedAt);
        if ($matchTs === false || $startTs === false) {
            throw new RuntimeException('MVP-20.3 anti-farming timestamp is invalid.');
        }
        return $matchTs >= $startTs;
    }

    private function ratingDay(string $utcTimestamp, string $timezone): string
    {
        $utc = new DateTimeZone('UTC');
        $target = new DateTimeZone($timezone);
        $date = new DateTimeImmutable($utcTimestamp, $utc);
        return $date->setTimezone($target)->format('Y-m-d');
    }

    private function before(string $left, string $right): bool
    {
        $leftTs = strtotime($left);
        $rightTs = strtotime($right);
        if ($leftTs === false || $rightTs === false) {
            throw new RuntimeException('MVP-20.1 rating timestamp is invalid.');
        }
        return $leftTs < $rightTs;
    }

    private function nullableText(mixed $value): ?string
    {
        $value = trim((string)($value ?? ''));
        return $value === '' ? null : $value;
    }

    private function timestamp(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');
    }
}
