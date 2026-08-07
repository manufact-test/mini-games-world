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
    'Search guard must distinguish invitation participants from unrelated users.'
);
$assert(
    str_contains($runtime, "if (\$status === 'pending') continue;"),
    'Any pending invitation, including an outgoing rematch, must not block unrelated matchmaking.'
);
$assert(
    !str_contains($runtime, "\$invite['source'] ?? '') !== 'rematch'"),
    'Search guard must not keep a rematch-only pending exception after rematch acceptance stops auto-starting.'
);
$assert(
    str_contains($runtime, 'if (!$isOwner && !$isInvitee) continue;'),
    'Users unrelated to the invitation must remain outside the guard.'
);
$assert(
    str_contains($runtime, "if (!in_array(\$status, ['pending', 'awaiting_start'], true)) continue;")
        && str_contains($runtime, "'Сначала запустите или отмените подтверждённое приглашение.'"),
    'Accepted invitations must remain protected until explicit Start or Cancel.'
);

fwrite(STDOUT, "ProductionV110PendingInviteSearchNonBlockingContractTest: {$assertions} assertions passed\n");
