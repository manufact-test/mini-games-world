<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$css = file_get_contents($root . '/app/assets/css/screens/notifications-v2.css');
$main = file_get_contents($root . '/app/assets/css/main.css');
$manifest = file_get_contents($root . '/app/runtime/client/version-manifest.php');
if (!is_string($css) || !is_string($main) || !is_string($manifest)) {
    throw new RuntimeException('Unable to read Notification Center visual runtime files.');
}

foreach ([
    '.notification-center-head{align-items:center}',
    '.notification-mark-all{height:40px;min-height:40px;padding:0 12px',
    '.notification-card-actions{display:grid;grid-template-columns:1fr;gap:8px;margin-top:4px}',
    '.notification-card-actions .btn{width:100%;min-height:42px;padding:10px 12px}',
] as $needle) {
    if (!str_contains($css, $needle)) throw new RuntimeException('Missing visual contract: ' . $needle);
}

if (!str_contains($main, "notifications-v2.css?v=3&mvp16=notification-center-v2-polish-desktop")) {
    throw new RuntimeException('Notification v2 desktop stylesheet cache-bust missing.');
}
if (!preg_match('/main\.css\?v=(?:17[4-9]|1[89][0-9]|[2-9][0-9]{2,}).*mvp16=notification-center-v2-desktop-cache/', $manifest)) {
    throw new RuntimeException('Main CSS desktop cache-bust missing.');
}

fwrite(STDOUT, "Notification Center v2 visual contract: OK\n");
