<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$runtime = file_get_contents($root . '/bot/services/ChessRuntimeService.php');
if (!is_string($runtime)) {
    throw new RuntimeException('Cannot read ChessRuntimeService.php');
}

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

$assert(
    str_contains($runtime, '$isOwner = (string)($invite[\'inviter_id\'] ?? \'\') === $userId;')
        && str_contains($runtime, '$isInvitee = (string)($invite[\'invitee_id\'] ?? \'\') === $userId;'),
    'Search guard must distinguish invitation owner and recipient roles.'
);
$assert(
    str_contains($runtime, "if (\$status === 'pending' && \$isInvitee && !\$isOwner) continue;"),
    'A received pending invitation must not block unrelated matchmaking.'
);
$assert(
    str_contains($runtime, "if (\$status === 'pending' && \$isOwner && (string)(\$invite['source'] ?? '') !== 'rematch') continue;"),
    'A normal outgoing pending invitation must not block its owner from unrelated matchmaking.'
);
$assert(
    str_contains($runtime, 'if (!$isOwner && !$isInvitee) continue;'),
    'Users unrelated to the invitation must remain outside the guard.'
);
$assert(
    str_contains($runtime, "if (!in_array(\$status, ['pending', 'awaiting_start'], true)) continue;")
        && str_contains($runtime, "'Сначала запустите или отмените подтверждённое приглашение.'")
        && str_contains($runtime, "'Сначала ответьте на текущее приглашение или отмените его.'"),
    'Accepted invitations and pending owner rematches must remain protected.'
);

fwrite(STDOUT, "ProductionV110PendingInviteSearchNonBlockingContractTest: {$assertions} assertions passed\n");
