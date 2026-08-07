<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$read = static function (string $path) use ($root): string {
    $value = file_get_contents($root . '/' . $path);
    if (!is_string($value)) throw new RuntimeException('Cannot read ' . $path);
    return $value;
};

$client = $read('app/assets/js/games/game-invites-v110.js');
$creation = $read('bot/services/invites/GameInviteCreationTrait.php');
$validation = $read('bot/services/invites/GameInviteValidationTrait.php');
$storage = $read('bot/services/invites/GameInviteStorageTrait.php');
$actions = $read('bot/services/invites/GameInviteActionTrait.php');
$runtime = $read('bot/services/ChessRuntimeService.php');
$service = $read('bot/services/GameInviteService.php');

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

$assert(
    str_contains($creation, "return !empty(\$invite['is_owner']);")
        && str_contains($creation, "if (!empty(\$candidate['is_owner'])) {")
        && str_contains($creation, '$trackedInvite = $candidate;')
        && str_contains($creation, '$openedInvite = $candidate;'),
    'Every owner pending invite, including rematch, must be hidden from fresh active sync but stay tracked while its sent sheet owns the exact token.'
);

$assert(
    str_contains($client, 'if (status === \'pending\' && isPassiveOwnerPending(currentInvite)) return false;')
        && str_contains($client, "String(invite?.status || '') === 'pending'")
        && str_contains($client, '&& Boolean(invite?.is_owner);')
        && !str_contains($client, "String(invite?.source || '') !== 'rematch'"),
    'Outgoing pending must be passive immediately in the current document for direct, link and rematch sources.'
);

$assert(
    str_contains($client, 'data-direct-invite-pending=')
        && str_contains($client, 'isDirectInvitePendingSurfaceOpen(requestGeneration)')
        && str_contains($client, '} else if (isPassiveOwnerPending(currentInvite)) {\n      currentInvite = null;'),
    'Closing the optimistic direct sent sheet before create_direct returns must prevent the later response from resurrecting local blocking state.'
);

$assert(
    str_contains($validation, '$passivePendingOwner = $this->isPassivePendingOwner(')
        && str_contains($validation, "in_array(\$status, ['draft', 'pending'], true)")
        && str_contains($validation, "return (string)(\$invite['inviter_id'] ?? '') === \$userId;")
        && !str_contains($validation, "(string)(\$invite['source'] ?? '') !== 'rematch'"),
    'Busy allowance must apply to the sender of any draft or pending invitation, including rematch.'
);

$acceptStart = strpos($actions, 'public function accept(');
$acceptEnd = $acceptStart === false ? false : strpos($actions, 'public function start(', $acceptStart);
$accept = $acceptStart === false || $acceptEnd === false ? '' : substr($actions, $acceptStart, $acceptEnd - $acceptStart);
$assert(
    $accept !== ''
        && substr_count($accept, '$this->assertAvailableForStart(') >= 2
        && !str_contains($accept, 'leaveSearch(')
        && !str_contains($accept, 'startInternal('),
    'Acceptance must validate both players without auto-cancelling activity or auto-starting rematches.'
);

$rematchStart = strpos($actions, 'public function createRematch(');
$rematchEnd = $rematchStart === false ? false : strpos($actions, 'public function markSeen(', $rematchStart);
$rematch = $rematchStart === false || $rematchEnd === false ? '' : substr($actions, $rematchStart, $rematchEnd - $rematchStart);
$assert(
    $rematch !== ''
        && str_contains($rematch, "if (\$status === 'awaiting_start')")
        && str_contains($rematch, "return ['invite' => \$this->publicInvite(\$existing, \$userId), 'game' => null, 'reused' => true];")
        && !str_contains($rematch, "return \$this->startInternal(\$db, \$existing, \$userId)"),
    'Repeated rematch action on an already accepted rematch must not bypass the explicit Start action.'
);

$assert(
    str_contains($runtime, "if (\$status === 'pending') continue;")
        && !str_contains($runtime, "\$invite['source'] ?? '') !== 'rematch'"),
    'Ordinary matchmaking must ignore all pending invitations while accepted invitations remain binding.'
);

$assert(
    str_contains($storage, 'private function effectiveReadyDeadlineTs(array $invite): int')
        && str_contains($storage, "(string)(\$invite['source'] ?? '') !== 'rematch'")
        && str_contains($storage, "\$inviteExpiry = strtotime((string)(\$invite['expires_at'] ?? '')) ?: 0;")
        && str_contains($storage, '$deadline = $this->effectiveReadyDeadlineTs($invite);'),
    'Normal accepted invitations keep their longer original deadline while rematch keeps its existing strict ready deadline.'
);

$assert(
    str_contains($service, "if (\$status === 'awaiting_start' && \$isOwner) return ['start', 'cancel'];"),
    'Accepted owner notification must keep the authoritative Start and Cancel actions for direct invites and rematches.'
);

fwrite(STDOUT, "ProductionMvp14OwnerPendingPassiveLifecycleContractTest: {$assertions} assertions passed\n");
