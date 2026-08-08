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
$importMap = <<<'HTML'
<script type="importmap">
{
  "imports": {
    "./assets/js/api/client.js?v=34": "./assets/js/api/client.js?v=1131",
    "./assets/js/api/client.js?v=38": "./assets/js/api/client.js?v=1131",
    "./assets/js/api/client.js?v=46": "./assets/js/api/client.js?v=1131",
    "./assets/js/api/client.js?v=47": "./assets/js/api/client.js?v=1131",
    "./assets/js/session.js?v=21": "./assets/js/session.js?v=1131",
    "./assets/js/session.js?v=27": "./assets/js/session.js?v=1131",
    "./assets/js/screens/game-screen-v102-safe.js?v=102": "./assets/js/screens/game-screen-v102-safe.js?v=102&b=76d5b9d8d659",
    "./assets/js/production-v110-acceptance-runtime.js?v=110": "./assets/js/production-v110-acceptance-runtime.js?v=110&b=254aaa33a021"
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
    $telegramScript . "\n  " . $importMap,
    $html
);
$html = str_replace(
    './assets/js/production-regression-fix-entry.js?v=102',
    './assets/js/production-clean-entry-v110.js?v=1121&b=3f6490b354f2',
    $html
);
$html = str_replace(
    './assets/js/main.js?v=98.3',
    './assets/js/main-v110.js?v=1135&pending=6&b=e0fe64eeb704',
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