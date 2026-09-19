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

        $matches = $this->database->fetchAll(
            'SELECT m.match_id, m.game_type, m.status, m.match_source, m.winner_player_ref,
                    m.finish_reason, m.finished_at_utc
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
            'duplicates' => 0,
            'deferred' => 0,
        ];

        foreach ($matches as $match) {
            if (!is_array($match)) continue;
            $result = $this->processMatch($match, $control);
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

    private function processMatch(array $match, array $control): array
    {
        $matchId = trim((string)($match['match_id'] ?? ''));
        if ($matchId === '') return ['status' => 'deferred', 'points_delta' => 0];

        $gameType = trim((string)($match['game_type'] ?? ''));
        $matchSource = $this->nullableText($match['match_source'] ?? null);
        $finishReason = $this->nullableText($match['finish_reason'] ?? null);
        $finishedAt = $this->nullableText($match['finished_at_utc'] ?? null);
        $winnerPlayerRef = $this->nullableText($match['winner_player_ref'] ?? null);

        $effectiveState = $control['competition_state'];
        $seasonId = $control['current_season_id'];

        if ($effectiveState === self::STATE_ACTIVE
            && $control['activated_at'] !== null
            && $finishedAt !== null
            && $this->before($finishedAt, $control['activated_at'])) {
            // A late projector may see a pre-activation result only after the
            // official switch. Keep it in the preseason bucket so it can never
            // become a retroactive official-season award.
            $effectiveState = self::STATE_PRESEASON;
            $seasonId = self::PRESEASON_ID;
        }

        $players = $this->database->fetchAll(
            'SELECT player_ref, mgw_id, player_type
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

        $pointsDelta = (int)$decision['points_delta'];
        $winnerMgwId = $decision['winner_mgw_id'];

        return $this->database->transaction(function (DatabaseConnectionInterface $database) use (
            $matchId,
            $seasonId,
            $gameType,
            $effectiveState,
            $winnerMgwId,
            $pointsDelta,
            $decision,
            $matchSource,
            $finishReason,
            $finishedAt
        ): array {
            $inserted = $this->insertOutcomeIfNew($database, [
                'match_id' => $matchId,
                'season_id' => $seasonId,
                'game_type' => $gameType !== '' ? $gameType : 'unknown',
                'competition_state' => $effectiveState,
                'winner_mgw_id' => $winnerMgwId,
                'points_delta' => $pointsDelta,
                'outcome_code' => $decision['outcome_code'],
                'match_source' => $matchSource,
                'finish_reason' => $finishReason,
                'finished_at_utc' => $finishedAt,
                'processed_at_utc' => $this->timestamp(),
            ]);
            if (!$inserted) {
                return ['status' => 'duplicate', 'points_delta' => 0];
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

            return ['status' => 'recorded', 'points_delta' => $pointsDelta];
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
        if ($winnerPlayerRef === null || $finishReason === 'draw') {
            return $this->finalDecision(0, 'draw');
        }
        if ($finishReason !== 'normal_win') {
            return $this->finalDecision(0, 'technical_result');
        }
        if (count($players) < 2) {
            return $this->finalDecision(0, 'invalid_players');
        }

        $winnerMgwId = null;
        foreach ($players as $player) {
            if (!is_array($player)) return $this->finalDecision(0, 'invalid_players');
            if (strtolower(trim((string)($player['player_type'] ?? 'human'))) !== 'human') {
                return $this->finalDecision(0, 'bot_game');
            }
            if (trim((string)($player['player_ref'] ?? '')) === $winnerPlayerRef) {
                $candidate = trim((string)($player['mgw_id'] ?? ''));
                if ($candidate !== '') $winnerMgwId = $candidate;
            }
        }

        // A normal human win without a canonical account identity is not a
        // zero-point result: identity projection may still catch up. Leave it
        // pending instead of permanently discarding a legitimate point.
        if ($winnerMgwId === null) {
            return [
                'final' => false,
                'points_delta' => 0,
                'outcome_code' => 'pending_winner_identity',
                'winner_mgw_id' => null,
            ];
        }

        $isTournament = $matchSource === self::TOURNAMENT_MATCH_SOURCE;
        return [
            'final' => true,
            'points_delta' => $isTournament ? 2 : 1,
            'outcome_code' => $isTournament ? 'rated_tournament_win' : 'rated_normal_win',
            'winner_mgw_id' => $winnerMgwId,
        ];
    }

    private function finalDecision(int $points, string $code): array
    {
        return [
            'final' => true,
            'points_delta' => $points,
            'outcome_code' => $code,
            'winner_mgw_id' => null,
        ];
    }

    private function insertOutcomeIfNew(DatabaseConnectionInterface $database, array $row): bool
    {
        $columns = '(match_id, season_id, game_type, competition_state, winner_mgw_id,
                     points_delta, outcome_code, match_source, finish_reason,
                     finished_at_utc, processed_at_utc)';
        $values = '(:match_id, :season_id, :game_type, :competition_state, :winner_mgw_id,
                    :points_delta, :outcome_code, :match_source, :finish_reason,
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
