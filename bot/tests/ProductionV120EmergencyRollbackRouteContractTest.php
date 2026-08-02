<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$launch = file_get_contents($root . '/bot/helpers/WebAppLaunchUrl.php');
$welcome = file_get_contents($root . '/bot/helpers/UserWelcomeGuard.php');
$main110 = file_get_contents($root . '/app/assets/js/main-v110.js');
$shell110 = file_get_contents($root . '/app/assets/js/main-v110-handoff-shell.js');
$main120 = file_get_contents($root . '/app/assets/js/main-v120.js');

foreach ([$launch, $welcome, $main110, $shell110, $main120] as $content) {
    if (!is_string($content)) throw new RuntimeException('Rollback route source is missing.');
}

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

$assert(
    str_contains($launch, "private const ENTRY_PATH = '/app/v110.php?v=1123';")
        && str_contains($welcome, "Active canonical path: '/app/v110.php?v=1123'."),
    'Telegram menu, start and invite links must be rolled back to v110.'
);
$assert(
    str_contains($main110, 'main-v110-handoff-shell.js?v=1123')
        && !str_contains($main110, 'main-v120-invite-controller-shell.js')
        && !str_contains($shell110, 'invite-controller-v120.js'),
    'The active v110 graph must not load the rejected controller.'
);
$assert(
    str_contains($main120, 'main-v120-invite-controller-shell.js?v=1200')
        && str_contains($launch, "// private const ENTRY_PATH = '/app/v120.php?v=1200';"),
    'The v120 experiment must remain dormant and available only for postmortem.'
);

fwrite(STDOUT, "ProductionV120EmergencyRollbackRouteContractTest: {$assertions} assertions passed\n");
