<?php
declare(strict_types=1);

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');

require __DIR__ . '/core/bootstrap.php';
require_once __DIR__ . '/helpers/AdminWebAuth.php';
require_once __DIR__ . '/incident/IncidentRecoveryService.php';
require_once __DIR__ . '/accounts/AccountIdentityService.php';
require_once __DIR__ . '/system/SystemAdminService.php';
require_once __DIR__ . '/system/RuntimeFeatureFlagAdminService.php';

try {
    if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
        json_response(['ok'=>false,'error'=>'Метод запроса не поддерживается.'], 405);
    }

    $payload = json_decode(file_get_contents('php://input') ?: '{}', true);
    if (!is_array($payload)) {
        json_response(['ok'=>false,'error'=>'Некорректный запрос.'], 400);
    }

    $admin = AdminWebAuth::authorize($config, (string)($payload['initData'] ?? ''));
    $telegramId = trim((string)($admin['id'] ?? ''));
    if ($telegramId === '') throw new RuntimeException('Не удалось определить авторизованного администратора Telegram.');
    $actorRef = 'telegram:' . $telegramId;
    $environment = strtolower(trim((string)($config['environment'] ?? 'production')));

    $databaseConfig = DatabaseConfig::fromApplicationConfig($config);
    if (!$databaseConfig->enabled()) {
        json_response(['ok'=>false,'error'=>'Консоль восстановления недоступна: база данных отключена.'], 503);
    }
    $database = PdoConnectionFactory::create($databaseConfig);
    $incidents = new IncidentRecoveryService($database);
    $identity = new AccountIdentityService($database);
    $system = new SystemAdminService($database);

    $externalConfigFile = getenv('MGW_CONFIG_FILE') ?: dirname(__DIR__, 2) . '/_private_mgw/config.php';
    $primaryConfigFile = is_file($externalConfigFile)
        ? $externalConfigFile
        : __DIR__ . '/config/config.php';
    $flagEditor = new RuntimeFeatureFlagAdminService(dirname($primaryConfigFile) . '/runtime.php');

    $action = strtolower(trim((string)($payload['action'] ?? 'snapshot')));
    $operation = null;

    if ($action === 'create_incident') {
        $operation = $incidents->createIncident(
            clean_string($payload['title'] ?? '', 240),
            clean_string($payload['summary'] ?? '', 2000),
            $actorRef
        );
    } elseif ($action === 'set_incident_status') {
        $incidentId = clean_string($payload['incident_id'] ?? '', 64);
        $status = strtolower(clean_string($payload['status'] ?? '', 32));
        if ($status === 'resolved') {
            $current = $incidents->snapshot()['active_incident'] ?? null;
            if (is_array($current)
                && (string)($current['incident_id'] ?? '') === $incidentId
                && !empty($current['security_mode_enabled_at_utc'])
                && empty($current['security_mode_disabled_at_utc'])) {
                throw new RuntimeException('Сначала выйдите из режима безопасности, затем завершайте инцидент.');
            }
        }
        $operation = $incidents->setIncidentStatus(
            $incidentId,
            $status,
            clean_string($payload['reason'] ?? '', 800),
            $actorRef
        );
    } elseif ($action === 'update_key_check') {
        $operation = $incidents->updateKeyCheck(
            clean_string($payload['incident_id'] ?? '', 64),
            clean_string($payload['key_code'] ?? '', 64),
            clean_string($payload['status'] ?? '', 24),
            clean_string($payload['note'] ?? '', 800),
            $actorRef
        );
    } elseif ($action === 'add_evidence') {
        $operation = $incidents->addEvidence(
            clean_string($payload['incident_id'] ?? '', 64),
            clean_string($payload['evidence_type'] ?? '', 32),
            clean_string($payload['label'] ?? '', 240),
            clean_string($payload['reference'] ?? '', 500),
            clean_string($payload['fingerprint_sha256'] ?? '', 64),
            $actorRef
        );
    } elseif ($action === 'update_restore_status') {
        $operation = $incidents->updateRestoreStatus(
            clean_string($payload['incident_id'] ?? '', 64),
            clean_string($payload['status'] ?? '', 24),
            clean_string($payload['backup_reference'] ?? '', 500),
            clean_string($payload['rollback_reference'] ?? '', 500),
            clean_string($payload['restore_sha'] ?? '', 40),
            clean_string($payload['notes'] ?? '', 1200),
            $actorRef
        );
    } elseif ($action === 'request_high_risk_action') {
        $actionCode = clean_string($payload['action_code'] ?? '', 64);
        $request = [];
        if ($actionCode === 'enable_security_mode') {
            $request['baseline_flags'] = $flagEditor->persistedSnapshot();
        } elseif ($actionCode === 'disable_security_mode') {
            $incidentId = clean_string($payload['incident_id'] ?? '', 64);
            if ($incidents->securityModeBaseline($incidentId) === []) {
                throw new RuntimeException('Для этого инцидента нет сохранённого состояния до режима безопасности.');
            }
        }
        $operation = $incidents->requestHighRiskAction(
            clean_string($payload['incident_id'] ?? '', 64),
            $actionCode,
            clean_string($payload['reason'] ?? '', 800),
            $actorRef,
            $request
        );
    } elseif ($action === 'review_high_risk_action') {
        $actionId = clean_string($payload['action_id'] ?? '', 64);
        $decision = strtolower(clean_string($payload['decision'] ?? '', 16));
        $reviewNote = clean_string($payload['review_note'] ?? '', 800);

        if ($decision === 'reject') {
            $operation = $incidents->rejectHighRiskAction($actionId, $actorRef, $reviewNote);
        } elseif ($decision === 'approve') {
            $claimed = $incidents->claimHighRiskAction($actionId, $actorRef, $reviewNote);
            $actionCode = (string)$claimed['action_code'];
            $result = ['ok'=>true,'changed'=>false];

            try {
                if ($actionCode === 'enable_security_mode') {
                    $before = $flagEditor->persistedSnapshot();
                    $target = $before;
                    $target['maintenance_mode'] = true;
                    $target['maintenance_message'] = 'MINI GAMES WORLD временно работает в режиме безопасности. Пожалуйста, попробуйте позже.';
                    $target['financial_read_only'] = true;
                    $changed = $flagEditor->update($target);
                    $system->recordAudit(
                        'incident_security_mode_enabled',
                        $actorRef,
                        $reviewNote,
                        $changed['before'],
                        $changed['after']
                    );
                    $result['changed'] = (bool)$changed['changed'];
                } elseif ($actionCode === 'disable_security_mode') {
                    $baseline = $incidents->securityModeBaseline((string)$claimed['incident_id']);
                    if ($baseline === []) throw new RuntimeException('Не найдено сохранённое состояние системных переключателей до инцидента.');
                    $changed = $flagEditor->update($baseline);
                    $system->recordAudit(
                        'incident_security_mode_disabled',
                        $actorRef,
                        $reviewNote,
                        $changed['before'],
                        $changed['after']
                    );
                    $result['changed'] = (bool)$changed['changed'];
                } elseif ($actionCode === 'revoke_all_sessions') {
                    $result['revoked_sessions'] = $identity->revokeAllActiveSessions();
                    $result['changed'] = $result['revoked_sessions'] > 0;
                } else {
                    throw new RuntimeException('Неподдерживаемое опасное действие.');
                }

                $operation = $incidents->completeHighRiskAction($actionId, $result, $actorRef);
            } catch (Throwable $operationError) {
                $incidents->failHighRiskAction($actionId, $operationError->getMessage(), $actorRef);
                throw $operationError;
            }
        } else {
            throw new InvalidArgumentException('Выберите подтверждение или отклонение опасного действия.');
        }
    } elseif ($action === 'run_staging_rehearsal') {
        if (!in_array($environment, ['staging','local'], true)) {
            throw new RuntimeException('Безопасная репетиция инцидента доступна только в тестовой или локальной среде.');
        }
        $before = $incidents->snapshot();
        if (is_array($before['active_incident'] ?? null)) {
            throw new RuntimeException('Перед репетицией завершите текущий активный инцидент.');
        }
        $simulation = $incidents->createIncident(
            'Учебная симуляция восстановления',
            'Безопасная проверка карточки инцидента, доказательств и статуса восстановления без изменения production.',
            $actorRef
        );
        $incidentId = (string)$simulation['incident_id'];
        $incidents->addEvidence(
            $incidentId,
            'other',
            'Безопасная симуляция',
            'staging-simulation:no-production-change',
            '',
            $actorRef
        );
        $incidents->updateRestoreStatus(
            $incidentId,
            'verified',
            'staging-simulation',
            '',
            '',
            'Проверена только управляющая цепочка. Реальное восстановление данных не выполнялось.',
            $actorRef
        );
        $incidents->setIncidentStatus($incidentId, 'recovering', 'Переход к этапу восстановления в учебной симуляции.', $actorRef);
        $operation = $incidents->setIncidentStatus($incidentId, 'resolved', 'Учебная симуляция завершена без изменения production.', $actorRef);
    } elseif ($action !== 'snapshot') {
        json_response(['ok'=>false,'error'=>'Неизвестное действие консоли восстановления.'], 422);
    }

    $migrationStatus = (new MigrationRunner(
        $database,
        __DIR__ . '/database/migrations'
    ))->status();
    $persistedFlags = $flagEditor->persistedSnapshot();
    $incidentSnapshot = $incidents->snapshot();

    json_response([
        'ok'=>true,
        'generated_at'=>gmdate(DATE_ATOM),
        'operation'=>$operation,
        'environment'=>$environment,
        'runtime_build'=>FeatureFlagService::BUILD,
        'persisted_flags'=>$persistedFlags,
        'health'=>[
            'database_driver'=>$database->driver(),
            'schema_current'=>(int)($migrationStatus['pending_count'] ?? -1) === 0,
            'pending_migrations'=>(int)($migrationStatus['pending_count'] ?? -1),
            'active_sessions'=>$identity->activeSessionCount(),
            'security_mode'=>($persistedFlags['maintenance_mode'] ?? false) === true
                && ($persistedFlags['financial_read_only'] ?? false) === true,
            'production_changed'=>false,
        ],
        'incidents'=>$incidentSnapshot,
        'incident_statuses'=>IncidentRecoveryService::INCIDENT_STATUSES,
        'key_codes'=>IncidentRecoveryService::KEY_CODES,
        'key_statuses'=>IncidentRecoveryService::KEY_STATUSES,
        'restore_statuses'=>IncidentRecoveryService::RESTORE_STATUSES,
        'evidence_types'=>IncidentRecoveryService::EVIDENCE_TYPES,
        'high_risk_actions'=>IncidentRecoveryService::HIGH_RISK_ACTIONS,
    ]);
} catch (AdminWebAuthException $error) {
    json_response(['ok'=>false,'error'=>$error->publicMessage()], $error->httpStatus());
} catch (InvalidArgumentException $error) {
    json_response(['ok'=>false,'error'=>$error->getMessage()], 422);
} catch (RuntimeException $error) {
    json_response(['ok'=>false,'error'=>$error->getMessage()], 409);
} catch (Throwable $error) {
    error_log('[MiniGamesWorld incident recovery] ' . $error->getMessage());
    json_response(['ok'=>false,'error'=>'Не удалось обработать консоль восстановления.'], 500);
}
