<?php
declare(strict_types=1);

/**
 * MVP-22.9 durable incident/recovery state.
 *
 * This service owns incident records, evidence, recovery status and the
 * two-admin workflow only. Runtime feature flags and sessions remain owned by
 * their existing services and are invoked by the Admin endpoint after a
 * high-risk action is claimed.
 */
final class IncidentRecoveryService
{
    public const INCIDENT_STATUSES = ['open','mitigating','recovering','resolved'];
    public const KEY_CODES = [
        'telegram_bot_token',
        'database_credentials',
        'account_data_hook_secret',
    ];
    public const KEY_STATUSES = ['pending','rotated','verified','not_applicable'];
    public const RESTORE_STATUSES = ['not_started','preparing','ready','verified','blocked'];
    public const EVIDENCE_TYPES = ['commit_sha','workflow_run','backup_reference','log_reference','other'];
    public const HIGH_RISK_ACTIONS = [
        'enable_security_mode',
        'disable_security_mode',
        'revoke_all_sessions',
    ];
    public const ACTION_PENDING = 'pending_second_review';
    public const ACTION_EXECUTING = 'executing';
    public const ACTION_COMPLETED = 'completed';
    public const ACTION_REJECTED = 'rejected';
    public const ACTION_FAILED = 'failed';

    public function __construct(private DatabaseConnectionInterface $database) {}

    public function snapshot(): array
    {
        $incidents = $this->database->fetchAll(
            'SELECT incident_id,title,incident_status,summary_text,opened_by_ref,opened_at_utc,
                    resolved_by_ref,resolved_at_utc,security_mode_enabled_at_utc,
                    security_mode_disabled_at_utc,updated_at_utc
             FROM mgw_incidents
             ORDER BY CASE WHEN incident_status=:resolved THEN 1 ELSE 0 END, updated_at_utc DESC
             LIMIT 30',
            ['resolved'=>'resolved']
        );

        $active = null;
        foreach ($incidents as $row) {
            if (($row['incident_status'] ?? '') !== 'resolved') {
                $active = $row;
                break;
            }
        }

        return [
            'active_incident'=>$active,
            'incidents'=>$incidents,
            'pending_actions'=>$active === null ? [] : $this->actions((string)$active['incident_id'], 30),
            'key_checks'=>$active === null ? [] : $this->keyChecks((string)$active['incident_id']),
            'evidence'=>$active === null ? [] : $this->evidence((string)$active['incident_id'], 50),
            'restore_status'=>$active === null ? null : $this->restoreStatus((string)$active['incident_id']),
            'recent_audit'=>$active === null ? [] : $this->auditRows((string)$active['incident_id'], 50),
        ];
    }

    public function createIncident(string $title, string $summary, string $actorRef): array
    {
        $title = $this->requiredText($title, 240, 'Название инцидента обязательно.');
        $summary = $this->optionalText($summary, 2000);
        $actorRef = $this->requiredText($actorRef, 191, 'Администратор не определён.');
        $incidentId = $this->id('inc');
        $now = $this->timestamp();

        return $this->database->transaction(function (DatabaseConnectionInterface $database) use (
            $incidentId, $title, $summary, $actorRef, $now
        ): array {
            $existing = $database->fetchAll(
                'SELECT incident_id FROM mgw_incidents WHERE incident_status<>:resolved LIMIT 1',
                ['resolved'=>'resolved']
            );
            if ($existing !== []) {
                throw new RuntimeException('Сначала завершите текущий активный инцидент.');
            }

            $database->execute(
                'INSERT INTO mgw_incidents (
                    incident_id,title,incident_status,summary_text,opened_by_ref,opened_at_utc,updated_at_utc
                 ) VALUES (
                    :incident_id,:title,:status,:summary,:actor,:opened_at,:updated_at
                 )',
                [
                    'incident_id'=>$incidentId,
                    'title'=>$title,
                    'status'=>'open',
                    'summary'=>$summary !== '' ? $summary : null,
                    'actor'=>$actorRef,
                    'opened_at'=>$now,
                    'updated_at'=>$now,
                ]
            );

            foreach (self::KEY_CODES as $keyCode) {
                $database->execute(
                    'INSERT INTO mgw_incident_key_checks (
                        incident_id,key_code,status_code,note_text,updated_by_ref,updated_at_utc
                     ) VALUES (
                        :incident_id,:key_code,:status_code,NULL,:actor,:updated_at
                     )',
                    [
                        'incident_id'=>$incidentId,
                        'key_code'=>$keyCode,
                        'status_code'=>'pending',
                        'actor'=>$actorRef,
                        'updated_at'=>$now,
                    ]
                );
            }

            $database->execute(
                'INSERT INTO mgw_incident_restore_status (
                    incident_id,status_code,backup_reference,rollback_reference,restore_sha,
                    notes_text,updated_by_ref,updated_at_utc
                 ) VALUES (
                    :incident_id,:status_code,NULL,NULL,NULL,NULL,:actor,:updated_at
                 )',
                [
                    'incident_id'=>$incidentId,
                    'status_code'=>'not_started',
                    'actor'=>$actorRef,
                    'updated_at'=>$now,
                ]
            );

