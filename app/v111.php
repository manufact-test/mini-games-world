<?php
declare(strict_types=1);

// Staging-only v28 shell. Reuse the accepted v110 document and change only the
// Domino module identity so Telegram/Chromium cannot reuse the v27 module cache.
ob_start();
require __DIR__ . '/v110.php';
$html = ob_get_clean();

if (!is_string($html) || $html === '') {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Mini Games World v111 base entry is unavailable.';
    exit;
}

$needle = '&gesture_owner=v27';
$replacement = $needle . '&pointer_owner=v28';
$count = 0;
$html = str_replace($needle, $replacement, $html, $count);
if ($count < 1) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Mini Games World v111 Domino pointer owner route is unavailable.';
    exit;
}

header('X-MGW-Domino-Hand-Pointer: v28-stable-container-capture');
echo $html;
