<?php
declare(strict_types=1);

/**
 * MVP-22.7 canonical Admin operations owner.
 *
 * Owns:
 * - manual/recurring Admin tasks and their owner/status/result;
 * - Future Plans;
 * - release log.
 *
 * Does NOT own quarterly season reminder schedules or reward readiness. Those
 * stay with SeasonLifecycleService; this service only materializes due canonical
 * reminder rows as Admin work items and writes readiness through that owner.
 */
final class AdminOperationsService
{
    public const TASK_STATUSES = ['open', 'in_progress', 'done', 'skipped'];
    public const TASK_RECURRENCES = ['once', 'daily', 'weekly', 'monthly', 'quarterly'];
    public const TASK_CATEGORIES = ['season', 'product', 'engineering', 'operations', 'support', 'content', 'other'];

    public const PLAN_STATUSES = ['idea', 'planned', 'in_progress', 'blocked', 'done', 'cancelled'];
    public const PLAN_CATEGORIES = ['product', 'engineering', 'operations', 'content', 'growth', 'other'];

    public const RELEASE_ENVIRONMENTS = ['staging', 'production'];

    public function __construct(
        private DatabaseConnectionInterface $database,
        private ?SeasonCalendar $calendar = null
    ) {
        $this->calendar ??= new SeasonCalendar();
    }

    public function snapshot(?DateTimeImmutable $now = null): array
    {
        $now = $this->utcNow($now);
        $this->syncSeasonPreparationTasks($now);

        return [
            'generated_at_utc'=>$now->format(DATE_ATOM),
            'tasks'=>[
                'active'=>$this->database->fetchAll(
                    'SELECT task_id,series_id,source_type,source_ref,title,category,recurrence_code,
                            due_at_utc,owner_ref,task_status,result_text,completed_at_utc,created_at_utc,updated_at_utc
                     FROM mgw_admin_tasks
                     WHERE task_status IN (:open_status,:progress_status)
                     ORDER BY CASE WHEN due_at_utc IS NULL THEN 1 ELSE 0 END,
                              due_at_utc ASC, created_at_utc DESC
                     LIMIT 100',
                    ['open_status'=>'open','progress_status'=>'in_progress']
                ),
                'recent_closed'=>$this->database->fetchAll(
                    'SELECT task_id,series_id,source_type,source_ref,title,category,recurrence_code,
                            due_at_utc,owner_ref,task_status,result_text,completed_at_utc,created_at_utc,updated_at_utc
                     FROM mgw_admin_tasks
                     WHERE task_status IN (:done_status,:skipped_status)
                     ORDER BY completed_at_utc DESC, updated_at_utc DESC
                     LIMIT 40',
                    ['done_status'=>'done','skipped_status'=>'skipped']
                ),
            ],
            'season_preparation'=>$this->seasonPreparationSnapshot($now),
            'future_plans'=>$this->database->fetchAll(
                'SELECT plan_id,title,category,plan_status,target_period,owner_ref,notes,created_at_utc,updated_at_utc
                 FROM mgw_admin_future_plans
                 ORDER BY CASE plan_status
                    WHEN :in_progress THEN 0
                    WHEN :blocked THEN 1
                    WHEN :planned THEN 2
                    WHEN :idea THEN 3
                    WHEN :done THEN 4
                    ELSE 5 END,
                    updated_at_utc DESC
                 LIMIT 120',
                [
                    'in_progress'=>'in_progress',
                    'blocked'=>'blocked',
                    'planned'=>'planned',
                    'idea'=>'idea',
                    'done'=>'done',
                ]
            ),
            'release_log'=>$this->database->fetchAll(
                'SELECT release_id,version_label,environment,release_sha,released_at_utc,
                        summary_text,known_issues_text,rollback_link,created_by_ref,created_at_utc,updated_at_utc
                 FROM mgw_admin_release_log
                 ORDER BY released_at_utc DESC, created_at_utc DESC
                 LIMIT 80'
            ),
            'recent_audit'=>$this->database->fetchAll(
                'SELECT audit_id,entity_type,entity_id,action_code,actor_ref,created_at_utc
                 FROM mgw_admin_operations_audit
                 ORDER BY audit_id DESC
                 LIMIT 50'
            ),
            'coverage'=>[
                'release_history'=>'Журнал релизов начинается с MVP-22.7. Старые релизы не восстанавливаются задним числом.',
                'season_schedule'=>'Контрольные точки T-21 / T-14 / T-7 читаются из существующего владельца сезонного календаря и не дублируются отдельным планировщиком.',
                'cron'=>'Регулярная задача создаёт следующую итерацию при завершении текущей. Срок задачи обрабатывается единым напоминанием без повторного спама по просрочке.',
            ],
        ];
    }

