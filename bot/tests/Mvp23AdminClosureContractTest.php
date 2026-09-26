<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$read = static function (string $path) use ($root): string {
    $content = file_get_contents($root . '/' . $path);
    if (!is_string($content)) throw new RuntimeException('Missing closure source: ' . $path);
    return $content;
};

$page = $read('app/admin.php');
$shell = $read('app/assets/js/admin-shell.js');
$systemEndpoint = $read('bot/admin-system.php');
$systemService = $read('bot/system/SystemAdminService.php');
$operationsEndpoint = $read('bot/admin-operations.php');
$operationsService = $read('bot/operations/AdminOperationsService.php');
$incidentEndpoint = $read('bot/admin-incident.php');
$incidentService = $read('bot/incident/IncidentRecoveryService.php');
$accountData = $read('bot/account-data.php');
$accountLifecycle = $read('bot/accounts/AccountDataLifecycleService.php');
$moderation = $read('bot/moderation/ModerationService.php');
$compensation = $read('bot/compensation/CompensationService.php');
$antifraud = $read('bot/antifraud/AntiFraudCaseService.php');
$tournaments = $read('bot/tournaments/TournamentAdminService.php');
$analytics = $read('bot/analytics/ProductEconomyAnalyticsService.php');
$notifications = $read('bot/notifications/AdminNotificationEventService.php');

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

foreach ([
    'overview'=>'Обзор',
    'analytics'=>'Аналитика',
    'operations'=>'Задачи и релизы',
    'users'=>'Пользователи',
    'antifraud'=>'Проверка игр',
    'support'=>'Поддержка',
    'tournaments'=>'Турниры и сезоны',
    'economy'=>'Экономика',
    'notifications'=>'Уведомления',
    'system'=>'Система',
    'incident'=>'Инциденты',
    'tests'=>'Тесты',
] as $section => $label) {
    $assert(
        str_contains($page, 'data-admin-nav-target="' . $section . '">' . $label . '</button>')
            && str_contains($shell, $section . ':['),
        'Admin closure missing first-class section: ' . $section
    );
}

foreach ([
    '../bot/admin-read.php',
    '../bot/admin-economy.php',
    '../bot/admin-compensation.php',
    '../bot/admin-replay.php',
    '../bot/admin-reports.php',
    '../bot/admin-support.php',
    '../bot/admin-notifications.php',
    '../bot/admin-rating.php',
    '../bot/admin-tournaments.php',
    '../bot/admin-system.php',
    '../bot/admin-incident.php',
    '../bot/admin-analytics.php',
    '../bot/admin-operations.php',
] as $endpoint) {
    $assert(str_contains($page, $endpoint), 'Admin closure missing API wiring: ' . $endpoint);
}

$assert(
    str_contains($page, "Cache-Control: no-store, no-cache, must-revalidate")
        && str_contains($page, "Content-Security-Policy:")
        && str_contains($page, 'Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=()'),
    'Admin shell must retain hardened no-store browser boundary.'
);

$assert(
    str_contains($systemEndpoint, 'AdminWebAuth::authorize')
        && str_contains($incidentEndpoint, 'AdminWebAuth::authorize')
        && str_contains($operationsEndpoint, 'AdminWebAuth::authorize'),
    'Critical Admin owners must remain behind Telegram Admin authorization.'
);

$assert(
    str_contains($systemService, "if ($environment !== 'production')")
        && str_contains($systemService, 'Запуск на боевом сервере нельзя выполнить из тестовой или локальной среды.')
        && str_contains($systemService, 'READINESS_THRESHOLD = 500')
        && str_contains($systemService, 'staging_accepted_sha')
        && str_contains($systemService, 'checklistComplete')
        && str_contains($systemEndpoint, "'activate_official_competition'"),
    'Official rating-season activation must remain an explicit production-only Admin action gated by readiness and accepted staging.'
);

$assert(
    str_contains($systemService, "staging_rehearsal_active")
        && str_contains($systemService, "PerGameRatingService::STATE_PRESEASON")
        && str_contains($systemService, "'staging_rehearsal_started'")
        && str_contains($systemService, "'staging_rehearsal_stopped'"),
    'Staging season rehearsal must remain reversible and distinct from official production activation.'
);

$assert(
    str_contains($operationsEndpoint, '--dispatch-task-reminders')
        && str_contains($operationsService, 'claimTaskReminder')
        && str_contains($operationsService, 'markTaskReminderSent')
        && str_contains($notifications, 'Canonical producer for admin/system/support bell events.')
        && !str_contains($operationsService, 'INSERT INTO mgw_notifications'),
    'Task reminders must keep one CLI scheduler seam and one canonical notification producer.'
);

$assert(
    str_contains($incidentService, "ACTION_PENDING = 'pending_second_review'")
        && str_contains($incidentService, 'Опасное действие должен подтвердить другой администратор.')
        && str_contains($incidentService, "'enable_security_mode'")
        && str_contains($incidentService, "'disable_security_mode'")
        && str_contains($incidentService, "'revoke_all_sessions'")
        && !str_contains($incidentEndpoint, 'DROP TABLE')
        && !str_contains($incidentEndpoint, 'git reset'),
    'Incident recovery must keep second-admin confirmation and no silent destructive recovery.'
);

$assert(
    str_contains($accountData, 'AdminWebAuth') === false
        && str_contains($accountLifecycle, 'runRetention')
        && str_contains($accountLifecycle, 'identity_tombstones_expired'),
    'Account-data lifecycle must stay on its dedicated player/data lifecycle owner, not become an Admin write shortcut.'
);

$assert(
    str_contains($moderation, 'permanent')
        && str_contains($moderation, 'second')
        && !str_contains($moderation, 'auto-ban'),
    'Moderation closure must retain review boundaries for permanent restrictions.'
);

$assert(
    !str_contains($compensation, 'UPDATE mgw_wallet')
        && !str_contains($compensation, 'SET balance')
        && str_contains($compensation, 'ledger'),
    'Compensation must remain ledger-based without direct balance edits.'
);

$assert(
    !str_contains($antifraud, 'autoBan')
        && !str_contains($antifraud, 'auto_ban')
        && str_contains($antifraud, 'case'),
    'Anti-fraud signals must remain review/case based rather than one-signal automatic bans.'
);

$assert(
    str_contains($tournaments, 'audit')
        || str_contains($tournaments, 'reason'),
    'Tournament Admin owner must retain auditable operator actions.'
);

$assert(
    str_contains($analytics, 'telemetry')
        || str_contains($analytics, 'event'),
    'Analytics must consume product telemetry rather than fabricate historical data.'
);

$assert(
    !str_contains($page, '<h1>Web Admin</h1>')
        && str_contains($page, '<h1>Панель администратора</h1>')
        && str_contains($page, 'data-admin-back-overview'),
    'Operator-facing Admin shell must retain Russian copy and explicit return navigation.'
);

$assert(
    substr_count($page, 'data-admin-nav-target="incident"') === 1,
    'Incident workspace must have exactly one first-class navigation owner.'
);

$assert(
    !str_contains($incidentEndpoint, 'MGW_CONFIG_FILE =')
        && !str_contains($systemEndpoint, 'MGW_CONFIG_FILE ='),
    'Admin endpoints must not embed private configuration or secrets.'
);

fwrite(STDOUT, "MVP-23 Admin closure contract OK ({$assertions} assertions).\n");
