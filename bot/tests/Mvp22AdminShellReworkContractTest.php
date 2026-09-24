<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$read = static fn(string $path): string => (string)file_get_contents($root . '/' . $path);

$page = $read('app/admin.php');
$css = $read('app/assets/css/admin-shell.css');
$shell = $read('app/assets/js/admin-shell.js');
$support = $read('app/assets/js/admin-support.js');
$tournaments = $read('app/assets/js/admin-tournaments.js');
$rating = $read('app/assets/js/admin-rating.js');
$notifications = $read('app/assets/js/admin-notifications.js');
$telegram = $read('bot/services/TelegramService.php');
$adminService = $read('bot/services/AdminService.php');
$launch = $read('bot/helpers/WebAppLaunchUrl.php');

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

foreach ([
    'overview' => 'Обзор',
    'users' => 'Пользователи',
    'support' => 'Поддержка',
    'tournaments' => 'Турниры и сезоны',
    'economy' => 'Экономика',
    'notifications' => 'Уведомления',
    'system' => 'Система',
    'tests' => 'Тесты',
] as $key => $label) {
    $assert(
        str_contains($page, 'data-admin-nav-target="' . $key . '">' . $label . '</button>'),
        'Missing top-level Web Admin navigation item: ' . $label
    );
}

$assert(
    !str_contains($page, 'data-admin-nav-target="work"')
        && !str_contains($page, '>Работа</button>'),
    'Web Admin must not introduce the rejected extra parent section "Работа".'
);

$assert(
    str_contains($page, 'data-admin-environment-badge')
        && str_contains($shell, "environmentLabel = rawEnvironment === 'staging'")
        && str_contains($shell, "rawEnvironment === 'production'")
        && str_contains($css, '.mgw-admin__environment-badge[data-environment="staging"]')
        && str_contains($css, '.mgw-admin__environment-badge[data-environment="production"]'),
    'Web Admin must show an explicit STAGING / PRODUCTION environment indicator.'
);

$testSection = strpos($page, 'data-admin-section="tests" data-tournament-test-tools');
$manualPanel = strpos($page, 'data-tournament-manual-panel');
$progressPanel = strpos($page, 'data-tournament-progression-panel');
$resetPanel = strpos($page, 'data-tournament-reset-panel');
$assert(
    $testSection !== false
        && $manualPanel !== false && $manualPanel > $testSection
        && $progressPanel !== false && $progressPanel > $testSection
        && $resetPanel !== false && $resetPanel > $testSection
        && str_contains($page, 'data-admin-section="tests" data-replay-card')
        && str_contains($page, 'data-admin-section="tests" data-test-coins-card'),
    'Staging fixtures, reset, replay diagnostics and test coins must live under Tests.'
);

$assert(
    str_contains($shell, "testsButton.hidden = rawEnvironment === 'production'")
        && str_contains($css, '.mgw-admin.is-production [data-admin-section="tests"]{display:none!important}'),
    'Production must hide the Tests section and its tools.'
);

$assert(
    str_contains($page, 'class="mgw-admin__overview" data-admin-dashboard')
        && str_contains($page, 'data-overview-support')
        && str_contains($page, 'data-overview-tournament')
        && str_contains($page, 'data-overview-season')
        && str_contains($shell, 'const renderDashboard = raw =>')
        && str_contains($shell, "mgw:admin-support-summary")
        && str_contains($shell, "mgw:admin-tournament-summary")
        && str_contains($shell, "mgw:admin-rating-summary")
        && !str_contains($page, '<pre data-admin-dashboard>'),
    'Overview must be a structured operational dashboard with live support/tournament/season summaries.'
);

foreach ([
    'Взять в работу',
    'Вернуть в очередь',
    'Ответственный не назначен',
    'Связанные данные: нет',
    'Технические данные',
    'Отправить ответ',
    'Поиск и фильтры',
] as $copy) {
    $assert(str_contains($page, $copy), 'Support work area missing operator copy: ' . $copy);
}
$assert(
    str_contains($support, "historyLabel = value =>")
        && str_contains($support, "root.classList.add('is-ticket-open')")
        && str_contains($support, "back.addEventListener('click'")
        && str_contains($css, '[data-admin-support].is-ticket-open [data-support-queue-panel]{display:none}'),
    'Support must use responsive list/detail flow with human history labels and a narrow-screen back action.'
);

$assert(
    str_contains($tournaments, "root.querySelector('[data-tournament-manual-panel]')")
        && str_contains($tournaments, "root.querySelector('[data-tournament-reset-panel]')"),
    'Tournament staging helpers must remain wired after moving into Tests.'
);

foreach ([
    'Rating Admin request failed.',
    'Recalculation jobs ещё не запускались.',
    'Reviewed exclusion сохранён.',
    'Notification pipeline готов.',
    'Загружаю bell events',
    'Создаю bell event',
] as $oldCopy) {
    $assert(
        !str_contains($rating . "\n" . $notifications, $oldCopy),
        'Old English operator copy must not remain visible: ' . $oldCopy
    );
}

$assert(
    str_contains($telegram, "'text' => '🌐 Открыть Web Admin'")
        && preg_match("/return \[\s*'inline_keyboard' => \[\[/", $telegram) === 1
        && str_contains($telegram, '$mainAdminCallbacks[\'admin:dashboard\']')
        && str_contains($telegram, '$mainAdminCallbacks[\'admin:orders\']')
        && str_contains($telegram, '$mainAdminCallbacks[\'admin:support\']')
        && str_contains($telegram, '$mainAdminCallbacks[\'admin:users\']'),
    'Visible legacy Telegram admin menu must collapse to one Web Admin button.'
);

foreach (['admin:dashboard','admin:orders','admin:support','admin:users','admin:payments','admin:system_check'] as $callback) {
    $assert(
        str_contains($adminService, $callback),
        'Legacy backend callback must remain available but hidden: ' . $callback
    );
}

$assert(
    str_contains($launch, "private const ADMIN_PATH = '/app/admin.php?v=2'"),
    'Reworked admin HTML must cache-bust the authoritative Telegram admin launch parent.'
);

$assert(
    str_contains($page, 'data-admin-section="support"')
        && str_contains($page, 'data-admin-section="tournaments"')
        && str_contains($page, 'data-admin-section="economy"')
        && str_contains($page, 'data-admin-section="notifications"')
        && str_contains($page, 'data-admin-section="system"'),
    'Existing operational owners must be slotted into the new shell rather than replaced.'
);

fwrite(STDOUT, "Mvp22AdminShellReworkContractTest: {$assertions} assertions passed\n");
