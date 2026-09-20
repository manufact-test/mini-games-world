<?php
declare(strict_types=1);

/**
 * MVP-20.4 authoritative quarterly season lifecycle owner.
 *
 * It owns calendar boundaries, durable FINALIZING / ASSETS_REQUIRED state and
 * reminder due dates. It does not own seasonal reward grants (MVP-20.5),
 * yearly medal fragments (MVP-20.6), Admin UI (MVP-22.5/22.7) or Cron.
 */
final class SeasonLifecycleService
{
    public const SEASON_ACTIVE = 'active';
    public const SEASON_FINALIZING = 'finalizing';
    public const SEASON_CLOSED = 'closed';

    public const PACKAGE_PENDING = 'pending';
    public const PACKAGE_READY = 'ready';

    public const CHECK_PENDING = 'pending';
    public const CHECK_READY = 'ready';
    public const CHECK_NOT_REQUIRED = 'not_required';

    public const REMINDER_PENDING = 'pending';
    public const REMINDER_DUE = 'due';
    public const REMINDER_SATISFIED = 'satisfied';
    public const REMINDER_EXPIRED = 'expired';

    public const OP_FINALIZING = 'finalizing';
    public const OP_ASSETS_REQUIRED = 'assets_required';
    public const OP_COMPLETED = 'completed';

    private const CONTROL_KEY = 'global';
    private const MAX_CATCH_UP_QUARTERS = 16;

    public function __construct(
        private DatabaseConnectionInterface $database,
        private ?SeasonCalendar $calendar = null
    ) {
        $this->calendar ??= new SeasonCalendar();
    }

    public function reconcile(?DateTimeImmutable $now = null): array
    {
        $now = $this->utcNow($now);

        return $this->database->transaction(function (DatabaseConnectionInterface $database) use ($now): array {
            $control = $this->controlForUpdate($database);
            $summary = [
                'competition_state' => $control['competition_state'],
                'current_season_id' => $control['current_season_id'],
                'opened' => [],
                'boundaries' => [],
                'recovered' => [],
                'reminders_due' => [],
            ];

            if ($control['competition_state'] !== PerGameRatingService::STATE_ACTIVE) {
                return $summary + ['status' => 'inactive'];
            }

            if ($control['activated_at'] === null) {
                throw new RuntimeException('ACTIVE competition requires activated_at before season reconciliation.');
            }

            $current = $this->loadSeason($database, $control['current_season_id'], true);
            if ($current === null) {
                $definition = $this->calendar->definitionForInstant($this->calendar->utc($control['activated_at']));
                $officialStart = $this->laterTimestamp(
                    $definition['calendar_start_at_utc'],
                    $control['activated_at']
                );
                $current = $this->ensureSeason(
                    $database,
                    $definition,
                    $officialStart,
                    self::SEASON_ACTIVE,
                    $now
                );
                $this->setCurrentSeason($database, $current['season_id'], $now);
                $control['current_season_id'] = $current['season_id'];
                $summary['opened'][] = $current['season_id'];
            }

            $iterations = 0;
            while ($this->compare($now->format('Y-m-d H:i:s.u'), (string)$current['calendar_end_at_utc']) >= 0) {
                if (++$iterations > self::MAX_CATCH_UP_QUARTERS) {
                    throw new RuntimeException('Season catch-up exceeded the safety window.');
                }

                $nextDefinition = $this->calendar->nextDefinition($current);
                $nextSeasonId = (string)$nextDefinition['season_id'];
                $this->ensurePreparationCycle($database, $current, $nextDefinition, $now);
                $readiness = $this->rewardPackage($database, $nextSeasonId, true);

                $boundary = (string)$current['calendar_end_at_utc'];
                $this->beginFinalizing($database, $current, $nextSeasonId, $boundary, $now);

                $next = $this->ensureSeason(
                    $database,
                    $nextDefinition,
                    (string)$nextDefinition['calendar_start_at_utc'],
                    self::SEASON_ACTIVE,
                    $now
                );
                $this->setCurrentSeason($database, $nextSeasonId, $now);

                if ($readiness['package_state'] === self::PACKAGE_READY) {
                    $this->completeFinalization($database, (string)$current['season_id'], $nextSeasonId, $boundary, $now);
                    $summary['boundaries'][] = [
                        'ending_season_id' => (string)$current['season_id'],
                        'target_season_id' => $nextSeasonId,
                        'state' => self::OP_COMPLETED,
                    ];
                } else {
                    $this->blockFinalizationForAssets($database, (string)$current['season_id'], $nextSeasonId, $boundary, $now);
                    $summary['boundaries'][] = [
                        'ending_season_id' => (string)$current['season_id'],
                        'target_season_id' => $nextSeasonId,
                        'state' => self::OP_ASSETS_REQUIRED,
                    ];
                }

                $current = $next;
                $control['current_season_id'] = $nextSeasonId;
                $summary['opened'][] = $nextSeasonId;
            }

            $this->ensurePreparationCycle(
                $database,
                $current,
                $this->calendar->nextDefinition($current),
                $now
            );
            $summary['reminders_due'] = $this->refreshReminders($database, $current, $now);
            $summary['recovered'] = $this->recoverReadyFinalizations($database, $now);
            $summary['current_season_id'] = $control['current_season_id'];

            return $summary + ['status' => 'active'];
        });
    }

