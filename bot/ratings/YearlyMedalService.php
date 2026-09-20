<?php
declare(strict_types=1);

/**
 * MVP-20.6 authoritative yearly-medal owner.
 *
 * One real rated human match in an official quarter unlocks exactly that
 * quarter's fragment. Missing quarters stay missing. Fragments are not Store
 * inventory and there is no purchase/backfill path.
 */
final class YearlyMedalService
{
    public const DESIGN_READY = 'ready';
    public const DESIGN_PENDING = 'pending';

    public const FRAGMENT_ACTIVE = 'active';
    public const FRAGMENT_REVOKED = 'revoked';

    public function __construct(private DatabaseConnectionInterface $database) {}

    public function boundaryReadiness(string $endingSeasonId, string $targetSeasonId): array
    {
        $ending = $this->season($endingSeasonId);
        $target = $this->season($targetSeasonId);
        $requiredYears = [(int)$ending['calendar_year']];
        if ((int)$target['calendar_year'] !== (int)$ending['calendar_year']) {
            $requiredYears[] = (int)$target['calendar_year'];
        }

        $missing = [];
        $designs = [];
        foreach (array_values(array_unique($requiredYears)) as $year) {
            $design = $this->design($year);
            if ($design === null || (string)$design['design_state'] !== self::DESIGN_READY) {
                $missing[] = $year;
                continue;
            }
            $designs[$year] = $design;
        }

        return [
            'ready' => $missing === [],
            'ending_season_id' => (string)$ending['season_id'],
            'target_season_id' => (string)$target['season_id'],
            'required_years' => $requiredYears,
            'missing_years' => $missing,
            'designs' => $designs,
        ];
    }

