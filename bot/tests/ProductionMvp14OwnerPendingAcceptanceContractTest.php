<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$action = file_get_contents($root . '/bot/services/invites/GameInviteActionTrait.php');
$creation = file_get_contents($root . '/bot/services/invites/GameInviteCreationTrait.php');
$validation = file_get_contents($root . '/bot/services/invites/GameInviteValidationTrait.php');
$client = file_get_contents($root . '/app/assets/js/games/game-invites-v110.js');
$notifications = file_get_contents($root . '/bot/notifications.php');

foreach ([$action, $creation, $validation, $client, $notifications] as $source) {
    if (!is_string($source)) throw new RuntimeException('Cannot read owner-pending acceptance sources.');
}

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

$assert(
    str_contains($creation, "return !empty(\$invite['is_owner']) && (string)(\$invite['source'] ?? '') !== 'rematch';")
        && str_contains($creation, '$trackedInvite = $candidate;')
        && str_contains($creation, '$openedInvite = $candidate;'),
    'Sync must hide normal owner pending invitations from fresh active state while retaining an explicitly tracked sent sheet.'
);

$assert(
    str_contains($client, 'if (isPassiveOwnerPending(currentInvite)) currentInvite = null;')
        && str_contains($client, "String(invite?.source || '') !== 'rematch'"),
    'Closing a normal sent-invitation sheet must release only the passive owner pending client state.'
);

$assert(
    str_contains($action, '$isRematch = (string)($invite[\'source\'] ?? \'\') === \'rematch\';')
        && str_contains($action, '$this->assertNoOpenInvite(')
        && str_contains($action, "if ((string)(\$inviter['status'] ?? '') === 'searching')")
        && str_contains($action, '$this->games->leaveSearch($db, $inviter);')
        && str_contains($validation, 'private function isBusyWithGameOrSearch('),
    'Normal invitation acceptance must tolerate a busy owner, cancel an unrelated search, and preserve rematch strictness.'
);

$assert(
    str_contains($action, '$readyTtl = $inviterBusy ? self::INVITE_TTL_SEC : self::READY_TTL_SEC;')
        && str_contains($action, "'invite_accepted'")
        && str_contains($action, "'Соперник согласен'"),
    'Acceptance while the owner is already playing must receive a longer handoff window and the existing accepted notification.'
);

$assert(
    str_contains($notifications, "if (\$status === 'accepted' && \$owner) return ['start', 'cancel'];"),
    'The accepted owner notification must keep the existing Start/Cancel authoritative actions.'
);

fwrite(STDOUT, "ProductionMvp14OwnerPendingAcceptanceContractTest: {$assertions} assertions passed\n");
