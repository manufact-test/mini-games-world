<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: no-referrer');
}

require __DIR__ . '/core/bootstrap.php';
require_once __DIR__ . '/helpers/AdminWebAuth.php';
require_once __DIR__ . '/operations/AdminOperationsService.php';
require_once __DIR__ . '/notifications/AdminNotificationEventService.php';

function mgw_dispatch_due_admin_task_reminders(
    array $config,
    DatabaseConnectionInterface $database,
    AdminOperationsService $operations,
    ?DateTimeImmutable $now = null
): array {
    $now = ($now ?? new DateTimeImmutable('now', new DateTimeZone('UTC')))
        ->setTimezone(new DateTimeZone('UTC'));
    $candidates = $operations->dueTaskReminderCandidates($now, 100);
    $summary = [
        'checked'=>count($candidates),
        'sent'=>0,
        'skipped'=>0,
        'failed'=>0,
        'task_ids'=>[],
    ];
    if ($candidates === []) return $summary;

    $adminIds = array_values(array_unique(array_filter(array_map(
        static fn(mixed $value): string => trim((string)$value),
        is_array($config['admin_ids'] ?? null) ? $config['admin_ids'] : []
    ), static fn(string $value): bool => $value !== '')));
    $storage = StorageFactory::createJson((string)($config['data_dir'] ?? (__DIR__ . '/data')));
    $bell = new AdminNotificationEventService();
    $telegram = new TelegramService($config);
    $mgwByChatId = [];

    foreach ($candidates as $task) {
        if (!is_array($task)) continue;
        $taskId = trim((string)($task['task_id'] ?? ''));
        $actorRef = trim((string)($task['created_by_ref'] ?? ''));
        if ($taskId === '' || preg_match('/^telegram:(.+)$/', $actorRef, $matches) !== 1) {
            $summary['skipped']++;
            continue;
        }

        $chatId = trim((string)($matches[1] ?? ''));
        if ($chatId === '' || !in_array($chatId, $adminIds, true)) {
            $summary['skipped']++;
            continue;
        }

        if (!$operations->claimTaskReminder($taskId, $now)) {
            $summary['skipped']++;
            continue;
        }

        try {
            if (!array_key_exists($chatId, $mgwByChatId)) {
                $identityRows = $database->fetchAll(
                    'SELECT mgw_id
                     FROM mgw_identities
                     WHERE provider=:provider AND provider_subject=:provider_subject
                     LIMIT 2',
                    ['provider'=>'telegram','provider_subject'=>$chatId]
                );
                $mgwByChatId[$chatId] = count($identityRows) === 1
                    ? trim((string)($identityRows[0]['mgw_id'] ?? ''))
                    : '';
            }
            $mgwId = (string)$mgwByChatId[$chatId];
            if ($mgwId === '') {
                throw new RuntimeException('Не найден MGW-ID администратора для напоминания.');
            }

            $due = (new DateTimeImmutable((string)$task['due_at_utc'], new DateTimeZone('UTC')))
                ->setTimezone(new DateTimeZone('UTC'));
            $title = trim((string)($task['title'] ?? 'Задача'));
            $event = [
                'request_id'=>'admin-task-due:' . $taskId,
                'source_type'=>'system',
                'audience_type'=>'one',
                'target_mgw_id'=>$mgwId,
                'title'=>'⏰ Срок задачи наступил',
                'text'=>'Наступил срок задачи «' . $title . '».',
                'deep_link'=>'',
                'scheduled_at'=>$due->format(DATE_ATOM),
            ];
            $published = $storage->transaction(
                static function (array &$data) use ($bell, $event): array {
                    return $bell->createEvent(
                        $data,
                        $event,
                        'system:admin-task-reminder'
                    );
                }
            );

            $message = "⏰ Срок задачи наступил\n\n" . $title;
            $owner = trim((string)($task['owner_ref'] ?? ''));
            if ($owner !== '') $message .= "\nОтветственный: " . $owner;
            $message .= "\n\nОткройте раздел «Задачи и релизы» в панели администратора.";

            $params = [
                'chat_id'=>$chatId,
                'text'=>$message,
                'disable_web_page_preview'=>true,
            ];
            $adminUrl = WebAppLaunchUrl::admin($config);
            if ($adminUrl !== '') {
                $adminUrl = preg_replace('/#.*$/', '', $adminUrl) . '#operations';
                $params['reply_markup'] = [
                    'inline_keyboard'=>[[
                        ['text'=>'🌐 Открыть задачи','web_app'=>['url'=>$adminUrl]],
                    ]],
                ];
            }

            $telegramResult = $telegram->api('sendMessage', $params);
            if (empty($telegramResult['ok'])) {
                throw new RuntimeException('Telegram не подтвердил отправку напоминания.');
            }

            $marked = $operations->markTaskReminderSent(
                $taskId,
                [
                    'bell_event_id'=>(string)($published['event_id'] ?? ''),
                    'bell_recipient_count'=>(int)($published['recipient_count'] ?? 0),
                    'telegram_chat_id'=>$chatId,
                    'due_at_utc'=>$due->format(DATE_ATOM),
                ],
                $now
            );
            if (!$marked) {
                $summary['skipped']++;
                continue;
            }

            $summary['sent']++;
            $summary['task_ids'][] = $taskId;
        } catch (Throwable $error) {
            $summary['failed']++;
            error_log('[MiniGamesWorld task reminder] ' . $taskId . ': ' . $error->getMessage());
        }
    }

    return $summary;
}

