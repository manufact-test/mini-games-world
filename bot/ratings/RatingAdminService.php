<?php
declare(strict_types=1);

/**
 * MVP-20.8 operational owner for reviewed rating exclusions, metrics,
 * deterministic post-review recalculation and season-close rehearsal.
 *
 * It never rewrites match history, visible rating scores or hidden skill.
 * Award corrections delegate to the accepted MVP-20.5 / MVP-20.6 owners.
 */
final class RatingAdminService
{
    public const EXCLUSION_ACTIVE = 'active';
    public const EXCLUSION_REVOKED = 'revoked';

    public const JOB_RUNNING = 'running';
    public const JOB_COMPLETED = 'completed';
    public const JOB_FAILED = 'failed';

    private const CONTROL_KEY = 'global';
    private const REASON_CODES = [
        'fraud',
        'automation',
        'duplicate_identity',
        'match_manipulation',
        'manual_review',
    ];

    public function __construct(
        private DatabaseConnectionInterface $database,
        private ?LeaderboardService $leaderboard = null
    ) {
        $this->leaderboard ??= new LeaderboardService($database);
    }

    public function snapshot(): array
    {
        $control = $this->competitionControl();
        $seasonId = $control['current_season_id'];
        $season = $seasonId === PerGameRatingService::PRESEASON_ID
            ? null
            : $this->seasonOrNull($seasonId);

        return [
            'competition' => $control,
            'current_season' => $season === null ? null : $this->publicSeason($season),
            'metrics' => $season === null ? $this->emptyMetrics($seasonId) : $this->seasonMetrics($seasonId),
            'active_exclusions' => $this->activeExclusions($seasonId === PerGameRatingService::PRESEASON_ID ? null : $seasonId),
            'recent_jobs' => $this->recentJobs(20),
            'reason_codes' => self::REASON_CODES,
        ];
    }

