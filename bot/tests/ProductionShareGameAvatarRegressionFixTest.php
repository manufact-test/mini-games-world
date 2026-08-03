<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$assertions = 0;

$read = static function (string $path) use ($root): string {
    $content = file_get_contents($root . '/' . $path);
    if (!is_string($content)) throw new RuntimeException('Cannot read regression source: ' . $path);
    return $content;
};

$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

$index = $read('app/index.html');
$entry = $read('app/assets/js/production-regression-fix-entry.js');
$avatar = $read('app/assets/js/production-standard-avatar.js');
$share = $read('app/assets/js/production-prepared-share-fix.js');
$game = $read('app/assets/js/production-tictactoe-turn-fix.js');

$entryPosition = strpos($index, 'production-regression-fix-entry.js?v=96');
$mainPosition = strpos($index, 'main.js?v=98');
$assert(
    $entryPosition !== false
        && $mainPosition !== false
        && $entryPosition < $mainPosition
        && !str_contains($index, 'main.js?v=96')
        && str_contains($index, 'data-hotfix-build="v98-mvp14-notification-canonical-owner"'),
    'The retained v96 regression layer must load before the active v97 application entrypoint.'
);

$assert(
    str_contains($entry, 'initSessionOwnershipFix();')
        && str_contains($entry, 'initProductionUiStabilityFix();')
        && str_contains($entry, 'initCrossGameCoordinator();')
        && str_contains($entry, 'scheduleCrossGameCoordinatorAfterMain();')
        && str_contains($entry, 'initDeterministicGameIcons();')
        && str_contains($entry, 'initStandardAvatarPolicy();')
        && str_contains($entry, 'initPreparedShareFix();')
        && str_contains($entry, 'initTicTacToeTurnFixEarly();')
        && str_contains($entry, 'scheduleTicTacToeTurnFixAfter();'),
    'The current entrypoint must retain session, stability, game, icon, avatar, share and turn ownership repairs.'
);

$assert(
    str_contains($avatar, "const STANDARD_AVATAR_LABEL = 'MG';")
        && str_contains($avatar, "const STANDARD_AVATAR_ID = 'starter-default-01';")
        && str_contains($avatar, "avatar.classList.remove('has-photo')")
        && str_contains($avatar, "avatar.style.backgroundImage = ''"),
    'Telegram photos must stay hidden behind the temporary standard MGW avatar.'
);

$assert(
    str_contains($share, 'prepareMessage:true')
        && str_contains($share, 'prepareMessage === false')
        && str_contains($share, 'body:JSON.stringify({ ...meta.payload, prepareMessage:true })')
        && str_contains($share, "typeof tg?.shareMessage === 'function'")
        && str_contains($share, 'tg.shareMessage(preparedId')
        && str_contains($share, "sent === false ? 'Отправка отменена.'")
        && str_contains($share, "inviteRequest('confirm_shared', { token })"),
    'Prepared-message send/cancel semantics must remain intact.'
);

$assert(
    str_contains($game, 'let viewer = viewerByGame.get(key) || null;')
        && str_contains($game, "viewerId = String(game.turn || '')")
        && str_contains($game, '!button.disabled')
        && str_contains($game, "String(game.turn || '') === viewerId")
        && str_contains($game, "symbol === 'X' || symbol === 'O'")
        && str_contains($game, 'busyGames.has(key) || actionPromiseByGame.has(key)')
        && str_contains($game, 'generation !== gameGeneration(key)'),
    'Tic Tac Toe must retain first-tap ownership and stale-poll protection.'
);

fwrite(STDOUT, "ProductionShareGameAvatarRegressionFixTest: {$assertions} assertions passed\n");
