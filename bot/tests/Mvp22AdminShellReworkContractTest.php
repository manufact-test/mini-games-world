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
        && str_contains($css, '.mgw-admin__environment-badge[data-environment="production"]')
        && str_contains($shell, "'ТЕСТОВАЯ СРЕДА'")
        && str_contains($shell, "'РАБОЧАЯ СРЕДА'"),
    'Admin must show explicit, human-readable Russian environment labels.'
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
    str_contains($telegram, "'text' => '🌐 Открыть панель администратора'")
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
    str_contains($shell, "initialParams.has('ticket')")
        && str_contains($shell, "initialParams.has('report')"),
    'Existing support/report deep links must open the matching section in the new shell.'
);

$assert(
    str_contains($page, "Cache-Control: no-store, no-cache, must-revalidate")
        && str_contains($page, 'admin-shell.css?v=9')
        && str_contains($page, 'admin-shell.js?v=6'),
    'Admin-only rework must stay no-store and publish fresh child asset identities without changing the shared game launch owner.'
);

$assert(
    str_contains($page, '<h1>Панель администратора</h1>')
        && !str_contains($page, '<h1>Web Admin</h1>')
        && str_contains($page, 'data-admin-back-overview')
        && str_contains($shell, "backOverview?.addEventListener('click'")
        && str_contains($css, '.mgw-admin__nav{position:static;display:grid;grid-template-columns:repeat(2,minmax(0,1fr))'),
    'Manual-review fix must keep the mobile admin navigation complete and provide a clear route back to Overview.'
);

$assert(
    str_contains($page, 'class="mgw-admin__reports"')
        && str_contains($page, 'class="mgw-admin__file-picker"')
        && str_contains($page, 'data-support-file-summary')
        && str_contains($support, "fileSummary.textContent = files.length === 1")
        && str_contains($css, '.mgw-admin__support-reply>button')
        && str_contains($css, '.mgw-admin__economy-actions button'),
    'Manual-review fix must preserve mobile spacing, full-width actions and the custom support file picker.'
);

foreach ([
    'COMPETITION',
    'SCORE ROWS',
    'PARTICIPATION',
    'PENDING PROJECTION',
    'REVIEW EXCLUSIONS',
    'BOT OUTCOMES',
    'BOT VIOLATIONS',
] as $oldVisibleMetric) {
    $assert(
        !str_contains($page, '>' . $oldVisibleMetric . '<'),
        'English rating metric must not be hard-coded in the operator UI: ' . $oldVisibleMetric
    );
}

$assert(
    str_contains($rating, "season.toLowerCase() === 'preseason' ? 'Предсезон' : season")
        && str_contains($rating, "state.toUpperCase()"),
    'Rating UI must localize preseason and competition states before rendering.'
);

$assert(
    !str_contains($adminService, '"🎮 Последние матчи')
        && !str_contains($adminService, '"🧾 Последние операции'),
    'Telegram admin dashboard must omit the long recent matches and recent operations sections.'
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
