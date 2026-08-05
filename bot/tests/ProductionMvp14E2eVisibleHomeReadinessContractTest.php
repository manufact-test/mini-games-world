<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$read = static function (string $path) use ($root): string {
    $content = file_get_contents($root . '/' . $path);
    if (!is_string($content)) {
        throw new RuntimeException('Cannot read visible-home readiness source: ' . $path);
    }
    return $content;
};

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

$helperPath = 'e2e/staging/support/ordinary-start-readiness.mjs';
$helper = $read($helperPath);
$consumers = [
    'e2e/staging/d1-bug-b-player-picker-v122.spec.mjs',
    'e2e/staging/d1-real-user-regressions-v127.spec.mjs',
    'e2e/staging/d2-d3-d5-integration.spec.mjs',
];

$assert(str_contains($helper, 'export async function openOrdinaryStartReady(page')
    && str_contains($helper, "page.goto(appRoute, { waitUntil: 'domcontentloaded' })")
    && str_contains($helper, 'expect(response.ok(), `${label} app status`).toBe(true)'),
    'The shared helper must validate the ordinary Start document response.');
$assert(str_contains($helper, "page.locator('#screen-home')")
    && str_contains($helper, '.toHaveClass(/active/, { timeout })')
    && str_contains($helper, "page.locator('#preloader')")
    && str_contains($helper, '.toBeHidden({ timeout })'),
    'Visible active home and hidden preloader must be the authoritative readiness criteria.');
$assert(str_contains($helper, "page.on('pageerror', onPageError)")
    && str_contains($helper, 'response.status() >= 500')
    && str_contains($helper, 'expect(pageErrors, `${label} startup page errors`).toEqual([])')
    && str_contains($helper, 'expect(serverErrors, `${label} startup server errors`).toEqual([])'),
    'JavaScript page errors and same-origin 5xx responses must remain fatal.');
$assert(str_contains($helper, 'if (bootstrapResponse)')
    && str_contains($helper, 'expect(bootstrapResponse.status(), `${label} bootstrap status`).toBe(200)')
    && str_contains($helper, 'expect(bootstrap?.ok, `${label} bootstrap payload`).toBe(true)'),
    'An observed bootstrap response must still be validated as 200/ok.');
$assert(str_contains($helper, 'Promise.race([')
    && str_contains($helper, 'page.waitForTimeout(250)')
    && !str_contains($helper, 'waitForResponse('),
    'Bootstrap transport observation must be optional and bounded, never the sole readiness gate.');

foreach ($consumers as $path) {
    $source = $read($path);
    $assert(str_contains($source, "import { openOrdinaryStartReady } from './support/ordinary-start-readiness.mjs';")
        && str_contains($source, 'await openOrdinaryStartReady(page, {'),
        $path . ' must use the shared visible-home readiness helper.');
    $assert(!str_contains($source, 'waitForResponse(response => response.url() === API_ROUTE')
        && !str_contains($source, 'waitForResponse(isBootstrapResponse'),
        $path . ' must not restore mandatory bootstrap-response waiting.');
}

$d2 = $read('e2e/staging/d2-d3-d5-integration.spec.mjs');
$assert(str_contains($d2, 'Expected terminal token ${directToken}')
    && str_contains($d2, "expect(String(bNotification.item?.invite_status || '')).toMatch(/cancelled|canceled/)")
    && str_contains($d2, 'expect(Array.isArray(bNotification.item?.actions) ? bNotification.item.actions : []).toEqual([])'),
    'D2 terminal notification product assertions must remain intact.');

fwrite(STDOUT, 'ProductionMvp14E2eVisibleHomeReadinessContractTest: ' . $assertions . " assertions passed\n");
