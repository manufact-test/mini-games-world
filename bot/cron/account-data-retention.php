<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$argv = is_array($argv ?? null) ? $argv : [];
if (!in_array('--run-retention', $argv, true)) {
    $argv[] = '--run-retention';
}

require dirname(__DIR__) . '/account-data.php';
