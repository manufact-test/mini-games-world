<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$spec = file_get_contents($root . '/e2e/staging/two-context.spec.mjs');
$endpoint = file_get_contents($root . '/bot/invites.php');
$validation = file_get_contents($root . '/bot/services/invites/GameInviteValidationTrait.php');
foreach ([$spec, $endpoint, $validation] as $source) {
    if (!is_string($source)) throw new RuntimeException('Missing direct-invite public contract source.');
}

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

$assert(str_contains($spec, "expect(created.user?.id).toBe('stg_test_player_a')")
    && str_contains($spec, "expect(created.recipient_id).toBe('stg_test_player_b')")
    && str_contains($spec, 'expect(created.invite?.is_owner).toBe(true)')
    && str_contains($spec, 'expect(created.invite?.is_invitee).toBe(false)'),
    'The E2E scenario must prove actor and recipient through supported public response fields.');

$assert(str_contains($spec, "expect(created.invite).not.toHaveProperty('inviter_id')")
    && str_contains($spec, "expect(created.invite).not.toHaveProperty('invitee_id')")
    && !str_contains($spec, 'created.invite?.inviter_id')
    && !str_contains($spec, 'created.invite?.invitee_id'),
    'The E2E scenario must preserve participant ID redaction in the public invite object.');

$publicInvitePosition = strpos($validation, 'private function publicInvite(array $invite, string $viewerId): array');
$isParticipantPosition = strpos($validation, 'private function isParticipant(array $invite, string $userId): bool');
$publicInviteSource = $publicInvitePosition !== false && $isParticipantPosition !== false
    ? substr($validation, $publicInvitePosition, $isParticipantPosition - $publicInvitePosition)
    : '';
$returnPosition = strpos($publicInviteSource, 'return [');
$returnEnd = $returnPosition !== false ? strpos($publicInviteSource, '];', $returnPosition) : false;
$publicReturn = $returnPosition !== false && $returnEnd !== false
    ? substr($publicInviteSource, $returnPosition, $returnEnd - $returnPosition + 2)
    : '';
$assert($publicReturn !== ''
    && str_contains($publicReturn, "'is_owner' => \$isOwner")
    && str_contains($publicReturn, "'is_invitee' => \$isInvitee")
    && !str_contains($publicReturn, "'inviter_id' =>")
    && !str_contains($publicReturn, "'invitee_id' =>"),
    'The public invite return object must expose viewer roles without raw participant ID keys.');

$assert(str_contains($endpoint, "\$core['recipient_id'] = \$inviteeId")
    && str_contains($endpoint, "\$core['user'] = \$users->publicUser(\$user)")
    && str_contains($endpoint, 'api_ok($result);'),
    'The endpoint must return the supported actor and direct recipient evidence used by the E2E assertion.');

$acceptPosition = strpos($spec, "openNotificationsAndWaitForAction(\n      playerB.page,\n      inviteToken,\n      'accept'");
$createPosition = strpos($spec, "action: 'create_direct'");
$assert($createPosition !== false && $acceptPosition !== false && $createPosition < $acceptPosition,
    'Player B must still prove recipient ownership by receiving and accepting the exact invitation token.');

$assert(!str_contains($spec, 'lemonchiffon-gerbil-545102.hostingersite.com')
    && !str_contains($spec, 'mini-games-world.com')
    && str_contains($spec, 'seashell-okapi-889488.hostingersite.com'),
    'The corrected scenario must remain isolated to staging.');

fwrite(STDOUT, "ProductionMvp14R13StagingDirectInvitePublicContractTest: {$assertions} assertions passed\n");
