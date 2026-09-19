<?php
declare(strict_types=1);

/**
 * MVP-20.2 authoritative hidden-skill owner.
 *
 * Product-visible rating stays in PerGameRatingService. This model is private,
 * per game, and is consumed only by server matchmaking.
 */
final class HiddenSkillService
{
    public const MODEL_VERSION = 'elo-v1';
    public const BASE_SKILL = 1500;
    public const MIN_SKILL = 400;
    public const MAX_SKILL = 2600;
    public const K_FACTOR = 32;
    public const ELO_SCALE = 400;
    public const BAND_WIDTH = 100;

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
        $model = $this->modelControl();
        $competition = $this->competitionControl();

        $matches = $this->database->fetchAll(
            'SELECT m.match_id, m.game_type, m.status, m.winner_player_ref,
                    m.finish_reason, m.finished_at_utc
             FROM mgw_matches m
             WHERE m.status = :status
               AND m.finished_at_utc IS NOT NULL
               AND m.finished_at_utc >= :tracking_started_at
               AND NOT EXISTS (
                   SELECT 1 FROM mgw_hidden_skill_outcomes h WHERE h.match_id = m.match_id
               )
             ORDER BY m.finished_at_utc ASC, m.match_id ASC
             LIMIT ' . $limit,
            [
                'status' => 'finished',
                'tracking_started_at' => $model['tracking_started_at'],
            ]
        );

        $summary = [
            'scanned' => count($matches),
            'modeled' => 0,
            'ignored' => 0,
            'duplicates' => 0,
        ];

        foreach ($matches as $match) {
            if (!is_array($match)) continue;
            $status = $this->processMatch($match, $competition, $model);
            if ($status === 'modeled') $summary['modeled']++;
            elseif ($status === 'duplicate') $summary['duplicates']++;
            else $summary['ignored']++;
        }

