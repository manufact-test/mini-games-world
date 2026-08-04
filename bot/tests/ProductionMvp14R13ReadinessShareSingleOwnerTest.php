<?php
declare(strict_types=1);
$root = dirname(__DIR__, 2);
$main = file_get_contents($root . '/app/assets/js/main.js');
$readiness = file_get_contents($root . '/app/assets/js/first-interaction-readiness.js');
$invites = file_get_contents($root . '/app/assets/js/games/game-invites.js');
if (!is_string($main) || !is_string($readiness) || !is_string($invites)) throw new RuntimeException('Missing current Share ownership source.');
$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void { $assertions++; if (!$condition) throw new RuntimeException($message); };
$assert(str_contains($main, "./first-interaction-readiness.js?v=d1")
    && substr_count($main, 'initFirstInteractionReadinessEarly();') === 1
    && substr_count($main, 'initGameInvites();') === 1,
    'Main must initialize one readiness service and one invite coordinator.');
$assert(!str_contains($readiness, 'data-create-link-invite')
    && !str_contains($readiness, 'create_link_draft')
    && !str_contains($readiness, 'sharePreparedLink')
    && !str_contains($readiness, 'openTelegramShare')
    && !str_contains($readiness, 'invite-opponents.php')
    && !str_contains($readiness, 'window.fetch ='),
    'Readiness must not intercept Share or opponents transport.');
$assert(str_contains($readiness, 'warmProfileSnapshot()')
    && str_contains($readiness, 'warmHistorySnapshot()')
    && str_contains($readiness, 'warmNotificationsSnapshot()')
    && str_contains($readiness, 'warmShopOrders()'),
    'Readiness must remain a read-only warmup service.');
$assert(str_contains($invites, 'data-create-link-invite')
    && str_contains($invites, 'async function createLinkDraft(context, button)')
    && str_contains($invites, 'showPreparedLink(draftInvite, context);')
    && str_contains($invites, 'data-copy-invite-link')
    && str_contains($invites, 'data-discard-draft'),
    'Invite coordinator must exclusively own complete Share UI.');
fwrite(STDOUT, "ProductionMvp14R13ReadinessShareSingleOwnerTest: {$assertions} assertions passed\n");
