<?php
declare(strict_types=1);

$indexPath = __DIR__ . '/index.html';
$html = file_get_contents($indexPath);
if (!is_string($html)) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Mini Games World entrypoint is unavailable.';
    exit;
}

// Emergency rollback: every existing v108 Telegram menu link now serves the
// accepted v105 client graph plus one isolated instant notification-open owner.
// No data or backend state is rolled back.
$html = str_replace(
    './assets/js/production-regression-fix-entry.js?v=96',
    './assets/js/production-clean-entry-v105-fast-notifications.js?v=1051',
    $html
);
$html = str_replace(
    './assets/js/main.js?v=96',
    './assets/js/main-v105.js?v=105',
    $html
);
$html = str_replace(
    'data-hotfix-build="v96-mvp14-root-cause-stabilization"',
    'data-hotfix-build="v105-mvp14-emergency-rollback-fast-notifications"',
    $html
);

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');
echo $html;
