<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$read = static function (string $path) use ($root): string {
    $content = file_get_contents($root . '/' . $path);
    if (!is_string($content)) throw new RuntimeException('Missing contract source: ' . $path);
    return $content;
};

$page = $read('app/admin.php');
$css = $read('app/assets/css/admin-shell.css');
$shell = $read('app/assets/js/admin-shell.js');
$client = $read('app/assets/js/admin-operations.js');
$endpoint = $read('bot/admin-operations.php');
$service = $read('bot/operations/AdminOperationsService.php');
$migration = $read('bot/database/migrations/20260926_0066_create_admin_operations.php');
$season = $read('bot/ratings/SeasonLifecycleService.php');
$calendar = $read('bot/ratings/SeasonCalendar.php');
$notifications = $read('bot/notifications/AdminNotificationEventService.php');
$manifest = $read('bot/helpers/staging-e2e-runtime-files.txt');

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

$assert(
    str_contains($page, 'data-admin-nav-target="operations">Задачи и релизы</button>')
        && str_contains($page, 'data-admin-section="operations" data-admin-operations')
        && str_contains($page, 'data-operations-api="../bot/admin-operations.php"')
        && str_contains($page, 'admin-operations.js?v=3&mvp22_7=task-reminders-v1')
        && str_contains($shell, "operations:['Задачи и релизы'"),
    'MVP-22.7 must be a first-class Web Admin workspace.'
);

foreach ([
    'Регулярные задачи',
    'Подготовка следующего рейтингового сезона',
    'Планы на будущее',
    'Журнал релизов',
    'Ответственный',
    'Результат / комментарий',
    'Известные проблемы',
    'Ссылка на инструкцию / точку отката',
] as $copy) {
    $assert(str_contains($page . "\n" . $client, $copy), 'MVP-22.7 UI missing required operator concept: ' . $copy);
}

$assert(
    str_contains($endpoint, 'AdminWebAuth::authorize')
        && str_contains($endpoint, 'new AdminOperationsService')
        && str_contains($endpoint, 'new AdminNotificationEventService')
        && !str_contains($endpoint, 'INSERT INTO mgw_notifications')
        && !str_contains($endpoint, 'UPDATE mgw_notifications'),
    'Operations endpoint must use Admin auth and the canonical notification producer without writing notification storage directly.'
);

$assert(
    str_contains($migration, 'mgw_admin_tasks')
        && str_contains($migration, 'mgw_admin_future_plans')
        && str_contains($migration, 'mgw_admin_release_log')
        && str_contains($migration, 'mgw_admin_operations_audit')
        && !str_contains($migration, 'CREATE TABLE IF NOT EXISTS mgw_season_preparation_reminders'),
    'MVP-22.7 may own Admin work records but must not duplicate canonical season reminder storage.'
);

$assert(
    str_contains($service, 'FROM mgw_season_preparation_reminders r')
        && str_contains($service, 'new SeasonLifecycleService')
        && str_contains($service, 'updateRewardReadiness')
        && str_contains($season, 'mgw_season_preparation_reminders')
        && str_contains($calendar, 'reminderDueAt'),
    'T-21/T-14/T-7 preparation must project existing SeasonLifecycle reminders and write readiness through that owner.'
);

foreach ([
    'Сезон заканчивается через 3 недели — подготовьте награды следующего сезона',
    'До конца сезона 2 недели — пакет наград следующего сезона ещё не готов',
    'До конца сезона 1 неделя — требуется завершить пакет наград следующего сезона',
] as $copy) {
    $assert(str_contains($service, $copy), 'Missing season preparation escalation copy: ' . $copy);
}

$assert(
    str_contains($service, "['once', 'daily', 'weekly', 'monthly', 'quarterly']")
        && str_contains($service, 'ensureNextRecurringTask')
        && str_contains($service, "'weekly'=>\$base->modify('+7 days')")
        && str_contains($service, "'quarterly'=>\$base->modify('+3 months')"),
    'Regular tasks must own explicit recurrence without adding Cron.'
);