    public function reconcileSeasonFragment(
        string $seasonId,
        array $excludedMgwIds = [],
        string $reason = 'season_close',
        string $actorRef = 'system:season_finalization',
        ?DateTimeImmutable $now = null
    ): array {
        $now = $this->utcNow($now);
        $season = $this->season($seasonId);
        if ($seasonId === PerGameRatingService::PRESEASON_ID) {
            throw new RuntimeException('PRESEASON can never issue official yearly medal fragments.');
        }

        $year = (int)$season['calendar_year'];
        $quarter = (int)$season['quarter'];
        $design = $this->design($year);
        if ($design === null || (string)$design['design_state'] !== self::DESIGN_READY) {
            return [
                'status' => 'assets_required',
                'season_id' => $seasonId,
                'calendar_year' => $year,
                'quarter' => $quarter,
                'eligible_count' => 0,
                'granted' => 0,
                'revoked' => 0,
            ];
        }

        $eligible = $this->eligiblePlayers($seasonId, $excludedMgwIds);
        $fingerprint = hash('sha256', json_encode($eligible, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

        return $this->database->transaction(function (DatabaseConnectionInterface $database) use (
            $seasonId,
            $year,
            $quarter,
            $design,
            $eligible,
            $fingerprint,
            $reason,
            $actorRef,
            $now
        ): array {
            $run = $this->run($database, $seasonId, true);
            if ($run !== null && (string)$run['eligibility_fingerprint'] === $fingerprint) {
                return [
                    'status' => 'unchanged',
                    'season_id' => $seasonId,
                    'calendar_year' => $year,
                    'quarter' => $quarter,
                    'eligible_count' => count($eligible),
                    'revision' => (int)$run['revision'],
                    'granted' => 0,
                    'revoked' => 0,
                ];
            }

            $revision = max(0, (int)($run['revision'] ?? 0)) + 1;
            $nowText = $now->format('Y-m-d H:i:s.u');
            $existingRows = $database->fetchAll(
                'SELECT calendar_year, mgw_id, quarter, season_id, medal_id,
                        fragment_state, revision, unlocked_at_utc, revoked_at_utc, updated_at_utc
                 FROM mgw_yearly_medal_fragments
                 WHERE season_id = :season_id',
                ['season_id' => $seasonId]
            );
            $existing = [];
            foreach ($existingRows as $row) {
                if (!is_array($row)) continue;
                $mgwId = trim((string)($row['mgw_id'] ?? ''));
                if ($mgwId !== '') $existing[$mgwId] = $row;
            }
            $desired = array_fill_keys($eligible, true);
            $granted = 0;
            $revoked = 0;

            foreach ($existing as $mgwId => $row) {
                if ((string)($row['fragment_state'] ?? '') !== self::FRAGMENT_ACTIVE) continue;
                if (isset($desired[$mgwId])) continue;
                $database->execute(
                    'UPDATE mgw_yearly_medal_fragments
                     SET fragment_state = :state,
                         revision = :revision,
                         revoked_at_utc = :revoked_at,
                         updated_at_utc = :updated_at
                     WHERE calendar_year = :calendar_year
                       AND mgw_id = :mgw_id
                       AND quarter = :quarter',
                    [
                        'state' => self::FRAGMENT_REVOKED,
                        'revision' => $revision,
                        'revoked_at' => $nowText,
                        'updated_at' => $nowText,
                        'calendar_year' => $year,
                        'mgw_id' => $mgwId,
                        'quarter' => $quarter,
                    ]
                );
                $this->audit($database, $seasonId, $year, $quarter, $mgwId, 'revoke', $reason, $actorRef, $revision, $nowText);
                $revoked++;
            }

            foreach ($eligible as $mgwId) {
                $row = $existing[$mgwId] ?? null;
                if ($row === null) {
                    $database->execute(
                        'INSERT INTO mgw_yearly_medal_fragments (
                            calendar_year, mgw_id, quarter, season_id, medal_id,
                            fragment_state, revision, unlocked_at_utc, revoked_at_utc, updated_at_utc
                         ) VALUES (
                            :calendar_year, :mgw_id, :quarter, :season_id, :medal_id,
                            :fragment_state, :revision, :unlocked_at_utc, NULL, :updated_at_utc
                         )',
                        [
                            'calendar_year' => $year,
                            'mgw_id' => $mgwId,
                            'quarter' => $quarter,
                            'season_id' => $seasonId,
                            'medal_id' => (string)$design['medal_id'],
                            'fragment_state' => self::FRAGMENT_ACTIVE,
                            'revision' => $revision,
                            'unlocked_at_utc' => $nowText,
                            'updated_at_utc' => $nowText,
                        ]
                    );
                    $this->audit($database, $seasonId, $year, $quarter, $mgwId, 'grant', $reason, $actorRef, $revision, $nowText);
                    $granted++;
                    continue;
                }

                if ((string)($row['fragment_state'] ?? '') === self::FRAGMENT_ACTIVE
                    && (string)($row['medal_id'] ?? '') === (string)$design['medal_id']) {
                    continue;
                }

                $database->execute(
                    'UPDATE mgw_yearly_medal_fragments
                     SET season_id = :season_id,
                         medal_id = :medal_id,
                         fragment_state = :fragment_state,
                         revision = :revision,
                         unlocked_at_utc = :unlocked_at_utc,
                         revoked_at_utc = NULL,
                         updated_at_utc = :updated_at_utc
                     WHERE calendar_year = :calendar_year
                       AND mgw_id = :mgw_id
                       AND quarter = :quarter',
                    [
                        'season_id' => $seasonId,
                        'medal_id' => (string)$design['medal_id'],
                        'fragment_state' => self::FRAGMENT_ACTIVE,
                        'revision' => $revision,
                        'unlocked_at_utc' => $nowText,
                        'updated_at_utc' => $nowText,
                        'calendar_year' => $year,
                        'mgw_id' => $mgwId,
                        'quarter' => $quarter,
                    ]
                );
                $this->audit($database, $seasonId, $year, $quarter, $mgwId, 'reissue', $reason, $actorRef, $revision, $nowText);
                $granted++;
            }

            $this->writeRun(
                $database,
                $seasonId,
                $year,
                $quarter,
                (string)$design['medal_id'],
                $fingerprint,
                $revision,
                count($eligible),
                $reason,
                $actorRef,
                $nowText,
                $run === null
            );

            return [
                'status' => 'reconciled',
                'season_id' => $seasonId,
                'calendar_year' => $year,
                'quarter' => $quarter,
                'eligible_count' => count($eligible),
                'revision' => $revision,
                'granted' => $granted,
                'revoked' => $revoked,
            ];
        });
    }

    public function userSnapshot(string $mgwId, ?DateTimeImmutable $now = null): array
    {
        $mgwId = trim($mgwId);
        if ($mgwId === '' || strlen($mgwId) > 24) {
            throw new InvalidArgumentException('Yearly medal user identity is invalid.');
        }

        $control = $this->competitionControl();
        if ($control['competition_state'] !== PerGameRatingService::STATE_ACTIVE) {
            return [
                'competition_state' => $control['competition_state'],
                'visible' => false,
                'current' => null,
                'years' => [],
            ];
        }

        $currentSeason = $this->season($control['current_season_id']);
        $currentYear = (int)$currentSeason['calendar_year'];
        $rows = $this->database->fetchAll(
            'SELECT f.calendar_year, f.quarter, f.season_id, f.medal_id,
                    f.fragment_state, f.unlocked_at_utc,
                    d.theme_key, d.design_state
             FROM mgw_yearly_medal_fragments f
             INNER JOIN mgw_yearly_medal_designs d
                     ON d.calendar_year = f.calendar_year
                    AND d.medal_id = f.medal_id
             WHERE f.mgw_id = :mgw_id
               AND f.fragment_state = :state
             ORDER BY f.calendar_year DESC, f.quarter ASC',
            ['mgw_id' => $mgwId, 'state' => self::FRAGMENT_ACTIVE]
        );

        $byYear = [];
        foreach ($rows as $row) {
            if (!is_array($row)) continue;
            $year = (int)($row['calendar_year'] ?? 0);
            $quarter = (int)($row['quarter'] ?? 0);
            if ($year < 2000 || $quarter < 1 || $quarter > 4) continue;
            $byYear[$year] ??= [
                'calendar_year' => $year,
                'medal_id' => (string)($row['medal_id'] ?? ''),
                'theme_key' => (string)($row['theme_key'] ?? ''),
                'unlocked_quarters' => [],
            ];
            $byYear[$year]['unlocked_quarters'][] = $quarter;
        }

        $currentDesign = $this->design($currentYear);
        if ($currentDesign !== null) {
            $byYear[$currentYear] ??= [
                'calendar_year' => $currentYear,
                'medal_id' => (string)$currentDesign['medal_id'],
                'theme_key' => (string)$currentDesign['theme_key'],
                'unlocked_quarters' => [],
            ];
        }

        krsort($byYear);
        $years = [];
        foreach ($byYear as $year => $entry) {
            $quarters = array_values(array_unique(array_map('intval', $entry['unlocked_quarters'])));
            sort($quarters);
            $entry['unlocked_quarters'] = $quarters;
            $entry['fragment_count'] = count($quarters);
            $entry['complete'] = $quarters === [1,2,3,4];
            $years[] = $entry;
        }

        $current = null;
        foreach ($years as $entry) {
            if ((int)$entry['calendar_year'] === $currentYear) {
                $current = $entry;
                break;
            }
        }

        return [
            'competition_state' => $control['competition_state'],
            'visible' => $current !== null,
            'current_season_id' => $control['current_season_id'],
            'current' => $current,
            'years' => $years,
        ];
    }

    public function upsertDesign(
        int $calendarYear,
        string $medalId,
        string $themeKey,
        string $designState = self::DESIGN_PENDING,
        ?DateTimeImmutable $now = null
    ): array {
        if ($calendarYear < 2000 || $calendarYear > 9999) {
            throw new InvalidArgumentException('Yearly medal design year is invalid.');
        }
        $medalId = $this->normalizeToken($medalId, 64, 'yearly medal id');
        $themeKey = $this->normalizeToken($themeKey, 64, 'yearly medal theme');
        $designState = strtolower(trim($designState));
        if (!in_array($designState, [self::DESIGN_PENDING, self::DESIGN_READY], true)) {
            throw new InvalidArgumentException('Yearly medal design state is invalid.');
        }
        $nowText = $this->utcNow($now)->format('Y-m-d H:i:s.u');

        return $this->database->transaction(function (DatabaseConnectionInterface $database) use (
            $calendarYear,
            $medalId,
            $themeKey,
            $designState,
            $nowText
        ): array {
            $current = $this->designFromDatabase($database, $calendarYear, true);
            if ($current === null) {
                $database->execute(
                    'INSERT INTO mgw_yearly_medal_designs (
                        calendar_year, medal_id, theme_key, design_state, design_revision,
                        created_at_utc, updated_at_utc
                     ) VALUES (
                        :calendar_year, :medal_id, :theme_key, :design_state, 1,
                        :created_at_utc, :updated_at_utc
                     )',
                    [
                        'calendar_year' => $calendarYear,
                        'medal_id' => $medalId,
                        'theme_key' => $themeKey,
                        'design_state' => $designState,
                        'created_at_utc' => $nowText,
                        'updated_at_utc' => $nowText,
                    ]
                );
            } else {
                $database->execute(
                    'UPDATE mgw_yearly_medal_designs
                     SET medal_id = :medal_id,
                         theme_key = :theme_key,
                         design_state = :design_state,
                         design_revision = design_revision + 1,
                         updated_at_utc = :updated_at_utc
                     WHERE calendar_year = :calendar_year',
                    [
                        'medal_id' => $medalId,
                        'theme_key' => $themeKey,
                        'design_state' => $designState,
                        'updated_at_utc' => $nowText,
                        'calendar_year' => $calendarYear,
                    ]
                );
            }

            $design = $this->designFromDatabase($database, $calendarYear, true);
            if ($design === null) throw new RuntimeException('Unable to persist yearly medal design.');
            return $design;
        });
    }

