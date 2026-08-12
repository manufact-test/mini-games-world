<?php
declare(strict_types=1);

$entryVersion = '124';
$requestVersion = trim((string)($_GET['v'] ?? ''));
if ($requestVersion !== '' && $requestVersion !== $entryVersion) {
    $query = $_GET;
    $query['v'] = $entryVersion;
    $location = './?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('Expires: 0');
    header('Location: ' . $location, true, 302);
    exit;
}

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
    "./assets/js/api/client.js?v=47": "./assets/js/api/client.js?v=114",
    "./assets/js/session.js?v=21": "./assets/js/session.js?v=114",
    "./assets/js/session.js?v=27": "./assets/js/session.js?v=114",
    "./assets/js/telegram/telegram-app.js?v=21": "./assets/js/telegram/telegram-app.js?v=114",
    "./assets/js/telegram/telegram-app.js?v=27": "./assets/js/telegram/telegram-app.js?v=114",
    "./assets/js/state.js?v=27": "./assets/js/state.js?v=114",
    "./assets/js/config.js?v=38": "./assets/js/config.js?v=114",
    "./assets/js/components/preloader.js?v=42": "./assets/js/components/preloader.js?v=44&intro=v1141",
    "./assets/js/residual-ui-game-race-fix.js?v=91": "./assets/js/residual-ui-game-race-fix-v114.js?v=114",
    "./assets/js/interaction-latency-coordinator-v101.js?v=101": "./assets/js/interaction-latency-coordinator-v101.js?v=116&invite=no-duplicate-owner",
    "./assets/js/screens/game-screen.js?v=74": "./assets/js/screens/game-screen-phase-b-current.js?v=119&ttt=single-renderer"
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
    './assets/css/main.css?v=92',
    './assets/css/main.css?v=140&sk=3&icons=c1efd5af&render=19&review=ttt-authoritative-clock',
    $html
);
$html = str_replace(
    './assets/js/production-regression-fix-entry.js?v=102',
    './assets/js/phase-b-current-entry.js?v=127&ttt=real-launch-no-copy',
    $html
);
$html = str_replace(
    'data-hotfix-build="v98-mvp14-notification-canonical-owner"',
    'data-hotfix-build="d1-bootstrap-authoritative-owner"',
    $html
);
$html = str_replace(
    './assets/js/main.js?v=98.3',
    './assets/js/main.js?v=d1-real-entry-invite-v1142',
    $html
);

$mainScript = '<script type="module" src="./assets/js/main.js?v=d1-real-entry-invite-v1142"></script>';
if (!str_contains($html, $mainScript)) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Mini Games World main-script anchor is unavailable.';
    exit;
}
if (!str_contains($html, './assets/js/phase-b-current-entry.js?v=127&ttt=real-launch-no-copy')) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Mini Games World Phase B entrypoint is unavailable.';
    exit;
}
if (!str_contains($html, './assets/css/main.css?v=140&sk=3&icons=c1efd5af&render=19&review=ttt-authoritative-clock')) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Mini Games World Shield King presentation is unavailable.';
    exit;
}

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');
header('X-MGW-Frontend-Build: d1-bootstrap-authoritative-owner');
header('X-MGW-Phase-B-Build: phase-b-current-v127-ttt-real-launch-no-copy');
header('X-MGW-Entry-Version: v' . $entryVersion);
header('X-MGW-App-Entry-Presentation: shield-king-v1141-animation-end-gated-assembly');
echo $html;