    public function updateRewardReadiness(
        string $targetSeasonId,
        array $states,
        ?DateTimeImmutable $now = null
    ): array {
        $now = $this->utcNow($now);
        $this->calendar->parseSeasonId($targetSeasonId);

        return $this->database->transaction(function (DatabaseConnectionInterface $database) use (
            $targetSeasonId,
            $states,
            $now
        ): array {
            $row = $this->rewardPackage($database, $targetSeasonId, true);
            $columns = [
                'seasonal_awards_state' => false,
                'top3_frames_state' => true,
                'yearly_medal_state' => true,
                'localization_state' => false,
                'preview_validation_state' => false,
            ];

            foreach ($columns as $column => $optional) {
                if (!array_key_exists($column, $states)) continue;
                $value = strtolower(trim((string)$states[$column]));
                $allowed = $optional
                    ? [self::CHECK_PENDING, self::CHECK_READY, self::CHECK_NOT_REQUIRED]
                    : [self::CHECK_PENDING, self::CHECK_READY];
                if (!in_array($value, $allowed, true)) {
                    throw new InvalidArgumentException('Invalid reward readiness state for ' . $column . '.');
                }
                $row[$column] = $value;
            }

            $ready = $row['seasonal_awards_state'] === self::CHECK_READY
                && in_array($row['top3_frames_state'], [self::CHECK_READY, self::CHECK_NOT_REQUIRED], true)
                && in_array($row['yearly_medal_state'], [self::CHECK_READY, self::CHECK_NOT_REQUIRED], true)
                && $row['localization_state'] === self::CHECK_READY
                && $row['preview_validation_state'] === self::CHECK_READY;

            $row['package_state'] = $ready ? self::PACKAGE_READY : self::PACKAGE_PENDING;
            $row['ready_at_utc'] = $ready
                ? ($row['ready_at_utc'] ?? $now->format('Y-m-d H:i:s.u'))
                : null;
            $row['updated_at_utc'] = $now->format('Y-m-d H:i:s.u');

            $database->execute(
                'UPDATE mgw_season_reward_packages
                 SET package_state = :package_state,
                     seasonal_awards_state = :seasonal_awards_state,
                     top3_frames_state = :top3_frames_state,
                     yearly_medal_state = :yearly_medal_state,
                     localization_state = :localization_state,
                     preview_validation_state = :preview_validation_state,
                     ready_at_utc = :ready_at_utc,
                     updated_at_utc = :updated_at_utc
                 WHERE target_season_id = :target_season_id',
                [
                    'package_state' => $row['package_state'],
                    'seasonal_awards_state' => $row['seasonal_awards_state'],
                    'top3_frames_state' => $row['top3_frames_state'],
                    'yearly_medal_state' => $row['yearly_medal_state'],
                    'localization_state' => $row['localization_state'],
                    'preview_validation_state' => $row['preview_validation_state'],
                    'ready_at_utc' => $row['ready_at_utc'],
                    'updated_at_utc' => $row['updated_at_utc'],
                    'target_season_id' => $targetSeasonId,
                ]
            );

            return $this->rewardPackage($database, $targetSeasonId, true);
        });
    }

