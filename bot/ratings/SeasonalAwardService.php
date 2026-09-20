<?php
declare(strict_types=1);

/**
 * MVP-20.5 canonical seasonal award owner.
 *
 * Awards are durable season/game entitlements, deliberately separate from the
 * permanent Store inventory/equip tables. That keeps temporary top-3 frames
 * expirable and allows audited fraud corrections to revoke/reissue places.
 */
final class SeasonalAwardService
{
    public const AWARD_ACTIVE = 'active';
    public const AWARD_REVOKED = 'revoked';

    public const BADGE_GOLD = 'gold';
    public const BADGE_SILVER = 'silver';
    public const BADGE_BRONZE = 'bronze';

    private const MAX_REWARDED_RANK = 100;
    private const MAX_FRAME_RANK = 3;

    public function __construct(
        private DatabaseConnectionInterface $database,
        private ?LeaderboardService $leaderboard = null
    ) {
        $this->leaderboard ??= new LeaderboardService($database);
    }

    public function finalizeSeason(
        string $seasonId,
        string $targetSeasonId,
        string $reason = 'season_close',
        string $actorRef = 'system:season_finalization',
        ?DateTimeImmutable $now = null
    ): array {
        $now = $this->utcNow($now);
        $season = $this->season($seasonId);
        $target = $this->season($targetSeasonId);
        $this->assertRewardPackageReady($targetSeasonId);

        if ($seasonId === PerGameRatingService::PRESEASON_ID) {
            throw new RuntimeException('PRESEASON can never issue official seasonal awards.');
        }

        $pendingProjection = $this->pendingProjectionCount($season);
        if ($pendingProjection > 0) {
            return [
                'status' => 'projection_pending',
                'season_id' => $seasonId,
                'target_season_id' => $targetSeasonId,
                'pending_projection_count' => $pendingProjection,
                'games' => [],
            ];
        }

        $games = [];
        $changes = ['granted'=>0,'reissued'=>0,'revoked'=>0];
        foreach (LeaderboardService::gameTypes() as $gameType) {
            $result = $this->reconcileGameAwards(
                $seasonId,
                $gameType,
                $targetSeasonId,
                [],
                $reason,
                $actorRef,
                $now
            );
            $games[$gameType] = $result;
            foreach ($changes as $key => $value) {
                $changes[$key] += (int)($result[$key] ?? 0);
            }
        }

        return [
            'status' => 'completed',
            'season_id' => $seasonId,
            'target_season_id' => $targetSeasonId,
            'pending_projection_count' => 0,
            'games' => $games,
        ] + $changes;
    }

