<?php
declare(strict_types=1);

// v120 failed production acceptance and must never execute again. Telegram may
// keep old Web App URLs in previously sent messages and in a saved menu button,
// so this endpoint is a permanent compatibility tombstone that forwards every
// stale launch to the accepted v110 application.
$target = '/app/v110.php?v=1123';
$inviteToken = strtolower(trim((string)($_GET['invite'] ?? '')));
if (preg_match('/^[a-f0-9]{24}$/', $inviteToken)) {
    $target .= '&invite=' . rawurlencode($inviteToken);
}

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');
header('Location: ' . $target, true, 302);
exit;