    public function createTask(array $input, string $actorRef, ?DateTimeImmutable $now = null): array
    {
        $now = $this->utcNow($now);
        $actorRef = $this->requiredText($actorRef, 191, 'actor');
        $title = $this->requiredText((string)($input['title'] ?? ''), 240, 'Задача');
        $category = $this->enum((string)($input['category'] ?? 'operations'), self::TASK_CATEGORIES, 'Категория');
        $recurrence = $this->enum((string)($input['recurrence_code'] ?? 'once'), self::TASK_RECURRENCES, 'Повтор');
        $dueAt = $this->nullableUtc($input['due_at_utc'] ?? null);
        if ($recurrence !== 'once' && $dueAt === null) {
            throw new InvalidArgumentException('Для регулярной задачи нужно указать ближайший срок.');
        }
        $owner = $this->nullableText((string)($input['owner_ref'] ?? ''), 191);

        $taskId = $this->id('task');
        $seriesId = $this->id('series');
        $nowText = $this->sqlTime($now);
        $row = [
            'task_id'=>$taskId,
            'series_id'=>$seriesId,
            'source_type'=>'manual',
            'source_ref'=>$taskId,
            'title'=>$title,
            'category'=>$category,
            'recurrence_code'=>$recurrence,
            'due_at_utc'=>$dueAt,
            'owner_ref'=>$owner,
            'task_status'=>'open',
            'result_text'=>null,
            'created_by_ref'=>$actorRef,
            'completed_at_utc'=>null,
            'created_at_utc'=>$nowText,
            'updated_at_utc'=>$nowText,
        ];

        $this->database->transaction(function (DatabaseConnectionInterface $database) use ($row, $actorRef, $nowText): void {
            $this->insertTask($database, $row);
            $this->audit($database, 'task', $row['task_id'], 'created', $actorRef, null, $row, $nowText);
        });
        return $this->task($taskId);
    }

    public function updateTask(
        string $taskId,
        array $input,
        string $actorRef,
        ?DateTimeImmutable $now = null
    ): array {
        $now = $this->utcNow($now);
        $actorRef = $this->requiredText($actorRef, 191, 'actor');
        $taskId = $this->token($taskId, 64, 'идентификатор задачи');
        $nowText = $this->sqlTime($now);

        return $this->database->transaction(function (DatabaseConnectionInterface $database) use (
            $taskId,
            $input,
            $actorRef,
            $now,
            $nowText
        ): array {
            $before = $this->task($taskId, true);
            $status = array_key_exists('task_status', $input)
                ? $this->enum((string)$input['task_status'], self::TASK_STATUSES, 'Статус')
                : (string)$before['task_status'];
            $owner = array_key_exists('owner_ref', $input)
                ? $this->nullableText((string)$input['owner_ref'], 191)
                : $before['owner_ref'];
            $result = array_key_exists('result_text', $input)
                ? $this->nullableText((string)$input['result_text'], 3000)
                : $before['result_text'];

            $completedAt = in_array($status, ['done','skipped'], true)
                ? ($before['completed_at_utc'] ?? $nowText)
                : null;

            $database->execute(
                'UPDATE mgw_admin_tasks
                 SET owner_ref=:owner_ref,
                     task_status=:task_status,
                     result_text=:result_text,
                     completed_at_utc=:completed_at,
                     updated_at_utc=:updated_at
                 WHERE task_id=:task_id',
                [
                    'owner_ref'=>$owner,
                    'task_status'=>$status,
                    'result_text'=>$result,
                    'completed_at'=>$completedAt,
                    'updated_at'=>$nowText,
                    'task_id'=>$taskId,
                ]
            );

            $after = $this->task($taskId, true);
            $this->audit($database, 'task', $taskId, 'updated', $actorRef, $before, $after, $nowText);

            if (in_array((string)$before['source_type'], ['manual','manual_recurring'], true)
                && (string)$before['task_status'] !== $status
                && in_array($status, ['done','skipped'], true)
                && (string)$before['recurrence_code'] !== 'once') {
                $this->ensureNextRecurringTask($database, $after, $actorRef, $now);
            }

            return $after;
        });
    }