    public function snapshot(?DateTimeImmutable $now = null): array
    {
        $now = $this->utcNow($now);
        $control = $this->controlForUpdate($this->database, false);
        $current = $control['current_season_id'] !== ''
            ? $this->loadSeason($this->database, $control['current_season_id'], false)
            : null;

        return [
            'competition_state' => $control['competition_state'],
            'current_season_id' => $control['current_season_id'],
            'current_season' => $current,
            'pending_finalizations' => $this->database->fetchAll(
                'SELECT ending_season_id, target_season_id, boundary_at_utc,
                        operation_state, block_reason, started_at_utc, completed_at_utc
                 FROM mgw_season_boundary_operations
                 WHERE operation_state <> :completed
                 ORDER BY boundary_at_utc ASC',
                ['completed' => self::OP_COMPLETED]
            ),
            'due_reminders' => $this->database->fetchAll(
                'SELECT ending_season_id, target_season_id, checkpoint_days, due_at_utc,
                        reminder_state, became_due_at_utc, resolved_at_utc
                 FROM mgw_season_preparation_reminders
                 WHERE reminder_state = :state AND due_at_utc <= :now
                 ORDER BY due_at_utc ASC',
                ['state' => self::REMINDER_DUE, 'now' => $now->format('Y-m-d H:i:s.u')]
            ),
        ];
    }

