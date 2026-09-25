<?php
declare(strict_types=1);

final class ModerationException extends RuntimeException
{
    public function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }
}

final class ModerationService
{
    public const REPORT_REASONS = [
        'nickname' => 'Недопустимый никнейм',
        'avatar' => 'Недопустимый аватар',
        'spam' => 'Спам',
        'cheating' => 'Нечестная игра',
        'stalling' => 'Затягивание игры',
        'other' => 'Другое',
    ];

    public const LEGACY_REPORT_REASONS = [
        'abuse' => 'Оскорбления или травля',
        'offensive_profile' => 'Недопустимый профиль',
    ];

    public const RESTRICTION_SCOPES = [
        'profile' => 'Изменение профиля',
        'social' => 'Друзья и социальные действия',
        'gameplay' => 'Новые игры и матчи',
        'all' => 'Все игровые действия',
    ];

    public const RESTRICTION_DURATIONS = [
        3600 => '1 час',
        86400 => '24 часа',
        604800 => '7 дней',
        2592000 => '30 дней',
    ];

    private const ACTION_WARNING = 'warning';
    private const ACTION_RESTRICTION = 'restriction';
    private const ACTION_PERMANENT_BAN = 'permanent_ban';
    private const STATUS_ACTIVE = 'active';
    private const STATUS_PENDING_SECOND_REVIEW = 'pending_second_review';
    private const STATUS_CONFIRMED = 'confirmed';
    private const STATUS_REJECTED = 'rejected';
    private const STATUS_REVOKED = 'revoked';
    private const STATUS_EXPIRED = 'expired';

    public function __construct(private DatabaseConnectionInterface $database) {}

    public static function reportReasonLabel(string $reason): string
    {
        return self::REPORT_REASONS[$reason]
            ?? self::LEGACY_REPORT_REASONS[$reason]
            ?? $reason;
    }

    public function warning(string $reportId, string $note, string $adminRef): array
    {
        $report = $this->report($reportId);
        $note = $this->requiredText($note, 800, 'Укажите основание предупреждения.');
        $adminRef = $this->requiredText($adminRef, 191, 'Администратор не определён.');
        $action = $this->insertAction(
            $report,
            self::ACTION_WARNING,
            null,
            null,
            $note,
            $adminRef,
            self::STATUS_ACTIVE
        );
        $this->event('action', (string)$action['action_id'], 'warning_issued', $adminRef, [
            'report_id'=>$reportId,
            'target_mgw_id'=>(string)$report['target_mgw_id'],
        ]);
        return $action;
    }

    public function restrict(
        string $reportId,
        string $scope,
        mixed $durationSeconds,
        string $note,
        string $adminRef
    ): array {
        $report = $this->report($reportId);
        $scope = strtolower(trim($scope));
        if (!isset(self::RESTRICTION_SCOPES[$scope])) {
            throw new ModerationException('invalid_scope', 'Выберите область ограничения.');
        }
        $duration = is_int($durationSeconds)
            ? $durationSeconds
            : (is_string($durationSeconds) && preg_match('/^\d+$/', trim($durationSeconds)) === 1
                ? (int)$durationSeconds
                : 0);
        if (!isset(self::RESTRICTION_DURATIONS[$duration])) {
            throw new ModerationException('invalid_duration', 'Выберите срок ограничения.');
        }

        $note = $this->requiredText($note, 800, 'Укажите основание ограничения.');
        $adminRef = $this->requiredText($adminRef, 191, 'Администратор не определён.');
        $expiresAt = $this->timeAfter($duration);
        $action = $this->insertAction(
            $report,
            self::ACTION_RESTRICTION,
            $scope,
            $expiresAt,
            $note,
            $adminRef,
            self::STATUS_ACTIVE
        );
        $this->event('action', (string)$action['action_id'], 'restriction_applied', $adminRef, [
            'report_id'=>$reportId,
            'scope'=>$scope,
            'expires_at_utc'=>$expiresAt,
        ]);
        return $action;
    }

