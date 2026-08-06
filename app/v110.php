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

$html = str_replace(
    './assets/js/production-regression-fix-entry.js?v=102',
    './assets/js/production-clean-entry-v110.js?v=1121',
    $html
);
$html = str_replace(
    './assets/js/main.js?v=98.3',
    './assets/js/main-v110.js?v=1130',
    $html
);
$html = str_replace(
    'data-hotfix-build="v98-mvp14-notification-canonical-owner"',
    'data-hotfix-build="v110-mvp14r12-invite-notification-presence-stability"',
    $html
);

$importMap = <<<'HTML'
  <script type="importmap">
  {
    "imports": {
      "./assets/js/api/client.js?v=34": "./assets/js/api/client.js?v=48",
      "./assets/js/api/client.js?v=38": "./assets/js/api/client.js?v=48",
      "./assets/js/api/client.js?v=46": "./assets/js/api/client.js?v=48",
      "./assets/js/api/client.js?v=47": "./assets/js/api/client.js?v=48",
      "./assets/js/session.js?v=21": "./assets/js/session.js?v=28",
      "./assets/js/session.js?v=27": "./assets/js/session.js?v=28"
    }
  }
  </script>
HTML;
$html = str_replace(
    '  <script type="module" src="./assets/js/production-clean-entry-v110.js?v=1121"></script>',
    $importMap . "\n\n  <script type=\"module\" src=\"./assets/js/production-clean-entry-v110.js?v=1121\"></script>",
    $html
);

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');
echo $html;