    public function dueTaskReminderCandidates(?DateTimeImmutable $now = null, int $limit = 50): array
    {
        $now = $this->utcNow($now);
        $limit = max(1, min(200, $limit));

        return $this->database->fetchAll(
            'SELECT t.task_id,t.series_id,t.title,t.category,t.recurrence_code,t.due_at_utc,
                    t.owner_ref,t.task_status,t.created_by_ref
             FROM mgw_admin_tasks t
             WHERE t.task_status IN (\'open\',\'in_progress\')
               AND t.due_at_utc IS NOT NULL
               AND t.due_at_utc <= :due_before
               AND t.created_by_ref LIKE :telegram_actor
               AND NOT EXISTS (
                    SELECT 1
                    FROM mgw_admin_operations_audit a
                    WHERE a.entity_type = :entity_type
                      AND a.entity_id = t.task_id
                      AND a.action_code = :action_code
               )
             ORDER BY t.due_at_utc ASC,t.created_at_utc ASC
             LIMIT ' . $limit,
            [
                'due_before'=>$this->sqlTime($now),
                'telegram_actor'=>'telegram:%',
                'entity_type'=>'task',
                'action_code'=>'due_reminder_sent',
            ]
        );
    }

    public function markTaskReminderSent(
        string $taskId,
        array $delivery,
        ?DateTimeImmutable $now = null
    ): bool {
        $now = $this->utcNow($now);
        $taskId = $this->token($taskId, 64, 'идентификатор задачи');
        $nowText = $this->sqlTime($now);

        return $this->database->transaction(function (DatabaseConnectionInterface $database) use (
            $taskId,
            $delivery,
            $nowText
        ): bool {
            $existing = (int)$database->fetchValue(
                'SELECT COUNT(*)
                 FROM mgw_admin_operations_audit
                 WHERE entity_type=:entity_type
                   AND entity_id=:entity_id
                   AND action_code=:action_code',
                [
                    'entity_type'=>'task',
                    'entity_id'=>$taskId,
                    'action_code'=>'due_reminder_sent',
                ]
            );
            if ($existing > 0) return false;

            $task = $this->task($taskId, true);
            if (!in_array((string)$task['task_status'], ['open','in_progress'], true)) return false;

            $this->audit(
                $database,
                'task',
                $taskId,
                'due_reminder_sent',
                'system:admin-task-reminder',
                null,
                $delivery,
                $nowText
            );
            return true;
        });
    }

    public function createPlan(array $input, string $actorRef, ?DateTimeImmutable $now = null): array
    {
        $now = $this->utcNow($now);
        $actorRef = $this->requiredText($actorRef, 191, 'actor');
        $planId = $this->id('plan');
        $row = [
            'plan_id'=>$planId,
            'title'=>$this->requiredText((string)($input['title'] ?? ''), 240, 'План'),
            'category'=>$this->enum((string)($input['category'] ?? 'product'), self::PLAN_CATEGORIES, 'Категория'),
            'plan_status'=>$this->enum((string)($input['plan_status'] ?? 'idea'), self::PLAN_STATUSES, 'Статус'),
            'target_period'=>$this->nullableText((string)($input['target_period'] ?? ''), 120),
            'owner_ref'=>$this->nullableText((string)($input['owner_ref'] ?? ''), 191),
            'notes'=>$this->nullableText((string)($input['notes'] ?? ''), 5000),
            'created_by_ref'=>$actorRef,
            'created_at_utc'=>$this->sqlTime($now),
            'updated_at_utc'=>$this->sqlTime($now),
        ];
        $this->database->transaction(function (DatabaseConnectionInterface $database) use ($row, $actorRef): void {
            $database->execute(
                'INSERT INTO mgw_admin_future_plans (
                    plan_id,title,category,plan_status,target_period,owner_ref,notes,
                    created_by_ref,created_at_utc,updated_at_utc
                 ) VALUES (
                    :plan_id,:title,:category,:plan_status,:target_period,:owner_ref,:notes,
                    :created_by_ref,:created_at_utc,:updated_at_utc
                 )',
                $row
            );
            $this->audit($database, 'future_plan', $row['plan_id'], 'created', $actorRef, null, $row, $row['created_at_utc']);
        });
        return $this->plan($planId);
    }

