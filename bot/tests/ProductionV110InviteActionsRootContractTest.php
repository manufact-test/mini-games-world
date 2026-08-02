<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$read = static function (string $path) use ($root): string {
    $content = file_get_contents($root . '/' . $path);
    if (!is_string($content)) throw new RuntimeException('Cannot read invite root source: ' . $path);
    return $content;
};
$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

$entry = $read('app/assets/js/production-clean-entry-v110.js');
$invites = $read('app/assets/js/games/game-invites-v110.js');
$notifications = $read('app/assets/js/screens/notifications-screen-v110r12.js');
$storage = $read('bot/services/invites/GameInviteStorageTrait.php');
$php = $read('app/v110.php');
$main = $read('app/assets/js/main-v110.js');
$shell = $read('app/assets/js/main-v110-handoff-shell.js');
$launch = $read('bot/helpers/WebAppLaunchUrl.php');

$assert(!str_contains($entry, 'initV109ShareSpeed')
    && !str_contains($entry, 'initV109ShareFallbackGuard')
    && !str_contains($entry, 'initV99InvitePickerHold'),
    'The active v110 graph must not retain legacy share or picker layers.');
$assert(str_contains($invites, "document.querySelector('[data-open-player-picker]')?.addEventListener")
    && str_contains($invites, "document.querySelector('[data-create-link-invite]')?.addEventListener")
    && str_contains($invites, "inviteRequest('create_link_draft'")
    && str_contains($invites, "postJson(OPPONENTS_URL"),
    'The canonical invitation module must own both setup actions.');

$expireStart = strpos($storage, 'private function expireIfDue');
$normalizeStart = strpos($storage, 'private function normalizeLegacy');
$expireBody = $expireStart !== false && $normalizeStart !== false && $normalizeStart > $expireStart
    ? substr($storage, $expireStart, $normalizeStart - $expireStart)
    : '';
$assert($expireBody !== ''
    && !str_contains($expireBody, 'hideReceivedNotification')
    && !str_contains($expireBody, 'addNotification')
    && str_contains($expireBody, "'expired'")
    && str_contains($expireBody, "'timed_out'"),
    'Passive expiry must change only invitation state.');

$build = 'v110-mvp14r12-invite-notification-presence-stability';
$assert(str_contains($entry, $build)
    && str_contains($main, $build)
    && str_contains($shell, $build)
    && str_contains($php, $build),
    'The integrated task must publish one outer production build identity.');
$assert(str_contains($shell, 'notifications-screen-v110r12.js?v=1120')
    && !str_contains($shell, 'notifications-screen-v110r5.js')
    && substr_count($shell, 'initNotificationsScreen();') === 1
    && str_contains($notifications, 'data-notifications-owner="r12"'),
    'Exactly one current notification owner must be active beside the invitation owner.');
$assert(str_contains($php, 'production-clean-entry-v110.js?v=1120')
    && str_contains($php, 'main-v110.js?v=1121')
    && str_contains($main, 'main-v110-handoff-shell.js?v=1121')
    && str_contains($launch, '/app/v110.php?v=1120'),
    'Telegram and browser entrypoints must use the clean canonical route and current statistics shell.');

fwrite(STDOUT, 'ProductionV110InviteActionsRootContractTest: ' . $assertions . " assertions passed\n");
