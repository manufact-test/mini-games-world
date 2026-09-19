<?php
declare(strict_types=1);

return new class implements DatabaseMigrationInterface {
    public function version(): string
    {
        return '20260919_0044_create_leaderboards_and_antifarming';
    }

    public function description(): string
    {
        return 'Create MVP-20.3 leaderboard participation, anti-farming counters and rating audit fields.';
    }

    public function transactional(): bool
    {
        return false;
    }

    public function up(DatabaseConnectionInterface $database): void
    {
        $this->ensureOutcomeColumns($database);

        if ($database->driver() === 'sqlite') {
            $this->upSqlite($database);
        } else {
            $this->upMysql($database);
        }

        $this->backfillParticipation($database);
    }

    private function upMysql(DatabaseConnectionInterface $database): void
    {
        $database->execute(<<<'SQL'
CREATE TABLE IF NOT EXISTS mgw_leaderboard_control (
    control_key VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY,
    anti_farming_started_at_utc DATETIME(6) NOT NULL,
    day_timezone VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    min_rated_matches SMALLINT UNSIGNED NOT NULL DEFAULT 5,
    min_human_wins SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    max_credited_wins_same_opponent_day SMALLINT UNSIGNED NOT NULL DEFAULT 3,
    updated_at_utc DATETIME(6) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        $database->execute(<<<'SQL'
INSERT IGNORE INTO mgw_leaderboard_control (
    control_key, anti_farming_started_at_utc, day_timezone,
    min_rated_matches, min_human_wins,
    max_credited_wins_same_opponent_day, updated_at_utc
) VALUES (
    'global', UTC_TIMESTAMP(6), 'Europe/Moscow',
    5, 1, 3, UTC_TIMESTAMP(6)
)
SQL);

        $database->execute(<<<'SQL'
CREATE TABLE IF NOT EXISTS mgw_game_rating_participation (
    match_id VARCHAR(96) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    mgw_id VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    season_id VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    game_type VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    opponent_mgw_id VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    result_code VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    points_awarded SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    rating_day_moscow DATE NOT NULL,
    match_started_at_utc DATETIME(6) NULL,
    match_finished_at_utc DATETIME(6) NULL,
    created_at_utc DATETIME(6) NOT NULL,
    PRIMARY KEY (match_id, mgw_id),
    INDEX idx_mgw_rating_participation_board (season_id, game_type, mgw_id, result_code),
    INDEX idx_mgw_rating_participation_pair_day (game_type, mgw_id, opponent_mgw_id, rating_day_moscow, result_code),
    INDEX idx_mgw_rating_participation_user (mgw_id, season_id, game_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        $database->execute(<<<'SQL'
CREATE TABLE IF NOT EXISTS mgw_rating_daily_pair_wins (
    rating_day_moscow DATE NOT NULL,
    game_type VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    winner_mgw_id VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    opponent_mgw_id VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    credited_wins SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    updated_at_utc DATETIME(6) NOT NULL,
    PRIMARY KEY (rating_day_moscow, game_type, winner_mgw_id, opponent_mgw_id),
    INDEX idx_mgw_rating_daily_pair_wins_winner (winner_mgw_id, rating_day_moscow, game_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
    }

    private function upSqlite(DatabaseConnectionInterface $database): void
    {
        $database->execute(<<<'SQL'
CREATE TABLE IF NOT EXISTS mgw_leaderboard_control (
    control_key TEXT NOT NULL PRIMARY KEY,
    anti_farming_started_at_utc TEXT NOT NULL,
    day_timezone TEXT NOT NULL,
    min_rated_matches INTEGER NOT NULL DEFAULT 5,
    min_human_wins INTEGER NOT NULL DEFAULT 1,
    max_credited_wins_same_opponent_day INTEGER NOT NULL DEFAULT 3,
    updated_at_utc TEXT NOT NULL
)
SQL);

        $database->execute(<<<'SQL'
INSERT OR IGNORE INTO mgw_leaderboard_control (
    control_key, anti_farming_started_at_utc, day_timezone,
    min_rated_matches, min_human_wins,
    max_credited_wins_same_opponent_day, updated_at_utc
) VALUES (
    'global',
    strftime('%Y-%m-%d %H:%M:%f', 'now'),
    'Europe/Moscow',
    5, 1, 3,
    strftime('%Y-%m-%d %H:%M:%f', 'now')
)
SQL);

        $database->execute(<<<'SQL'
CREATE TABLE IF NOT EXISTS mgw_game_rating_participation (
    match_id TEXT NOT NULL,
    mgw_id TEXT NOT NULL,
    season_id TEXT NOT NULL,
    game_type TEXT NOT NULL,
    opponent_mgw_id TEXT NOT NULL,
    result_code TEXT NOT NULL,
    points_awarded INTEGER NOT NULL DEFAULT 0,
    rating_day_moscow TEXT NOT NULL,
    match_started_at_utc TEXT NULL,
    match_finished_at_utc TEXT NULL,
    created_at_utc TEXT NOT NULL,
    PRIMARY KEY (match_id, mgw_id)
)
SQL);
        $database->execute('CREATE INDEX IF NOT EXISTS idx_mgw_rating_participation_board ON mgw_game_rating_participation (season_id, game_type, mgw_id, result_code)');
        $database->execute('CREATE INDEX IF NOT EXISTS idx_mgw_rating_participation_pair_day ON mgw_game_rating_participation (game_type, mgw_id, opponent_mgw_id, rating_day_moscow, result_code)');
        $database->execute('CREATE INDEX IF NOT EXISTS idx_mgw_rating_participation_user ON mgw_game_rating_participation (mgw_id, season_id, game_type)');

        $database->execute(<<<'SQL'
CREATE TABLE IF NOT EXISTS mgw_rating_daily_pair_wins (
    rating_day_moscow TEXT NOT NULL,
    game_type TEXT NOT NULL,
    winner_mgw_id TEXT NOT NULL,
    opponent_mgw_id TEXT NOT NULL,
    credited_wins INTEGER NOT NULL DEFAULT 0,
    updated_at_utc TEXT NOT NULL,
    PRIMARY KEY (rating_day_moscow, game_type, winner_mgw_id, opponent_mgw_id)
)
SQL);
        $database->execute('CREATE INDEX IF NOT EXISTS idx_mgw_rating_daily_pair_wins_winner ON mgw_rating_daily_pair_wins (winner_mgw_id, rating_day_moscow, game_type)');
    }

    private function ensureOutcomeColumns(DatabaseConnectionInterface $database): void
    {
        $columns = [
            'opponent_mgw_id' => $database->driver() === 'sqlite'
                ? 'TEXT NULL'
                : 'VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NULL',
            'rating_day_moscow' => $database->driver() === 'sqlite'
                ? 'TEXT NULL'
                : 'DATE NULL',
            'points_requested' => $database->driver() === 'sqlite'
                ? 'INTEGER NOT NULL DEFAULT 0'
                : 'SMALLINT UNSIGNED NOT NULL DEFAULT 0',
            'anti_farming_limited' => $database->driver() === 'sqlite'
                ? 'INTEGER NOT NULL DEFAULT 0'
                : 'TINYINT(1) NOT NULL DEFAULT 0',
        ];

        foreach ($columns as $column => $definition) {
            if ($this->columnExists($database, 'mgw_game_rating_outcomes', $column)) continue;
            $database->execute(
                'ALTER TABLE mgw_game_rating_outcomes ADD COLUMN ' . $column . ' ' . $definition
            );
        }

        if ($database->driver() === 'sqlite') {
            $database->execute(
                'CREATE INDEX IF NOT EXISTS idx_mgw_game_rating_outcomes_pair_day
                 ON mgw_game_rating_outcomes (
                    winner_mgw_id, opponent_mgw_id, rating_day_moscow, game_type
                 )'
            );
            return;
        }

        if (!$this->indexExists($database, 'mgw_game_rating_outcomes', 'idx_mgw_game_rating_outcomes_pair_day')) {
            $database->execute(
                'ALTER TABLE mgw_game_rating_outcomes
                 ADD INDEX idx_mgw_game_rating_outcomes_pair_day (
                    winner_mgw_id, opponent_mgw_id, rating_day_moscow, game_type
                 )'
            );
        }
    }

    private function backfillParticipation(DatabaseConnectionInterface $database): void
    {
        $rows = $database->fetchAll(
            "SELECT r.match_id, r.season_id, r.game_type, r.winner_mgw_id,
                    r.points_delta, r.outcome_code, r.finished_at_utc,
                    m.started_at_utc
             FROM mgw_game_rating_outcomes r
             INNER JOIN mgw_matches m ON m.match_id = r.match_id
             WHERE r.outcome_code IN ('rated_normal_win', 'rated_tournament_win', 'draw')"
        );

        foreach ($rows as $row) {
            if (!is_array($row)) continue;
            $matchId = trim((string)($row['match_id'] ?? ''));
            $seasonId = trim((string)($row['season_id'] ?? ''));
            $gameType = trim((string)($row['game_type'] ?? ''));
            if ($matchId === '' || $seasonId === '' || $gameType === '') continue;

            $players = $database->fetchAll(
                "SELECT mgw_id, player_type
                 FROM mgw_match_players
                 WHERE match_id = :match_id
                 ORDER BY seat ASC",
                ['match_id' => $matchId]
            );
            if (count($players) !== 2) continue;

            $ids = [];
            foreach ($players as $player) {
                if (!is_array($player)) continue 2;
                if (strtolower(trim((string)($player['player_type'] ?? 'human'))) !== 'human') continue 2;
                $mgwId = trim((string)($player['mgw_id'] ?? ''));
                if ($mgwId === '') continue 2;
                $ids[] = $mgwId;
            }
            if (count(array_unique($ids)) !== 2) continue;

            $winner = trim((string)($row['winner_mgw_id'] ?? ''));
            $isDraw = trim((string)($row['outcome_code'] ?? '')) === 'draw';
            if (!$isDraw && !in_array($winner, $ids, true)) continue;

            $startedAt = $this->nullableText($row['started_at_utc'] ?? null);
            $finishedAt = $this->nullableText($row['finished_at_utc'] ?? null);
            $daySource = $startedAt ?? $finishedAt;
            if ($daySource === null) continue;
            $ratingDay = $this->moscowDay($daySource);
            $createdAt = $this->timestamp();

            foreach ($ids as $index => $mgwId) {
                $opponent = $ids[1 - $index];
                $resultCode = $isDraw ? 'draw' : ($mgwId === $winner ? 'win' : 'loss');
                $pointsAwarded = $mgwId === $winner ? max(0, (int)($row['points_delta'] ?? 0)) : 0;
                $this->insertParticipationIfNew($database, [
                    'match_id' => $matchId,
                    'mgw_id' => $mgwId,
                    'season_id' => $seasonId,
                    'game_type' => $gameType,
                    'opponent_mgw_id' => $opponent,
                    'result_code' => $resultCode,
                    'points_awarded' => $pointsAwarded,
                    'rating_day_moscow' => $ratingDay,
                    'match_started_at_utc' => $startedAt,
                    'match_finished_at_utc' => $finishedAt,
                    'created_at_utc' => $createdAt,
                ]);
            }

            if (!$isDraw) {
                $opponent = $ids[0] === $winner ? $ids[1] : $ids[0];
                $database->execute(
                    "UPDATE mgw_game_rating_outcomes
                     SET opponent_mgw_id = COALESCE(opponent_mgw_id, :opponent_mgw_id),
                         rating_day_moscow = COALESCE(rating_day_moscow, :rating_day_moscow),
                         points_requested = CASE
                            WHEN points_requested = 0 THEN points_delta
                            ELSE points_requested
                         END
                     WHERE match_id = :match_id",
                    [
                        'opponent_mgw_id' => $opponent,
                        'rating_day_moscow' => $ratingDay,
                        'match_id' => $matchId,
                    ]
                );
            }
        }
    }

    private function insertParticipationIfNew(DatabaseConnectionInterface $database, array $row): void
    {
        $columns = '(match_id, mgw_id, season_id, game_type, opponent_mgw_id,
                     result_code, points_awarded, rating_day_moscow,
                     match_started_at_utc, match_finished_at_utc, created_at_utc)';
        $values = '(:match_id, :mgw_id, :season_id, :game_type, :opponent_mgw_id,
                    :result_code, :points_awarded, :rating_day_moscow,
                    :match_started_at_utc, :match_finished_at_utc, :created_at_utc)';
        $sql = $database->driver() === 'sqlite'
            ? 'INSERT OR IGNORE INTO mgw_game_rating_participation ' . $columns . ' VALUES ' . $values
            : 'INSERT IGNORE INTO mgw_game_rating_participation ' . $columns . ' VALUES ' . $values;
        $database->execute($sql, $row);
    }

    private function columnExists(
        DatabaseConnectionInterface $database,
        string $table,
        string $column
    ): bool {
        if ($database->driver() === 'sqlite') {
            foreach ($database->fetchAll('PRAGMA table_info(' . $table . ')') as $row) {
                if (is_array($row) && (string)($row['name'] ?? '') === $column) return true;
            }
            return false;
        }

        return (int)$database->fetchValue(
            'SELECT COUNT(*)
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = :table_name
               AND COLUMN_NAME = :column_name',
            ['table_name' => $table, 'column_name' => $column]
        ) > 0;
    }

    private function indexExists(
        DatabaseConnectionInterface $database,
        string $table,
        string $index
    ): bool {
        if ($database->driver() === 'sqlite') {
            foreach ($database->fetchAll('PRAGMA index_list(' . $table . ')') as $row) {
                if (is_array($row) && (string)($row['name'] ?? '') === $index) return true;
            }
            return false;
        }

        return (int)$database->fetchValue(
            'SELECT COUNT(*)
             FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = :table_name
               AND INDEX_NAME = :index_name',
            ['table_name' => $table, 'index_name' => $index]
        ) > 0;
    }

    private function moscowDay(string $utcTimestamp): string
    {
        $utc = new DateTimeZone('UTC');
        $moscow = new DateTimeZone('Europe/Moscow');
        $date = new DateTimeImmutable($utcTimestamp, $utc);
        return $date->setTimezone($moscow)->format('Y-m-d');
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
};
