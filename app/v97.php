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
    './assets/js/production-regression-fix-entry.js?v=96',
    './assets/js/production-regression-fix-entry-v97.js?v=97',
    $html
);
$html = str_replace(
    'data-hotfix-build="v96-mvp14-root-cause-stabilization"',
    'data-hotfix-build="v97-mvp14-single-runtime-owner"',
    $html
);

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');
echo $html;
