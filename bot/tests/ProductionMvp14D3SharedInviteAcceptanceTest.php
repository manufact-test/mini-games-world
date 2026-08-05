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

$owner = $read('app/assets/js/games/game-invites-v110.js');
$linkEntry = $read('app/assets/js/games/invite-link-entry-v110r12.js');
$endpoint = $read('bot/invites.php');
$launch = $read('bot/helpers/WebAppLaunchUrl.php');
$shell = $read('app/assets/js/main-v110-handoff-shell.js');

$assert(str_contains($owner, "document.addEventListener('pointerdown', handleInvitePointerDown, true)")
    && str_contains($owner, 'function warmShareDraft(context)')
    && str_contains($owner, "inviteRequest('create_link_draft', normalized, { prefetch:true })"),
    'The canonical invite owner must serialize one prepared-message warmup before Share.');

$assert(str_contains($owner, 'tg.shareMessage(preparedId')
    && str_contains($owner, "tg.onEvent('shareMessageSent'")
    && str_contains($owner, "tg.onEvent('shareMessageFailed'")
    && !str_contains($owner, 'showSharingSheet('),
    'Telegram must retain the native editable share surface instead of an application imitation.');

$assert(str_contains($owner, "String(errorCode || '') === 'USER_DECLINED'")
    && str_contains($owner, 'restoreWarmShareDraft(attempt);')
    && !str_contains($owner, "toast('Приглашение отменено"),
    'Cancelling native Share must be immediate and silent while keeping the prepared draft reusable.');

$assert(str_contains($owner, "inviteRequest('confirm_shared'")
    && str_contains($owner, "inviteRequest('discard_draft'")
    && str_contains($owner, "inviteRequest('create_direct'")
    && substr_count($owner, 'const INVITES_URL = `${window.location.origin}/bot/invites.php`;') === 1,
    'Direct and shared invitations must use the same canonical endpoint and client owner.');

$assert(str_contains($endpoint, "case 'create_link_draft':")
    && str_contains($endpoint, "case 'confirm_shared':")
    && str_contains($endpoint, "case 'discard_draft':")
    && str_contains($endpoint, "case 'create_direct':")
    && str_contains($endpoint, "case 'open_link':")
    && str_contains($endpoint, 'new GameInviteService(')
    && str_contains($endpoint, 'StorageFactory::createJson('),
    'Every invitation path must share one server service and one active runtime transaction.');

$openStart = strpos($endpoint, "case 'open_link':");
$openEnd = strpos($endpoint, "case 'sync':", $openStart ?: 0);
$openBlock = $openStart !== false && $openEnd !== false
    ? substr($endpoint, $openStart, $openEnd - $openStart)
    : '';
$assert(substr_count($openBlock, 'bindFromLink(') === 1
    && substr_count($openBlock, '$invites->sync(') === 1
    && !str_contains($openBlock, 'createDirect(')
    && !str_contains($openBlock, 'createLinkDraft('),
    'Opening one shared link must bind and synchronize the existing draft, never create a second invitation or match path.');

$assert(str_contains($linkEntry, "action:'open_link'")
    && str_contains($linkEntry, 'const invite = result?.opened_invite || null;')
    && str_contains($linkEntry, "startParam.startsWith('invite_')")
    && str_contains($linkEntry, "new URLSearchParams(window.location.search).get('invite')"),
    'Telegram start_param and canonical invite query must converge on one open-link action.');

$assert(str_contains($launch, "private const ENTRY_PATH = '/app/v110.php?v=1123';")
    && str_contains($launch, "return $baseUrl . '&invite=' . rawurlencode($normalizedToken);")
    && str_contains($endpoint, 'return WebAppLaunchUrl::invitation($config, $token);'),
    'Shared and direct Telegram buttons must launch the ordinary canonical v110 runtime.');

$assert(substr_count($shell, 'initGameInvites();') === 1
    && str_contains($shell, 'game-invites-v110.js?v=1129')
    && str_contains($shell, 'invite-link-entry-v110r12.js?v=1123'),
    'The accepted staging graph must expose exactly one invitation owner and one link-entry adapter.');

fwrite(STDOUT, "ProductionMvp14D3SharedInviteAcceptanceTest: {$assertions} assertions passed\n");