    public function updatePlan(
        string $planId,
        array $input,
        string $actorRef,
        ?DateTimeImmutable $now = null
    ): array {
        $now = $this->utcNow($now);
        $actorRef = $this->requiredText($actorRef, 191, 'actor');
        $planId = $this->token($planId, 64, 'идентификатор плана');
        $nowText = $this->sqlTime($now);

        return $this->database->transaction(function (DatabaseConnectionInterface $database) use (
            $planId,
            $input,
            $actorRef,
            $nowText
        ): array {
            $before = $this->plan($planId, true);
            $after = [
                'title'=>array_key_exists('title', $input)
                    ? $this->requiredText((string)$input['title'], 240, 'План')
                    : (string)$before['title'],
                'category'=>array_key_exists('category', $input)
                    ? $this->enum((string)$input['category'], self::PLAN_CATEGORIES, 'Категория')
                    : (string)$before['category'],
                'plan_status'=>array_key_exists('plan_status', $input)
                    ? $this->enum((string)$input['plan_status'], self::PLAN_STATUSES, 'Статус')
                    : (string)$before['plan_status'],
                'target_period'=>array_key_exists('target_period', $input)
                    ? $this->nullableText((string)$input['target_period'], 120)
                    : $before['target_period'],
                'owner_ref'=>array_key_exists('owner_ref', $input)
                    ? $this->nullableText((string)$input['owner_ref'], 191)
                    : $before['owner_ref'],
                'notes'=>array_key_exists('notes', $input)
                    ? $this->nullableText((string)$input['notes'], 5000)
                    : $before['notes'],
            ];

            $database->execute(
                'UPDATE mgw_admin_future_plans
                 SET title=:title,category=:category,plan_status=:plan_status,
                     target_period=:target_period,owner_ref=:owner_ref,notes=:notes,
                     updated_at_utc=:updated_at
                 WHERE plan_id=:plan_id',
                $after + ['updated_at'=>$nowText,'plan_id'=>$planId]
            );
            $current = $this->plan($planId, true);
            $this->audit($database, 'future_plan', $planId, 'updated', $actorRef, $before, $current, $nowText);
            return $current;
        });
    }