    public function recommendPermanentBan(string $reportId, string $note, string $adminRef): array
    {
        $report = $this->report($reportId);
        $note = $this->requiredText($note, 800, 'Укажите основание рекомендации постоянной блокировки.');
        $adminRef = $this->requiredText($adminRef, 191, 'Администратор не определён.');

        $existing = $this->database->fetchAll(
            'SELECT * FROM mgw_moderation_actions
             WHERE target_mgw_id=:target_mgw_id
               AND action_type=:action_type
               AND status_code=:status_code
             ORDER BY created_at_utc DESC LIMIT 2',
            [
                'target_mgw_id'=>(string)$report['target_mgw_id'],
                'action_type'=>self::ACTION_PERMANENT_BAN,
                'status_code'=>self::STATUS_PENDING_SECOND_REVIEW,
            ]
        );
        if ($existing !== []) {
            throw new ModerationException('ban_review_pending', 'Для этого игрока уже есть рекомендация постоянной блокировки на второй проверке.');
        }

        $action = $this->insertAction(
            $report,
            self::ACTION_PERMANENT_BAN,
            'all',
            null,
            $note,
            $adminRef,
            self::STATUS_PENDING_SECOND_REVIEW
        );
        $this->event('action', (string)$action['action_id'], 'permanent_ban_recommended', $adminRef, [
            'report_id'=>$reportId,
            'target_mgw_id'=>(string)$report['target_mgw_id'],
        ]);
        return $action;
    }

    public function reviewPermanentBan(
        string $actionId,
        string $decision,
        string $note,
        string $adminRef
    ): array {
        $action = $this->action($actionId);
        if ((string)$action['action_type'] !== self::ACTION_PERMANENT_BAN
            || (string)$action['status_code'] !== self::STATUS_PENDING_SECOND_REVIEW) {
            throw new ModerationException('ban_review_unavailable', 'Эта рекомендация уже обработана или недоступна.');
        }

        $adminRef = $this->requiredText($adminRef, 191, 'Администратор не определён.');
        if (hash_equals((string)$action['created_by_admin_ref'], $adminRef)) {
            throw new ModerationException('second_admin_required', 'Постоянную блокировку должен проверить другой администратор.');
        }

        $decision = strtolower(trim($decision));
        if (!in_array($decision, ['approve','reject'], true)) {
            throw new ModerationException('invalid_decision', 'Выберите решение второй проверки.');
        }
        $note = $this->requiredText($note, 800, 'Добавьте комментарий второй проверки.');
        $now = $this->timestamp();
        $status = $decision === 'approve' ? self::STATUS_CONFIRMED : self::STATUS_REJECTED;

        $this->database->transaction(function (DatabaseConnectionInterface $db) use (
            $action,
            $adminRef,
            $note,
            $now,
            $status,
            $decision
        ): void {
            $affected = $db->execute(
                'UPDATE mgw_moderation_actions
                 SET status_code=:status_code,
                     second_review_by_admin_ref=:second_review_by_admin_ref,
                     second_review_note=:second_review_note,
                     second_reviewed_at_utc=:second_reviewed_at_utc,
                     updated_at_utc=:updated_at_utc
                 WHERE action_id=:action_id AND status_code=:expected_status',
                [
                    'status_code'=>$status,
                    'second_review_by_admin_ref'=>$adminRef,
                    'second_review_note'=>$note,
                    'second_reviewed_at_utc'=>$now,
                    'updated_at_utc'=>$now,
                    'action_id'=>(string)$action['action_id'],
                    'expected_status'=>self::STATUS_PENDING_SECOND_REVIEW,
                ]
            );
            if ($affected !== 1) {
                throw new ModerationException('ban_review_race', 'Рекомендация уже была обработана.');
            }

            if ($decision === 'approve') {
                $db->execute(
                    'UPDATE mgw_users SET status=:status, updated_at_utc=:updated_at WHERE mgw_id=:mgw_id',
                    [
                        'status'=>'banned',
                        'updated_at'=>$now,
                        'mgw_id'=>(string)$action['target_mgw_id'],
                    ]
                );
            }
        });

        $this->event('action', $actionId, $decision === 'approve' ? 'permanent_ban_confirmed' : 'permanent_ban_rejected', $adminRef, [
            'note'=>$note,
            'first_admin_ref'=>(string)$action['created_by_admin_ref'],
        ]);
        return $this->publicAction($this->action($actionId));
    }

