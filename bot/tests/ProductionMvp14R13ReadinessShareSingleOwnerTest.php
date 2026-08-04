<?php
declare(strict_types=1);
$root = dirname(__DIR__, 2);
$main = file_get_contents($root . '/app/assets/js/main.js');
$readiness = file_get_contents($root . '/app/assets/js/first-interaction-readiness-v103.js');
$invites = file_get_contents($root . '/app/assets/js/games/game-invites.js');
$index = file_get_contents($root . '/app/index.html');
$v110 = file_get_contents($root . '/app/v110.php');
if (!is_string($main) || !is_string($readiness) || !is_string($invites) || !is_string($index) || !is_string($v110)) {
    throw new RuntimeException('Missing readiness Share ownership source.');
}
$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};
$assert(str_contains($main, "./first-interaction-readiness-v103.js?v=103")
    && substr_count($main, 'initFirstInteractionReadinessEarly();') === 1
    && substr_count($main, 'initGameInvites();') === 1,
    'Main must install one readiness owner and one invite coordinator.');
$assert(!str_contains($readiness, 'data-create-link-invite')
    && !str_contains($readiness, 'create_link_draft')
    && !str_contains($readiness, 'sharePreparedLink')
    && !str_contains($readiness, 'openTelegramShare'),
    'Readiness must not intercept, create or navigate Share.');
$assert(str_contains($readiness, "[data-invite-friend], [data-open-player-picker]")
    && str_contains($readiness, 'refreshOpponentsNetwork(false)')
    && str_contains($readiness, 'warmNotificationsSnapshot()'),
    'Readiness must retain read-only warming.');
$assert(str_contains($invites, 'data-create-link-invite')
    && str_contains($invites, 'showPreparedLink(draftInvite, context);')
    && str_contains($invites, 'data-copy-invite-link')
    && str_contains($invites, 'data-discard-draft'),
    'Invite coordinator must own complete Share UI.');
$assert(str_contains($index, 'main.js?v=98.3') && !str_contains($index, 'main.js?v=98.2'),
    'HTML must publish the fresh main graph.');
$assert(str_contains($v110, "'./assets/js/main.js?v=98.3'")
    && str_contains($v110, "'./assets/js/main-v110.js?v=1124'"),
    'v110 must replace the exact fresh base main.');
fwrite(STDOUT, "ProductionMvp14R13ReadinessShareSingleOwnerTest: {$assertions} assertions passed
");