$assert(
    str_contains($service, "PLAN_STATUSES = ['idea', 'planned', 'in_progress', 'blocked', 'done', 'cancelled']")
        && str_contains($service, 'PLAN_CATEGORIES')
        && str_contains($migration, 'plan_status')
        && str_contains($migration, 'category'),
    'Future plans must persist statuses and categories.'
);

$assert(
    str_contains($migration, 'version_label')
        && str_contains($migration, 'known_issues_text')
        && str_contains($migration, 'rollback_link')
        && str_contains($service, 'release_sha')
        && str_contains($service, 'https://github.com/manufact-test/mini-games-world/')
        && str_contains($service, 'Старые релизы не восстанавливаются задним числом.'),
    'Release history must preserve version/SHA/known issues/rollback reference without invented history.'
);

$assert(
    str_contains($notifications, 'Canonical producer for admin/system/support bell events.')
        && !str_contains($migration, 'mgw_notifications')
        && !str_contains($service, 'INSERT INTO mgw_notifications'),
    'Existing notification producer must remain the single message owner.'
);

$assert(
    str_contains($service, 'dueTaskReminderCandidates')
        && str_contains($service, 'claimTaskReminder')
        && str_contains($service, 'markTaskReminderSent')
        && str_contains($service, "action_code'=>'due_reminder_sent'")
        && str_contains($endpoint, 'mgw_dispatch_due_admin_task_reminders')
        && str_contains($endpoint, "'request_id'=>'admin-task-due:'")
        && str_contains($endpoint, "'source_type'=>'system'")
        && str_contains($endpoint, "'audience_type'=>'one'")
        && str_contains($endpoint, "->api('sendMessage'")
        && str_contains($endpoint, '--dispatch-task-reminders')
        && str_contains($client, 'window.setInterval')
        && str_contains($page, 'Срок — это момент напоминания'),
    'Due tasks must produce exactly one canonical bell event plus one Telegram admin reminder, with CLI and in-Admin dispatch paths.'
);

$assert(
    str_contains($service, "due_reminder_claimed")
        && str_contains($service, "claimTtlSeconds")
        && str_contains($service, "WHERE action_code <> :internal_claim"),
    'Concurrent reminder workers must use a short-lived claim without polluting the visible operations audit.'
);

foreach ([
    'bot/admin-operations.php',
    'bot/operations/AdminOperationsService.php',
    'bot/database/migrations/20260926_0066_create_admin_operations.php',
    'app/assets/js/admin-operations.js',
] as $path) {
    $assert(str_contains($manifest, $path), 'Exact staging fingerprint missing MVP-22.7 runtime file: ' . $path);
}

$assert(
    str_contains($css, '/* MVP-22.7 · tasks, Future Plans and release log */')
        && str_contains($css, '.mgw-admin__operations-kpis')
        && str_contains($css, '@media(max-width:520px)'),
    'Operations workspace must have isolated responsive styling.'
);

$assert(
    str_contains($page, 'data-operations-task-feedback')
        && str_contains($client, 'const requireValue =')
        && str_contains($client, 'focusInvalid')
        && str_contains($client, 'renderPagedList')
        && str_contains($client, "listPages = {tasks:1, closed:1, plans:1, releases:1}")
        && str_contains($css, '.mgw-admin__operations-item--details')
        && str_contains($css, '[data-admin-operations] textarea')
        && str_contains($css, 'height:auto!important'),
    'Manual acceptance corrective must surface validation, keep editors collapsed and bound long operation lists.'
);

$assert(
    !str_contains($page, '<summary>Future Plans</summary>')
        && !str_contains($page, '<summary>Release log</summary>')
        && !str_contains($page, 'Текущая runtime-сборка')
        && !str_contains($page, '<strong>READY</strong>'),
    'Operator-facing MVP-22.7 copy must stay Russian.'
);

$assert(
    !str_contains($service, 'shell_exec')
        && !str_contains($service, 'exec(')
        && !str_contains($endpoint, 'cron')
        && !str_contains($service, 'production rollback'),
    'MVP-22.7 must not install Cron or execute rollback actions.'
);

fwrite(STDOUT, "Mvp22_7AdminOperationsContractTest: {$assertions} assertions passed\n");
