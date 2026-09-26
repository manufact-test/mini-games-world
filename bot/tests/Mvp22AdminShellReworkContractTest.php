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
    'analytics' => 'Аналитика',
    'operations' => 'Задачи и релизы',
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
        && str_contains($page, 'data-admin-section="tests" data-test-coins-card')
        && !str_contains($page, 'data-admin-section="tests" data-replay-card')
        && str_contains($page, 'data-admin-section="antifraud" data-admin-antifraud'),
    'Staging fixtures/reset/test coins must remain under Tests while replay moves to the dedicated anti-fraud work area.'
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
    'Закрыть обращение',
    'Активные',
    'Обработанные',
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
    str_contains($page, 'data-support-mode="active"')
        && str_contains($page, 'data-support-mode="processed"')
        && !str_contains($page, 'data-support-unassign')
        && str_contains($support, "queueMode = requestedTicket ? 'all' : 'active'")
        && str_contains($support, "обращение закрыто и перенесено в «Обработанные»")
        && str_contains($support, "takeButton.hidden = String(ticket.status || '') !== 'open'")
        && str_contains($support, "closeButton.hidden = !['in_progress','waiting_user'].includes"),
    'Support operator lifecycle must separate active/processed tickets and expose only one-way actions.'
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
        && str_contains($shell, "initialParams.has('report')")
        && str_contains($shell, "initialParams.has('afcase')"),
    'Existing support/report deep links must open the matching section in the new shell.'
);

$assert(
    str_contains($page, "Cache-Control: no-store, no-cache, must-revalidate")
        && str_contains($page, 'admin-shell.css?v=16')
        && str_contains($page, 'admin-shell.js?v=10')
        && str_contains($page, 'admin-antifraud.js?v=1'),
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
    str_contains($page, 'admin-support.js?v=8')
        && str_contains($support, "detail.scrollIntoView({")
        && !str_contains($support, "root.scrollIntoView({block:'start', behavior:'smooth'})")
        && str_contains($support, "data-support-attachment-viewer")
        && str_contains($support, "showAttachmentViewer")
        && str_contains($support, "sourceButton.textContent = 'Открываю…'")
        && str_contains($css, '.mgw-admin__attachment-viewer{')
        && str_contains($css, '.mgw-admin__support-detail{scroll-margin-top:72px}'),
    'Support ticket opening must focus the selected detail card and attachments must open in the inline viewer instead of blocked async popups.'
);

$assert(
    str_contains($support, "result.notification?.ok")
        && str_contains($support, "result.notification?.feed_verified")
        && str_contains($support, "Number(result.notification?.delivered_count || 0) > 0")
        && str_contains($support, "Уведомление создано в колокольчике пользователя.")
        && str_contains($support, "Ответ сохранён, но уведомление не подтверждено в колокольчике пользователя.")
        && str_contains($support, "throw error;"),
    'Admin Support reply UX must report success only after the exact recipient bell feed is verified.'
);

$assert(
    str_contains($page, 'admin-reports.js?v=3')
        && str_contains($css, '.mgw-admin__report-card{')
        && str_contains($css, '.mgw-admin__report-message{')
        && str_contains($css, '.mgw-admin__report-tech{'),
    'Moderation queue must publish the readable report-card UI with technical metadata collapsed.'
);

$assert(
    str_contains($page, 'class="mgw-admin__reports"')
        && str_contains($page, 'class="mgw-admin__file-picker"')
        && str_contains($page, 'data-support-file-summary')
        && str_contains($page, 'data-support-reply-file-list')
        && str_contains($support, 'selectedReplyFiles')
        && str_contains($support, 'formatFileSize')
        && str_contains($support, "selectedReplyFiles.length >= 3")
        && str_contains($support, "file.size || 0) > 2_000_000")
        && str_contains($support, "remove.textContent = '×'")
        && str_contains($css, '.mgw-admin__reply-file{')
        && str_contains($css, '.mgw-admin__reply-file-remove{')
        && str_contains($css, '.mgw-admin__support-reply>button')
        && str_contains($css, '.mgw-admin__economy-actions button'),
    'Manual-review fix must preserve mobile spacing, full-width actions and managed Support reply attachments with size/removal controls.'
);

$assert(
    !str_contains($support, "open.textContent = mime === 'application/pdf' ? 'Открыть PDF' : 'Открыть отдельно'")
        && !str_contains($support, 'Открыть отдельно')
        && str_contains($support, "download.textContent = mime === 'application/pdf' ? 'Скачать PDF' : 'Скачать'")
        && str_contains($css, '.mgw-admin__attachment-viewer-actions{')
        && str_contains($css, 'grid-template-columns:1fr;'),
    'Inline attachment viewer must not expose the non-working separate-open action and must keep a reliable download action.'
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
    !str_contains($adminService, '"🎁 Магазин')
        && !str_contains($adminService, 'Заявки ожидают')
        && !str_contains($adminService, '$pendingOrders'),
    'Admin overview must not expose the legacy pending shop-order counter after commerce moved to direct cosmetic purchases.'
);

$assert(
    str_contains($page, 'data-admin-section="support"')
        && str_contains($page, 'data-admin-section="tournaments"')
        && str_contains($page, 'data-admin-section="economy"')
        && str_contains($page, 'data-admin-section="notifications"')
        && str_contains($page, 'data-admin-section="system"'),
    'Existing operational owners must be slotted into the new shell rather than replaced.'
);


$assert(
    str_contains($shell, 'window.MGWAdminUX = AdminUX')
        && str_contains($shell, 'renderPager(container, meta, onPage)')
        && str_contains($css, '.mgw-admin__pager{'),
    'Web Admin must expose one shared pagination pattern instead of unbounded repeated cards.'
);

foreach ([
    'data-support-pagination',
    'data-report-pagination',
    'data-notification-pagination',
    'data-compensation-pagination',
    'data-economy-pagination',
    'data-rating-exclusions-pagination',
    'data-rating-jobs-pagination',
    'data-tournament-review-pagination',
] as $paginationSurface) {
    $assert(
        str_contains($page, $paginationSurface),
        'Potentially long Admin collection must expose bounded navigation: ' . $paginationSurface
    );
}

$assert(
    str_contains($page, 'data-compensation-operation-trigger')
        && str_contains($page, 'data-compensation-operation-list')
        && !str_contains($page, '<select data-compensation-operation-picker>'),
    'Long compensation operation choices must stay inside the managed Admin picker instead of Android native select.'
);

$assert(
    str_contains($page, 'Показать техническую диагностику')
        && str_contains($page, 'mgw-admin__rating-rehearsal-wrap')
        && str_contains($page, 'mgw-admin__list-disclosure')
        && str_contains($css, '.mgw-admin__technical-disclosure'),
    'Technical and secondary Admin content must remain collapsed until requested.'
);

$assert(
    str_contains($css, '.mgw-admin__support-thread{')
        && str_contains($css, 'max-height:min(540px,52vh)')
        && str_contains($css, '.mgw-admin__af-review-tabs::-webkit-scrollbar'),
    'Long Support threads and mobile review rails must stay inside bounded scrollable workspaces.'
);

fwrite(STDOUT, "Mvp22AdminShellReworkContractTest: {$assertions} assertions passed\n");
