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

$assert(str_contains($context, "request.url() === API_ROUTE")
    && str_contains($context, "request.method() === 'POST'")
    && str_contains($context, "requestAction(request) === 'profile'")
    && str_contains($context, "request.failure()?.errorText || '') === 'net::ERR_ABORTED'"),
    'The D3 diagnostic may classify only the exact aborted POST profile request.');
$assert(str_contains($context, 'report.allowBackgroundProfileAbort && isExpectedBackgroundProfileAbort(request)'),
    'The exact profile abort must remain fatal outside the explicit D3 lifecycle.');
$assert(str_contains($context, 'report.ignoredBackgroundProfileAborts += 1'),
    'Controlled profile aborts must be counted instead of silently discarded.');
$assert(!str_contains($context, "request.url().includes('/bot/api.php') && String(request.failure"),
    'The diagnostic must not introduce a generic API abort allowance.');

$callbackAt = strpos($context, 'await beforeOpen(page, diagnostics)');
$navigationAt = strpos($context, 'await openOrdinaryStartReady(page');
$assert($callbackAt !== false && $navigationAt !== false && $callbackAt < $navigationAt,
    'D3 diagnostics must be configurable before navigation and passive prefetch can begin.');

$assert(substr_count($scenario, 'diagnostics.allowBackgroundProfileAbort = true;') === 2,
    'Both isolated players must enable the exact passive-profile classification before their page flow.');
$firstReadyAt = strpos($scenario, 'await cleanupPlayer(playerA.page);');
$firstEnableAt = strpos($scenario, 'diagnostics.allowBackgroundProfileAbort = true;');
$decisionAt = strpos($scenario, "toHaveText('Вас приглашают сыграть')");
$secondEnableAt = strpos($scenario, 'diagnostics.allowBackgroundProfileAbort = true;', $firstEnableAt + 1);
$assert($firstEnableAt !== false && $firstReadyAt !== false && $firstEnableAt < $firstReadyAt,
    'Player A classification must be armed before the first D3 action.');
$assert($secondEnableAt !== false && $decisionAt !== false && $secondEnableAt < $decisionAt,
    'Player B classification must be armed before automatic open_link can start.');

$gameBAt = strpos($scenario, 'const gameB = await expectPlayerRequest');
$disableAt = strpos($scenario, 'playerA.diagnostics.allowBackgroundProfileAbort = false;');
$finalDiagnosticsAt = strpos($scenario, 'expect(playerA.diagnostics.pageErrors).toEqual([])');
$assert($gameBAt !== false && $disableAt !== false && $finalDiagnosticsAt !== false
    && $gameBAt < $disableAt && $disableAt < $finalDiagnosticsAt,
    'The lifecycle allowance must close after both players prove the same active game and before final diagnostics.');
$assert(substr_count($scenario, 'ignoredBackgroundProfileAborts).toBeLessThanOrEqual(1)') === 2,
    'Each player may expose at most one controlled passive profile abort.');
$assert(str_contains($scenario, 'expect(playerA.diagnostics.failedRequests).toEqual([])')
    && str_contains($scenario, 'expect(playerB.diagnostics.failedRequests).toEqual([])')
    && str_contains($scenario, 'expect(playerA.diagnostics.serverErrors).toEqual([])')
    && str_contains($scenario, 'expect(playerB.diagnostics.serverErrors).toEqual([])'),
    'All other failed requests and same-origin server errors must remain fatal to D3.');

fwrite(STDOUT, 'ProductionMvp14D3ProfilePrefetchAbortContractTest: ' . $assertions . " assertions passed\n");
