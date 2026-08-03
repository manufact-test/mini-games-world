<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$main = file_get_contents($root . '/app/assets/js/main.js');
$entry = file_get_contents($root . '/app/assets/js/games/invite-link-entry-v115.js');
$server = file_get_contents($root . '/bot/services/invites/GameInviteCreationTrait.php');
if (!is_string($main) || !is_string($entry) || !is_string($server)) {
    throw new RuntimeException('Missing link-entry sources.');
}

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

$assert(
    str_contains($main, "import { initGameInvites } from './games/game-invites.js?v=85';")
        && str_contains($main, "import { openIncomingInviteFromTelegram } from './games/invite-link-entry-v115.js?v=115';")
        && str_contains($main, 'await openIncomingInviteFromTelegram();')
        && !str_contains($main, 'await openIncomingInviteIfPresent();'),
    'Boot must use the dedicated one-shot link-entry owner while retaining the canonical invite action coordinator.'
);

$assert(
    str_contains($entry, "action:'open_link'")
        && str_contains($entry, 'const invite = result?.opened_invite || null;')
        && !str_contains($entry, 'result?.invite || null'),
    'Link entry must consume the server one-shot opened_invite projection, not active invite state.'
);

$assert(
    str_contains($entry, '<h2>Вас приглашают сыграть</h2>')
        && str_contains($entry, 'data-invite-action="accept"')
        && str_contains($entry, 'Принять приглашение')
        && str_contains($entry, 'data-invite-action="decline"')
        && str_contains($entry, 'Отклонить'),
    'The recipient must receive one actionable invitation decision sheet.'
);

$assert(
    !str_contains($entry, 'Понятно')
        && !str_contains($entry, 'showTerminalInvite')
        && !str_contains($entry, 'Приглашение отклонено')
        && !str_contains($entry, 'Приглашение отменено'),
    'The link-entry owner must never render a second terminal confirmation sheet or actor success message.'
);

$assert(
    str_contains($entry, "detail:{ item, unreadCount, announce:false }")
        && !str_contains($entry, 'announce:true'),
    'Opening through a link must seed the bell silently without a duplicate blue toast.'
);

$assert(
    str_contains($server, '$openedInvite = $candidate;')
        && str_contains($server, "'opened_invite' => \$openedInvite")
        && !str_contains($server, '$activeInvite = $openedInvite'),
    'The server must keep the linked pending invitation one-shot and non-blocking.'
);

fwrite(STDOUT, "ProductionMvp14D1FeedbackLinkEntrySheetTest: {$assertions} assertions passed\n");
