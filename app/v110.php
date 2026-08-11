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
    "./assets/js/screens/game-screen-v102-safe.js?v=102": "./assets/js/screens/game-screen-v102-safe.js?v=102&b=901c5c869703",
    "./assets/js/screens/game-screen-v102.js?v=102": "./assets/js/screens/game-screen-v102.js?v=102&b=342fd6cfbb7f",
    "./assets/js/production-v110-acceptance-runtime.js?v=110": "./assets/js/production-v110-acceptance-runtime.js?v=123&b=16abefbd4201",
    "./assets/js/components/shield-king-visuals.js?v=125&sk=2": "./assets/js/components/shield-king-visuals.js?v=126&sk=3&icons=c1efd5af",
    "./assets/js/components/preloader.js?v=42": "./assets/js/components/preloader.js?v=44&intro=v1141",
    "./assets/js/games/game-card-copy.js?v=81&sk=2": "./assets/js/games/game-card-copy.js?v=83&sk=5&icons=c1efd5af&delivery=static"
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

$html = str_replace($telegramScript, $telegramScript . "\n  " . $importMap, $html);
$html = str_replace(
    './assets/css/main.css?v=92',
    './assets/css/main.css?v=135&sk=3&icons=c1efd5af&render=15&review=visual-polish-exact',
    $html
);
$html = str_replace(
    './assets/js/production-regression-fix-entry.js?v=102',
    './assets/js/production-clean-entry-v110.js?v=1122&sk=6',
    $html
);
$html = str_replace(
    './assets/js/main.js?v=98.3',
    './assets/js/main-v110.js?v=1138&ux=1&sk=3&icons=c1efd5af&render=5',
    $html
);
$html = str_replace(
    'data-hotfix-build="v98-mvp14-notification-canonical-owner"',
    'data-hotfix-build="v110-mvp14-invite-transition-ux-v1137"',
    $html
);

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');
header('X-MGW-Api-Session-Graph: v1131');
header('X-MGW-Notification-Graph: v1137');
header('X-MGW-Invite-Graph: v1137');
header('X-MGW-Phase-B-Presentation: v123-v110-deterministic-loader');
header('X-MGW-App-Entry-Presentation: shield-king-v1141-animation-end-gated-assembly');
header('X-MGW-Design-System: shield-king-v2-light-metallic');
header('X-MGW-Icon-Pack: c1efd5afbf0125a090b1755fed2b40cb2cc6f2e1');
header('X-MGW-Icon-Render: accepted-v1142-cache-identity');
echo $html;