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
$notificationWindowOwner = '<script type="module" src="./assets/js/screens/notification-window-owner-v119.js?v=119"></script>';
$nativeFetchGuard = '<script type="module" src="./assets/js/opponents-native-fetch-v115.js?v=115"></script>';
$opponentsGuard = '<script type="module" src="./assets/js/opponents-empty-cache-guard-v115.js?v=115"></script>';
$opponentsConfirm = '<script type="module" src="./assets/js/opponents-authoritative-confirm-v117.js?v=117"></script>';
$importMap = <<<'HTML'
<script type="importmap">
{
  "imports": {
    "./assets/js/api/client.js?v=47": "./assets/js/api/client.js?v=114",
    "./assets/js/session.js?v=21": "./assets/js/session.js?v=114",
    "./assets/js/session.js?v=27": "./assets/js/session.js?v=114",
    "./assets/js/telegram/telegram-app.js?v=21": "./assets/js/telegram/telegram-app.js?v=114",
    "./assets/js/telegram/telegram-app.js?v=27": "./assets/js/telegram/telegram-app.js?v=114",
    "./assets/js/state.js?v=27": "./assets/js/state.js?v=114",
    "./assets/js/config.js?v=38": "./assets/js/config.js?v=114",
    "./assets/js/first-interaction-readiness-v103.js?v=103": "./assets/js/first-interaction-readiness-v103.js?v=114",
    "./assets/js/residual-ui-game-race-fix.js?v=91": "./assets/js/residual-ui-game-race-fix-v114.js?v=114",
    "./assets/js/interaction-latency-coordinator-v101.js?v=101": "./assets/js/interaction-latency-coordinator-v101.js?v=114",
    "./assets/js/screens/notifications-screen-v99.js?v=99": "./assets/js/screens/notifications-screen-v99.js?v=114",
    "./assets/js/games/game-invites.js?v=85": "./assets/js/games/game-invites.js?v=114"
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
    $telegramScript . "\n  " . $importMap
      . "\n  " . $notificationWindowOwner
      . "\n  " . $nativeFetchGuard,
    $html
);
$html = str_replace(
    'data-hotfix-build="v98-mvp14-notification-canonical-owner"',
    'data-hotfix-build="v119-mvp14-notification-canonical-owner"',
    $html
);
$html = str_replace(
    './assets/js/main.js?v=98.3',
    './assets/js/main.js?v=115',
    $html
);

$mainScript = '<script type="module" src="./assets/js/main.js?v=115"></script>';
if (!str_contains($html, $mainScript)) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Mini Games World main-script anchor is unavailable.';
    exit;
}
$html = str_replace(
    $mainScript,
    $mainScript . "\n  " . $opponentsGuard . "\n  " . $opponentsConfirm,
    $html
);

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');
header('X-MGW-Frontend-Build: v119-mvp14-notification-canonical-owner');
echo $html;