            $after = $this->incident($incidentId, true);
            $this->audit($database, $incidentId, 'incident_opened', $actorRef, $summary, null, $after, $now);
            return $after;
        });
    }

    public function setIncidentStatus(
        string $incidentId,
        string $status,
        string $reason,
        string $actorRef
    ): array {
        $status = $this->enum($status, self::INCIDENT_STATUSES, 'статус инцидента');
        $reason = $this->requiredText($reason, 800, 'Укажите причину изменения статуса.');
        $actorRef = $this->requiredText($actorRef, 191, 'Администратор не определён.');
        $now = $this->timestamp();

        return $this->database->transaction(function (DatabaseConnectionInterface $database) use (
            $incidentId, $status, $reason, $actorRef, $now
        ): array {
            $before = $this->incident($incidentId, true);
            if ((string)$before['incident_status'] === 'resolved') {
                throw new RuntimeException('Завершённый инцидент нельзя вернуть в работу.');
            }

            if ($status === 'resolved') {
                $pending = $database->fetchAll(
                    'SELECT action_id FROM mgw_incident_actions
                     WHERE incident_id=:incident_id
                       AND status_code IN (:pending,:executing)
                     LIMIT 1',
                    [
                        'incident_id'=>$incidentId,
                        'pending'=>self::ACTION_PENDING,
                        'executing'=>self::ACTION_EXECUTING,
                    ]
                );
                if ($pending !== []) {
                    throw new RuntimeException('Перед завершением инцидента обработайте ожидающие опасные действия.');
                }
            }

            $database->execute(
                'UPDATE mgw_incidents
                 SET incident_status=:status,
                     resolved_by_ref=:resolved_by,
                     resolved_at_utc=:resolved_at,
                     updated_at_utc=:updated_at
                 WHERE incident_id=:incident_id',
                [
                    'status'=>$status,
                    'resolved_by'=>$status === 'resolved' ? $actorRef : null,
                    'resolved_at'=>$status === 'resolved' ? $now : null,
                    'updated_at'=>$now,
                    'incident_id'=>$incidentId,
                ]
            );
            $after = $this->incident($incidentId, true);
            $this->audit($database, $incidentId, 'incident_status_changed', $actorRef, $reason, $before, $after, $now);
            return $after;
        });
    }

    public function updateKeyCheck(
        string $incidentId,
        string $keyCode,
        string $status,
        string $note,
        string $actorRef
    ): array {
        $this->assertActiveIncident($incidentId);
        $keyCode = $this->enum($keyCode, self::KEY_CODES, 'пункт ключей');
        $status = $this->enum($status, self::KEY_STATUSES, 'статус проверки ключа');
        $note = $this->optionalText($note, 800);
        $this->assertNoSecretMaterial($note);
        $actorRef = $this->requiredText($actorRef, 191, 'Администратор не определён.');
        $now = $this->timestamp();

        $before = $this->keyCheck($incidentId, $keyCode);
        $this->database->execute(
            'UPDATE mgw_incident_key_checks
             SET status_code=:status,note_text=:note,updated_by_ref=:actor,updated_at_utc=:updated_at
             WHERE incident_id=:incident_id AND key_code=:key_code',
            [
                'status'=>$status,
                'note'=>$note !== '' ? $note : null,
                'actor'=>$actorRef,
                'updated_at'=>$now,
                'incident_id'=>$incidentId,
                'key_code'=>$keyCode,
            ]
        );
        $after = $this->keyCheck($incidentId, $keyCode);
        $this->audit($this->database, $incidentId, 'key_check_updated', $actorRef, $keyCode, $before, $after, $now);
        return $after;
    }

    public function addEvidence(
        string $incidentId,
        string $type,
        string $label,
        string $reference,
        string $fingerprint,
        string $actorRef
    ): array {
        $this->assertActiveIncident($incidentId);
        $type = $this->enum($type, self::EVIDENCE_TYPES, 'тип доказательства');
        $label = $this->requiredText($label, 240, 'Укажите название доказательства.');
        $reference = $this->requiredText($reference, 500, 'Укажите безопасную ссылку или идентификатор.');
        $this->assertNoSecretMaterial($reference);
        $fingerprint = strtolower(trim($fingerprint));
        if ($fingerprint !== '' && preg_match('/^[a-f0-9]{64}$/', $fingerprint) !== 1) {
            throw new InvalidArgumentException('SHA-256 должен содержать 64 шестнадцатеричных символа.');
        }
        $actorRef = $this->requiredText($actorRef, 191, 'Администратор не определён.');
        $evidenceId = $this->id('ev');
        $now = $this->timestamp();

        $this->database->execute(
            'INSERT INTO mgw_incident_evidence (
                evidence_id,incident_id,evidence_type,label_text,reference_text,
                fingerprint_sha256,captured_by_ref,created_at_utc
             ) VALUES (
                :evidence_id,:incident_id,:evidence_type,:label_text,:reference_text,
                :fingerprint,:actor,:created_at
             )',
            [
                'evidence_id'=>$evidenceId,
                'incident_id'=>$incidentId,
                'evidence_type'=>$type,
                'label_text'=>$label,
                'reference_text'=>$reference,
                'fingerprint'=>$fingerprint !== '' ? $fingerprint : null,
                'actor'=>$actorRef,
                'created_at'=>$now,
            ]
        );
        $row = $this->evidenceRow($evidenceId);
        $this->audit($this->database, $incidentId, 'evidence_preserved', $actorRef, $label, null, $row, $now);
        return $row;
    }

    public function updateRestoreStatus(
        string $incidentId,
        string $status,
        string $backupReference,
        string $rollbackReference,
        string $restoreSha,
        string $notes,
        string $actorRef
    ): array {
        $this->assertActiveIncident($incidentId);
        $status = $this->enum($status, self::RESTORE_STATUSES, 'статус восстановления');
        $backupReference = $this->optionalText($backupReference, 500);
        $rollbackReference = $this->optionalText($rollbackReference, 500);
        $notes = $this->optionalText($notes, 1200);
        foreach ([$backupReference, $rollbackReference, $notes] as $safeText) $this->assertNoSecretMaterial($safeText);
        $restoreSha = strtolower(trim($restoreSha));
        if ($restoreSha !== '' && preg_match('/^[a-f0-9]{40}$/', $restoreSha) !== 1) {
            throw new InvalidArgumentException('SHA восстановления должен содержать 40 шестнадцатеричных символов.');
        }
        if ($status === 'verified' && $backupReference === '' && $rollbackReference === '' && $restoreSha === '') {
            throw new InvalidArgumentException('Для статуса «Проверено» сохраните хотя бы одну ссылку восстановления.');
        }
        $actorRef = $this->requiredText($actorRef, 191, 'Администратор не определён.');
        $now = $this->timestamp();

        $before = $this->restoreStatus($incidentId);
        $this->database->execute(
            'UPDATE mgw_incident_restore_status
             SET status_code=:status,
                 backup_reference=:backup_reference,
                 rollback_reference=:rollback_reference,
                 restore_sha=:restore_sha,
                 notes_text=:notes,
                 updated_by_ref=:actor,
                 updated_at_utc=:updated_at
             WHERE incident_id=:incident_id',
            [
                'status'=>$status,
                'backup_reference'=>$backupReference !== '' ? $backupReference : null,
                'rollback_reference'=>$rollbackReference !== '' ? $rollbackReference : null,
                'restore_sha'=>$restoreSha !== '' ? $restoreSha : null,
                'notes'=>$notes !== '' ? $notes : null,
                'actor'=>$actorRef,
                'updated_at'=>$now,
                'incident_id'=>$incidentId,
            ]
        );
        $after = $this->restoreStatus($incidentId);
        $this->audit($this->database, $incidentId, 'restore_status_updated', $actorRef, $notes, $before, $after, $now);
        return $after;
    }

    public function requestHighRiskAction(
        string $incidentId,
        string $actionCode,
        string $reason,
        string $actorRef,
        array $request = []
    ): array {
        $this->assertActiveIncident($incidentId);
        $actionCode = $this->enum($actionCode, self::HIGH_RISK_ACTIONS, 'опасное действие');
        $reason = $this->requiredText($reason, 800, 'Укажите причину опасного действия.');
        $actorRef = $this->requiredText($actorRef, 191, 'Администратор не определён.');
        $safeRequest = $this->safeActionRequest($request);
        $now = $this->timestamp();
        $actionId = $this->id('act');

        return $this->database->transaction(function (DatabaseConnectionInterface $database) use (
            $incidentId, $actionCode, $reason, $actorRef, $safeRequest, $now, $actionId
        ): array {
            $pending = $database->fetchAll(
                'SELECT action_id FROM mgw_incident_actions
                 WHERE incident_id=:incident_id AND action_code=:action_code
                   AND status_code IN (:pending,:executing)
                 LIMIT 1',
                [
                    'incident_id'=>$incidentId,
                    'action_code'=>$actionCode,
                    'pending'=>self::ACTION_PENDING,
                    'executing'=>self::ACTION_EXECUTING,
                ]
            );
            if ($pending !== []) {
                throw new RuntimeException('Такое действие уже ожидает вторую проверку или выполняется.');
            }

            $database->execute(
                'INSERT INTO mgw_incident_actions (
                    action_id,incident_id,action_code,status_code,requested_by_ref,reason_text,
                    request_json,requested_at_utc,updated_at_utc
                 ) VALUES (
                    :action_id,:incident_id,:action_code,:status_code,:requested_by,:reason_text,
                    :request_json,:requested_at,:updated_at
                 )',
                [
                    'action_id'=>$actionId,
                    'incident_id'=>$incidentId,
                    'action_code'=>$actionCode,
                    'status_code'=>self::ACTION_PENDING,
                    'requested_by'=>$actorRef,
                    'reason_text'=>$reason,
                    'request_json'=>$safeRequest === [] ? null : json_encode($safeRequest, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                    'requested_at'=>$now,
                    'updated_at'=>$now,
                ]
            );
            $row = $this->action($actionId, true);
            $this->audit($database, $incidentId, 'high_risk_action_requested', $actorRef, $reason, null, $this->publicAction($row), $now);
            return $this->publicAction($row);
        });
    }

    public function claimHighRiskAction(
        string $actionId,
        string $actorRef,
        string $reviewNote
    ): array {
        $actorRef = $this->requiredText($actorRef, 191, 'Администратор не определён.');
        $reviewNote = $this->requiredText($reviewNote, 800, 'Добавьте комментарий второй проверки.');
        $now = $this->timestamp();

        return $this->database->transaction(function (DatabaseConnectionInterface $database) use (
            $actionId, $actorRef, $reviewNote, $now
        ): array {
            $before = $this->action($actionId, true);
            if ((string)$before['status_code'] !== self::ACTION_PENDING) {
                throw new RuntimeException('Это действие уже обработано или выполняется.');
            }
            if (hash_equals((string)$before['requested_by_ref'], $actorRef)) {
                throw new RuntimeException('Опасное действие должен подтвердить другой администратор.');
            }
            $affected = $database->execute(
                'UPDATE mgw_incident_actions
                 SET status_code=:status,
                     second_review_by_ref=:reviewer,
                     second_review_note=:review_note,
                     second_reviewed_at_utc=:reviewed_at,
                     updated_at_utc=:updated_at
                 WHERE action_id=:action_id AND status_code=:expected',
                [
                    'status'=>self::ACTION_EXECUTING,
                    'reviewer'=>$actorRef,
                    'review_note'=>$reviewNote,
                    'reviewed_at'=>$now,
                    'updated_at'=>$now,
                    'action_id'=>$actionId,
                    'expected'=>self::ACTION_PENDING,
                ]
            );
            if ($affected !== 1) throw new RuntimeException('Действие уже забрал другой администратор.');

            if ((string)$before['action_code'] === 'enable_security_mode') {
                $request = $this->decodeJson($before['request_json'] ?? null);
                $baseline = is_array($request['baseline_flags'] ?? null) ? $request['baseline_flags'] : [];
                if ($baseline === []) {
                    throw new RuntimeException('Не удалось зафиксировать состояние системных переключателей до режима безопасности.');
                }
                $database->execute(
                    'UPDATE mgw_incidents
                     SET security_mode_before_json=:baseline,updated_at_utc=:updated_at
                     WHERE incident_id=:incident_id',
                    [
                        'baseline'=>json_encode($baseline, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                        'updated_at'=>$now,
                        'incident_id'=>(string)$before['incident_id'],
                    ]
                );
            }

            $after = $this->action($actionId, true);
            $this->audit(
                $database,
                (string)$after['incident_id'],
                'high_risk_action_confirmed',
                $actorRef,
                $reviewNote,
                $this->publicAction($before),
                $this->publicAction($after),
                $now
            );
            return $after;
        });
    }

    public function rejectHighRiskAction(
        string $actionId,
        string $actorRef,
        string $reviewNote
    ): array {
        $actorRef = $this->requiredText($actorRef, 191, 'Администратор не определён.');
        $reviewNote = $this->requiredText($reviewNote, 800, 'Добавьте комментарий второй проверки.');
        $now = $this->timestamp();

        return $this->database->transaction(function (DatabaseConnectionInterface $database) use (
            $actionId, $actorRef, $reviewNote, $now
        ): array {
            $before = $this->action($actionId, true);
            if ((string)$before['status_code'] !== self::ACTION_PENDING) {
                throw new RuntimeException('Это действие уже обработано.');
            }
            if (hash_equals((string)$before['requested_by_ref'], $actorRef)) {
                throw new RuntimeException('Отклонить собственный запрос нельзя: нужен другой администратор.');
            }
            $database->execute(
                'UPDATE mgw_incident_actions
                 SET status_code=:status,
                     second_review_by_ref=:reviewer,
                     second_review_note=:review_note,
                     second_reviewed_at_utc=:reviewed_at,
                     completed_at_utc=:completed_at,
                     updated_at_utc=:updated_at
                 WHERE action_id=:action_id AND status_code=:expected',
                [
                    'status'=>self::ACTION_REJECTED,
                    'reviewer'=>$actorRef,
                    'review_note'=>$reviewNote,
                    'reviewed_at'=>$now,
                    'completed_at'=>$now,
                    'updated_at'=>$now,
                    'action_id'=>$actionId,
                    'expected'=>self::ACTION_PENDING,
                ]
            );
            $after = $this->action($actionId, true);
            $this->audit(
                $database,
                (string)$after['incident_id'],
                'high_risk_action_rejected',
                $actorRef,
                $reviewNote,
                $this->publicAction($before),
                $this->publicAction($after),
                $now
            );
            return $this->publicAction($after);
        });
    }

    public function completeHighRiskAction(string $actionId, array $result, string $actorRef): array
    {
        $actorRef = $this->requiredText($actorRef, 191, 'Администратор не определён.');
        $now = $this->timestamp();
        $result = $this->safeResult($result);

        return $this->database->transaction(function (DatabaseConnectionInterface $database) use (
            $actionId, $result, $actorRef, $now
        ): array {
            $before = $this->action($actionId, true);
            if ((string)$before['status_code'] !== self::ACTION_EXECUTING) {
                throw new RuntimeException('Действие не находится в состоянии выполнения.');
            }
            $database->execute(
                'UPDATE mgw_incident_actions
                 SET status_code=:status,result_json=:result_json,completed_at_utc=:completed_at,updated_at_utc=:updated_at
                 WHERE action_id=:action_id AND status_code=:expected',
                [
                    'status'=>self::ACTION_COMPLETED,
                    'result_json'=>json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                    'completed_at'=>$now,
                    'updated_at'=>$now,
                    'action_id'=>$actionId,
                    'expected'=>self::ACTION_EXECUTING,
                ]
            );

            $incidentId = (string)$before['incident_id'];
            $actionCode = (string)$before['action_code'];
            if ($actionCode === 'enable_security_mode') {
                $database->execute(
                    'UPDATE mgw_incidents
                     SET security_mode_enabled_at_utc=:enabled_at,
                         security_mode_disabled_at_utc=NULL,
                         updated_at_utc=:updated_at
                     WHERE incident_id=:incident_id',
                    [
                        'enabled_at'=>$now,
                        'updated_at'=>$now,
                        'incident_id'=>$incidentId,
                    ]
                );
            } elseif ($actionCode === 'disable_security_mode') {
                $database->execute(
                    'UPDATE mgw_incidents
                     SET security_mode_disabled_at_utc=:disabled_at,updated_at_utc=:updated_at
                     WHERE incident_id=:incident_id',
                    [
                        'disabled_at'=>$now,
                        'updated_at'=>$now,
                        'incident_id'=>$incidentId,
                    ]
                );
            }

            $after = $this->action($actionId, true);
            $this->audit(
                $database,
                $incidentId,
                'high_risk_action_completed',
                $actorRef,
                $actionCode,
                $this->publicAction($before),
                $this->publicAction($after),
                $now
            );
            return $this->publicAction($after);
        });
    }

    public function failHighRiskAction(string $actionId, string $message, string $actorRef): array
    {
        $actorRef = $this->requiredText($actorRef, 191, 'Администратор не определён.');
        $message = $this->optionalText($message, 500);
        $this->assertNoSecretMaterial($message);
        $now = $this->timestamp();
        $before = $this->action($actionId, false);
        if ((string)$before['status_code'] !== self::ACTION_EXECUTING) return $this->publicAction($before);

        $result = ['ok'=>false,'error'=>$message !== '' ? $message : 'operation_failed'];
        $this->database->execute(
            'UPDATE mgw_incident_actions
             SET status_code=:status,result_json=:result_json,completed_at_utc=:completed_at,updated_at_utc=:updated_at
             WHERE action_id=:action_id AND status_code=:expected',
            [
                'status'=>self::ACTION_FAILED,
                'result_json'=>json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                'completed_at'=>$now,
                'updated_at'=>$now,
                'action_id'=>$actionId,
                'expected'=>self::ACTION_EXECUTING,
            ]
        );
        $after = $this->action($actionId, false);
        $this->audit(
            $this->database,
            (string)$after['incident_id'],
            'high_risk_action_failed',
            $actorRef,
            $message,
            $this->publicAction($before),
            $this->publicAction($after),
            $now
        );
        return $this->publicAction($after);
    }

    public function actionRequest(array $action): array
    {
        return $this->decodeJson($action['request_json'] ?? null);
    }

    public function securityModeBaseline(string $incidentId): array
    {
        $row = $this->incident($incidentId, false);
        return $this->decodeJson($row['security_mode_before_json'] ?? null);
    }

    public function actions(string $incidentId, int $limit = 30): array
    {
        $limit = max(1, min(100, $limit));
        $rows = $this->database->fetchAll(
            'SELECT action_id,incident_id,action_code,status_code,requested_by_ref,reason_text,
                    requested_at_utc,second_review_by_ref,second_review_note,second_reviewed_at_utc,
                    result_json,completed_at_utc,updated_at_utc
             FROM mgw_incident_actions
             WHERE incident_id=:incident_id
             ORDER BY requested_at_utc DESC
             LIMIT ' . $limit,
            ['incident_id'=>$incidentId]
        );
        return array_map(fn(array $row): array => $this->publicAction($row), $rows);
    }

    public function keyChecks(string $incidentId): array
    {
        return $this->database->fetchAll(
            'SELECT incident_id,key_code,status_code,note_text,updated_by_ref,updated_at_utc
             FROM mgw_incident_key_checks
             WHERE incident_id=:incident_id
             ORDER BY key_code ASC',
            ['incident_id'=>$incidentId]
        );
    }

    public function evidence(string $incidentId, int $limit = 50): array
    {
        $limit = max(1, min(100, $limit));
        return $this->database->fetchAll(
            'SELECT evidence_id,incident_id,evidence_type,label_text,reference_text,
                    fingerprint_sha256,captured_by_ref,created_at_utc
             FROM mgw_incident_evidence
             WHERE incident_id=:incident_id
             ORDER BY created_at_utc DESC
             LIMIT ' . $limit,
            ['incident_id'=>$incidentId]
        );
    }

    public function restoreStatus(string $incidentId): ?array
    {
        $rows = $this->database->fetchAll(
            'SELECT incident_id,status_code,backup_reference,rollback_reference,restore_sha,
                    notes_text,updated_by_ref,updated_at_utc
             FROM mgw_incident_restore_status WHERE incident_id=:incident_id',
            ['incident_id'=>$incidentId]
        );
        return is_array($rows[0] ?? null) ? $rows[0] : null;
    }

    private function incident(string $incidentId, bool $forUpdate): array
    {
        $incidentId = $this->requiredText($incidentId, 64, 'Инцидент не выбран.');
        $sql = 'SELECT * FROM mgw_incidents WHERE incident_id=:incident_id';
        if ($forUpdate && $this->database->driver() !== 'sqlite') $sql .= ' FOR UPDATE';
        $rows = $this->database->fetchAll($sql, ['incident_id'=>$incidentId]);
        if (!is_array($rows[0] ?? null)) throw new InvalidArgumentException('Инцидент не найден.');
        return $rows[0];
    }

    private function assertActiveIncident(string $incidentId): array
    {
        $incident = $this->incident($incidentId, false);
        if ((string)$incident['incident_status'] === 'resolved') {
            throw new RuntimeException('Завершённый инцидент доступен только для чтения.');
        }
        return $incident;
    }

    private function action(string $actionId, bool $forUpdate): array
    {
        $actionId = $this->requiredText($actionId, 64, 'Действие не выбрано.');
        $sql = 'SELECT * FROM mgw_incident_actions WHERE action_id=:action_id';
        if ($forUpdate && $this->database->driver() !== 'sqlite') $sql .= ' FOR UPDATE';
        $rows = $this->database->fetchAll($sql, ['action_id'=>$actionId]);
        if (!is_array($rows[0] ?? null)) throw new InvalidArgumentException('Опасное действие не найдено.');
        return $rows[0];
    }

    private function keyCheck(string $incidentId, string $keyCode): array
    {
        $rows = $this->database->fetchAll(
            'SELECT incident_id,key_code,status_code,note_text,updated_by_ref,updated_at_utc
             FROM mgw_incident_key_checks WHERE incident_id=:incident_id AND key_code=:key_code',
            ['incident_id'=>$incidentId,'key_code'=>$keyCode]
        );
        if (!is_array($rows[0] ?? null)) throw new InvalidArgumentException('Пункт проверки ключа не найден.');
        return $rows[0];
    }

    private function evidenceRow(string $evidenceId): array
    {
        $rows = $this->database->fetchAll(
            'SELECT evidence_id,incident_id,evidence_type,label_text,reference_text,
                    fingerprint_sha256,captured_by_ref,created_at_utc
             FROM mgw_incident_evidence WHERE evidence_id=:evidence_id',
            ['evidence_id'=>$evidenceId]
        );
        if (!is_array($rows[0] ?? null)) throw new RuntimeException('Не удалось сохранить доказательство.');
        return $rows[0];
    }

    private function auditRows(string $incidentId, int $limit): array
    {
        $limit = max(1, min(100, $limit));
        return $this->database->fetchAll(
            'SELECT audit_id,incident_id,action_code,actor_ref,reason_text,created_at_utc
             FROM mgw_incident_audit
             WHERE incident_id=:incident_id
             ORDER BY audit_id DESC
             LIMIT ' . $limit,
            ['incident_id'=>$incidentId]
        );
    }

    private function audit(
        DatabaseConnectionInterface $database,
        string $incidentId,
        string $actionCode,
        string $actorRef,
        string $reason,
        ?array $before,
        ?array $after,
        string $createdAt
    ): void {
        $database->execute(
            'INSERT INTO mgw_incident_audit (
                incident_id,action_code,actor_ref,reason_text,before_json,after_json,created_at_utc
             ) VALUES (
                :incident_id,:action_code,:actor_ref,:reason_text,:before_json,:after_json,:created_at
             )',
            [
                'incident_id'=>$incidentId,
                'action_code'=>$actionCode,
                'actor_ref'=>$actorRef,
                'reason_text'=>$reason !== '' ? $this->optionalText($reason, 800) : null,
                'before_json'=>$before === null ? null : json_encode($before, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                'after_json'=>$after === null ? null : json_encode($after, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                'created_at'=>$createdAt,
            ]
        );
    }

    private function publicAction(array $row): array
    {
        $result = $row;
        unset($result['request_json']);
        if (array_key_exists('result_json', $result)) {
            $result['result'] = $this->decodeJson($result['result_json']);
            unset($result['result_json']);
        }
        return $result;
    }

    private function safeActionRequest(array $request): array
    {
        $safe = [];
        if (is_array($request['baseline_flags'] ?? null)) {
            $flags = $request['baseline_flags'];
            $safe['baseline_flags'] = [
                'maintenance_mode'=>($flags['maintenance_mode'] ?? false) === true,
                'maintenance_message'=>$this->optionalText((string)($flags['maintenance_message'] ?? ''), 500),
                'financial_read_only'=>($flags['financial_read_only'] ?? false) === true,
                'features'=>is_array($flags['features'] ?? null) ? $flags['features'] : [],
                'games'=>is_array($flags['games'] ?? null) ? $flags['games'] : [],
            ];
        }
        return $safe;
    }

    private function safeResult(array $result): array
    {
        $safe = [];
        foreach (['ok','changed','revoked_sessions'] as $key) {
            if (array_key_exists($key, $result)) $safe[$key] = $result[$key];
        }
        return $safe;
    }

    private function decodeJson(mixed $value): array
    {
        $value = trim((string)($value ?? ''));
        if ($value === '') return [];
        $decoded = json_decode($value, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function assertNoSecretMaterial(string $value): void
    {
        if ($value === '') return;
        if (preg_match('/(?:password|secret|token|api[_ -]?key)\s*[:=]/i', $value) === 1
            || preg_match('/^\d{6,}:[A-Za-z0-9_-]{20,}$/', trim($value)) === 1
            || preg_match('/-----BEGIN [A-Z ]+PRIVATE KEY-----/', $value) === 1) {
            throw new InvalidArgumentException('Не сохраняйте секреты или значения ключей в карточке инцидента.');
        }
    }

    private function enum(string $value, array $allowed, string $label): string
    {
        $value = strtolower(trim($value));
        if (!in_array($value, $allowed, true)) {
            throw new InvalidArgumentException('Некорректное значение: ' . $label . '.');
        }
        return $value;
    }

    private function id(string $prefix): string
    {
        return $prefix . '_' . bin2hex(random_bytes(12));
    }

    private function requiredText(string $value, int $max, string $message): string
    {
        $value = $this->optionalText($value, $max);
        if ($value === '') throw new InvalidArgumentException($message);
        return $value;
    }

    private function optionalText(string $value, int $max): string
    {
        $value = trim(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', ' ', $value) ?? '');
        return function_exists('mb_substr') ? mb_substr($value, 0, $max) : substr($value, 0, $max);
    }

    private function timestamp(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');
    }
}