    public function createRelease(array $input, string $actorRef, ?DateTimeImmutable $now = null): array
    {
        $now = $this->utcNow($now);
        $actorRef = $this->requiredText($actorRef, 191, 'actor');
        $releaseId = $this->id('release');
        $environment = $this->enum((string)($input['environment'] ?? 'staging'), self::RELEASE_ENVIRONMENTS, 'Среда');
        $versionLabel = $this->requiredText((string)($input['version_label'] ?? ''), 80, 'Версия');
        $existingRelease = $this->database->fetchValue(
            'SELECT COUNT(*) FROM mgw_admin_release_log
             WHERE environment=:environment AND version_label=:version_label',
            ['environment'=>$environment,'version_label'=>$versionLabel]
        );
        if ((int)$existingRelease > 0) {
            throw new InvalidArgumentException('Такая версия уже есть в журнале для выбранной среды.');
        }

        $row = [
            'release_id'=>$releaseId,
            'version_label'=>$versionLabel,
            'environment'=>$environment,
            'release_sha'=>$this->sha((string)($input['release_sha'] ?? '')),
            'released_at_utc'=>$this->requiredUtc($input['released_at_utc'] ?? $now->format(DATE_ATOM)),
            'summary_text'=>$this->requiredText((string)($input['summary_text'] ?? ''), 5000, 'Что вошло'),
            'known_issues_text'=>$this->nullableText((string)($input['known_issues_text'] ?? ''), 5000),
            'rollback_link'=>$this->rollbackLink((string)($input['rollback_link'] ?? '')),
            'created_by_ref'=>$actorRef,
            'created_at_utc'=>$this->sqlTime($now),
            'updated_at_utc'=>$this->sqlTime($now),
        ];
        $this->database->transaction(function (DatabaseConnectionInterface $database) use ($row, $actorRef): void {
            $database->execute(
                'INSERT INTO mgw_admin_release_log (
                    release_id,version_label,environment,release_sha,released_at_utc,
                    summary_text,known_issues_text,rollback_link,created_by_ref,created_at_utc,updated_at_utc
                 ) VALUES (
                    :release_id,:version_label,:environment,:release_sha,:released_at_utc,
                    :summary_text,:known_issues_text,:rollback_link,:created_by_ref,:created_at_utc,:updated_at_utc
                 )',
                $row
            );
            $this->audit($database, 'release', $row['release_id'], 'created', $actorRef, null, $row, $row['created_at_utc']);
        });
        return $this->release($releaseId);
    }

    public function updateRelease(
        string $releaseId,
        array $input,
        string $actorRef,
        ?DateTimeImmutable $now = null
    ): array {
        $now = $this->utcNow($now);
        $actorRef = $this->requiredText($actorRef, 191, 'actor');
        $releaseId = $this->token($releaseId, 64, 'идентификатор релиза');
        $nowText = $this->sqlTime($now);

        return $this->database->transaction(function (DatabaseConnectionInterface $database) use (
            $releaseId,
            $input,
            $actorRef,
            $nowText
        ): array {
            $before = $this->release($releaseId, true);
            $summary = array_key_exists('summary_text', $input)
                ? $this->requiredText((string)$input['summary_text'], 5000, 'Что вошло')
                : (string)$before['summary_text'];
            $issues = array_key_exists('known_issues_text', $input)
                ? $this->nullableText((string)$input['known_issues_text'], 5000)
                : $before['known_issues_text'];
            $rollback = array_key_exists('rollback_link', $input)
                ? $this->rollbackLink((string)$input['rollback_link'])
                : $before['rollback_link'];

            $database->execute(
                'UPDATE mgw_admin_release_log
                 SET summary_text=:summary_text,
                     known_issues_text=:known_issues_text,
                     rollback_link=:rollback_link,
                     updated_at_utc=:updated_at
                 WHERE release_id=:release_id',
                [
                    'summary_text'=>$summary,
                    'known_issues_text'=>$issues,
                    'rollback_link'=>$rollback,
                    'updated_at'=>$nowText,
                    'release_id'=>$releaseId,
                ]
            );
            $current = $this->release($releaseId, true);
            $this->audit($database, 'release', $releaseId, 'updated', $actorRef, $before, $current, $nowText);
            return $current;
        });
    }

    public function updateSeasonReadiness(
        string $targetSeasonId,
        array $states,
        string $actorRef,
        ?DateTimeImmutable $now = null
    ): array {
        $now = $this->utcNow($now);
        $actorRef = $this->requiredText($actorRef, 191, 'actor');
        $snapshot = $this->seasonPreparationSnapshot($now);
        if (($snapshot['enabled'] ?? false) !== true) {
            throw new RuntimeException('Подготовка следующего сезона доступна только при активных официальных сезонах.');
        }
        if ((string)($snapshot['target_season_id'] ?? '') !== strtolower(trim($targetSeasonId))) {
            throw new InvalidArgumentException('Можно обновлять только пакет следующего текущего сезона.');
        }

        $before = is_array($snapshot['reward_package'] ?? null) ? $snapshot['reward_package'] : [];
        $after = (new SeasonLifecycleService($this->database, $this->calendar))
            ->updateRewardReadiness($targetSeasonId, $states, $now);
        $this->audit(
            $this->database,
            'season_package',
            $targetSeasonId,
            'readiness_updated',
            $actorRef,
            $before,
            $after,
            $this->sqlTime($now)
        );
        $this->syncSeasonPreparationTasks($now);
        return $this->seasonPreparationSnapshot($now);
    }

    private function syncSeasonPreparationTasks(DateTimeImmutable $now): void
    {
        $controlRows = $this->database->fetchAll(
            'SELECT competition_state FROM mgw_rating_control WHERE control_key=:control_key',
            ['control_key'=>'global']
        );
        if (strtolower(trim((string)($controlRows[0]['competition_state'] ?? ''))) !== PerGameRatingService::STATE_ACTIVE) {
            return;
        }

        $rows = $this->database->fetchAll(
            'SELECT r.ending_season_id,r.target_season_id,r.checkpoint_days,r.due_at_utc,
                    s.calendar_end_at_utc,
                    p.package_state
             FROM mgw_season_preparation_reminders r
             JOIN mgw_rating_seasons s ON s.season_id=r.ending_season_id
             LEFT JOIN mgw_season_reward_packages p ON p.target_season_id=r.target_season_id
             ORDER BY r.due_at_utc ASC'
        );
        $nowText = $this->sqlTime($now);

        foreach ($rows as $row) {
            if (!is_array($row)) continue;
            $ending = (string)($row['ending_season_id'] ?? '');
            $target = (string)($row['target_season_id'] ?? '');
            $checkpoint = (int)($row['checkpoint_days'] ?? 0);
            if ($ending === '' || $target === '' || !in_array($checkpoint, [21,14,7], true)) continue;

            $sourceRef = $ending . ':' . $target . ':t-' . $checkpoint;
            $existing = $this->taskBySource('season_preparation', $sourceRef);
            $packageReady = strtolower(trim((string)($row['package_state'] ?? ''))) === SeasonLifecycleService::PACKAGE_READY;
            $boundaryPassed = strcmp($nowText, (string)($row['calendar_end_at_utc'] ?? '')) >= 0;

            if ($packageReady || $boundaryPassed) {
                if (is_array($existing) && in_array((string)$existing['task_status'], ['open','in_progress'], true)) {
                    $status = $packageReady ? 'done' : 'skipped';
                    $result = $packageReady
                        ? 'Пакет наград следующего сезона переведён в состояние «Готово».'
                        : 'Граница сезона пройдена; дальнейшее состояние контролирует сезонная финализация.';
                    $this->database->execute(
                        'UPDATE mgw_admin_tasks
                         SET task_status=:status,result_text=:result_text,
                             completed_at_utc=:completed_at,updated_at_utc=:updated_at
                         WHERE task_id=:task_id',
                        [
                            'status'=>$status,
                            'result_text'=>$result,
                            'completed_at'=>$nowText,
                            'updated_at'=>$nowText,
                            'task_id'=>(string)$existing['task_id'],
                        ]
                    );
                }
                continue;
            }

            if (strcmp($nowText, (string)($row['due_at_utc'] ?? '')) < 0 || is_array($existing)) continue;

            $taskId = $this->id('task');
            $task = [
                'task_id'=>$taskId,
                'series_id'=>'season_' . substr(hash('sha256', $ending . '|' . $target), 0, 32),
                'source_type'=>'season_preparation',
                'source_ref'=>$sourceRef,
                'title'=>$this->seasonReminderTitle($checkpoint),
                'category'=>'season',
                'recurrence_code'=>'season_checkpoint',
                'due_at_utc'=>(string)$row['due_at_utc'],
                'owner_ref'=>null,
                'task_status'=>'open',
                'result_text'=>null,
                'created_by_ref'=>'system:season-lifecycle',
                'completed_at_utc'=>null,
                'created_at_utc'=>$nowText,
                'updated_at_utc'=>$nowText,
            ];
            $this->insertTask($this->database, $task);
            $this->audit(
                $this->database,
                'task',
                $taskId,
                'season_reminder_materialized',
                'system:season-lifecycle',
                null,
                $task,
                $nowText
            );
        }
    }

    private function seasonPreparationSnapshot(DateTimeImmutable $now): array
    {
        $controlRows = $this->database->fetchAll(
            'SELECT competition_state,current_season_id
             FROM mgw_rating_control
             WHERE control_key=:control_key',
            ['control_key'=>'global']
        );
        $competition = strtolower(trim((string)($controlRows[0]['competition_state'] ?? 'off')));
        $currentSeasonId = strtolower(trim((string)($controlRows[0]['current_season_id'] ?? '')));
        if ($competition !== PerGameRatingService::STATE_ACTIVE || $currentSeasonId === '' || $currentSeasonId === PerGameRatingService::PRESEASON_ID) {
            return [
                'enabled'=>false,
                'competition_state'=>$competition,
                'current_season_id'=>$currentSeasonId,
                'message'=>'Регулярная подготовка следующего сезона включится после запуска официальных сезонов.',
            ];
        }

        $seasonRows = $this->database->fetchAll(
            'SELECT season_id,calendar_year,quarter,timezone,calendar_start_at_utc,
                    calendar_end_at_utc,official_start_at_utc,season_state
             FROM mgw_rating_seasons
             WHERE season_id=:season_id',
            ['season_id'=>$currentSeasonId]
        );
        if (!is_array($seasonRows[0] ?? null)) {
            return [
                'enabled'=>false,
                'competition_state'=>$competition,
                'current_season_id'=>$currentSeasonId,
                'message'=>'Текущий официальный сезон пока не материализован.',
            ];
        }
        $current = $seasonRows[0];
        $next = $this->calendar->nextDefinition($current);
        $target = (string)$next['season_id'];
        $packageRows = $this->database->fetchAll(
            'SELECT target_season_id,package_state,seasonal_awards_state,top3_frames_state,
                    yearly_medal_state,localization_state,preview_validation_state,
                    ready_at_utc,updated_at_utc
             FROM mgw_season_reward_packages
             WHERE target_season_id=:target',
            ['target'=>$target]
        );
        $package = is_array($packageRows[0] ?? null) ? $packageRows[0] : null;
        $reminders = $this->database->fetchAll(
            'SELECT checkpoint_days,due_at_utc,reminder_state,became_due_at_utc,resolved_at_utc
             FROM mgw_season_preparation_reminders
             WHERE ending_season_id=:ending
             ORDER BY checkpoint_days DESC',
            ['ending'=>$currentSeasonId]
        );

        return [
            'enabled'=>true,
            'competition_state'=>$competition,
            'current_season_id'=>$currentSeasonId,
            'target_season_id'=>$target,
            'target_timezone'=>(string)$next['timezone'],
            'target_start_at_utc'=>(string)$next['calendar_start_at_utc'],
            'target_end_at_utc'=>(string)$next['calendar_end_at_utc'],
            'current_end_at_utc'=>(string)$current['calendar_end_at_utc'],
            'reward_package'=>$package,
            'reminders'=>$reminders,
            'ready'=>is_array($package)
                && strtolower((string)($package['package_state'] ?? '')) === SeasonLifecycleService::PACKAGE_READY,
        ];
    }

    private function ensureNextRecurringTask(
        DatabaseConnectionInterface $database,
        array $closedTask,
        string $actorRef,
        DateTimeImmutable $now
    ): void {
        $recurrence = (string)$closedTask['recurrence_code'];
        if (!in_array($recurrence, self::TASK_RECURRENCES, true) || $recurrence === 'once') return;

        $base = $closedTask['due_at_utc'] !== null
            ? $this->calendar->utc((string)$closedTask['due_at_utc'])
            : $now;
        $nextDue = match ($recurrence) {
            'daily'=>$base->modify('+1 day'),
            'weekly'=>$base->modify('+7 days'),
            'monthly'=>$base->modify('+1 month'),
            'quarterly'=>$base->modify('+3 months'),
            default=>$base,
        };
        while ($nextDue <= $now) {
            $nextDue = match ($recurrence) {
                'daily'=>$nextDue->modify('+1 day'),
                'weekly'=>$nextDue->modify('+7 days'),
                'monthly'=>$nextDue->modify('+1 month'),
                'quarterly'=>$nextDue->modify('+3 months'),
                default=>$nextDue,
            };
        }

        $sourceRef = 'recurring:' . (string)$closedTask['series_id'] . ':' . $nextDue->format('YmdHis');
        if (is_array($this->taskBySource('manual_recurring', $sourceRef))) return;

        $nowText = $this->sqlTime($now);
        $next = [
            'task_id'=>$this->id('task'),
            'series_id'=>(string)$closedTask['series_id'],
            'source_type'=>'manual_recurring',
            'source_ref'=>$sourceRef,
            'title'=>(string)$closedTask['title'],
            'category'=>(string)$closedTask['category'],
            'recurrence_code'=>$recurrence,
            'due_at_utc'=>$this->sqlTime($nextDue),
            'owner_ref'=>$closedTask['owner_ref'],
            'task_status'=>'open',
            'result_text'=>null,
            'created_by_ref'=>$actorRef,
            'completed_at_utc'=>null,
            'created_at_utc'=>$nowText,
            'updated_at_utc'=>$nowText,
        ];
        $this->insertTask($database, $next);
        $this->audit($database, 'task', $next['task_id'], 'recurrence_created', $actorRef, null, $next, $nowText);
    }

    private function insertTask(DatabaseConnectionInterface $database, array $row): void
    {
        $database->execute(
            'INSERT INTO mgw_admin_tasks (
                task_id,series_id,source_type,source_ref,title,category,recurrence_code,
                due_at_utc,owner_ref,task_status,result_text,created_by_ref,
                completed_at_utc,created_at_utc,updated_at_utc
             ) VALUES (
                :task_id,:series_id,:source_type,:source_ref,:title,:category,:recurrence_code,
                :due_at_utc,:owner_ref,:task_status,:result_text,:created_by_ref,
                :completed_at_utc,:created_at_utc,:updated_at_utc
             )',
            $row
        );
    }

    private function task(string $taskId, bool $forUpdate = false): array
    {
        $sql = 'SELECT task_id,series_id,source_type,source_ref,title,category,recurrence_code,
                       due_at_utc,owner_ref,task_status,result_text,created_by_ref,
                       completed_at_utc,created_at_utc,updated_at_utc
                FROM mgw_admin_tasks WHERE task_id=:task_id';
        if ($forUpdate && $this->database->driver() !== 'sqlite') $sql .= ' FOR UPDATE';
        $rows = $this->database->fetchAll($sql, ['task_id'=>$taskId]);
        if (!is_array($rows[0] ?? null)) throw new InvalidArgumentException('Задача не найдена.');
        return $rows[0];
    }

    private function taskBySource(string $sourceType, string $sourceRef): ?array
    {
        $rows = $this->database->fetchAll(
            'SELECT task_id,series_id,source_type,source_ref,title,category,recurrence_code,
                    due_at_utc,owner_ref,task_status,result_text,created_by_ref,
                    completed_at_utc,created_at_utc,updated_at_utc
             FROM mgw_admin_tasks
             WHERE source_type=:source_type AND source_ref=:source_ref',
            ['source_type'=>$sourceType,'source_ref'=>$sourceRef]
        );
        return is_array($rows[0] ?? null) ? $rows[0] : null;
    }

    private function plan(string $planId, bool $forUpdate = false): array
    {
        $sql = 'SELECT plan_id,title,category,plan_status,target_period,owner_ref,notes,
                       created_by_ref,created_at_utc,updated_at_utc
                FROM mgw_admin_future_plans WHERE plan_id=:plan_id';
        if ($forUpdate && $this->database->driver() !== 'sqlite') $sql .= ' FOR UPDATE';
        $rows = $this->database->fetchAll($sql, ['plan_id'=>$planId]);
        if (!is_array($rows[0] ?? null)) throw new InvalidArgumentException('План не найден.');
        return $rows[0];
    }

    private function release(string $releaseId, bool $forUpdate = false): array
    {
        $sql = 'SELECT release_id,version_label,environment,release_sha,released_at_utc,
                       summary_text,known_issues_text,rollback_link,created_by_ref,created_at_utc,updated_at_utc
                FROM mgw_admin_release_log WHERE release_id=:release_id';
        if ($forUpdate && $this->database->driver() !== 'sqlite') $sql .= ' FOR UPDATE';
        $rows = $this->database->fetchAll($sql, ['release_id'=>$releaseId]);
        if (!is_array($rows[0] ?? null)) throw new InvalidArgumentException('Релиз не найден.');
        return $rows[0];
    }

    private function seasonReminderTitle(int $checkpoint): string
    {
        return match ($checkpoint) {
            21=>'Сезон заканчивается через 3 недели — подготовьте награды следующего сезона',
            14=>'До конца сезона 2 недели — пакет наград следующего сезона ещё не готов',
            7=>'До конца сезона 1 неделя — требуется завершить пакет наград следующего сезона',
            default=>'Подготовьте пакет наград следующего сезона',
        };
    }

    private function audit(
        DatabaseConnectionInterface $database,
        string $entityType,
        string $entityId,
        string $action,
        string $actorRef,
        ?array $before,
        ?array $after,
        string $createdAt
    ): void {
        $database->execute(
            'INSERT INTO mgw_admin_operations_audit (
                entity_type,entity_id,action_code,actor_ref,before_json,after_json,created_at_utc
             ) VALUES (
                :entity_type,:entity_id,:action_code,:actor_ref,:before_json,:after_json,:created_at
             )',
            [
                'entity_type'=>$entityType,
                'entity_id'=>$entityId,
                'action_code'=>$action,
                'actor_ref'=>$actorRef,
                'before_json'=>$before === null ? null : json_encode($before, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                'after_json'=>$after === null ? null : json_encode($after, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                'created_at'=>$createdAt,
            ]
        );
    }

    private function rollbackLink(string $value): ?string
    {
        $value = trim($value);
        if ($value === '') return null;
        if (strlen($value) > 500
            || !str_starts_with($value, 'https://github.com/manufact-test/mini-games-world/')) {
            throw new InvalidArgumentException('Ссылка отката должна вести в репозиторий MINI GAMES WORLD на GitHub.');
        }
        return $value;
    }

    private function sha(string $value): string
    {
        $value = strtolower(trim($value));
        if (preg_match('/^[a-f0-9]{40}$/', $value) !== 1) {
            throw new InvalidArgumentException('SHA релиза должен содержать 40 шестнадцатеричных символов.');
        }
        return $value;
    }

    private function enum(string $value, array $allowed, string $label): string
    {
        $value = strtolower(trim($value));
        if (!in_array($value, $allowed, true)) {
            throw new InvalidArgumentException('Некорректное значение: ' . $label . '.');
        }
        return $value;
    }

    private function token(string $value, int $max, string $label): string
    {
        $value = strtolower(trim($value));
        if ($value === '' || strlen($value) > $max || preg_match('/^[a-z0-9][a-z0-9_.:-]*$/', $value) !== 1) {
            throw new InvalidArgumentException('Некорректное значение: ' . $label . '.');
        }
        return $value;
    }

    private function id(string $prefix): string
    {
        return $prefix . '_' . bin2hex(random_bytes(12));
    }

    private function requiredText(string $value, int $max, string $label): string
    {
        $value = $this->cleanText($value, $max);
        if ($value === '') throw new InvalidArgumentException('Поле «' . $label . '» обязательно.');
        return $value;
    }

    private function nullableText(string $value, int $max): ?string
    {
        $value = $this->cleanText($value, $max);
        return $value === '' ? null : $value;
    }

    private function cleanText(string $value, int $max): string
    {
        $value = trim(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', ' ', $value) ?? '');
        return function_exists('mb_substr') ? mb_substr($value, 0, $max) : substr($value, 0, $max);
    }

    private function nullableUtc(mixed $value): ?string
    {
        $value = trim((string)($value ?? ''));
        return $value === '' ? null : $this->requiredUtc($value);
    }

    private function requiredUtc(mixed $value): string
    {
        $value = trim((string)$value);
        if ($value === '') throw new InvalidArgumentException('Дата и время обязательны.');
        try {
            return $this->sqlTime((new DateTimeImmutable($value))->setTimezone(new DateTimeZone('UTC')));
        } catch (Throwable) {
            throw new InvalidArgumentException('Некорректная дата или время.');
        }
    }

    private function utcNow(?DateTimeImmutable $now): DateTimeImmutable
    {
        return ($now ?? new DateTimeImmutable('now', new DateTimeZone('UTC')))
            ->setTimezone(new DateTimeZone('UTC'));
    }

    private function sqlTime(DateTimeImmutable $value): string
    {
        return $value->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
    }
}
