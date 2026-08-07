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

$telegramScript = '<script src="https://telegram.org/js/telegram-web-app.js"></script>';
$invitePendingBackdropStyle = '<link rel="stylesheet" href="./assets/css/mvp14-invite-pending-backdrop-v1.css?v=1" />';
$importMap = <<<'HTML'
<script type="importmap">
{
  "imports": {
    "./assets/js/api/client.js?v=34": "./assets/js/api/client.js?v=1131",
    "./assets/js/api/client.js?v=38": "./assets/js/api/client.js?v=1131",
    "./assets/js/api/client.js?v=46": "./assets/js/api/client.js?v=1131",
    "./assets/js/api/client.js?v=47": "./assets/js/api/client.js?v=1131",
    "./assets/js/session.js?v=21": "./assets/js/session.js?v=1131",
    "./assets/js/session.js?v=27": "./assets/js/session.js?v=1131"
  }
}
</script>
HTML;

if (!str_contains($html, $telegramScript)) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Mini Games World import-map anchor is unavailable.';
    exit;
}

$html = str_replace(
    $telegramScript,
    $invitePendingBackdropStyle . "\n  " . $telegramScript . "\n  " . $importMap,
    $html
);
$html = str_replace(
    './assets/js/production-regression-fix-entry.js?v=102',
    './assets/js/production-clean-entry-v110.js?v=1121',
    $html
);
$html = str_replace(
    './assets/js/main.js?v=98.3',
    './assets/js/main-v110.js?v=1135&pending=1',
    $html
);
$html = str_replace(
    'data-hotfix-build="v98-mvp14-notification-canonical-owner"',
    'data-hotfix-build="v110-mvp14-interface-invite-speed-v1135"',
    $html
);

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');
header('X-MGW-Api-Session-Graph: v1131');
header('X-MGW-Notification-Graph: v1134');
header('X-MGW-Invite-Graph: v1135');
echo $html;