    /**
     * Rebuilds one game's rewarded top 100 from the canonical leaderboard.
     *
     * MVP-20.8 can later pass reviewed fraud exclusions here. The desired board
     * is reconciled against existing awards, so shifted places revoke/reissue
     * deterministically with an append-only audit trail.
     */
    public function reconcileGameAwards(
        string $seasonId,
        string $gameType,
        string $targetSeasonId,
        array $excludedMgwIds = [],
        string $reason = 'season_close',
        string $actorRef = 'system:season_finalization',
        ?DateTimeImmutable $now = null
    ): array {
        $now = $this->utcNow($now);
        $seasonId = $this->normalizeSeasonId($seasonId);
        $targetSeasonId = $this->normalizeSeasonId($targetSeasonId);
        $gameType = strtolower(trim($gameType));
        if (!in_array($gameType, LeaderboardService::gameTypes(), true)) {
            throw new InvalidArgumentException('Unsupported seasonal-award game type.');
        }
        if ($seasonId === PerGameRatingService::PRESEASON_ID) {
            throw new RuntimeException('PRESEASON can never issue official seasonal awards.');
        }

        $season = $this->season($seasonId);
        $target = $this->season($targetSeasonId);
        $this->assertRewardPackageReady($targetSeasonId);
        $reason = $this->normalizeToken($reason, 64, 'seasonal award reason');
        $actorRef = $this->normalizeText($actorRef, 191, 'seasonal award actor');

        $pendingProjection = $this->pendingProjectionCount($season);
        if ($pendingProjection > 0) {
            return [
                'status' => 'projection_pending',
                'season_id' => $seasonId,
                'game_type' => $gameType,
                'target_season_id' => $targetSeasonId,
                'pending_projection_count' => $pendingProjection,
                'revision' => null,
                'granted' => 0,
                'reissued' => 0,
                'revoked' => 0,
                'active_awards' => 0,
            ];
        }

        $standings = $this->leaderboard->standingsForSeason(
            $seasonId,
            $gameType,
            self::MAX_REWARDED_RANK,
            $excludedMgwIds
        );
        $desired = $this->desiredAwards($standings, $target);
        $fingerprint = hash(
            'sha256',
            json_encode(array_values($desired), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)
        );

        return $this->database->transaction(function (DatabaseConnectionInterface $database) use (
            $seasonId,
            $gameType,
            $targetSeasonId,
            $desired,
            $fingerprint,
            $reason,
            $actorRef,
            $now
        ): array {
            $run = $this->awardRun($database, $seasonId, $gameType, true);
            if ($run !== null
                && (string)$run['target_season_id'] === $targetSeasonId
                && (string)$run['standings_fingerprint'] === $fingerprint) {
                return [
                    'status' => 'unchanged',
                    'season_id' => $seasonId,
                    'game_type' => $gameType,
                    'target_season_id' => $targetSeasonId,
                    'pending_projection_count' => 0,
                    'revision' => (int)$run['revision'],
                    'granted' => 0,
                    'reissued' => 0,
                    'revoked' => 0,
                    'active_awards' => count($desired),
                ];
            }

            $revision = max(0, (int)($run['revision'] ?? 0)) + 1;
            $nowText = $now->format('Y-m-d H:i:s.u');
            $currentRows = $database->fetchAll(
                'SELECT season_id, game_type, mgw_id, rank_position, badge_tier,
                        frame_place, frame_target_season_id,
                        frame_valid_from_at_utc, frame_valid_until_at_utc,
                        award_state, revision, granted_at_utc, revoked_at_utc, updated_at_utc
                 FROM mgw_season_awards
                 WHERE season_id = :season_id AND game_type = :game_type',
                ['season_id' => $seasonId, 'game_type' => $gameType]
            );
            $current = [];
            foreach ($currentRows as $row) {
                if (!is_array($row)) continue;
                $mgwId = trim((string)($row['mgw_id'] ?? ''));
                if ($mgwId !== '') $current[$mgwId] = $row;
            }

            $granted = 0;
            $reissued = 0;
            $revoked = 0;

            foreach ($current as $mgwId => $row) {
                if ((string)($row['award_state'] ?? '') !== self::AWARD_ACTIVE) continue;
                if (isset($desired[$mgwId])) continue;

                $before = $this->awardSnapshot($row);
                $database->execute(
                    'UPDATE mgw_season_awards
                     SET award_state = :state,
                         revision = :revision,
                         revoked_at_utc = :revoked_at,
                         updated_at_utc = :updated_at
                     WHERE season_id = :season_id
                       AND game_type = :game_type
                       AND mgw_id = :mgw_id',
                    [
                        'state' => self::AWARD_REVOKED,
                        'revision' => $revision,
                        'revoked_at' => $nowText,
                        'updated_at' => $nowText,
                        'season_id' => $seasonId,
                        'game_type' => $gameType,
                        'mgw_id' => $mgwId,
                    ]
                );
                $after = $before;
                $after['award_state'] = self::AWARD_REVOKED;
                $after['revision'] = $revision;
                $after['revoked_at_utc'] = $nowText;
                $this->audit($database, $seasonId, $gameType, $mgwId, 'revoke', $reason, $actorRef, $revision, $before, $after, $nowText);
                $revoked++;
            }

            foreach ($desired as $mgwId => $award) {
                $row = $current[$mgwId] ?? null;
                if ($row === null) {
                    $database->execute(
                        'INSERT INTO mgw_season_awards (
                            season_id, game_type, mgw_id, rank_position, badge_tier,
                            frame_place, frame_target_season_id,
                            frame_valid_from_at_utc, frame_valid_until_at_utc,
                            award_state, revision, granted_at_utc, revoked_at_utc, updated_at_utc
                         ) VALUES (
                            :season_id, :game_type, :mgw_id, :rank_position, :badge_tier,
                            :frame_place, :frame_target_season_id,
                            :frame_valid_from_at_utc, :frame_valid_until_at_utc,
                            :award_state, :revision, :granted_at_utc, NULL, :updated_at_utc
                         )',
                        $award + [
                            'award_state' => self::AWARD_ACTIVE,
                            'revision' => $revision,
                            'granted_at_utc' => $nowText,
                            'updated_at_utc' => $nowText,
                        ]
                    );
                    $after = $award + [
                        'award_state' => self::AWARD_ACTIVE,
                        'revision' => $revision,
                        'granted_at_utc' => $nowText,
                        'revoked_at_utc' => null,
                    ];
                    $this->audit($database, $seasonId, $gameType, $mgwId, 'grant', $reason, $actorRef, $revision, null, $after, $nowText);
                    $granted++;
                    continue;
                }

                if ($this->awardMatches($row, $award)) continue;

                $before = $this->awardSnapshot($row);
                $grantedAt = trim((string)($row['granted_at_utc'] ?? ''));
                if ($grantedAt === '' || (string)($row['award_state'] ?? '') !== self::AWARD_ACTIVE) {
                    $grantedAt = $nowText;
                }
                $database->execute(
                    'UPDATE mgw_season_awards
                     SET rank_position = :rank_position,
                         badge_tier = :badge_tier,
                         frame_place = :frame_place,
                         frame_target_season_id = :frame_target_season_id,
                         frame_valid_from_at_utc = :frame_valid_from_at_utc,
                         frame_valid_until_at_utc = :frame_valid_until_at_utc,
                         award_state = :award_state,
                         revision = :revision,
                         granted_at_utc = :granted_at_utc,
                         revoked_at_utc = NULL,
                         updated_at_utc = :updated_at_utc
                     WHERE season_id = :season_id
                       AND game_type = :game_type
                       AND mgw_id = :mgw_id',
                    $award + [
                        'award_state' => self::AWARD_ACTIVE,
                        'revision' => $revision,
                        'granted_at_utc' => $grantedAt,
                        'updated_at_utc' => $nowText,
                    ]
                );
                $after = $award + [
                    'award_state' => self::AWARD_ACTIVE,
                    'revision' => $revision,
                    'granted_at_utc' => $grantedAt,
                    'revoked_at_utc' => null,
                ];
                $this->audit($database, $seasonId, $gameType, $mgwId, 'reissue', $reason, $actorRef, $revision, $before, $after, $nowText);
                $reissued++;
            }

            $this->writeAwardRun(
                $database,
                $seasonId,
                $gameType,
                $targetSeasonId,
                $revision,
                $fingerprint,
                $reason,
                $actorRef,
                $nowText,
                $run === null
            );

            return [
                'status' => 'reconciled',
                'season_id' => $seasonId,
                'game_type' => $gameType,
                'target_season_id' => $targetSeasonId,
                'pending_projection_count' => 0,
                'revision' => $revision,
                'granted' => $granted,
                'reissued' => $reissued,
                'revoked' => $revoked,
                'active_awards' => count($desired),
            ];
        });
    }

    public function userAwards(string $mgwId, ?DateTimeImmutable $now = null): array
    {
        $mgwId = trim($mgwId);
        if ($mgwId === '' || strlen($mgwId) > 24) {
            throw new InvalidArgumentException('Seasonal-award user identity is invalid.');
        }
        $nowText = $this->utcNow($now)->format('Y-m-d H:i:s.u');
        $rows = $this->database->fetchAll(
            'SELECT season_id, game_type, rank_position, badge_tier,
                    frame_place, frame_target_season_id,
                    frame_valid_from_at_utc, frame_valid_until_at_utc,
                    award_state, revision, granted_at_utc, revoked_at_utc
             FROM mgw_season_awards
             WHERE mgw_id = :mgw_id
             ORDER BY season_id DESC, game_type ASC',
            ['mgw_id' => $mgwId]
        );

        $awards = [];
        $activeFrames = [];
        foreach ($rows as $row) {
            if (!is_array($row)) continue;
            $snapshot = $this->awardSnapshot($row);
            $frameActive = $snapshot['award_state'] === self::AWARD_ACTIVE
                && $snapshot['frame_place'] !== null
                && is_string($snapshot['frame_valid_from_at_utc'])
                && is_string($snapshot['frame_valid_until_at_utc'])
                && strcmp($nowText, $snapshot['frame_valid_from_at_utc']) >= 0
                && strcmp($nowText, $snapshot['frame_valid_until_at_utc']) < 0;
            $snapshot['frame_active'] = $frameActive;
            $awards[] = $snapshot;
            if ($frameActive) $activeFrames[] = $snapshot;
        }

        usort($activeFrames, static function (array $left, array $right): int {
            $place = ((int)$left['frame_place']) <=> ((int)$right['frame_place']);
            if ($place !== 0) return $place;
            $season = strcmp((string)$right['season_id'], (string)$left['season_id']);
            return $season !== 0 ? $season : strcmp((string)$left['game_type'], (string)$right['game_type']);
        });

        return [
            'mgw_id' => $mgwId,
            'awards' => $awards,
            'active_frame_entitlements' => $activeFrames,
            'best_active_frame' => $activeFrames[0] ?? null,
        ];
    }

    private function desiredAwards(array $standings, array $targetSeason): array
    {
        $desired = [];
        foreach ($standings as $row) {
            if (!is_array($row)) continue;
            $rank = (int)($row['rank'] ?? 0);
            $mgwId = trim((string)($row['mgw_id'] ?? ''));
            if ($rank < 1 || $rank > self::MAX_REWARDED_RANK || $mgwId === '') continue;

            $framePlace = $rank <= self::MAX_FRAME_RANK ? $rank : null;
            $desired[$mgwId] = [
                'season_id' => trim((string)($row['season_id'] ?? '')),
                'game_type' => trim((string)($row['game_type'] ?? '')),
                'mgw_id' => $mgwId,
                'rank_position' => $rank,
                'badge_tier' => $this->badgeTierForRank($rank),
                'frame_place' => $framePlace,
                'frame_target_season_id' => $framePlace !== null ? (string)$targetSeason['season_id'] : null,
                'frame_valid_from_at_utc' => $framePlace !== null ? (string)$targetSeason['official_start_at_utc'] : null,
                'frame_valid_until_at_utc' => $framePlace !== null ? (string)$targetSeason['calendar_end_at_utc'] : null,
            ];
        }

        // Leaderboard rows intentionally do not duplicate scope metadata.
        // Fill it exactly once here so the durable award rows are self-contained.
        foreach ($desired as &$award) {
            if ($award['season_id'] === '') $award['season_id'] = '__scope__';
            if ($award['game_type'] === '') $award['game_type'] = '__scope__';
        }
        unset($award);
        return $desired;
    }

    private function badgeTierForRank(int $rank): string
    {
        if ($rank === 1) return self::BADGE_GOLD;
        if ($rank <= 10) return self::BADGE_SILVER;
        return self::BADGE_BRONZE;
    }

    private function awardMatches(array $current, array $desired): bool
    {
        return (string)($current['award_state'] ?? '') === self::AWARD_ACTIVE
            && (int)($current['rank_position'] ?? 0) === (int)$desired['rank_position']
            && (string)($current['badge_tier'] ?? '') === (string)$desired['badge_tier']
            && $this->nullableInt($current['frame_place'] ?? null) === $this->nullableInt($desired['frame_place'])
            && $this->nullableText($current['frame_target_season_id'] ?? null) === $this->nullableText($desired['frame_target_season_id'])
            && $this->nullableText($current['frame_valid_from_at_utc'] ?? null) === $this->nullableText($desired['frame_valid_from_at_utc'])
            && $this->nullableText($current['frame_valid_until_at_utc'] ?? null) === $this->nullableText($desired['frame_valid_until_at_utc']);
    }

    private function awardSnapshot(array $row): array
    {
        return [
            'season_id' => (string)($row['season_id'] ?? ''),
            'game_type' => (string)($row['game_type'] ?? ''),
            'mgw_id' => (string)($row['mgw_id'] ?? ''),
            'rank_position' => max(0, (int)($row['rank_position'] ?? 0)),
            'badge_tier' => (string)($row['badge_tier'] ?? ''),
            'frame_place' => $this->nullableInt($row['frame_place'] ?? null),
            'frame_target_season_id' => $this->nullableText($row['frame_target_season_id'] ?? null),
            'frame_valid_from_at_utc' => $this->nullableText($row['frame_valid_from_at_utc'] ?? null),
            'frame_valid_until_at_utc' => $this->nullableText($row['frame_valid_until_at_utc'] ?? null),
            'award_state' => (string)($row['award_state'] ?? ''),
            'revision' => max(0, (int)($row['revision'] ?? 0)),
            'granted_at_utc' => $this->nullableText($row['granted_at_utc'] ?? null),
            'revoked_at_utc' => $this->nullableText($row['revoked_at_utc'] ?? null),
        ];
    }

    private function audit(
        DatabaseConnectionInterface $database,
        string $seasonId,
        string $gameType,
        string $mgwId,
        string $action,
        string $reason,
        string $actorRef,
        int $revision,
        ?array $before,
        ?array $after,
        string $now
    ): void {
        $database->execute(
            'INSERT INTO mgw_season_award_audit (
                season_id, game_type, mgw_id, action_code, reason_code,
                actor_ref, revision, before_json, after_json, created_at_utc
             ) VALUES (
                :season_id, :game_type, :mgw_id, :action_code, :reason_code,
                :actor_ref, :revision, :before_json, :after_json, :created_at_utc
             )',
            [
                'season_id' => $seasonId,
                'game_type' => $gameType,
                'mgw_id' => $mgwId,
                'action_code' => $action,
                'reason_code' => $reason,
                'actor_ref' => $actorRef,
                'revision' => $revision,
                'before_json' => $before === null ? null : json_encode($before, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                'after_json' => $after === null ? null : json_encode($after, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                'created_at_utc' => $now,
            ]
        );
    }

    private function awardRun(
        DatabaseConnectionInterface $database,
        string $seasonId,
        string $gameType,
        bool $forUpdate
    ): ?array {
        $sql = 'SELECT season_id, game_type, target_season_id, revision,
                       standings_fingerprint, last_reason, last_actor_ref,
                       reconciled_at_utc, created_at_utc, updated_at_utc
                FROM mgw_season_award_runs
                WHERE season_id = :season_id AND game_type = :game_type';
        if ($forUpdate && $database->driver() !== 'sqlite') $sql .= ' FOR UPDATE';
        $rows = $database->fetchAll($sql, ['season_id'=>$seasonId,'game_type'=>$gameType]);
        if ($rows === []) return null;
        return is_array($rows[0]) ? $rows[0] : null;
    }

    private function writeAwardRun(
        DatabaseConnectionInterface $database,
        string $seasonId,
        string $gameType,
        string $targetSeasonId,
        int $revision,
        string $fingerprint,
        string $reason,
        string $actorRef,
        string $now,
        bool $insert
    ): void {
        if ($insert) {
            $database->execute(
                'INSERT INTO mgw_season_award_runs (
                    season_id, game_type, target_season_id, revision,
                    standings_fingerprint, last_reason, last_actor_ref,
                    reconciled_at_utc, created_at_utc, updated_at_utc
                 ) VALUES (
                    :season_id, :game_type, :target_season_id, :revision,
                    :standings_fingerprint, :last_reason, :last_actor_ref,
                    :reconciled_at_utc, :created_at_utc, :updated_at_utc
                 )',
                [
                    'season_id'=>$seasonId,
                    'game_type'=>$gameType,
                    'target_season_id'=>$targetSeasonId,
                    'revision'=>$revision,
                    'standings_fingerprint'=>$fingerprint,
                    'last_reason'=>$reason,
                    'last_actor_ref'=>$actorRef,
                    'reconciled_at_utc'=>$now,
                    'created_at_utc'=>$now,
                    'updated_at_utc'=>$now,
                ]
            );
            return;
        }

        $database->execute(
            'UPDATE mgw_season_award_runs
             SET target_season_id = :target_season_id,
                 revision = :revision,
                 standings_fingerprint = :standings_fingerprint,
                 last_reason = :last_reason,
                 last_actor_ref = :last_actor_ref,
                 reconciled_at_utc = :reconciled_at_utc,
                 updated_at_utc = :updated_at_utc
             WHERE season_id = :season_id AND game_type = :game_type',
            [
                'target_season_id'=>$targetSeasonId,
                'revision'=>$revision,
                'standings_fingerprint'=>$fingerprint,
                'last_reason'=>$reason,
                'last_actor_ref'=>$actorRef,
                'reconciled_at_utc'=>$now,
                'updated_at_utc'=>$now,
                'season_id'=>$seasonId,
                'game_type'=>$gameType,
            ]
        );
    }

    private function season(string $seasonId): array
    {
        $seasonId = $this->normalizeSeasonId($seasonId);
        if ($seasonId === PerGameRatingService::PRESEASON_ID) {
            throw new RuntimeException('PRESEASON has no official season row.');
        }

        $rows = $this->database->fetchAll(
            'SELECT season_id, calendar_year, quarter, timezone,
                    calendar_start_at_utc, calendar_end_at_utc, official_start_at_utc,
                    season_state, finalization_reason, standings_frozen_at_utc,
                    finalization_started_at_utc, finalized_at_utc
             FROM mgw_rating_seasons
             WHERE season_id = :season_id',
            ['season_id' => $seasonId]
        );
        if (count($rows) !== 1 || !is_array($rows[0])) {
            throw new RuntimeException('Official rating season is unavailable.');
        }
        return $rows[0];
    }

    private function assertRewardPackageReady(string $targetSeasonId): void
    {
        $rows = $this->database->fetchAll(
            'SELECT package_state
             FROM mgw_season_reward_packages
             WHERE target_season_id = :target_season_id',
            ['target_season_id' => $targetSeasonId]
        );
        if (count($rows) !== 1 || !is_array($rows[0])
            || strtolower(trim((string)($rows[0]['package_state'] ?? ''))) !== SeasonLifecycleService::PACKAGE_READY) {
            throw new RuntimeException('Season reward package is not READY.');
        }
    }

    private function pendingProjectionCount(array $season): int
    {
        return max(0, (int)$this->database->fetchValue(
            'SELECT COUNT(*)
             FROM mgw_matches m
             WHERE m.status = :status
               AND m.finished_at_utc IS NOT NULL
               AND m.finished_at_utc >= :official_start
               AND m.finished_at_utc < :calendar_end
               AND NOT EXISTS (
                   SELECT 1
                   FROM mgw_game_rating_outcomes r
                   WHERE r.match_id = m.match_id
               )',
            [
                'status' => 'finished',
                'official_start' => (string)$season['official_start_at_utc'],
                'calendar_end' => (string)$season['calendar_end_at_utc'],
            ]
        ));
    }

    private function normalizeSeasonId(string $seasonId): string
    {
        $seasonId = strtolower(trim($seasonId));
        if ($seasonId === PerGameRatingService::PRESEASON_ID) return $seasonId;
        if (preg_match('/^\d{4}-q[1-4]$/', $seasonId) !== 1) {
            throw new InvalidArgumentException('Seasonal award season id is invalid.');
        }
        return $seasonId;
    }

    private function normalizeToken(string $value, int $maxLength, string $label): string
    {
        $value = strtolower(trim($value));
        if ($value === '' || strlen($value) > $maxLength
            || preg_match('/^[a-z0-9][a-z0-9_.:-]*$/', $value) !== 1) {
            throw new InvalidArgumentException($label . ' is invalid.');
        }
        return $value;
    }

    private function normalizeText(string $value, int $maxLength, string $label): string
    {
        $value = trim($value);
        $length = function_exists('mb_strlen') ? mb_strlen($value) : strlen($value);
        if ($value === '' || $length > $maxLength) {
            throw new InvalidArgumentException($label . ' is invalid.');
        }
        return $value;
    }

    private function nullableText(mixed $value): ?string
    {
        $value = trim((string)($value ?? ''));
        return $value === '' ? null : $value;
    }

    private function nullableInt(mixed $value): ?int
    {
        if ($value === null || $value === '') return null;
        return (int)$value;
    }

    private function utcNow(?DateTimeImmutable $now): DateTimeImmutable
    {
        return ($now ?? new DateTimeImmutable('now', new DateTimeZone('UTC')))
            ->setTimezone(new DateTimeZone('UTC'));
    }
}
