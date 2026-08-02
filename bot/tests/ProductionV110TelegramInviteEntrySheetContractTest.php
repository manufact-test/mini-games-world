<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$read = static function (string $path) use ($root): string {
    $content = file_get_contents($root . '/' . $path);
    if (!is_string($content)) throw new RuntimeException('Cannot read ' . $path);
    return $content;
};
$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

$creation = $read('bot/services/invites/GameInviteCreationTrait.php');
$shell = $read('app/assets/js/main-v110-handoff-shell.js');
$entry = $read('app/assets/js/games/invite-link-entry-v110r12.js');
$legacy = $read('app/assets/js/games/game-invites-v110.js');
$storage = $read('bot/services/invites/GameInviteStorageTrait.php');

$assert(
    str_contains($creation, '$openedInvite = null;')
        && str_contains($creation, '$openedInvite = $candidate;')
        && str_contains($creation, "'opened_invite' => \$openedInvite")
        && str_contains($creation, 'if ($this->isNotificationOnlyPendingInvite($activeInvite)) $activeInvite = null;'),
    'A Telegram-linked pending invitation must be returned once without becoming active invite state.'
);

$assert(
    str_contains($shell, "import { initGameInvites } from './games/game-invites-v110.js?v=1114';")
        && !str_contains($shell, 'openIncomingInviteIfPresent')
        && str_contains($shell, "openIncomingInviteFromTelegram } from './games/invite-link-entry-v110r12.js?v=1123'")
        && str_contains($shell, 'await openIncomingInviteFromTelegram();'),
    'The active graph must have one Telegram deep-link entry owner and must not call the legacy open-link path.'
);

$assert(
    str_contains($entry, "action:'open_link'")
        && str_contains($entry, 'const invite = result?.opened_invite || null;')
        && str_contains($entry, 'showIncomingInvite(invite);')
        && str_contains($entry, 'data-invite-action="accept"')
        && str_contains($entry, 'data-invite-action="decline"')
        && str_contains($entry, 'data-invite-state="pending:invitee"'),
    'Opening a Telegram link must immediately paint the complete invitation sheet with accept and decline actions.'
);

$assert(
    str_contains($entry, "detail:{ item, unreadCount, announce:false }")
        && str_contains($entry, "toast('Не удалось открыть приглашение. Попробуйте открыть ссылку ещё раз.')")
        && !str_contains($entry, 'toast(error.message'),
    'The entry owner must seed notifications silently and never expose raw database errors to the player.'
);

$assert(
    str_contains($storage, "':received:' . \$inviteeId")
        && str_contains($storage, "(string)(\$existing['event_key'] ?? '') === \$eventKey")
        && str_contains($storage, "(string)(\$existing['user_id'] ?? '') === \$userId) return;"),
    'Repeated opening by the same recipient must reuse the deterministic received-notification identity.'
);

$assert(
    str_contains($legacy, 'export async function openIncomingInviteIfPresent()')
        && !str_contains($shell, 'openIncomingInviteIfPresent'),
    'The retired legacy function may remain for rollback but must not be active in the current graph.'
);

fwrite(STDOUT, "ProductionV110TelegramInviteEntrySheetContractTest: {$assertions} assertions passed\n");
