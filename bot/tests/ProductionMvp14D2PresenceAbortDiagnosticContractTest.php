<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$read = static function (string $path) use ($root): string {
    $content = file_get_contents($root . '/' . $path);
    if (!is_string($content)) throw new RuntimeException('Cannot read D2 presence diagnostic source: ' . $path);
    return $content;
};

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

$e2e = $read('e2e/staging/d2-d3-d5-integration.spec.mjs');
$presence = $read('app/assets/js/production-v110-presence.js');

$helperStart = strpos($e2e, 'function isExpectedPresenceResumeAbort(request)');
$helperEnd = $helperStart !== false ? strpos($e2e, 'function collectDiagnostics(', $helperStart) : false;
$helper = $helperStart !== false && $helperEnd !== false
    ? substr($e2e, $helperStart, $helperEnd - $helperStart)
    : '';

$assert($helper !== ''
    && str_contains($helper, "request.method() === 'POST'")
    && str_contains($helper, "new URL(request.url()).pathname === '/bot/presence.php'")
    && str_contains($helper, "failure === 'net::ERR_ABORTED'"),
    'The diagnostic exception must match only the exact controlled POST presence abort.');
$assert(str_contains($e2e, 'if (!request.url().startsWith(STAGING_ORIGIN)) return;')
    && str_contains($e2e, 'if (isExpectedPresenceResumeAbort(request)) return;')
    && str_contains($e2e, 'report.failedRequests.push({'),
    'Every other same-origin failed request must still be recorded.');
$assert(str_contains($e2e, 'response.status() >= 500')
    && str_contains($e2e, 'report.serverErrors.push(')
    && str_contains($e2e, "page.on('pageerror'"),
    'Server and page errors must remain fatal diagnostics.');
$assert(str_contains($presence, 'if (force) cancelInFlightRequests();')
    && str_contains($presence, 'runtime.pingController?.abort();')
    && str_contains($presence, 'void pingPresence();'),
    'The filtered abort must correspond to the retained forced-resume replacement flow.');
$assert(!str_contains($e2e, "failure.includes('ERR_ABORTED')")
    && !str_contains($e2e, "pathname.includes('presence')"),
    'The diagnostic filter must not broaden into a generic abort or presence suppression.');

fwrite(STDOUT, 'ProductionMvp14D2PresenceAbortDiagnosticContractTest: ' . $assertions . " assertions passed\n");
