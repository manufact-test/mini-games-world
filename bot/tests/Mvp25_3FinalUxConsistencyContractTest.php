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

$launch = $read('bot/helpers/WebAppLaunchUrl.php');
$entry = $read('app/v110.php');
$manifest = $read('app/runtime/client/version-manifest.php');
$main = $read('app/assets/css/main.css');
$ux = $read('app/assets/css/mvp25-3-ux-consistency-v1.css');
$home = $read('app/assets/js/screens/home-screen.js');
$audit = $read('docs/MVP25_3_FINAL_UX_CONSISTENCY.md');
$manual = $read('docs/MVP25_3_MANUAL_ACCEPTANCE.md');

$assert(str_contains($launch, "private const ENTRY_PATH = '/app/v110.php"), 'MVP-25.3 must audit the real v110 launch owner.');
$assert(str_contains($entry, "version-manifest.php"), 'v110 must still consume the canonical version manifest.');
$assert(
    str_contains($manifest, "./assets/css/main.css?v=209&mvp25_3=ux-consistency-v2&rules=human-guide-top-spacing-v1"),
    'Manifest must publish the current MVP-25.3 main CSS identity including the accepted rules-guide successor.'
);
$assert(str_contains($main, "@import url('./mvp25-3-ux-consistency-v1.css?v=2');"), 'Main CSS must import the final UX overlay.');
$assert(strpos($main, "@import url('./mvp25-3-ux-consistency-v1.css?v=2');") > strpos($main, "@import url('./shield-king-icons-v12.css"), 'MVP-25.3 overlay must load after accepted historical imports.');

$assert(str_contains($home, "menuItemMarkup('matchHistoryBtn'"), 'Home More menu proof must still contain the long ordinary-player menu.');

foreach ([
    '.sheet > .menu-list',
    'overflow-y:auto',
    'overscroll-behavior:contain',
    'env(safe-area-inset-bottom, 0px)',
    'env(safe-area-inset-top, 0px)',
    '.friends-v110-more',
    'min-height:44px',
    '.sheet button:focus-visible',
    '.sheet > .profile-v2-moderation',
    'var(--sk-disabled-bg)',
    '.friends-v110-loading',
] as $needle) {
    $assert(str_contains($ux, $needle), 'MVP-25.3 UX contract missing: ' . $needle);
}

foreach ([
    '.store-v2-game-buy',
    '.store-v2-equip',
    '.tournaments-v2-tournament-action',
    '.board',
    '.game-board',
] as $acceptedOwner) {
    $assert(!str_contains($ux, $acceptedOwner), 'MVP-25.3 cross-product overlay must not resize accepted game/cosmetic owner: ' . $acceptedOwner);
}

$assert(str_contains($audit, 'CLOSED / MANUALLY ACCEPTED / FROZEN'), 'MVP-25.3 must record final product-owner acceptance and freeze.');
$assert(str_contains($audit, '36608738815'), 'MVP-25.3 audit must record the final exact green staging E2E run.');
$assert(str_contains($audit, 'b2c60acce74375e21eef3b0824a66b69c34502e1'), 'MVP-25.3 audit must record the final accepted staging SHA.');
$assert(str_contains($manual, 'Manual acceptance checklist'), 'MVP-25.3 manual checklist must be recorded.');
$assert(str_contains($manual, 'Home → More on a short/mobile viewport'), 'Manual acceptance must cover the short-viewport More menu.');
$assert(str_contains($manual, 'Desktop keyboard spot-check'), 'Manual acceptance must cover visible focus state.');
$assert(str_contains($manual, 'PASS — CLOSED / MANUALLY ACCEPTED / FROZEN'), 'MVP-25.3 manual acceptance result must be PASS and frozen.');
$assert(str_contains($manual, 'NEXT: MVP-25.4'), 'MVP-25.3 closure must hand off to MVP-25.4.');

fwrite(STDOUT, "Mvp25_3FinalUxConsistencyContractTest: {$assertions} assertions passed\n");
