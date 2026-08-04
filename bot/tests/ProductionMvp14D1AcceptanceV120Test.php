<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$config = file_get_contents($root . '/e2e/playwright.config.mjs');
$replacement = file_get_contents($root . '/e2e/staging/d1-followup-acceptance-v120.spec.mjs');
$stale = file_get_contents($root . '/e2e/staging/d1-followup-stress.spec.mjs');
if (!is_string($config) || !is_string($replacement) || !is_string($stale)) {
    throw new RuntimeException('Missing D1 v120 acceptance replacement sources.');
}

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

$staleTitles = [
    'D1 follow-up: declined invitation remains read history without actions or toast',
    'D1 follow-up: mobile cached invitation wins over a delayed false-empty response',
    'D1 follow-up: desktop bell opens during an unfinished request and ignores its stale finish',
];
foreach ($staleTitles as $title) {
    $assert(str_contains($stale, "test('{$title}'"), "The superseded scenario must remain identifiable: {$title}");
}

$assert(str_contains($config, '/D1 follow-up: ('),
    'The superseded filter must retain the exact shared D1 follow-up prefix.');
foreach ([
    'declined invitation remains read history without actions or toast',
    'mobile cached invitation wins over a delayed false-empty response',
    'desktop bell opens during an unfinished request and ignores its stale finish',
] as $alternative) {
    $assert(str_contains($config, $alternative),
        "The superseded filter must include only the named alternative: {$alternative}");
}

$replacementTitles = [
    'D1 v120 acceptance: declined invitation remains visible read history without actions or toast',
    'D1 v120 acceptance: mobile notification toast paints cached invitation before delayed false-empty response',
    'D1 v120 acceptance: desktop bell remains immediately reusable while a prior request finishes',
];
foreach ($replacementTitles as $title) {
    $assert(str_contains($replacement, "test('{$title}'"), "The corrected replacement scenario must exist: {$title}");
    $assert(!str_contains($config, $title), "The corrected replacement must not be excluded: {$title}");
}

$assert(substr_count($replacement, "test('D1 v120 acceptance:") === 3,
    'The v120 replacement file must contain exactly three corrected scenarios.');
$assert(str_contains($replacement, "toContainText('@mgw_test_player_b')")
        && !str_contains($replacement, "toContainText('TEST PLAYER B')"),
    'Declined history acceptance must verify the public username shown to the user.');
$assert(str_contains($replacement, 'await toast.click();')
        && str_contains($replacement, 'Cached mobile toast first-paint latency')
        && !str_contains($replacement, "locator('#notificationsOpen').click();\n    const accept"),
    'Mobile first-paint timing must start from the visible blue notification surface, not a covered bell.');
$assert(str_contains($replacement, 'expect(markReadCalls).toBeGreaterThanOrEqual(1);')
        && !str_contains($replacement, "expect(markReadCalls).toBeGreaterThanOrEqual(2);\n    await playerA.page.unroute(NOTIFICATIONS_ROUTE);\n    expectClean(playerA, 'Player A v120 desktop"),
    'Desktop acceptance must verify immediate reusable UI and stale-close safety without requiring a redundant second request.');
$assert(str_contains($config, 'grepInvert:supersededD1StressScenarios'),
    'The Playwright config must apply only the named superseded-scenario filter.');

fwrite(STDOUT, "ProductionMvp14D1AcceptanceV120Test: {$assertions} assertions passed\n");
