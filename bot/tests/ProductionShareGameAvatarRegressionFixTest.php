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

$entryPosition = strpos($index, 'production-regression-fix-entry.js?v=93');
$mainPosition = strpos($index, 'main.js?v=92');
$assert(
    $entryPosition !== false
        && $mainPosition !== false
        && $entryPosition < $mainPosition
        && str_contains($index, 'data-hotfix-build="v93-mvp14-share-game-avatar-regression-fix"'),
    'The v93 regression layer must load before the v92 application entrypoint.'
);

$assert(
    str_contains($entry, 'initStandardAvatarPolicy();')
        && str_contains($entry, 'initPreparedShareFix();')
        && str_contains($entry, 'initTicTacToeTurnFixEarly();')
        && str_contains($entry, 'scheduleTicTacToeTurnFixAfter();'),
    'The v93 entrypoint must install all three isolated regression repairs.'
);

$assert(
    str_contains($avatar, "const STANDARD_AVATAR_LABEL = 'MG';")
        && str_contains($avatar, "const STANDARD_AVATAR_ID = 'starter-default-01';")
        && str_contains($avatar, "avatar.classList.remove('has-photo')")
        && str_contains($avatar, "avatar.style.backgroundImage = ''"),
    'Telegram photos must be hidden behind the temporary standard MGW avatar.'
);

$assert(
    str_contains($share, 'prepareMessage:true')
        && str_contains($share, 'prepareMessage === false')
        && str_contains($share, 'body:JSON.stringify({ ...meta.payload, prepareMessage:true })')
        && str_contains($share, "typeof tg?.shareMessage === 'function'")
        && str_contains($share, 'tg.shareMessage(preparedId')
        && str_contains($share, "sent === false ? 'Отправка отменена.'")
        && str_contains($share, "inviteRequest('confirm_shared', { token })"),
    'Link sharing must restore Telegram prepared-message send/cancel semantics.'
);

$assert(
    str_contains($game, 'const viewer = viewerByGame.get(key);')
        && str_contains($game, "String(game.turn || '') === viewerId")
        && str_contains($game, "symbol === 'X' || symbol === 'O'")
        && str_contains($game, 'busyGames.has(key) || actionPromiseByGame.has(key)')
        && str_contains($game, 'actionPromiseByGame.set(key, actionPromise)')
        && str_contains($game, 'generation !== gameGeneration(key)')
        && !str_contains($game, 'state.user?.id || state.user?.telegram_id'),
    'Tic-tac-toe must use the authoritative viewer, one action lock and stale-poll rejection.'
);

$assert(
    str_contains($game, "turn:String(nextPlayer?.id || '')")
        && str_contains($game, "api.gameAction(game.id, { type:'cell', cell })")
        && str_contains($game, 'renderBoard(result.game, result.me || viewer)'),
    'The optimistic board must immediately pass the turn and reconcile from the server result.'
);

fwrite(STDOUT, "ProductionShareGameAvatarRegressionFixTest: {$assertions} assertions passed\n");
