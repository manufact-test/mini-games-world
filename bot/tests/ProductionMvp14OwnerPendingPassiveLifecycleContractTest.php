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
$service = $read('bot/services/GameInviteService.php');

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

$assert(
    str_contains($creation, "return !empty(\$invite['is_owner']) && (string)(\$invite['source'] ?? '') !== 'rematch';")
        && str_contains($creation, '$trackedInvite = $candidate;')
        && str_contains($creation, '$openedInvite = $candidate;'),
    'Normal owner pending must be hidden from fresh active sync but stay tracked while its sent sheet owns the token.'
);

$assert(
    str_contains($client, 'if (isPassiveOwnerPending(currentInvite)) currentInvite = null;')
        && str_contains($client, "String(invite?.source || '') !== 'rematch'"),
    'Closing the sent sheet must release only a normal owner pending client state, not rematch/accepted states.'
);

$assert(
    str_contains($validation, '$passivePendingOwner = $this->isPassivePendingOwner(')
        && str_contains($validation, "in_array(\$status, ['draft', 'pending'], true)")
        && str_contains($validation, "(string)(\$invite['source'] ?? '') !== 'rematch'"),
    'Busy allowance must be limited to the sender of a normal draft/pending invitation.'
);

$acceptStart = strpos($actions, 'public function accept(');
$acceptEnd = $acceptStart === false ? false : strpos($actions, 'public function start(', $acceptStart);
$accept = $acceptStart === false || $acceptEnd === false ? '' : substr($actions, $acceptStart, $acceptEnd - $acceptStart);
$assert(
    $accept !== ''
        && substr_count($accept, '$this->assertAvailableForStart(') >= 2
        && !str_contains($accept, 'leaveSearch('),
    'Acceptance must reuse the single availability contract and must not auto-cancel the sender search.'
);

$assert(
    str_contains($storage, 'private function effectiveReadyDeadlineTs(array $invite): int')
        && str_contains($storage, "(string)(\$invite['source'] ?? '') !== 'rematch'")
        && str_contains($storage, "\$inviteExpiry = strtotime((string)(\$invite['expires_at'] ?? '')) ?: 0;")
        && str_contains($storage, '$deadline = $this->effectiveReadyDeadlineTs($invite);'),
    'Normal accepted invitations must keep the later original invite deadline without changing rematch expiry semantics.'
);

$assert(
    str_contains($service, "if (\$status === 'awaiting_start' && \$isOwner) return ['start', 'cancel'];"),
    'Accepted owner notification must keep the existing authoritative Start/Cancel actions.'
);

fwrite(STDOUT, "ProductionMvp14OwnerPendingPassiveLifecycleContractTest: {$assertions} assertions passed\n");