    private function eligiblePlayers(string $seasonId, array $excludedMgwIds): array
    {
        $params = [
            'season_id' => $seasonId,
            'active_status' => 'active',
            'development_provider' => 'development',
        ];
        $excluded = [];
        foreach ($excludedMgwIds as $value) {
            $mgwId = trim((string)$value);
            if ($mgwId === '') continue;
            if (strlen($mgwId) > 24) throw new InvalidArgumentException('Yearly medal exclusion identity is invalid.');
            $excluded[$mgwId] = $mgwId;
        }
        $excluded = array_values($excluded);
        $excludeSql = '';
        if ($excluded !== []) {
            $placeholders = [];
            foreach ($excluded as $index => $mgwId) {
                $key = 'excluded_' . $index;
                $placeholders[] = ':' . $key;
                $params[$key] = $mgwId;
            }
            $excludeSql = ' AND p.mgw_id NOT IN (' . implode(', ', $placeholders) . ')';
        }

        $rows = $this->database->fetchAll(
            'SELECT DISTINCT p.mgw_id
             FROM mgw_game_rating_participation p
             INNER JOIN mgw_users u ON u.mgw_id = p.mgw_id
             WHERE p.season_id = :season_id
               AND u.status = :active_status
               AND NOT EXISTS (
                   SELECT 1
                   FROM mgw_identities dev_identity
                   WHERE dev_identity.mgw_id = p.mgw_id
                     AND dev_identity.provider = :development_provider
               )'
               . $excludeSql .
            ' ORDER BY p.mgw_id ASC',
            $params
        );

        $eligible = [];
        foreach ($rows as $row) {
            if (!is_array($row)) continue;
            $mgwId = trim((string)($row['mgw_id'] ?? ''));
            if ($mgwId !== '') $eligible[] = $mgwId;
        }
        return $eligible;
    }

