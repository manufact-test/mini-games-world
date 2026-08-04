<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$main = file_get_contents($root . '/app/assets/js/main.js');
$presence = file_get_contents($root . '/app/assets/js/presence-v115.js');
if (!is_string($main) || !is_string($presence)) {
    throw new RuntimeException('Missing deep-link presence sources.');
}

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

$presenceInit = strpos($main, 'initV115Presence();');
$bootstrap = strpos($main, 'const result = await api.bootstrap();');
$assert(
    str_contains($main, "import { initV115Presence } from './presence-v115.js?v=115';")
        && $presenceInit !== false
        && $bootstrap !== false
        && $presenceInit < $bootstrap,
    'Presence must start before bootstrap for both ordinary and invitation-link documents.'
);

$assert(
    str_contains($presence, "void pingPresence();")
        && str_contains($presence, 'HEARTBEAT_MS = 4000')
        && str_contains($presence, 'presenceLeaseId')
        && str_contains($presence, "action === 'leave'"),
    'Every visible document must own a bounded live presence lease with heartbeat and leave semantics.'
);

$assert(
    str_contains($presence, 'initData:getInitData()')
        && str_contains($presence, 'sessionId:getSessionId()')
        && str_contains($presence, 'presenceLeaseId,')
        && str_contains($presence, "cache:'no-store'"),
    'Invitation and ordinary launches must use the same authenticated no-store presence payload.'
);

$assert(
    str_contains($presence, "Object.prototype.hasOwnProperty.call(stats, 'online_players')")
        && str_contains($presence, 'window.__MGW_V115_PRESENCE_ONLINE__')
        && str_contains($main, 'state.stats = mergePresenceOnline(result.stats);')
        && substr_count($main, 'state.stats = mergePresenceOnline(result.stats);') === 2,
    'Only presence may authoritatively refresh online_players across bootstrap and later stats polling.'
);

$assert(
    !str_contains($presence, 'openSheet(')
        && !str_contains($presence, 'notificationsOpen')
        && !str_contains($presence, 'data-invite-action')
        && !str_contains($presence, 'invite-opponents.php'),
    'The isolated presence owner must not change notifications, invitation actions or opponent picker behavior.'
);

fwrite(STDOUT, "ProductionMvp14D1FeedbackDeepLinkPresenceTest: {$assertions} assertions passed\n");