    private function ensurePreparationCycle(
        DatabaseConnectionInterface $database,
        array $endingSeason,
        array $nextDefinition,
        DateTimeImmutable $now
    ): void {
        $targetSeasonId = (string)$nextDefinition['season_id'];
        $this->rewardPackage($database, $targetSeasonId, true);

        foreach ([21, 14, 7] as $days) {
            $params = [
                'ending_season_id' => (string)$endingSeason['season_id'],
                'target_season_id' => $targetSeasonId,
                'checkpoint_days' => $days,
                'due_at_utc' => $this->calendar->reminderDueAt($endingSeason, $days),
                'reminder_state' => self::REMINDER_PENDING,
                'created_at_utc' => $now->format('Y-m-d H:i:s.u'),
                'updated_at_utc' => $now->format('Y-m-d H:i:s.u'),
            ];
            $sql = $database->driver() === 'sqlite'
                ? 'INSERT OR IGNORE INTO mgw_season_preparation_reminders (
                       ending_season_id, target_season_id, checkpoint_days, due_at_utc,
                       reminder_state, became_due_at_utc, resolved_at_utc,
                       created_at_utc, updated_at_utc
                   ) VALUES (
                       :ending_season_id, :target_season_id, :checkpoint_days, :due_at_utc,
                       :reminder_state, NULL, NULL, :created_at_utc, :updated_at_utc
                   )'
                : 'INSERT IGNORE INTO mgw_season_preparation_reminders (
                       ending_season_id, target_season_id, checkpoint_days, due_at_utc,
                       reminder_state, became_due_at_utc, resolved_at_utc,
                       created_at_utc, updated_at_utc
                   ) VALUES (
                       :ending_season_id, :target_season_id, :checkpoint_days, :due_at_utc,
                       :reminder_state, NULL, NULL, :created_at_utc, :updated_at_utc
                   )';
            $database->execute($sql, $params);
        }
    }

    private function refreshReminders(
        DatabaseConnectionInterface $database,
        array $endingSeason,
        DateTimeImmutable $now
    ): array {
        $nextDefinition = $this->calendar->nextDefinition($endingSeason);
        $targetSeasonId = (string)$nextDefinition['season_id'];
        $package = $this->rewardPackage($database, $targetSeasonId, true);
        $boundary = (string)$endingSeason['calendar_end_at_utc'];
        $rows = $database->fetchAll(
            'SELECT ending_season_id, target_season_id, checkpoint_days, due_at_utc,
                    reminder_state, became_due_at_utc, resolved_at_utc
             FROM mgw_season_preparation_reminders
             WHERE ending_season_id = :ending_season_id
             ORDER BY checkpoint_days DESC',
            ['ending_season_id' => (string)$endingSeason['season_id']]
        );

        $due = [];
        $nowText = $now->format('Y-m-d H:i:s.u');
        foreach ($rows as $row) {
            if (!is_array($row)) continue;
            $state = (string)($row['reminder_state'] ?? self::REMINDER_PENDING);
            $newState = $state;
            $becameDue = $row['became_due_at_utc'] ?? null;
            $resolved = $row['resolved_at_utc'] ?? null;

            if ($package['package_state'] === self::PACKAGE_READY) {
                $newState = self::REMINDER_SATISFIED;
                $resolved ??= $nowText;
            } elseif ($this->compare($nowText, $boundary) >= 0) {
                $newState = self::REMINDER_EXPIRED;
                $resolved ??= $nowText;
            } elseif ($this->compare($nowText, (string)$row['due_at_utc']) >= 0) {
                $newState = self::REMINDER_DUE;
                $becameDue ??= $nowText;
                $due[] = [
                    'ending_season_id' => (string)$row['ending_season_id'],
                    'target_season_id' => (string)$row['target_season_id'],
                    'checkpoint_days' => (int)$row['checkpoint_days'],
                    'due_at_utc' => (string)$row['due_at_utc'],
                ];
            }

            if ($newState !== $state || $becameDue !== ($row['became_due_at_utc'] ?? null)
                || $resolved !== ($row['resolved_at_utc'] ?? null)) {
                $database->execute(
                    'UPDATE mgw_season_preparation_reminders
                     SET reminder_state = :state,
                         became_due_at_utc = :became_due,
                         resolved_at_utc = :resolved,
                         updated_at_utc = :updated_at
                     WHERE ending_season_id = :ending_season_id
                       AND checkpoint_days = :checkpoint_days',
                    [
                        'state' => $newState,
                        'became_due' => $becameDue,
                        'resolved' => $resolved,
                        'updated_at' => $nowText,
                        'ending_season_id' => (string)$row['ending_season_id'],
                        'checkpoint_days' => (int)$row['checkpoint_days'],
                    ]
                );
            }
        }

        return $due;
    }

    private function recoverReadyFinalizations(DatabaseConnectionInterface $database, DateTimeImmutable $now): array
    {
        $rows = $database->fetchAll(
            'SELECT ending_season_id, target_season_id, boundary_at_utc
             FROM mgw_season_boundary_operations
             WHERE operation_state = :state
             ORDER BY boundary_at_utc ASC',
            ['state' => self::OP_ASSETS_REQUIRED]
        );

        $recovered = [];
        foreach ($rows as $row) {
            if (!is_array($row)) continue;
            $targetSeasonId = (string)$row['target_season_id'];
            $package = $this->rewardPackage($database, $targetSeasonId, true);
            if ($package['package_state'] !== self::PACKAGE_READY) continue;

            $this->completeFinalization(
                $database,
                (string)$row['ending_season_id'],
                $targetSeasonId,
                (string)$row['boundary_at_utc'],
                $now
            );
            $recovered[] = (string)$row['ending_season_id'];
        }
        return $recovered;
    }

    private function beginFinalizing(
        DatabaseConnectionInterface $database,
        array $endingSeason,
        string $targetSeasonId,
        string $boundary,
        DateTimeImmutable $now
    ): void {
        $nowText = $now->format('Y-m-d H:i:s.u');
        $database->execute(
            'UPDATE mgw_rating_seasons
             SET season_state = :state,
                 standings_frozen_at_utc = COALESCE(standings_frozen_at_utc, :boundary),
                 finalization_started_at_utc = COALESCE(finalization_started_at_utc, :boundary),
                 updated_at_utc = :updated_at
             WHERE season_id = :season_id
               AND season_state <> :closed',
            [
                'state' => self::SEASON_FINALIZING,
                'boundary' => $boundary,
                'updated_at' => $nowText,
                'season_id' => (string)$endingSeason['season_id'],
                'closed' => self::SEASON_CLOSED,
            ]
        );

        $params = [
            'ending_season_id' => (string)$endingSeason['season_id'],
            'target_season_id' => $targetSeasonId,
            'boundary_at_utc' => $boundary,
            'operation_state' => self::OP_FINALIZING,
            'started_at_utc' => $boundary,
            'created_at_utc' => $nowText,
            'updated_at_utc' => $nowText,
        ];
        $sql = $database->driver() === 'sqlite'
            ? 'INSERT OR IGNORE INTO mgw_season_boundary_operations (
                   ending_season_id, target_season_id, boundary_at_utc,
                   operation_state, block_reason, started_at_utc, completed_at_utc,
                   created_at_utc, updated_at_utc
               ) VALUES (
                   :ending_season_id, :target_season_id, :boundary_at_utc,
                   :operation_state, NULL, :started_at_utc, NULL,
                   :created_at_utc, :updated_at_utc
               )'
            : 'INSERT IGNORE INTO mgw_season_boundary_operations (
                   ending_season_id, target_season_id, boundary_at_utc,
                   operation_state, block_reason, started_at_utc, completed_at_utc,
                   created_at_utc, updated_at_utc
               ) VALUES (
                   :ending_season_id, :target_season_id, :boundary_at_utc,
                   :operation_state, NULL, :started_at_utc, NULL,
                   :created_at_utc, :updated_at_utc
               )';
        $database->execute($sql, $params);
    }

    private function blockFinalizationForAssets(
        DatabaseConnectionInterface $database,
        string $endingSeasonId,
        string $targetSeasonId,
        string $boundary,
        DateTimeImmutable $now
    ): void {
        $nowText = $now->format('Y-m-d H:i:s.u');
        $database->execute(
            'UPDATE mgw_rating_seasons
             SET season_state = :state,
                 finalization_reason = :reason,
                 standings_frozen_at_utc = COALESCE(standings_frozen_at_utc, :boundary),
                 finalization_started_at_utc = COALESCE(finalization_started_at_utc, :boundary),
                 updated_at_utc = :updated_at
             WHERE season_id = :season_id',
            [
                'state' => self::SEASON_FINALIZING,
                'reason' => self::OP_ASSETS_REQUIRED,
                'boundary' => $boundary,
                'updated_at' => $nowText,
                'season_id' => $endingSeasonId,
            ]
        );
        $database->execute(
            'UPDATE mgw_season_boundary_operations
             SET target_season_id = :target_season_id,
                 operation_state = :state,
                 block_reason = :reason,
                 updated_at_utc = :updated_at
             WHERE ending_season_id = :ending_season_id',
            [
                'target_season_id' => $targetSeasonId,
                'state' => self::OP_ASSETS_REQUIRED,
                'reason' => self::OP_ASSETS_REQUIRED,
                'updated_at' => $nowText,
                'ending_season_id' => $endingSeasonId,
            ]
        );
    }

    private function completeFinalization(
        DatabaseConnectionInterface $database,
        string $endingSeasonId,
        string $targetSeasonId,
        string $boundary,
        DateTimeImmutable $now
    ): void {
        $nowText = $now->format('Y-m-d H:i:s.u');
        $database->execute(
            'UPDATE mgw_rating_seasons
             SET season_state = :state,
                 finalization_reason = NULL,
                 standings_frozen_at_utc = COALESCE(standings_frozen_at_utc, :boundary),
                 finalization_started_at_utc = COALESCE(finalization_started_at_utc, :boundary),
                 finalized_at_utc = COALESCE(finalized_at_utc, :finalized_at),
                 updated_at_utc = :updated_at
             WHERE season_id = :season_id',
            [
                'state' => self::SEASON_CLOSED,
                'boundary' => $boundary,
                'finalized_at' => $nowText,
                'updated_at' => $nowText,
                'season_id' => $endingSeasonId,
            ]
        );
        $database->execute(
            'UPDATE mgw_season_boundary_operations
             SET target_season_id = :target_season_id,
                 operation_state = :state,
                 block_reason = NULL,
                 completed_at_utc = COALESCE(completed_at_utc, :completed_at),
                 updated_at_utc = :updated_at
             WHERE ending_season_id = :ending_season_id',
            [
                'target_season_id' => $targetSeasonId,
                'state' => self::OP_COMPLETED,
                'completed_at' => $nowText,
                'updated_at' => $nowText,
                'ending_season_id' => $endingSeasonId,
            ]
        );
    }

    private function ensureSeason(
        DatabaseConnectionInterface $database,
        array $definition,
        string $officialStart,
        string $state,
        DateTimeImmutable $now
    ): array {
        $params = [
            'season_id' => (string)$definition['season_id'],
            'calendar_year' => (int)$definition['calendar_year'],
            'quarter' => (int)$definition['quarter'],
            'timezone' => (string)$definition['timezone'],
            'calendar_start_at_utc' => (string)$definition['calendar_start_at_utc'],
            'calendar_end_at_utc' => (string)$definition['calendar_end_at_utc'],
            'official_start_at_utc' => $officialStart,
            'season_state' => $state,
            'created_at_utc' => $now->format('Y-m-d H:i:s.u'),
            'updated_at_utc' => $now->format('Y-m-d H:i:s.u'),
        ];
        $sql = $database->driver() === 'sqlite'
            ? 'INSERT OR IGNORE INTO mgw_rating_seasons (
                   season_id, calendar_year, quarter, timezone,
                   calendar_start_at_utc, calendar_end_at_utc, official_start_at_utc,
                   season_state, finalization_reason, standings_frozen_at_utc,
                   finalization_started_at_utc, finalized_at_utc,
                   created_at_utc, updated_at_utc
               ) VALUES (
                   :season_id, :calendar_year, :quarter, :timezone,
                   :calendar_start_at_utc, :calendar_end_at_utc, :official_start_at_utc,
                   :season_state, NULL, NULL, NULL, NULL,
                   :created_at_utc, :updated_at_utc
               )'
            : 'INSERT IGNORE INTO mgw_rating_seasons (
                   season_id, calendar_year, quarter, timezone,
                   calendar_start_at_utc, calendar_end_at_utc, official_start_at_utc,
                   season_state, finalization_reason, standings_frozen_at_utc,
                   finalization_started_at_utc, finalized_at_utc,
                   created_at_utc, updated_at_utc
               ) VALUES (
                   :season_id, :calendar_year, :quarter, :timezone,
                   :calendar_start_at_utc, :calendar_end_at_utc, :official_start_at_utc,
                   :season_state, NULL, NULL, NULL, NULL,
                   :created_at_utc, :updated_at_utc
               )';
        $database->execute($sql, $params);

        $row = $this->loadSeason($database, (string)$definition['season_id'], true);
        if ($row === null) throw new RuntimeException('Unable to persist rating season.');
        return $row;
    }

    private function rewardPackage(
        DatabaseConnectionInterface $database,
        string $targetSeasonId,
        bool $create
    ): array {
        if ($create) {
            $now = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');
            $params = [
                'target_season_id' => $targetSeasonId,
                'package_state' => self::PACKAGE_PENDING,
                'pending' => self::CHECK_PENDING,
                'created_at_utc' => $now,
                'updated_at_utc' => $now,
            ];
            $sql = $database->driver() === 'sqlite'
                ? 'INSERT OR IGNORE INTO mgw_season_reward_packages (
                       target_season_id, package_state,
                       seasonal_awards_state, top3_frames_state, yearly_medal_state,
                       localization_state, preview_validation_state,
                       ready_at_utc, created_at_utc, updated_at_utc
                   ) VALUES (
                       :target_season_id, :package_state,
                       :pending, :pending, :pending,
                       :pending, :pending,
                       NULL, :created_at_utc, :updated_at_utc
                   )'
                : 'INSERT IGNORE INTO mgw_season_reward_packages (
                       target_season_id, package_state,
                       seasonal_awards_state, top3_frames_state, yearly_medal_state,
                       localization_state, preview_validation_state,
                       ready_at_utc, created_at_utc, updated_at_utc
                   ) VALUES (
                       :target_season_id, :package_state,
                       :pending, :pending, :pending,
                       :pending, :pending,
                       NULL, :created_at_utc, :updated_at_utc
                   )';
            $database->execute($sql, $params);
        }

        $rows = $database->fetchAll(
            'SELECT target_season_id, package_state,
                    seasonal_awards_state, top3_frames_state, yearly_medal_state,
                    localization_state, preview_validation_state,
                    ready_at_utc, created_at_utc, updated_at_utc
             FROM mgw_season_reward_packages
             WHERE target_season_id = :target_season_id',
            ['target_season_id' => $targetSeasonId]
        );
        if (count($rows) !== 1 || !is_array($rows[0])) {
            throw new RuntimeException('Season reward readiness package is unavailable.');
        }
        return $rows[0];
    }

    private function loadSeason(
        DatabaseConnectionInterface $database,
        string $seasonId,
        bool $forUpdate
    ): ?array {
        $seasonId = trim($seasonId);
        if ($seasonId === '' || $seasonId === PerGameRatingService::PRESEASON_ID) return null;

        $sql = 'SELECT season_id, calendar_year, quarter, timezone,
                       calendar_start_at_utc, calendar_end_at_utc, official_start_at_utc,
                       season_state, finalization_reason, standings_frozen_at_utc,
                       finalization_started_at_utc, finalized_at_utc,
                       created_at_utc, updated_at_utc
                FROM mgw_rating_seasons
                WHERE season_id = :season_id';
        if ($forUpdate && $database->driver() !== 'sqlite') $sql .= ' FOR UPDATE';

        $rows = $database->fetchAll($sql, ['season_id' => $seasonId]);
        if ($rows === []) return null;
        return is_array($rows[0]) ? $rows[0] : null;
    }

    private function setCurrentSeason(
        DatabaseConnectionInterface $database,
        string $seasonId,
        DateTimeImmutable $now
    ): void {
        $database->execute(
            'UPDATE mgw_rating_control
             SET current_season_id = :season_id,
                 updated_at_utc = :updated_at
             WHERE control_key = :control_key',
            [
                'season_id' => $seasonId,
                'updated_at' => $now->format('Y-m-d H:i:s.u'),
                'control_key' => self::CONTROL_KEY,
            ]
        );
    }

    private function controlForUpdate(
        DatabaseConnectionInterface $database,
        bool $forUpdate = true
    ): array {
        $sql = 'SELECT competition_state, current_season_id, activated_at_utc
                FROM mgw_rating_control WHERE control_key = :control_key';
        if ($forUpdate && $database->driver() !== 'sqlite') $sql .= ' FOR UPDATE';

        $rows = $database->fetchAll($sql, ['control_key' => self::CONTROL_KEY]);
        if (count($rows) !== 1 || !is_array($rows[0])) {
            throw new RuntimeException('MVP-20 competition control is unavailable.');
        }

        $state = strtolower(trim((string)($rows[0]['competition_state'] ?? '')));
        if (!in_array($state, [
            PerGameRatingService::STATE_OFF,
            PerGameRatingService::STATE_PRESEASON,
            PerGameRatingService::STATE_ACTIVE,
        ], true)) {
            throw new RuntimeException('MVP-20 competition control state is invalid.');
        }

        return [
            'competition_state' => $state,
            'current_season_id' => trim((string)($rows[0]['current_season_id'] ?? '')),
            'activated_at' => $this->nullableText($rows[0]['activated_at_utc'] ?? null),
        ];
    }

    private function laterTimestamp(string $left, string $right): string
    {
        return $this->compare($left, $right) >= 0 ? $left : $right;
    }

    private function compare(string $left, string $right): int
    {
        $leftTs = strtotime($left);
        $rightTs = strtotime($right);
        if ($leftTs === false || $rightTs === false) {
            throw new RuntimeException('MVP-20.4 season timestamp is invalid.');
        }
        return $leftTs <=> $rightTs;
    }

    private function utcNow(?DateTimeImmutable $now): DateTimeImmutable
    {
        return ($now ?? new DateTimeImmutable('now', new DateTimeZone('UTC')))
            ->setTimezone(new DateTimeZone('UTC'));
    }

    private function nullableText(mixed $value): ?string
    {
        $value = trim((string)($value ?? ''));
        return $value === '' ? null : $value;
    }
}