try {
    $databaseConfig = DatabaseConfig::fromApplicationConfig($config);
    if (!$databaseConfig->enabled()) {
        if (PHP_SAPI === 'cli') {
            fwrite(STDERR, "Операционные данные недоступны: база данных отключена.\n");
            exit(2);
        }
        json_response(['ok'=>false,'error'=>'Операционные данные недоступны: база данных отключена.'], 503);
    }

    $database = PdoConnectionFactory::create($databaseConfig);
    $service = new AdminOperationsService($database);

    $argvList = is_array($argv ?? null) ? $argv : [];
    $cliReminderDispatch = PHP_SAPI === 'cli'
        && in_array('--dispatch-task-reminders', $argvList, true);
    if ($cliReminderDispatch) {
        $summary = mgw_dispatch_due_admin_task_reminders($config, $database, $service);
        fwrite(STDOUT, json_encode(
            ['ok'=>$summary['failed'] === 0,'task_reminders'=>$summary],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        ) . PHP_EOL);
        exit($summary['failed'] === 0 ? 0 : 1);
    }

    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, "Используйте --dispatch-task-reminders для запуска напоминаний.\n");
        exit(2);
    }

    if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
        json_response(['ok'=>false,'error'=>'Метод запроса не поддерживается.'], 405);
    }

    $payload = json_decode(file_get_contents('php://input') ?: '{}', true);
    if (!is_array($payload)) {
        json_response(['ok'=>false,'error'=>'Некорректный запрос.'], 400);
    }

    $admin = AdminWebAuth::authorize($config, (string)($payload['initData'] ?? ''));
    $actorRef = 'telegram:' . trim((string)($admin['id'] ?? 'unknown'));
    $action = strtolower(trim((string)($payload['action'] ?? 'snapshot')));
    $result = null;

    if ($action === 'create_task') {
        $result = $service->createTask(
            is_array($payload['task'] ?? null) ? $payload['task'] : [],
            $actorRef
        );
    } elseif ($action === 'update_task') {
        $result = $service->updateTask(
            (string)($payload['task_id'] ?? ''),
            is_array($payload['changes'] ?? null) ? $payload['changes'] : [],
            $actorRef
        );
    } elseif ($action === 'create_plan') {
        $result = $service->createPlan(
            is_array($payload['plan'] ?? null) ? $payload['plan'] : [],
            $actorRef
        );
    } elseif ($action === 'update_plan') {
        $result = $service->updatePlan(
            (string)($payload['plan_id'] ?? ''),
            is_array($payload['changes'] ?? null) ? $payload['changes'] : [],
            $actorRef
        );
    } elseif ($action === 'create_release') {
        $result = $service->createRelease(
            is_array($payload['release'] ?? null) ? $payload['release'] : [],
            $actorRef
        );
    } elseif ($action === 'update_release') {
        $result = $service->updateRelease(
            (string)($payload['release_id'] ?? ''),
            is_array($payload['changes'] ?? null) ? $payload['changes'] : [],
            $actorRef
        );
    } elseif ($action === 'update_season_readiness') {
        $result = $service->updateSeasonReadiness(
            (string)($payload['target_season_id'] ?? ''),
            is_array($payload['states'] ?? null) ? $payload['states'] : [],
            $actorRef
        );
    } elseif ($action !== 'snapshot') {
        json_response(['ok'=>false,'error'=>'Некорректное действие раздела задач и релизов.'], 400);
    }

    $reminders = mgw_dispatch_due_admin_task_reminders($config, $database, $service);

    json_response([
        'ok'=>true,
        'environment'=>(string)($config['environment'] ?? 'production'),
        'runtime_build'=>FeatureFlagService::BUILD,
        'result'=>$result,
        'task_reminders'=>$reminders,
        'operations'=>$service->snapshot(),
        'task_statuses'=>AdminOperationsService::TASK_STATUSES,
        'task_recurrences'=>AdminOperationsService::TASK_RECURRENCES,
        'task_categories'=>AdminOperationsService::TASK_CATEGORIES,
        'plan_statuses'=>AdminOperationsService::PLAN_STATUSES,
        'plan_categories'=>AdminOperationsService::PLAN_CATEGORIES,
        'release_environments'=>AdminOperationsService::RELEASE_ENVIRONMENTS,
    ]);
} catch (AdminWebAuthException $error) {
    json_response(['ok'=>false,'error'=>$error->publicMessage()], $error->httpStatus());
} catch (InvalidArgumentException $error) {
    json_response(['ok'=>false,'error'=>$error->getMessage()], 422);
} catch (Throwable $error) {
    error_log('[MiniGamesWorld admin operations] ' . $error->getMessage());
    json_response(['ok'=>false,'error'=>'Не удалось обработать раздел задач и релизов.'], 500);
}
