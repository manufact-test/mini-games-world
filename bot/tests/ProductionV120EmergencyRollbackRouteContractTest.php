<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$launch = file_get_contents($root . '/bot/helpers/WebAppLaunchUrl.php');
$welcome = file_get_contents($root . '/bot/helpers/UserWelcomeGuard.php');
$main110 = file_get_contents($root . '/app/assets/js/main-v110.js');
$shell110 = file_get_contents($root . '/app/assets/js/main-v110-handoff-shell.js');
$v120 = file_get_contents($root . '/app/v120.php');

foreach ([$launch, $welcome, $main110, $shell110, $v120] as $content) {
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
    'Telegram menu, start and newly generated invite links must use v110.'
);
$assert(
    str_contains($main110, 'main-v110-handoff-shell.js?v=1123')
        && !str_contains($main110, 'main-v120-invite-controller-shell.js')
        && !str_contains($shell110, 'invite-controller-v120.js'),
    'The active v110 graph must not load the rejected controller.'
);
$assert(
    str_contains($v120, "\$target = '/app/v110.php?v=1123';")
        && str_contains($v120, "\$_GET['invite']")
        && str_contains($v120, "\$target .= '&invite=' . rawurlencode(\$inviteToken);")
        && str_contains($v120, "header('Location: ' . \$target, true, 302);")
        && !str_contains($v120, 'main-v120.js')
        && !str_contains($v120, 'index.html'),
    'Every stale v120 URL must hard-redirect to v110 while preserving a valid invite token.'
);

fwrite(STDOUT, "ProductionV120EmergencyRollbackRouteContractTest: {$assertions} assertions passed\n");
