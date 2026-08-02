<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$spec = file_get_contents($root . '/e2e/staging/two-context.spec.mjs');
$invites = file_get_contents($root . '/bot/invites.php');
$notifications = file_get_contents($root . '/bot/notifications.php');
$renderer = file_get_contents($root . '/app/assets/js/games/tictactoe/renderer.js');
if (!is_string($spec) || !is_string($invites) || !is_string($notifications) || !is_string($renderer)) {
    throw new RuntimeException('Missing staging invite-match E2E source.');
}

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

$assert(str_contains($spec, "test('A invites B through notifications and they finish a Tic Tac Toe match'")
    && str_contains($spec, "action: 'create_direct'")
    && str_contains($spec, "inviteeId: 'stg_test_player_b'")
    && str_contains($spec, "gameType: 'tictactoe'")
    && str_contains($spec, "room: 'match'")
    && str_contains($spec, 'bet: 10')
    && str_contains($spec, 'boardSize: 3'),
    'The live suite must create one fixed direct Match-room Tic Tac Toe invitation from A to B.');

$assert(str_contains($spec, "openNotificationsAndWaitForAction(\n      playerB.page,\n      inviteToken,\n      'accept'")
    && str_contains($spec, "clickInviteAction(playerB.page, acceptButton, 'accept')")
    && str_contains($spec, "toHaveText('Приглашение принято'")
    && str_contains($spec, "openNotificationsAndWaitForAction(\n      playerA.page,\n      inviteToken,\n      'start'")
    && str_contains($spec, "clickInviteAction(playerA.page, startButton, 'start')"),
    'B must accept and A must start the invitation through the visible notifications UI.');

$assert(str_contains($spec, "const winningSequence = [0, 3, 1, 4, 2]")
    && str_contains($spec, 'playTicTacToeCell(actor, cell)')
    && str_contains($spec, '#screen-game.active [data-game-cell=')
    && str_contains($renderer, 'data-game-cell='),
    'The match must be completed through enabled rendered board cells with a deterministic win sequence.');

$assert(str_contains($spec, "toHaveText('Победа!'")
    && str_contains($spec, "toHaveText('Поражение'")
    && str_contains($spec, 'afterBalances[winnerId] - beforeBalances[winnerId]').
    && str_contains($spec, 'afterBalances[loserId] - beforeBalances[loserId]'),
    'The suite must verify both result sheets and relative winner/loser Match balances.');

$assert(str_contains($spec, 'totalPreserved: true')
    && str_contains($spec, 'winnerDelta: 10')
    && str_contains($spec, 'loserDelta: -10')
    && str_contains($spec, 'livePaymentsUsed: false')
    && str_contains($spec, 'productionChanged: false'),
    'The report must prove conservation, exact Match deltas and staging-only execution.');

$assert(str_contains($spec, 'async function cleanupPlayer(player)')
    && str_contains($spec, "action: 'leave_game'")
    && str_contains($spec, "action: 'leave_search'")
    && str_contains($spec, "action: 'cancel'")
    && str_contains($spec, 'if (playerA) await cleanupPlayer(playerA)')
    && str_contains($spec, 'if (playerB) await cleanupPlayer(playerB)'),
    'Failed or interrupted live runs must clean active games, searches and invitations before revoking sessions.');

$assert(str_contains($notifications, "if (\$status === 'pending' && \$invitee) return ['accept', 'decline'];")
    && str_contains($notifications, "if (\$status === 'accepted' && \$owner) return ['start', 'cancel'];")
    && str_contains($invites, "case 'create_direct':")
    && str_contains($invites, "case 'accept':")
    && str_contains($invites, "case 'start':"),
    'The tested notification actions must remain backed by the production invitation routes.');

$assert(!str_contains($spec, 'setup_secret')
    && !str_contains($spec, 'staging_test_auth_secret')
    && !str_contains($spec, 'lemonchiffon-gerbil-545102.hostingersite.com')
    && !str_contains($spec, 'mini-games-world.com'),
    'The live gameplay scenario must contain no long-lived secret or production target.');

fwrite(STDOUT, "ProductionMvp14R13StagingInviteTicTacToeE2ETest: {$assertions} assertions passed\n");