        return $summary + [
            'model_version' => $model['model_version'],
            'competition_state' => $competition['competition_state'],
            'season_id' => $competition['current_season_id'],
        ];
    }

    public function skillBandForUser(string $mgwId, string $gameType): string
    {
        $mgwId = trim($mgwId);
        $gameType = strtolower(trim($gameType));
        if ($mgwId === '' || !in_array($gameType, self::GAME_TYPES, true)) {
            return MatchmakingQueue::DEFAULT_SKILL_BAND;
        }

        $competition = $this->competitionControl();
        if ($competition['competition_state'] === PerGameRatingService::STATE_OFF) {
            return MatchmakingQueue::DEFAULT_SKILL_BAND;
        }

        $model = $this->modelControl();
        $score = $this->database->transaction(function (DatabaseConnectionInterface $database) use (
            $mgwId,
            $gameType,
            $competition,
            $model
        ): int {
            $row = $this->scoreRowForUpdate(
                $database,
                $mgwId,
                $gameType,
                $competition['current_season_id'],
                $model['soft_carry_basis_points']
            );
            return (int)$row['skill_score'];
        });

        return $this->bandForScore($score);
    }

    public function bandForScore(int $score): string
    {
        $score = $this->clampSkill($score);
        $band = intdiv($score + intdiv(self::BAND_WIDTH, 2), self::BAND_WIDTH);
        return 'band:' . $band;
    }

    public function softAdjustScore(int $score, int $carryBasisPoints): int
    {
        $carryBasisPoints = max(0, min(10000, $carryBasisPoints));
        $distance = $this->clampSkill($score) - self::BASE_SKILL;
        $adjusted = self::BASE_SKILL + (int)round($distance * ($carryBasisPoints / 10000));
        return $this->clampSkill($adjusted);
    }

    private function processMatch(array $match, array $competition, array $model): string
    {
        $matchId = trim((string)($match['match_id'] ?? ''));
        $gameType = strtolower(trim((string)($match['game_type'] ?? '')));
        $finishReason = $this->nullableText($match['finish_reason'] ?? null);
        $winnerPlayerRef = $this->nullableText($match['winner_player_ref'] ?? null);

        if ($matchId === '') return 'ignored';

        if (!in_array($gameType, self::GAME_TYPES, true)) {
            return $this->recordIgnored($matchId, $gameType !== '' ? $gameType : 'unknown', $competition, $finishReason, 'unsupported_game');
        }

        if ($competition['competition_state'] === PerGameRatingService::STATE_OFF) {
            return $this->recordIgnored($matchId, $gameType, $competition, $finishReason, 'competition_off');
        }

        $players = $this->database->fetchAll(
            'SELECT seat, player_ref, mgw_id, player_type
             FROM mgw_match_players
             WHERE match_id = :match_id
             ORDER BY seat ASC',
            ['match_id' => $matchId]
        );

        if (count($players) !== 2 || !is_array($players[0]) || !is_array($players[1])) {
            return $this->recordIgnored($matchId, $gameType, $competition, $finishReason, 'invalid_players');
        }

        foreach ($players as $player) {
            if (strtolower(trim((string)($player['player_type'] ?? 'human'))) !== 'human') {
                return $this->recordIgnored($matchId, $gameType, $competition, $finishReason, 'bot_game');
            }
        }

        $playerA = $this->playerIdentity($players[0]);
        $playerB = $this->playerIdentity($players[1]);
        if ($playerA['mgw_id'] === null || $playerB['mgw_id'] === null || $playerA['mgw_id'] === $playerB['mgw_id']) {
            // Identity projection may still catch up. Do not permanently discard
            // a legitimate human result until both canonical owners are known.
            return 'ignored';
        }

        $actualA = null;
        $resultCode = null;
        if ($finishReason === 'draw') {
            $actualA = 0.5;
            $resultCode = 'hidden_draw';
        } elseif ($finishReason === 'normal_win' && $winnerPlayerRef !== null) {
            if ($winnerPlayerRef === $playerA['player_ref']) {
                $actualA = 1.0;
                $resultCode = 'hidden_normal_win';
            } elseif ($winnerPlayerRef === $playerB['player_ref']) {
                $actualA = 0.0;
                $resultCode = 'hidden_normal_win';
            }
        }

        if ($actualA === null || $resultCode === null) {
            return $this->recordIgnored($matchId, $gameType, $competition, $finishReason, 'technical_result');
        }

        return $this->database->transaction(function (DatabaseConnectionInterface $database) use (
            $matchId,
            $gameType,
            $competition,
            $model,
            $finishReason,
            $playerA,
            $playerB,
            $actualA,
            $resultCode
        ): string {
            $seasonId = $competition['current_season_id'];
            $a = $this->scoreRowForUpdate(
                $database,
                $playerA['mgw_id'],
                $gameType,
                $seasonId,
                $model['soft_carry_basis_points']
            );
            $b = $this->scoreRowForUpdate(
                $database,
                $playerB['mgw_id'],
                $gameType,
                $seasonId,
                $model['soft_carry_basis_points']
            );

            $aBefore = (int)$a['skill_score'];
            $bBefore = (int)$b['skill_score'];
            $expectedA = 1.0 / (1.0 + pow(10.0, ($bBefore - $aBefore) / self::ELO_SCALE));
            $deltaA = (int)round(self::K_FACTOR * ($actualA - $expectedA));
            $deltaB = -$deltaA;
            $aAfter = $this->clampSkill($aBefore + $deltaA);
            $bAfter = $this->clampSkill($bBefore + $deltaB);
            $deltaA = $aAfter - $aBefore;
            $deltaB = $bAfter - $bBefore;

            $inserted = $this->insertOutcomeIfNew($database, [
                'match_id' => $matchId,
                'game_type' => $gameType,
                'season_id' => $seasonId,
                'player_a_mgw_id' => $playerA['mgw_id'],
                'player_b_mgw_id' => $playerB['mgw_id'],
                'player_a_before' => $aBefore,
                'player_b_before' => $bBefore,
                'player_a_after' => $aAfter,
                'player_b_after' => $bAfter,
                'player_a_delta' => $deltaA,
                'player_b_delta' => $deltaB,
                'skill_gap_before' => abs($aBefore - $bBefore),
                'outcome_code' => $resultCode,
                'finish_reason' => $finishReason,
                'processed_at_utc' => $this->timestamp(),
            ]);
            if (!$inserted) return 'duplicate';

            $this->updateScoreRow($database, $a, $aAfter, $actualA);
            $this->updateScoreRow($database, $b, $bAfter, 1.0 - $actualA);
            return 'modeled';
        });
    }

    private function recordIgnored(
        string $matchId,
        string $gameType,
        array $competition,
        ?string $finishReason,
        string $code
    ): string {
        $inserted = $this->database->transaction(function (DatabaseConnectionInterface $database) use (
            $matchId,
            $gameType,
            $competition,
            $finishReason,
            $code
        ): bool {
            return $this->insertOutcomeIfNew($database, [
                'match_id' => $matchId,
                'game_type' => $gameType,
                'season_id' => $competition['current_season_id'],
                'player_a_mgw_id' => null,
                'player_b_mgw_id' => null,
                'player_a_before' => null,
                'player_b_before' => null,
                'player_a_after' => null,
                'player_b_after' => null,
                'player_a_delta' => null,
                'player_b_delta' => null,
                'skill_gap_before' => null,
                'outcome_code' => $code,
                'finish_reason' => $finishReason,
                'processed_at_utc' => $this->timestamp(),
            ]);
        });
        return $inserted ? 'ignored' : 'duplicate';
    }

    private function scoreRowForUpdate(
        DatabaseConnectionInterface $database,
        string $mgwId,
        string $gameType,
        string $seasonId,
        int $softCarryBasisPoints
    ): array {
        $timestamp = $this->timestamp();
        $params = [
            'mgw_id' => $mgwId,
            'game_type' => $gameType,
            'skill_score' => self::BASE_SKILL,
            'season_id' => $seasonId,
            'updated_at' => $timestamp,
        ];

        if ($database->driver() === 'sqlite') {
            $database->execute(
                'INSERT OR IGNORE INTO mgw_hidden_skill_scores (
                    mgw_id, game_type, skill_score, current_season_id,
                    matches_played, wins, losses, draws, season_matches, updated_at_utc
                 ) VALUES (
                    :mgw_id, :game_type, :skill_score, :season_id,
                    0, 0, 0, 0, 0, :updated_at
                 )',
                $params
            );
        } else {
            $database->execute(
                'INSERT IGNORE INTO mgw_hidden_skill_scores (
                    mgw_id, game_type, skill_score, current_season_id,
                    matches_played, wins, losses, draws, season_matches, updated_at_utc
                 ) VALUES (
                    :mgw_id, :game_type, :skill_score, :season_id,
                    0, 0, 0, 0, 0, :updated_at
                 )',
                $params
            );
        }

        $select = 'SELECT mgw_id, game_type, skill_score, current_season_id,
                          matches_played, wins, losses, draws, season_matches, updated_at_utc
                   FROM mgw_hidden_skill_scores
                   WHERE mgw_id = :mgw_id AND game_type = :game_type';
        if ($database->driver() !== 'sqlite') $select .= ' FOR UPDATE';

        $rows = $database->fetchAll($select, ['mgw_id' => $mgwId, 'game_type' => $gameType]);
        if (count($rows) !== 1 || !is_array($rows[0])) {
            throw new RuntimeException('MVP-20.2 hidden skill row is unavailable.');
        }

        $row = $rows[0];
        if (trim((string)($row['current_season_id'] ?? '')) !== $seasonId) {
            $adjusted = $this->softAdjustScore((int)($row['skill_score'] ?? self::BASE_SKILL), $softCarryBasisPoints);
            $database->execute(
                'UPDATE mgw_hidden_skill_scores
                 SET skill_score = :skill_score,
                     current_season_id = :season_id,
                     season_matches = 0,
                     updated_at_utc = :updated_at
                 WHERE mgw_id = :mgw_id AND game_type = :game_type',
                [
                    'skill_score' => $adjusted,
                    'season_id' => $seasonId,
                    'updated_at' => $timestamp,
                    'mgw_id' => $mgwId,
                    'game_type' => $gameType,
                ]
            );
            $row['skill_score'] = $adjusted;
            $row['current_season_id'] = $seasonId;
            $row['season_matches'] = 0;
            $row['updated_at_utc'] = $timestamp;
        }

        return $row;
    }

    private function updateScoreRow(
        DatabaseConnectionInterface $database,
        array $row,
        int $newScore,
        float $actual
    ): void {
        $wins = (int)($row['wins'] ?? 0);
        $losses = (int)($row['losses'] ?? 0);
        $draws = (int)($row['draws'] ?? 0);
        if ($actual === 1.0) $wins++;
        elseif ($actual === 0.0) $losses++;
        else $draws++;

        $database->execute(
            'UPDATE mgw_hidden_skill_scores
             SET skill_score = :skill_score,
                 matches_played = :matches_played,
                 wins = :wins,
                 losses = :losses,
                 draws = :draws,
                 season_matches = :season_matches,
                 updated_at_utc = :updated_at
             WHERE mgw_id = :mgw_id AND game_type = :game_type',
            [
                'skill_score' => $newScore,
                'matches_played' => (int)($row['matches_played'] ?? 0) + 1,
                'wins' => $wins,
                'losses' => $losses,
                'draws' => $draws,
                'season_matches' => (int)($row['season_matches'] ?? 0) + 1,
                'updated_at' => $this->timestamp(),
                'mgw_id' => (string)$row['mgw_id'],
                'game_type' => (string)$row['game_type'],
            ]
        );
    }

    private function insertOutcomeIfNew(DatabaseConnectionInterface $database, array $row): bool
    {
        $columns = '(match_id, game_type, season_id, player_a_mgw_id, player_b_mgw_id,
                     player_a_before, player_b_before, player_a_after, player_b_after,
                     player_a_delta, player_b_delta, skill_gap_before,
                     outcome_code, finish_reason, processed_at_utc)';
        $values = '(:match_id, :game_type, :season_id, :player_a_mgw_id, :player_b_mgw_id,
                    :player_a_before, :player_b_before, :player_a_after, :player_b_after,
                    :player_a_delta, :player_b_delta, :skill_gap_before,
                    :outcome_code, :finish_reason, :processed_at_utc)';
        $sql = $database->driver() === 'sqlite'
            ? 'INSERT OR IGNORE INTO mgw_hidden_skill_outcomes ' . $columns . ' VALUES ' . $values
            : 'INSERT IGNORE INTO mgw_hidden_skill_outcomes ' . $columns . ' VALUES ' . $values;
        return $database->execute($sql, $row) === 1;
    }

    private function modelControl(): array
    {
        $rows = $this->database->fetchAll(
            'SELECT model_version, tracking_started_at_utc, soft_carry_basis_points
             FROM mgw_hidden_skill_control WHERE control_key = :control_key',
            ['control_key' => self::CONTROL_KEY]
        );
        if (count($rows) !== 1 || !is_array($rows[0])) {
            throw new RuntimeException('MVP-20.2 hidden skill control is unavailable.');
        }
        $row = $rows[0];
        $version = trim((string)($row['model_version'] ?? ''));
        $tracking = trim((string)($row['tracking_started_at_utc'] ?? ''));
        if ($version !== self::MODEL_VERSION || $tracking === '') {
            throw new RuntimeException('MVP-20.2 hidden skill control is invalid.');
        }
        return [
            'model_version' => $version,
            'tracking_started_at' => $tracking,
            'soft_carry_basis_points' => max(0, min(10000, (int)($row['soft_carry_basis_points'] ?? 7500))),
        ];
    }

    private function competitionControl(): array
    {
        $rows = $this->database->fetchAll(
            'SELECT competition_state, current_season_id
             FROM mgw_rating_control WHERE control_key = :control_key',
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
        return ['competition_state' => $state, 'current_season_id' => $seasonId];
    }

    private function playerIdentity(array $row): array
    {
        $mgwId = $this->nullableText($row['mgw_id'] ?? null);
        $playerRef = $this->nullableText($row['player_ref'] ?? null);
        return ['mgw_id' => $mgwId, 'player_ref' => $playerRef];
    }

    private function clampSkill(int $score): int
    {
        return max(self::MIN_SKILL, min(self::MAX_SKILL, $score));
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
