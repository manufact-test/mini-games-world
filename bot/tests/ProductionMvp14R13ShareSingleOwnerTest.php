<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$entry = file_get_contents($root . '/app/assets/js/production-regression-fix-entry.js');
$main = file_get_contents($root . '/app/assets/js/main.js');
$invites = file_get_contents($root . '/app/assets/js/games/game-invites.js');
$historical = file_get_contents($root . '/app/assets/js/production-prepared-share-fix.js');
$index = file_get_contents($root . '/app/index.html');
$v110 = file_get_contents($root . '/app/v110.php');
if (!is_string($entry) || !is_string($main) || !is_string($invites)
    || !is_string($historical) || !is_string($index) || !is_string($v110)) {
    throw new RuntimeException('Missing canonical Share ownership source.');
}

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

$assert(!str_contains($entry, 'initPreparedShareFix')
    && !str_contains($entry, 'production-prepared-share-fix.js'),
    'The active regression entry must not install the competing prepared-Share owner.');

$assert(substr_count($main, 'initGameInvites();') === 1
    && str_contains($invites, 'async function createLinkDraft(context, button)')
    && str_contains($invites, "const preparedId = String(draftInvite.prepared_message_id || '')")
    && str_contains($invites, "typeof tg?.shareMessage === 'function'")
    && str_contains($invites, 'showPreparedLink(draftInvite, context);'),
    'The canonical invite coordinator must own both prepared-message and ordinary-link Share paths.');

$assert(str_contains($invites, 'data-fallback-share')
    && str_contains($invites, 'data-copy-invite-link')
    && str_contains($invites, 'data-discard-draft')
    && str_contains($invites, "inviteRequest('discard_draft'"),
    'The ordinary-link fallback must remain visible, copyable and cancellable.');

$assert(str_contains($historical, 'initPreparedShareFix')
    && str_contains($historical, 'Telegram-сообщение не удалось подготовить.'),
    'The historical prepared-Share module must remain byte-addressable for rollback but inactive.');

$activeScript = '<script type="module" src="./assets/js/production-regression-fix-entry.js?v=102"></script>';
$mainScript = '<script type="module" src="./assets/js/main.js?v=98.2"></script>';
$assert(str_contains($index, $activeScript)
    && strpos($index, $activeScript) < strpos($index, $mainScript)
    && !str_contains($index, 'production-regression-fix-entry.js?v=96'),
    'The HTML shell must publish the fresh canonical-Share entry before active main.');

$assert(str_contains($v110, "'./assets/js/production-regression-fix-entry.js?v=102'")
    && str_contains($v110, "'./assets/js/production-clean-entry-v110.js?v=1120'"),
    'The v110 wrapper must replace the exact active base entry and retain its own accepted graph.');

$assert(!str_contains($entry, 'lemonchiffon-gerbil-545102.hostingersite.com')
    && !str_contains($entry, 'mini-games-world.com'),
    'The Share ownership fix must not introduce a production target.');

fwrite(STDOUT, "ProductionMvp14R13ShareSingleOwnerTest: {$assertions} assertions passed
");