    public function submitAppeal(string $mgwId, string $actionId, string $message): array
    {
        $mgwId = $this->validMgwId($mgwId);
        $action = $this->action($actionId);
        if ((string)$action['target_mgw_id'] !== $mgwId) {
            throw new ModerationException('appeal_forbidden', 'Это решение недоступно для апелляции.');
        }
        if (!in_array((string)$action['status_code'], [
            self::STATUS_ACTIVE,
            self::STATUS_CONFIRMED,
            self::STATUS_PENDING_SECOND_REVIEW,
        ], true)) {
            throw new ModerationException('appeal_unavailable', 'Это решение уже нельзя обжаловать.');
        }

        $message = $this->requiredText($message, 1200, 'Опишите причину апелляции.');
        $existing = $this->database->fetchAll(
            'SELECT * FROM mgw_moderation_appeals
             WHERE action_id=:action_id AND status_code IN (:open_status,:reviewing_status)
             ORDER BY created_at_utc DESC LIMIT 2',
            [
                'action_id'=>$actionId,
                'open_status'=>'open',
                'reviewing_status'=>'reviewing',
            ]
        );
        if ($existing !== []) {
            throw new ModerationException('appeal_exists', 'По этому решению уже есть открытая апелляция.');
        }

        $appealId = 'APL-' . strtoupper(bin2hex(random_bytes(10)));
        $now = $this->timestamp();
        $this->database->execute(
            'INSERT INTO mgw_moderation_appeals (
                appeal_id,action_id,target_mgw_id,message,status_code,
                reviewed_by_admin_ref,review_note,created_at_utc,updated_at_utc,reviewed_at_utc
             ) VALUES (
                :appeal_id,:action_id,:target_mgw_id,:message,:status_code,
                NULL,NULL,:created_at_utc,:updated_at_utc,NULL
             )',
            [
                'appeal_id'=>$appealId,
                'action_id'=>$actionId,
                'target_mgw_id'=>$mgwId,
                'message'=>$message,
                'status_code'=>'open',
                'created_at_utc'=>$now,
                'updated_at_utc'=>$now,
            ]
        );
        $this->event('appeal', $appealId, 'appeal_submitted', 'mgw:' . $mgwId, [
            'action_id'=>$actionId,
        ]);
        return $this->publicAppeal($this->appeal($appealId));
    }

    public function reviewAppeal(
        string $appealId,
        string $decision,
        string $note,
        string $adminRef
    ): array {
        $appeal = $this->appeal($appealId);
        if (!in_array((string)$appeal['status_code'], ['open','reviewing'], true)) {
            throw new ModerationException('appeal_closed', 'Апелляция уже обработана.');
        }
        $decision = strtolower(trim($decision));
        if (!in_array($decision, ['accept','reject'], true)) {
            throw new ModerationException('invalid_decision', 'Выберите решение по апелляции.');
        }
        $note = $this->requiredText($note, 800, 'Добавьте комментарий к решению по апелляции.');
        $adminRef = $this->requiredText($adminRef, 191, 'Администратор не определён.');
        $now = $this->timestamp();
        $status = $decision === 'accept' ? 'accepted' : 'rejected';
        $action = $this->action((string)$appeal['action_id']);

        $this->database->transaction(function (DatabaseConnectionInterface $db) use (
            $appeal,
            $action,
            $status,
            $decision,
            $note,
            $adminRef,
            $now
        ): void {
            $affected = $db->execute(
                'UPDATE mgw_moderation_appeals
                 SET status_code=:status_code,
                     reviewed_by_admin_ref=:reviewed_by_admin_ref,
                     review_note=:review_note,
                     updated_at_utc=:updated_at_utc,
                     reviewed_at_utc=:reviewed_at_utc
                 WHERE appeal_id=:appeal_id AND status_code IN (:open_status,:reviewing_status)',
                [
                    'status_code'=>$status,
                    'reviewed_by_admin_ref'=>$adminRef,
                    'review_note'=>$note,
                    'updated_at_utc'=>$now,
                    'reviewed_at_utc'=>$now,
                    'appeal_id'=>(string)$appeal['appeal_id'],
                    'open_status'=>'open',
                    'reviewing_status'=>'reviewing',
                ]
            );
            if ($affected !== 1) {
                throw new ModerationException('appeal_review_race', 'Апелляция уже была обработана.');
            }

            if ($decision === 'accept') {
                $db->execute(
                    'UPDATE mgw_moderation_actions
                     SET status_code=:status_code, updated_at_utc=:updated_at_utc
                     WHERE action_id=:action_id',
                    [
                        'status_code'=>self::STATUS_REVOKED,
                        'updated_at_utc'=>$now,
                        'action_id'=>(string)$action['action_id'],
                    ]
                );
                if ((string)$action['action_type'] === self::ACTION_PERMANENT_BAN
                    && (string)$action['status_code'] === self::STATUS_CONFIRMED) {
                    $db->execute(
                        'UPDATE mgw_users SET status=:status, updated_at_utc=:updated_at WHERE mgw_id=:mgw_id',
                        [
                            'status'=>'active',
                            'updated_at'=>$now,
                            'mgw_id'=>(string)$action['target_mgw_id'],
                        ]
                    );
                }
            }
        });

        $this->event('appeal', $appealId, $decision === 'accept' ? 'appeal_accepted' : 'appeal_rejected', $adminRef, [
            'action_id'=>(string)$appeal['action_id'],
            'note'=>$note,
        ]);
        return $this->publicAppeal($this->appeal($appealId));
    }

    public function assertAllowed(string $mgwId, string $scope): void
    {
        $mgwId = $this->validMgwId($mgwId);
        $scope = strtolower(trim($scope));
        if (!isset(self::RESTRICTION_SCOPES[$scope]) && $scope !== 'all') {
            throw new InvalidArgumentException('Unknown moderation scope.');
        }

        $userRows = $this->database->fetchAll(
            'SELECT status FROM mgw_users WHERE mgw_id=:mgw_id',
            ['mgw_id'=>$mgwId]
        );
        if (count($userRows) !== 1) {
            throw new ModerationException('user_unavailable', 'Игрок MGW не найден.');
        }
        if ((string)($userRows[0]['status'] ?? 'active') === 'banned') {
            throw new ModerationException('account_banned', 'Аккаунт заблокирован после ручной проверки.');
        }

        $this->expireRestrictionsForUser($mgwId);
        $rows = $this->database->fetchAll(
            'SELECT * FROM mgw_moderation_actions
             WHERE target_mgw_id=:mgw_id
               AND action_type=:action_type
               AND status_code=:status_code
               AND (scope_code=:scope_code OR scope_code=:all_scope)
               AND (expires_at_utc IS NULL OR expires_at_utc>:now_utc)
             ORDER BY created_at_utc DESC LIMIT 1',
            [
                'mgw_id'=>$mgwId,
                'action_type'=>self::ACTION_RESTRICTION,
                'status_code'=>self::STATUS_ACTIVE,
                'scope_code'=>$scope,
                'all_scope'=>'all',
                'now_utc'=>$this->timestamp(),
            ]
        );
        if ($rows !== []) {
            $action = $this->publicAction($rows[0]);
            $until = (string)($action['expires_at_utc'] ?? '');
            throw new ModerationException(
                'restricted',
                $until !== ''
                    ? 'Действует ограничение до ' . $until . ' UTC.'
                    : 'Для аккаунта действует ограничение.'
            );
        }
    }

    public function userSnapshot(string $mgwId): array
    {
        $mgwId = $this->validMgwId($mgwId);
        $this->expireRestrictionsForUser($mgwId);

        $userRows = $this->database->fetchAll(
            'SELECT status FROM mgw_users WHERE mgw_id=:mgw_id',
            ['mgw_id'=>$mgwId]
        );
        $status = count($userRows) === 1 ? (string)($userRows[0]['status'] ?? 'active') : 'active';

        $actions = $this->database->fetchAll(
            'SELECT * FROM mgw_moderation_actions
             WHERE target_mgw_id=:mgw_id
             ORDER BY created_at_utc DESC LIMIT 50',
            ['mgw_id'=>$mgwId]
        );
        $appeals = $this->database->fetchAll(
            'SELECT * FROM mgw_moderation_appeals
             WHERE target_mgw_id=:mgw_id
             ORDER BY created_at_utc DESC LIMIT 50',
            ['mgw_id'=>$mgwId]
        );

        return [
            'account_status'=>$status,
            'actions'=>array_map(fn(array $row): array => $this->publicAction($row), $actions),
            'appeals'=>array_map(fn(array $row): array => $this->publicAppeal($row), $appeals),
            'scope_labels'=>self::RESTRICTION_SCOPES,
        ];
    }

    public function reportSnapshot(string $reportId): array
    {
        $report = $this->report($reportId);
        $target = (string)$report['target_mgw_id'];
        $this->expireRestrictionsForUser($target);

        $actions = $this->database->fetchAll(
            'SELECT * FROM mgw_moderation_actions
             WHERE report_id=:report_id
             ORDER BY created_at_utc ASC',
            ['report_id'=>$reportId]
        );
        $actionIds = array_values(array_filter(array_map(
            static fn(array $row): string => (string)($row['action_id'] ?? ''),
            $actions
        )));
        $appeals = [];
        if ($actionIds !== []) {
            foreach ($actionIds as $actionId) {
                foreach ($this->database->fetchAll(
                    'SELECT * FROM mgw_moderation_appeals
                     WHERE action_id=:action_id ORDER BY created_at_utc ASC',
                    ['action_id'=>$actionId]
                ) as $appeal) {
                    if (is_array($appeal)) $appeals[] = $this->publicAppeal($appeal);
                }
            }
        }

        return [
            'actions'=>array_map(fn(array $row): array => $this->publicAction($row), $actions),
            'appeals'=>$appeals,
            'events'=>$this->eventsForEntities($reportId, $actionIds, array_column($appeals, 'appeal_id')),
        ];
    }

    public function adminOptions(): array
    {
        return [
            'report_reasons'=>self::REPORT_REASONS,
            'restriction_scopes'=>self::RESTRICTION_SCOPES,
            'restriction_durations'=>array_map(
                static fn(string $label, int $seconds): array => ['seconds'=>$seconds,'label'=>$label],
                array_values(self::RESTRICTION_DURATIONS),
                array_keys(self::RESTRICTION_DURATIONS)
            ),
        ];
    }

    private function insertAction(
        array $report,
        string $actionType,
        ?string $scope,
        ?string $expiresAt,
        string $note,
        string $adminRef,
        string $status
    ): array {
        $actionId = 'MOD-' . strtoupper(bin2hex(random_bytes(10)));
        $now = $this->timestamp();
        $this->database->execute(
            'INSERT INTO mgw_moderation_actions (
                action_id,report_id,target_mgw_id,action_type,reason_code,note,scope_code,
                starts_at_utc,expires_at_utc,status_code,created_by_admin_ref,
                second_review_by_admin_ref,second_review_note,second_reviewed_at_utc,
                created_at_utc,updated_at_utc
             ) VALUES (
                :action_id,:report_id,:target_mgw_id,:action_type,:reason_code,:note,:scope_code,
                :starts_at_utc,:expires_at_utc,:status_code,:created_by_admin_ref,
                NULL,NULL,NULL,:created_at_utc,:updated_at_utc
             )',
            [
                'action_id'=>$actionId,
                'report_id'=>(string)$report['report_id'],
                'target_mgw_id'=>(string)$report['target_mgw_id'],
                'action_type'=>$actionType,
                'reason_code'=>(string)$report['reason'],
                'note'=>$note,
                'scope_code'=>$scope,
                'starts_at_utc'=>$now,
                'expires_at_utc'=>$expiresAt,
                'status_code'=>$status,
                'created_by_admin_ref'=>$adminRef,
                'created_at_utc'=>$now,
                'updated_at_utc'=>$now,
            ]
        );
        return $this->publicAction($this->action($actionId));
    }

    private function report(string $reportId): array
    {
        $reportId = $this->requiredText($reportId, 40, 'Жалоба не определена.');
        $rows = $this->database->fetchAll(
            'SELECT * FROM mgw_player_reports WHERE report_id=:report_id',
            ['report_id'=>$reportId]
        );
        if (count($rows) !== 1 || !is_array($rows[0])) {
            throw new ModerationException('report_not_found', 'Жалоба не найдена.');
        }
        return $rows[0];
    }

    private function action(string $actionId): array
    {
        $actionId = $this->requiredText($actionId, 48, 'Решение модерации не определено.');
        $rows = $this->database->fetchAll(
            'SELECT * FROM mgw_moderation_actions WHERE action_id=:action_id',
            ['action_id'=>$actionId]
        );
        if (count($rows) !== 1 || !is_array($rows[0])) {
            throw new ModerationException('action_not_found', 'Решение модерации не найдено.');
        }
        return $rows[0];
    }

    private function appeal(string $appealId): array
    {
        $appealId = $this->requiredText($appealId, 48, 'Апелляция не определена.');
        $rows = $this->database->fetchAll(
            'SELECT * FROM mgw_moderation_appeals WHERE appeal_id=:appeal_id',
            ['appeal_id'=>$appealId]
        );
        if (count($rows) !== 1 || !is_array($rows[0])) {
            throw new ModerationException('appeal_not_found', 'Апелляция не найдена.');
        }
        return $rows[0];
    }

    private function publicAction(array $row): array
    {
        $status = (string)($row['status_code'] ?? '');
        $expires = trim((string)($row['expires_at_utc'] ?? ''));
        if ((string)($row['action_type'] ?? '') === self::ACTION_RESTRICTION
            && $status === self::STATUS_ACTIVE
            && $expires !== ''
            && strtotime($expires . ' UTC') <= time()) {
            $status = self::STATUS_EXPIRED;
        }

        return [
            'action_id'=>(string)$row['action_id'],
            'report_id'=>$this->nullable((string)($row['report_id'] ?? '')),
            'target_mgw_id'=>(string)$row['target_mgw_id'],
            'action_type'=>(string)$row['action_type'],
            'reason_code'=>(string)$row['reason_code'],
            'reason_label'=>self::reportReasonLabel((string)$row['reason_code']),
            'note'=>(string)$row['note'],
            'scope_code'=>$this->nullable((string)($row['scope_code'] ?? '')),
            'scope_label'=>isset(self::RESTRICTION_SCOPES[(string)($row['scope_code'] ?? '')])
                ? self::RESTRICTION_SCOPES[(string)$row['scope_code']]
                : null,
            'starts_at_utc'=>(string)$row['starts_at_utc'],
            'expires_at_utc'=>$this->nullable($expires),
            'status'=>$status,
            'created_by_admin_ref'=>(string)$row['created_by_admin_ref'],
            'second_review_by_admin_ref'=>$this->nullable((string)($row['second_review_by_admin_ref'] ?? '')),
            'second_review_note'=>$this->nullable((string)($row['second_review_note'] ?? '')),
            'second_reviewed_at_utc'=>$this->nullable((string)($row['second_reviewed_at_utc'] ?? '')),
            'created_at_utc'=>(string)$row['created_at_utc'],
            'updated_at_utc'=>(string)$row['updated_at_utc'],
        ];
    }

    private function publicAppeal(array $row): array
    {
        return [
            'appeal_id'=>(string)$row['appeal_id'],
            'action_id'=>(string)$row['action_id'],
            'target_mgw_id'=>(string)$row['target_mgw_id'],
            'message'=>(string)$row['message'],
            'status'=>(string)$row['status_code'],
            'reviewed_by_admin_ref'=>$this->nullable((string)($row['reviewed_by_admin_ref'] ?? '')),
            'review_note'=>$this->nullable((string)($row['review_note'] ?? '')),
            'created_at_utc'=>(string)$row['created_at_utc'],
            'updated_at_utc'=>(string)$row['updated_at_utc'],
            'reviewed_at_utc'=>$this->nullable((string)($row['reviewed_at_utc'] ?? '')),
        ];
    }

    private function event(
        string $entityType,
        string $entityId,
        string $eventType,
        string $actorRef,
        array $payload = []
    ): void {
        $this->database->execute(
            'INSERT INTO mgw_moderation_events (
                entity_type,entity_id,event_type,actor_ref,payload_json,created_at_utc
             ) VALUES (
                :entity_type,:entity_id,:event_type,:actor_ref,:payload_json,:created_at_utc
             )',
            [
                'entity_type'=>$entityType,
                'entity_id'=>$entityId,
                'event_type'=>$eventType,
                'actor_ref'=>$actorRef,
                'payload_json'=>$payload === [] ? null : json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                'created_at_utc'=>$this->timestamp(),
            ]
        );
    }

    private function eventsForEntities(string $reportId, array $actionIds, array $appealIds): array
    {
        $events = $this->database->fetchAll(
            'SELECT * FROM mgw_moderation_events
             WHERE entity_type=:entity_type AND entity_id=:entity_id
             ORDER BY event_id ASC',
            ['entity_type'=>'report','entity_id'=>$reportId]
        );
        foreach ([['action',$actionIds],['appeal',$appealIds]] as [$type,$ids]) {
            foreach ($ids as $id) {
                foreach ($this->database->fetchAll(
                    'SELECT * FROM mgw_moderation_events
                     WHERE entity_type=:entity_type AND entity_id=:entity_id
                     ORDER BY event_id ASC',
                    ['entity_type'=>$type,'entity_id'=>(string)$id]
                ) as $row) {
                    if (is_array($row)) $events[] = $row;
                }
            }
        }
        usort($events, static fn(array $a, array $b): int => strcmp(
            (string)($a['created_at_utc'] ?? ''),
            (string)($b['created_at_utc'] ?? '')
        ));
        return array_map(function (array $row): array {
            $payload = [];
            $raw = trim((string)($row['payload_json'] ?? ''));
            if ($raw !== '') {
                try {
                    $decoded = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
                    if (is_array($decoded)) $payload = $decoded;
                } catch (Throwable) {}
            }
            return [
                'event_type'=>(string)$row['event_type'],
                'entity_type'=>(string)$row['entity_type'],
                'entity_id'=>(string)$row['entity_id'],
                'actor_ref'=>(string)$row['actor_ref'],
                'payload'=>$payload,
                'created_at_utc'=>(string)$row['created_at_utc'],
            ];
        }, $events);
    }

    private function expireRestrictionsForUser(string $mgwId): void
    {
        $now = $this->timestamp();
        $this->database->execute(
            'UPDATE mgw_moderation_actions
             SET status_code=:expired_status, updated_at_utc=:updated_at_utc
             WHERE target_mgw_id=:target_mgw_id
               AND action_type=:action_type
               AND status_code=:active_status
               AND expires_at_utc IS NOT NULL
               AND expires_at_utc<=:now_utc',
            [
                'expired_status'=>self::STATUS_EXPIRED,
                'updated_at_utc'=>$now,
                'target_mgw_id'=>$mgwId,
                'action_type'=>self::ACTION_RESTRICTION,
                'active_status'=>self::STATUS_ACTIVE,
                'now_utc'=>$now,
            ]
        );
    }

    private function validMgwId(string $mgwId): string
    {
        $mgwId = strtoupper(trim($mgwId));
        if (!class_exists('MgwIdGenerator') || !MgwIdGenerator::isValid($mgwId)) {
            throw new ModerationException('user_unavailable', 'Игрок MGW не найден.');
        }
        return $mgwId;
    }

    private function requiredText(string $value, int $max, string $message): string
    {
        $value = trim(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $value) ?? '');
        if ($value === '') throw new ModerationException('required_text', $message);
        return function_exists('mb_substr') ? mb_substr($value, 0, $max) : substr($value, 0, $max);
    }

    private function nullable(string $value): ?string
    {
        $value = trim($value);
        return $value === '' ? null : $value;
    }

    private function timestamp(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');
    }

    private function timeAfter(int $seconds): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))
            ->modify('+' . $seconds . ' seconds')
            ->format('Y-m-d H:i:s.u');
    }
}
