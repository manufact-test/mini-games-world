<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$read = static function (string $path) use ($root): string {
    $content = file_get_contents($root . '/' . $path);
    if (!is_string($content)) {
        throw new RuntimeException('Cannot read D3 profile-prefetch contract source: ' . $path);
    }
    return $content;
};

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$speed = $read('app/assets/js/production-v101-speed-runtime-v102.js');
$context = $read('e2e/staging/support/d3-shared-context.mjs');
$scenario = $read('e2e/staging/d3-shared-invite.spec.mjs');

$assert(str_contains($speed, "() => prefetchApiAction('profile')"),
    'The existing speed runtime must prove that profile is a passive prefetch.');
$assert(str_contains($speed, 'mgwPrefetch:true'),
    'Passive requests must retain their explicit speed-runtime prefetch marker.');
$assert(str_contains($speed, 'runtime.backgroundControllers'),
    'The speed runtime must continue tracking passive reads separately from product actions.');
$assert(str_contains($speed, 'abortTracked(runtime.backgroundControllers)'),
    'A foreground game action must continue cancelling superseded passive reads.');

$assert(str_contains($context, "request.url() === API_ROUTE")
    && str_contains($context, "requestAction(request) === 'profile'")
    && str_contains($context, "request.failure()?.errorText || '') === 'net::ERR_ABORTED'"),
    'The D3 diagnostic may classify only the exact aborted profile request.');
$assert(str_contains($context, 'report.allowBackgroundProfileAbort && isExpectedBackgroundProfileAbort(request)'),
    'The exact profile abort must remain blocked outside the explicit transition window.');
$assert(str_contains($context, 'report.ignoredBackgroundProfileAborts += 1'),
    'Controlled profile aborts must be counted instead of silently discarded.');
$assert(!str_contains($context, "request.url().includes('/bot/api.php') && String(request.failure"),
    'The diagnostic must not introduce a generic API abort allowance.');

$enableAt = strpos($scenario, 'allowBackgroundProfileAbort = true');
$startAt = strpos($scenario, "clickInviteAction(playerA.page, 'start', token)");
$disableAt = strpos($scenario, 'allowBackgroundProfileAbort = false');
$assert($enableAt !== false && $startAt !== false && $disableAt !== false
    && $enableAt < $startAt && $startAt < $disableAt,
    'The profile-abort allowance must exist only around the successful start transition.');
$assert(substr_count($scenario, 'ignoredBackgroundProfileAborts).toBeLessThanOrEqual(1)') === 2,
    'Each player may expose at most one controlled passive profile abort.');
$assert(str_contains($scenario, 'expect(playerA.diagnostics.failedRequests).toEqual([])')
    && str_contains($scenario, 'expect(playerB.diagnostics.failedRequests).toEqual([])'),
    'All other failed requests must remain fatal to D3.');

fwrite(STDOUT, 'ProductionMvp14D3ProfilePrefetchAbortContractTest: ' . $assertions . " assertions passed\n");