    public function setExclusion(
        string $seasonId,
        string $mgwId,
        string $reasonCode,
        string $reviewNote,
        string $actorRef,
        ?DateTimeImmutable $now = null
    ): array {
        $season = $this->season($seasonId);
        $mgwId = $this->normalizeMgwId($mgwId);
        $reasonCode = $this->normalizeReasonCode($reasonCode);
        $reviewNote = $this->normalizeText($reviewNote, 500, 'review note');
        $actorRef = $this->normalizeText($actorRef, 191, 'actor');
        $nowText = $this->utcNow($now)->format('Y-m-d H:i:s.u');

        $this->assertReviewableUser($mgwId);

        $this->database->transaction(function (DatabaseConnectionInterface $database) use (
            $season,
            $mgwId,
            $reasonCode,
            $reviewNote,
            $actorRef,
            $nowText
        ): void {
            $params = [
                'season_id'=>(string)$season['season_id'],
                'mgw_id'=>$mgwId,
                'state'=>self::EXCLUSION_ACTIVE,
                'reason_code'=>$reasonCode,
                'review_note'=>$reviewNote,
                'actor_ref'=>$actorRef,
                'created_at'=>$nowText,
                'updated_at'=>$nowText,
            ];
            if ($database->driver() === 'sqlite') {
                $database->execute(
                    'INSERT INTO mgw_rating_review_exclusions (
                        season_id, mgw_id, exclusion_state, reason_code, review_note,
                        actor_ref, created_at_utc, updated_at_utc, revoked_at_utc
                     ) VALUES (
                        :season_id, :mgw_id, :state, :reason_code, :review_note,
                        :actor_ref, :created_at, :updated_at, NULL
                     )
                     ON CONFLICT(season_id, mgw_id) DO UPDATE SET
                        exclusion_state=excluded.exclusion_state,
                        reason_code=excluded.reason_code,
                        review_note=excluded.review_note,
                        actor_ref=excluded.actor_ref,
                        updated_at_utc=excluded.updated_at_utc,
                        revoked_at_utc=NULL',
                    $params
                );
            } else {
                $database->execute(
                    'INSERT INTO mgw_rating_review_exclusions (
                        season_id, mgw_id, exclusion_state, reason_code, review_note,
                        actor_ref, created_at_utc, updated_at_utc, revoked_at_utc
                     ) VALUES (
                        :season_id, :mgw_id, :state, :reason_code, :review_note,
                        :actor_ref, :created_at, :updated_at, NULL
                     )
                     ON DUPLICATE KEY UPDATE
                        exclusion_state=VALUES(exclusion_state),
                        reason_code=VALUES(reason_code),
                        review_note=VALUES(review_note),
                        actor_ref=VALUES(actor_ref),
                        updated_at_utc=VALUES(updated_at_utc),
                        revoked_at_utc=NULL',
                    $params
                );
            }
            $this->auditReview(
                $database,
                (string)$season['season_id'],
                $mgwId,
                'exclude',
                $reasonCode,
                $reviewNote,
                $actorRef,
                $nowText
            );
        });

        return $this->exclusion((string)$season['season_id'], $mgwId);
    }

    public function revokeExclusion(
        string $seasonId,
        string $mgwId,
        string $reviewNote,
        string $actorRef,
        ?DateTimeImmutable $now = null
    ): array {
        $season = $this->season($seasonId);
        $mgwId = $this->normalizeMgwId($mgwId);
        $reviewNote = $this->normalizeText($reviewNote, 500, 'review note');
        $actorRef = $this->normalizeText($actorRef, 191, 'actor');
        $current = $this->exclusion((string)$season['season_id'], $mgwId);
        if ($current['exclusion_state'] !== self::EXCLUSION_ACTIVE) {
            return $current;
        }

        $nowText = $this->utcNow($now)->format('Y-m-d H:i:s.u');
        $this->database->transaction(function (DatabaseConnectionInterface $database) use (
            $season,
            $mgwId,
            $current,
            $reviewNote,
            $actorRef,
            $nowText
        ): void {
            $database->execute(
                'UPDATE mgw_rating_review_exclusions
                 SET exclusion_state=:state,
                     review_note=:review_note,
                     actor_ref=:actor_ref,
                     revoked_at_utc=:revoked_at,
                     updated_at_utc=:updated_at
                 WHERE season_id=:season_id AND mgw_id=:mgw_id',
                [
                    'state'=>self::EXCLUSION_REVOKED,
                    'review_note'=>$reviewNote,
                    'actor_ref'=>$actorRef,
                    'revoked_at'=>$nowText,
                    'updated_at'=>$nowText,
                    'season_id'=>(string)$season['season_id'],
                    'mgw_id'=>$mgwId,
                ]
            );
            $this->auditReview(
                $database,
                (string)$season['season_id'],
                $mgwId,
                'restore',
                (string)$current['reason_code'],
                $reviewNote,
                $actorRef,
                $nowText
            );
        });

        return $this->exclusion((string)$season['season_id'], $mgwId);
    }

    public function recalculateSeason(
        string $seasonId,
        string $reason,
        string $actorRef,
        ?DateTimeImmutable $now = null
    ): array {
        $season = $this->season($seasonId);
        $state = (string)$season['season_state'];
        if (!in_array($state, [SeasonLifecycleService::SEASON_FINALIZING, SeasonLifecycleService::SEASON_CLOSED], true)) {
            throw new RuntimeException('Rating recalculation is allowed only for FINALIZING or CLOSED official seasons.');
        }

        $reason = $this->normalizeText($reason, 500, 'recalculation reason');
        $actorRef = $this->normalizeText($actorRef, 191, 'actor');
        $now = $this->utcNow($now);
        $nowText = $now->format('Y-m-d H:i:s.u');
        $targetSeasonId = $this->targetSeasonId($season);
        $excluded = $this->activeExcludedIds((string)$season['season_id']);
        $jobId = 'radm_' . bin2hex(random_bytes(12));

        $this->database->execute(
            'INSERT INTO mgw_rating_recalculation_jobs (
                job_id, job_type, season_id, job_state, actor_ref, reason_text,
                exclusions_json, result_json, error_text,
                created_at_utc, started_at_utc, completed_at_utc, updated_at_utc
             ) VALUES (
                :job_id, :job_type, :season_id, :job_state, :actor_ref, :reason_text,
                :exclusions_json, NULL, NULL,
                :created_at, :started_at, NULL, :updated_at
             )',
            [
                'job_id'=>$jobId,
                'job_type'=>'season_awards',
                'season_id'=>(string)$season['season_id'],
                'job_state'=>self::JOB_RUNNING,
                'actor_ref'=>$actorRef,
                'reason_text'=>$reason,
                'exclusions_json'=>json_encode($excluded, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                'created_at'=>$nowText,
                'started_at'=>$nowText,
                'updated_at'=>$nowText,
            ]
        );

        try {
            $games = [];
            $totals = ['granted'=>0,'reissued'=>0,'revoked'=>0];
            $awards = new SeasonalAwardService($this->database, $this->leaderboard);
            foreach (LeaderboardService::gameTypes() as $gameType) {
                $result = $awards->reconcileGameAwards(
                    (string)$season['season_id'],
                    $gameType,
                    $targetSeasonId,
                    $excluded,
                    'admin_review',
                    $actorRef,
                    $now
                );
                $games[$gameType] = $result;
                foreach ($totals as $key => $_) $totals[$key] += (int)($result[$key] ?? 0);
            }

            $medal = (new YearlyMedalService($this->database))->reconcileSeasonFragment(
                (string)$season['season_id'],
                $excluded,
                'admin_review',
                $actorRef,
                $now
            );
            $result = [
                'job_id'=>$jobId,
                'season_id'=>(string)$season['season_id'],
                'target_season_id'=>$targetSeasonId,
                'excluded_mgw_ids'=>$excluded,
                'games'=>$games,
                'yearly_medal'=>$medal,
            ] + $totals;

            $this->finishJob($jobId, self::JOB_COMPLETED, $result, null, $nowText);
            return $result;
        } catch (Throwable $error) {
            $this->finishJob($jobId, self::JOB_FAILED, null, $error->getMessage(), $nowText);
            throw $error;
        }
    }

    public function seasonCloseRehearsal(string $seasonId): array
    {
        $season = $this->season($seasonId);
        $seasonId = (string)$season['season_id'];
        $targetSeasonId = $this->nextSeasonId((int)$season['calendar_year'], (int)$season['quarter']);
        $excluded = $this->activeExcludedIds($seasonId);
        $target = $this->seasonOrNull($targetSeasonId);
        $package = $this->rewardPackageOrNull($targetSeasonId);
        $pendingProjection = $this->pendingProjectionCount($season);
        $bot = $this->botExclusionMetrics($seasonId);

        $games = [];
        foreach (LeaderboardService::gameTypes() as $gameType) {
            $rows = $this->leaderboard->standingsForSeason(
                $seasonId,
                $gameType,
                LeaderboardService::MAX_LIMIT,
                $excluded
            );
            $games[$gameType] = [
                'eligible_count'=>count($rows),
                'top3'=>array_map(
                    static fn(array $row): array => [
                        'rank'=>(int)($row['rank'] ?? 0),
                        'public_mgw_id'=>MgwIdGenerator::toPublic((string)$row['mgw_id']),
                        'points'=>(int)($row['points'] ?? 0),
                    ],
                    array_slice($rows, 0, 3)
                ),
            ];
        }

        $medalReadiness = null;
        if ($target !== null) {
            try {
                $medalReadiness = (new YearlyMedalService($this->database))
                    ->boundaryReadiness($seasonId, $targetSeasonId);
            } catch (Throwable $error) {
                $medalReadiness = ['ready'=>false,'error'=>$error->getMessage()];
            }
        }

        $ready = $target !== null
            && is_array($package)
            && (string)($package['package_state'] ?? '') === SeasonLifecycleService::PACKAGE_READY
            && $pendingProjection === 0
            && (int)$bot['participation_violations'] === 0
            && ($medalReadiness['ready'] ?? false) === true;

        return [
            'dry_run'=>true,
            'season'=>$this->publicSeason($season),
            'target_season_id'=>$targetSeasonId,
            'target_season'=>$target === null ? null : $this->publicSeason($target),
            'reward_package'=>$package,
            'active_exclusions'=>$this->activeExclusions($seasonId),
            'pending_projection_count'=>$pendingProjection,
            'bot_exclusion'=>$bot,
            'medal_readiness'=>$medalReadiness,
            'games'=>$games,
            'ready_to_finalize'=>$ready,
        ];
    }

    public function activeExclusions(?string $seasonId = null): array
    {
        $params = ['state'=>self::EXCLUSION_ACTIVE];
        $where = 'WHERE e.exclusion_state=:state';
        if ($seasonId !== null && trim($seasonId) !== '') {
            $where .= ' AND e.season_id=:season_id';
            $params['season_id']=trim($seasonId);
        }
        $rows = $this->database->fetchAll(
            "SELECT e.season_id, e.mgw_id, e.exclusion_state, e.reason_code,
                    e.review_note, e.actor_ref, e.created_at_utc, e.updated_at_utc,
                    e.revoked_at_utc, u.nickname
             FROM mgw_rating_review_exclusions e
             LEFT JOIN mgw_users u ON u.mgw_id=e.mgw_id
             $where
             ORDER BY e.updated_at_utc DESC
             LIMIT 200",
            $params
        );
        return array_values(array_map(fn(array $row): array => $this->publicExclusion($row), $rows));
    }

    public function recentJobs(int $limit = 20): array
    {
        $limit = max(1, min(100, $limit));
        $rows = $this->database->fetchAll(
            "SELECT job_id, job_type, season_id, job_state, actor_ref, reason_text,
                    exclusions_json, result_json, error_text,
                    created_at_utc, started_at_utc, completed_at_utc, updated_at_utc
             FROM mgw_rating_recalculation_jobs
             ORDER BY created_at_utc DESC
             LIMIT $limit"
        );

        $jobs = [];
        foreach ($rows as $row) {
            if (!is_array($row)) continue;
            $jobs[] = [
                'job_id'=>(string)($row['job_id'] ?? ''),
                'job_type'=>(string)($row['job_type'] ?? ''),
                'season_id'=>(string)($row['season_id'] ?? ''),
                'job_state'=>(string)($row['job_state'] ?? ''),
                'actor_ref'=>(string)($row['actor_ref'] ?? ''),
                'reason_text'=>(string)($row['reason_text'] ?? ''),
                'excluded_mgw_ids'=>$this->decodeList($row['exclusions_json'] ?? null),
                'result'=>$this->decodeObject($row['result_json'] ?? null),
                'error_text'=>$this->nullableText($row['error_text'] ?? null),
                'created_at_utc'=>(string)($row['created_at_utc'] ?? ''),
                'started_at_utc'=>$this->nullableText($row['started_at_utc'] ?? null),
                'completed_at_utc'=>$this->nullableText($row['completed_at_utc'] ?? null),
            ];
        }
        return $jobs;
    }

    private function seasonMetrics(string $seasonId): array
    {
        $scores = (int)$this->database->fetchValue(
            'SELECT COUNT(*) FROM mgw_game_rating_scores WHERE season_id=:season_id',
            ['season_id'=>$seasonId]
        );
        $participation = (int)$this->database->fetchValue(
            'SELECT COUNT(*) FROM mgw_game_rating_participation WHERE season_id=:season_id',
            ['season_id'=>$seasonId]
        );
        $outcomes = (int)$this->database->fetchValue(
            'SELECT COUNT(*) FROM mgw_game_rating_outcomes WHERE season_id=:season_id',
            ['season_id'=>$seasonId]
        );
        $excluded = (int)$this->database->fetchValue(
            'SELECT COUNT(*) FROM mgw_rating_review_exclusions
             WHERE season_id=:season_id AND exclusion_state=:state',
            ['season_id'=>$seasonId,'state'=>self::EXCLUSION_ACTIVE]
        );
        $bot = $this->botExclusionMetrics($seasonId);
        $season = $this->season($seasonId);

        return [
            'season_id'=>$seasonId,
            'score_rows'=>$scores,
            'participation_rows'=>$participation,
            'outcome_rows'=>$outcomes,
            'active_exclusions'=>$excluded,
            'pending_projection_count'=>$this->pendingProjectionCount($season),
            'bot_game_outcomes'=>$bot['bot_game_outcomes'],
            'bot_participation_violations'=>$bot['participation_violations'],
        ];
    }

    private function botExclusionMetrics(string $seasonId): array
    {
        $botOutcomes = (int)$this->database->fetchValue(
            'SELECT COUNT(*) FROM mgw_game_rating_outcomes
             WHERE season_id=:season_id AND outcome_code=:code',
            ['season_id'=>$seasonId,'code'=>'bot_game']
        );
        $violations = (int)$this->database->fetchValue(
            "SELECT COUNT(*)
             FROM mgw_game_rating_participation p
             WHERE p.season_id=:season_id
               AND EXISTS (
                   SELECT 1
                   FROM mgw_match_players mp
                   WHERE mp.match_id=p.match_id
                     AND LOWER(COALESCE(mp.player_type, 'human')) <> 'human'
               )",
            ['season_id'=>$seasonId]
        );
        return [
            'bot_game_outcomes'=>$botOutcomes,
            'participation_violations'=>$violations,
            'invariant_ok'=>$violations === 0,
        ];
    }

    private function pendingProjectionCount(array $season): int
    {
        return (int)$this->database->fetchValue(
            'SELECT COUNT(*)
             FROM mgw_matches m
             WHERE m.status=:status
               AND m.finished_at_utc IS NOT NULL
               AND m.finished_at_utc >= :start_at
               AND m.finished_at_utc < :end_at
               AND NOT EXISTS (
                   SELECT 1 FROM mgw_game_rating_outcomes o WHERE o.match_id=m.match_id
               )',
            [
                'status'=>'finished',
                'start_at'=>(string)$season['official_start_at_utc'],
                'end_at'=>(string)$season['calendar_end_at_utc'],
            ]
        );
    }

    private function activeExcludedIds(string $seasonId): array
    {
        $rows = $this->database->fetchAll(
            'SELECT mgw_id
             FROM mgw_rating_review_exclusions
             WHERE season_id=:season_id AND exclusion_state=:state
             ORDER BY mgw_id ASC',
            ['season_id'=>$seasonId,'state'=>self::EXCLUSION_ACTIVE]
        );
        $ids = [];
        foreach ($rows as $row) {
            if (!is_array($row)) continue;
            $id = trim((string)($row['mgw_id'] ?? ''));
            if ($id !== '') $ids[] = $id;
        }
        return $ids;
    }

    private function exclusion(string $seasonId, string $mgwId): array
    {
        $rows = $this->database->fetchAll(
            'SELECT e.season_id, e.mgw_id, e.exclusion_state, e.reason_code,
                    e.review_note, e.actor_ref, e.created_at_utc, e.updated_at_utc,
                    e.revoked_at_utc, u.nickname
             FROM mgw_rating_review_exclusions e
             LEFT JOIN mgw_users u ON u.mgw_id=e.mgw_id
             WHERE e.season_id=:season_id AND e.mgw_id=:mgw_id',
            ['season_id'=>$seasonId,'mgw_id'=>$mgwId]
        );
        if (count($rows) !== 1 || !is_array($rows[0])) {
            throw new RuntimeException('Rating review exclusion was not found.');
        }
        return $this->publicExclusion($rows[0]);
    }

    private function publicExclusion(array $row): array
    {
        $mgwId = (string)($row['mgw_id'] ?? '');
        return [
            'season_id'=>(string)($row['season_id'] ?? ''),
            'mgw_id'=>$mgwId,
            'public_mgw_id'=>MgwIdGenerator::toPublic($mgwId),
            'nickname'=>(string)($row['nickname'] ?? ''),
            'exclusion_state'=>(string)($row['exclusion_state'] ?? ''),
            'reason_code'=>(string)($row['reason_code'] ?? ''),
            'review_note'=>(string)($row['review_note'] ?? ''),
            'actor_ref'=>(string)($row['actor_ref'] ?? ''),
            'created_at_utc'=>(string)($row['created_at_utc'] ?? ''),
            'updated_at_utc'=>(string)($row['updated_at_utc'] ?? ''),
            'revoked_at_utc'=>$this->nullableText($row['revoked_at_utc'] ?? null),
        ];
    }

    private function assertReviewableUser(string $mgwId): void
    {
        $rows = $this->database->fetchAll(
            'SELECT u.mgw_id, u.status,
                    EXISTS(
                        SELECT 1 FROM mgw_identities i
                        WHERE i.mgw_id=u.mgw_id AND i.provider=:provider
                    ) AS is_development
             FROM mgw_users u
             WHERE u.mgw_id=:mgw_id',
            ['provider'=>'development','mgw_id'=>$mgwId]
        );
        if (count($rows) !== 1 || !is_array($rows[0])) {
            throw new InvalidArgumentException('Reviewed player was not found.');
        }
        if ((int)($rows[0]['is_development'] ?? 0) === 1) {
            throw new InvalidArgumentException('Development identities are already excluded by the canonical public-rating owner.');
        }
    }

    private function auditReview(
        DatabaseConnectionInterface $database,
        string $seasonId,
        string $mgwId,
        string $action,
        string $reason,
        string $note,
        string $actorRef,
        string $now
    ): void {
        $database->execute(
            'INSERT INTO mgw_rating_review_audit (
                season_id, mgw_id, action_code, reason_code,
                review_note, actor_ref, created_at_utc
             ) VALUES (
                :season_id, :mgw_id, :action_code, :reason_code,
                :review_note, :actor_ref, :created_at
             )',
            [
                'season_id'=>$seasonId,
                'mgw_id'=>$mgwId,
                'action_code'=>$action,
                'reason_code'=>$reason,
                'review_note'=>$note,
                'actor_ref'=>$actorRef,
                'created_at'=>$now,
            ]
        );
    }

    private function finishJob(
        string $jobId,
        string $state,
        ?array $result,
        ?string $error,
        string $now
    ): void {
        $this->database->execute(
            'UPDATE mgw_rating_recalculation_jobs
             SET job_state=:state,
                 result_json=:result_json,
                 error_text=:error_text,
                 completed_at_utc=:completed_at,
                 updated_at_utc=:updated_at
             WHERE job_id=:job_id',
            [
                'state'=>$state,
                'result_json'=>$result === null ? null : json_encode($result, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                'error_text'=>$error === null ? null : mb_substr($error, 0, 1000),
                'completed_at'=>$now,
                'updated_at'=>$now,
                'job_id'=>$jobId,
            ]
        );
    }

    private function targetSeasonId(array $season): string
    {
        $rows = $this->database->fetchAll(
            'SELECT target_season_id
             FROM mgw_season_boundary_operations
             WHERE ending_season_id=:season_id',
            ['season_id'=>(string)$season['season_id']]
        );
        if (count($rows) === 1 && is_array($rows[0])) {
            $target = trim((string)($rows[0]['target_season_id'] ?? ''));
            if ($target !== '') return $target;
        }
        return $this->nextSeasonId((int)$season['calendar_year'], (int)$season['quarter']);
    }

    private function nextSeasonId(int $year, int $quarter): string
    {
        if ($quarter >= 4) return ($year + 1) . '-q1';
        return $year . '-q' . ($quarter + 1);
    }

    private function rewardPackageOrNull(string $targetSeasonId): ?array
    {
        $rows = $this->database->fetchAll(
            'SELECT target_season_id, package_state,
                    seasonal_awards_state, top3_frames_state, yearly_medal_state,
                    localization_state, preview_validation_state, ready_at_utc
             FROM mgw_season_reward_packages
             WHERE target_season_id=:target_season_id',
            ['target_season_id'=>$targetSeasonId]
        );
        if ($rows === []) return null;
        return count($rows) === 1 && is_array($rows[0]) ? $rows[0] : null;
    }

    private function competitionControl(): array
    {
        $rows = $this->database->fetchAll(
            'SELECT competition_state, current_season_id, tracking_started_at_utc,
                    activated_at_utc, updated_at_utc
             FROM mgw_rating_control
             WHERE control_key=:control_key',
            ['control_key'=>self::CONTROL_KEY]
        );
        if (count($rows) !== 1 || !is_array($rows[0])) {
            throw new RuntimeException('Rating competition control is unavailable.');
        }
        return [
            'competition_state'=>(string)$rows[0]['competition_state'],
            'current_season_id'=>(string)$rows[0]['current_season_id'],
            'tracking_started_at_utc'=>(string)$rows[0]['tracking_started_at_utc'],
            'activated_at_utc'=>$this->nullableText($rows[0]['activated_at_utc'] ?? null),
            'updated_at_utc'=>(string)$rows[0]['updated_at_utc'],
        ];
    }

    private function season(string $seasonId): array
    {
        $seasonId = strtolower(trim($seasonId));
        if ($seasonId === '' || $seasonId === PerGameRatingService::PRESEASON_ID) {
            throw new InvalidArgumentException('Official rating season is required.');
        }
        $season = $this->seasonOrNull($seasonId);
        if ($season === null) throw new InvalidArgumentException('Rating season was not found.');
        return $season;
    }

    private function seasonOrNull(string $seasonId): ?array
    {
        $rows = $this->database->fetchAll(
            'SELECT season_id, calendar_year, quarter, timezone,
                    calendar_start_at_utc, calendar_end_at_utc, official_start_at_utc,
                    season_state, finalization_reason, standings_frozen_at_utc,
                    finalization_started_at_utc, finalized_at_utc
             FROM mgw_rating_seasons
             WHERE season_id=:season_id',
            ['season_id'=>strtolower(trim($seasonId))]
        );
        if ($rows === []) return null;
        if (count($rows) !== 1 || !is_array($rows[0])) {
            throw new RuntimeException('Rating season state is invalid.');
        }
        return $rows[0];
    }

    private function publicSeason(array $season): array
    {
        return [
            'season_id'=>(string)$season['season_id'],
            'calendar_year'=>(int)$season['calendar_year'],
            'quarter'=>(int)$season['quarter'],
            'timezone'=>(string)$season['timezone'],
            'official_start_at_utc'=>(string)$season['official_start_at_utc'],
            'calendar_end_at_utc'=>(string)$season['calendar_end_at_utc'],
            'season_state'=>(string)$season['season_state'],
            'finalization_reason'=>$this->nullableText($season['finalization_reason'] ?? null),
            'standings_frozen_at_utc'=>$this->nullableText($season['standings_frozen_at_utc'] ?? null),
            'finalized_at_utc'=>$this->nullableText($season['finalized_at_utc'] ?? null),
        ];
    }

    private function normalizeMgwId(string $mgwId): string
    {
        $mgwId = strtoupper(trim($mgwId));
        $internal = MgwIdGenerator::fromPublic($mgwId);
        if ($internal === null || !MgwIdGenerator::isValid($internal)) {
            throw new InvalidArgumentException('MGW-ID is invalid.');
        }
        return $internal;
    }

    private function normalizeReasonCode(string $reason): string
    {
        $reason = strtolower(trim($reason));
        if (!in_array($reason, self::REASON_CODES, true)) {
            throw new InvalidArgumentException('Unsupported rating review reason.');
        }
        return $reason;
    }

    private function normalizeText(string $value, int $max, string $label): string
    {
        $value = trim($value);
        if ($value === '' || mb_strlen($value) > $max) {
            throw new InvalidArgumentException("Rating admin $label is invalid.");
        }
        return $value;
    }

    private function utcNow(?DateTimeImmutable $now): DateTimeImmutable
    {
        return ($now ?? new DateTimeImmutable('now', new DateTimeZone('UTC')))
            ->setTimezone(new DateTimeZone('UTC'));
    }

    private function emptyMetrics(string $seasonId): array
    {
        return [
            'season_id'=>$seasonId,
            'score_rows'=>0,
            'participation_rows'=>0,
            'outcome_rows'=>0,
            'active_exclusions'=>0,
            'pending_projection_count'=>0,
            'bot_game_outcomes'=>0,
            'bot_participation_violations'=>0,
        ];
    }

    private function decodeList(mixed $json): array
    {
        if (!is_string($json) || trim($json) === '') return [];
        $decoded = json_decode($json, true);
        return is_array($decoded) ? array_values($decoded) : [];
    }

    private function decodeObject(mixed $json): ?array
    {
        if (!is_string($json) || trim($json) === '') return null;
        $decoded = json_decode($json, true);
        return is_array($decoded) ? $decoded : null;
    }

    private function nullableText(mixed $value): ?string
    {
        if ($value === null) return null;
        $text = trim((string)$value);
        return $text === '' ? null : $text;
    }
}