    private function competitionControl(): array
    {
        $rows = $this->database->fetchAll(
            'SELECT competition_state, current_season_id
             FROM mgw_rating_control
             WHERE control_key = :control_key',
            ['control_key' => 'global']
        );
        if (count($rows) !== 1 || !is_array($rows[0])) {
            throw new RuntimeException('Competition control is unavailable for yearly medal.');
        }
        return [
            'competition_state' => strtolower(trim((string)($rows[0]['competition_state'] ?? ''))),
            'current_season_id' => trim((string)($rows[0]['current_season_id'] ?? '')),
        ];
    }

    private function season(string $seasonId): array
    {
        $seasonId = strtolower(trim($seasonId));
        if ($seasonId === '' || $seasonId === PerGameRatingService::PRESEASON_ID) {
            throw new RuntimeException('Official yearly medal season is unavailable.');
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
            throw new RuntimeException('Official yearly medal season is unavailable.');
        }
        return $rows[0];
    }

    private function design(int $year): ?array
    {
        return $this->designFromDatabase($this->database, $year, false);
    }

    private function designFromDatabase(
        DatabaseConnectionInterface $database,
        int $year,
        bool $forUpdate
    ): ?array {
        $sql = 'SELECT calendar_year, medal_id, theme_key, design_state, design_revision,
                       created_at_utc, updated_at_utc
                FROM mgw_yearly_medal_designs
                WHERE calendar_year = :calendar_year';
        if ($forUpdate && $database->driver() !== 'sqlite') $sql .= ' FOR UPDATE';
        $rows = $database->fetchAll($sql, ['calendar_year' => $year]);
        if ($rows === []) return null;
        return is_array($rows[0]) ? $rows[0] : null;
    }

