<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$assertions = 0;

$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

$read = static function (string $path) use ($root): string {
    $content = file_get_contents($root . '/' . $path);
    if (!is_string($content)) throw new RuntimeException('Cannot read ' . $path);
    return $content;
};

$audit = $read('docs/MVP25_4_PERFORMANCE_RELIABILITY.md');
$manual = $read('docs/MVP25_4_MANUAL_ACCEPTANCE.md');

$assert(str_contains($audit, 'CLOSED / MANUALLY ACCEPTED WITH KNOWN RESIDUAL / FROZEN'), 'MVP-25.4 audit must record final accepted/frozen status.');
$assert(str_contains($audit, '5c7db576fcfc173d90b6e40f3fdb25e21598380a'), 'MVP-25.4 audit must record the final accepted implementation SHA.');
$assert(str_contains($audit, '36627303146'), 'MVP-25.4 audit must record the final exact canonical E2E run.');
$assert(str_contains($audit, '7/7 passed'), 'MVP-25.4 audit must record the final canonical Playwright result.');
$assert(str_contains($audit, '9,179 ms') && str_contains($audit, '4,506 ms'), 'MVP-25.4 audit must preserve baseline and final startup evidence.');
$assert(str_contains($audit, 'explicitly accepted known residual'), 'MVP-25.4 audit must preserve the accepted residual instead of claiming it disappeared.');
$assert(str_contains($audit, 'PR #1813') && str_contains($audit, 'PR #1814') && str_contains($audit, 'PR #1818'), 'MVP-25.4 audit must preserve the key corrective chain.');
$assert(str_contains($audit, 'PR #1817') && str_contains($audit, 'closed **without merge**'), 'MVP-25.4 history must preserve the obsolete unmerged follow-up.');
$assert(str_contains($audit, 'NEXT: MVP-25.5 — Security / resilience.'), 'MVP-25.4 closure must hand off to MVP-25.5 Security / resilience.');

$assert(str_contains($manual, 'PASS FOR RELEASE PATH — ACCEPTED WITH KNOWN RESIDUAL'), 'Manual acceptance must record the product-owner decision precisely.');
$assert(str_contains($manual, 'leave the current implementation as-is'), 'Manual acceptance must preserve the explicit leave-as-is instruction.');
$assert(str_contains($manual, 'Do **not** reopen MVP-25.4 merely because this known residual still exists.'), 'Manual acceptance must freeze the known residual.');
$assert(str_contains($manual, 'NEXT: MVP-25.5 — Security / resilience.'), 'Manual acceptance must hand off to the correct next milestone.');

fwrite(STDOUT, "Mvp25_4PerformanceReliabilityClosureContractTest: {$assertions} assertions passed\n");
