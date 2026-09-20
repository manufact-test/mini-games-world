<?php
declare(strict_types=1);

use Mgw\CleanRuntime\Server\RuntimeConfig;
use Mgw\CleanRuntime\Server\RuntimeEnvironmentGuard;

require_once __DIR__ . '/server/RuntimeConfig.php';
require_once __DIR__ . '/server/RuntimeEnvironmentGuard.php';

try {
    $config = RuntimeConfig::fromEnvironment();
    RuntimeEnvironmentGuard::assertAvailable($config, $_SERVER);
} catch (Throwable $error) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('X-Content-Type-Options: nosniff');
    echo 'Not found.';
    exit;
}

$htmlPath = __DIR__ . '/index.html';
$html = file_get_contents($htmlPath);
if (!is_string($html)) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Mini Games World clean runtime is unavailable.';
    exit;
}

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
echo $html;