    private function run(
        DatabaseConnectionInterface $database,
        string $seasonId,
        bool $forUpdate
    ): ?array {
        $sql = 'SELECT season_id, calendar_year, quarter, medal_id,
                       eligibility_fingerprint, revision, eligible_count,
                       last_reason, last_actor_ref, reconciled_at_utc,
                       created_at_utc, updated_at_utc
                FROM mgw_yearly_medal_runs
                WHERE season_id = :season_id';
        if ($forUpdate && $database->driver() !== 'sqlite') $sql .= ' FOR UPDATE';
        $rows = $database->fetchAll($sql, ['season_id' => $seasonId]);
        if ($rows === []) return null;
        return is_array($rows[0]) ? $rows[0] : null;
    }

    private function writeRun(
        DatabaseConnectionInterface $database,
        string $seasonId,
        int $year,
        int $quarter,
        string $medalId,
        string $fingerprint,
        int $revision,
        int $eligibleCount,
        string $reason,
        string $actorRef,
        string $now,
        bool $insert
    ): void {
        $reason = $this->normalizeToken($reason, 64, 'yearly medal reason');
        $actorRef = $this->normalizeText($actorRef, 191, 'yearly medal actor');
        if ($insert) {
            $database->execute(
                'INSERT INTO mgw_yearly_medal_runs (
                    season_id, calendar_year, quarter, medal_id,
                    eligibility_fingerprint, revision, eligible_count,
                    last_reason, last_actor_ref, reconciled_at_utc,
                    created_at_utc, updated_at_utc
                 ) VALUES (
                    :season_id, :calendar_year, :quarter, :medal_id,
                    :eligibility_fingerprint, :revision, :eligible_count,
                    :last_reason, :last_actor_ref, :reconciled_at_utc,
                    :created_at_utc, :updated_at_utc
                 )',
                [
                    'season_id' => $seasonId,
                    'calendar_year' => $year,
                    'quarter' => $quarter,
                    'medal_id' => $medalId,
                    'eligibility_fingerprint' => $fingerprint,
                    'revision' => $revision,
                    'eligible_count' => $eligibleCount,
                    'last_reason' => $reason,
                    'last_actor_ref' => $actorRef,
                    'reconciled_at_utc' => $now,
                    'created_at_utc' => $now,
                    'updated_at_utc' => $now,
                ]
            );
            return;
        }

        $database->execute(
            'UPDATE mgw_yearly_medal_runs
             SET calendar_year = :calendar_year,
                 quarter = :quarter,
                 medal_id = :medal_id,
                 eligibility_fingerprint = :eligibility_fingerprint,
                 revision = :revision,
                 eligible_count = :eligible_count,
                 last_reason = :last_reason,
                 last_actor_ref = :last_actor_ref,
                 reconciled_at_utc = :reconciled_at_utc,
                 updated_at_utc = :updated_at_utc
             WHERE season_id = :season_id',
            [
                'calendar_year' => $year,
                'quarter' => $quarter,
                'medal_id' => $medalId,
                'eligibility_fingerprint' => $fingerprint,
                'revision' => $revision,
                'eligible_count' => $eligibleCount,
                'last_reason' => $reason,
                'last_actor_ref' => $actorRef,
                'reconciled_at_utc' => $now,
                'updated_at_utc' => $now,
                'season_id' => $seasonId,
            ]
        );
    }

    private function audit(
        DatabaseConnectionInterface $database,
        string $seasonId,
        int $year,
        int $quarter,
        string $mgwId,
        string $action,
        string $reason,
        string $actorRef,
        int $revision,
        string $now
    ): void {
        $database->execute(
            'INSERT INTO mgw_yearly_medal_audit (
                season_id, calendar_year, quarter, mgw_id,
                action_code, reason_code, actor_ref, revision, created_at_utc
             ) VALUES (
                :season_id, :calendar_year, :quarter, :mgw_id,
                :action_code, :reason_code, :actor_ref, :revision, :created_at_utc
             )',
            [
                'season_id' => $seasonId,
                'calendar_year' => $year,
                'quarter' => $quarter,
                'mgw_id' => $mgwId,
                'action_code' => $action,
                'reason_code' => $this->normalizeToken($reason, 64, 'yearly medal reason'),
                'actor_ref' => $this->normalizeText($actorRef, 191, 'yearly medal actor'),
                'revision' => $revision,
                'created_at_utc' => $now,
            ]
        );
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

    private function utcNow(?DateTimeImmutable $now): DateTimeImmutable
    {
        return ($now ?? new DateTimeImmutable('now', new DateTimeZone('UTC')))
            ->setTimezone(new DateTimeZone('UTC'));
    }
}
