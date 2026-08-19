<?php
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/services/GameInviteService.php';

$assertions = 0;
$assertSame = static function (mixed $expected, mixed $actual, string $message) use (&$assertions): void {
    $assertions++;
    if ($expected !== $actual) {
        throw new RuntimeException($message . ': expected ' . var_export($expected, true)
            . ', got ' . var_export($actual, true));
    }
};
$assertTrue = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

$reflection = new ReflectionClass(GameInviteService::class);
$service = $reflection->newInstanceWithoutConstructor();
$now = time();
$token = str_repeat('a', 24);

$baseInvite = [
    'token' => $token,
    'status' => 'pending',
    'source' => 'link',
    'inviter_id' => 'private-owner-id',
    'inviter_name' => 'Private Owner',
    'invitee_id' => null,
    'invitee_name' => null,
    'game_type' => 'tictactoe',
    'game_title' => 'Крестики-нолики',
    'room' => 'match',
    'bet' => 100,
    'board_size' => 3,
    'created_at' => gmdate('c', $now),
    'updated_at' => gmdate('c', $now),
    'expires_at' => gmdate('c', $now + 120),
    'ready_deadline_at' => null,
];

$pendingSnapshot = $service->landingSnapshot(['invites' => [$baseInvite]], $token);
$assertSame(true, $pendingSnapshot['available'], 'Unbound pending link must be available on the public landing');
$assertSame('available', $pendingSnapshot['state'], 'Available pending link must use generic available state');
$assertSame('pending', $pendingSnapshot['phase'], 'Pending landing must preserve only the non-private countdown phase');
$assertTrue(
    (int)$pendingSnapshot['waiting_seconds'] >= 118 && (int)$pendingSnapshot['waiting_seconds'] <= 120,
    'Pending landing countdown must come from the canonical pending expiry'
);
$assertSame(
    ['available', 'state', 'phase', 'waiting_seconds'],
    array_keys($pendingSnapshot),
    'Public landing projection must expose only the privacy-safe allowlist'
);

$draft = $baseInvite;
$draft['status'] = 'draft';
$draft['expires_at'] = gmdate('c', $now + 900);
$draftSnapshot = $service->landingSnapshot(['invites' => [$draft]], $token);
$assertSame(true, $draftSnapshot['available'], 'Technical unbound draft must remain reachable before authenticated open');
$assertSame('draft', $draftSnapshot['phase'], 'Draft landing must not masquerade as the 120-second pending phase');
$assertTrue(
    (int)$draftSnapshot['waiting_seconds'] >= 898 && (int)$draftSnapshot['waiting_seconds'] <= 900,
    'Draft landing must show the technical link lifetime without mutating it to pending'
);

$claimed = $baseInvite;
$claimed['invitee_id'] = 'first-accepter-id';
$claimed['invitee_name'] = 'Private Accepter';
$claimedSnapshot = $service->landingSnapshot(['invites' => [$claimed]], $token);
$assertSame(false, $claimedSnapshot['available'], 'A link bound by the first accepter must not reopen publicly');
$assertSame('unavailable', $claimedSnapshot['state'], 'Claimed invite must use a generic non-private unavailable state');
$assertSame('', $claimedSnapshot['phase'], 'Claimed invite must not expose lifecycle details');
$assertSame(0, $claimedSnapshot['waiting_seconds'], 'Claimed invite must not expose its internal deadline');

$expired = $baseInvite;
$expired['expires_at'] = gmdate('c', $now - 1);
$expiredSnapshot = $service->landingSnapshot(['invites' => [$expired]], $token);
$assertSame(false, $expiredSnapshot['available'], 'Expired invite must not remain actionable');
$assertSame('expired', $expiredSnapshot['state'], 'Expired invite may expose only its generic expired state');
$assertSame(0, $expiredSnapshot['waiting_seconds'], 'Expired invite must have no remaining public countdown');

$missingSnapshot = $service->landingSnapshot(['invites' => []], str_repeat('b', 24));
$assertSame(false, $missingSnapshot['available'], 'Unknown invite code must be unavailable');
$assertSame('unavailable', $missingSnapshot['state'], 'Unknown invite must not reveal whether a record ever existed');

$endpointSource = file_get_contents($root . '/invites.php');
$creationSource = file_get_contents($root . '/services/invites/GameInviteCreationTrait.php');
$serviceSource = file_get_contents($root . '/services/GameInviteService.php');
$siteSource = file_get_contents(dirname($root) . '/site/invite.php');
$rewriteSource = file_get_contents(dirname($root) . '/.htaccess');
$launchSource = file_get_contents($root . '/helpers/WebAppLaunchUrl.php');
foreach ([$endpointSource, $creationSource, $serviceSource, $siteSource, $rewriteSource, $launchSource] as $source) {
    if (!is_string($source)) throw new RuntimeException('MVP-18.4 contract source is unavailable.');
}

$assertTrue(
    str_contains($endpointSource, 'return $baseUrl . \'/invite/\' . rawurlencode($normalizedToken);'),
    'Shared link owner must emit the universal /invite/CODE URL'
);
$assertTrue(
    str_contains($rewriteSource, 'RewriteRule ^invite/([A-Fa-f0-9]{24})/?$ site/invite.php?code=$1 [L,QSA]'),
    'Root dispatcher must route canonical 24-hex invite URLs to the isolated public landing'
);
$assertTrue(str_contains($siteSource, "['invites']"), 'Public landing must use a read-only invite-only snapshot');
$assertTrue(str_contains($siteSource, 'landingSnapshot($data, $token)'), 'Public landing must reuse the canonical invite projection');
$assertTrue(str_contains($siteSource, '?start=invite_'), 'Telegram platform button must reuse the existing bot deep-link transport');
$assertTrue(str_contains($siteSource, 'noindex, nofollow, noarchive'), 'Invite landing must never be indexable');
$assertTrue(str_contains($siteSource, 'no-store'), 'Invite landing must never be cached as public content');
$assertTrue(str_contains($siteSource, 'Referrer-Policy: no-referrer'), 'Invite code must not leak through referrer headers');
$assertTrue(!str_contains($siteSource, 'inviter_name'), 'Public landing must not render inviter identity');
$assertTrue(!str_contains($siteSource, 'invitee_name'), 'Public landing must not render accepter identity');
$assertTrue(!str_contains($siteSource, 'inviter_id'), 'Public landing must not render inviter ID');
$assertTrue(!str_contains($siteSource, 'invitee_id'), 'Public landing must not render accepter ID');
$assertTrue(
    str_contains($creationSource, 'Нельзя открыть собственное приглашение как соперник.'),
    'Authenticated open owner must retain self-invite protection'
);
$assertTrue(
    str_contains($creationSource, 'Это приглашение уже предназначено другому игроку.'),
    'Authenticated open owner must retain first-accepter/reopen protection'
);
$assertTrue(
    str_contains($creationSource, '$invite[\'invitee_id\'] = $userId;'),
    'First accepter binding must remain in the existing authenticated bindFromLink owner'
);
$assertTrue(
    str_contains($serviceSource, '$snapshot = $db;') && str_contains($serviceSource, '$this->cleanup($snapshot);'),
    'Public landing lifecycle normalization must happen on an in-memory copy only'
);
$assertTrue(
    str_contains($launchSource, "private const ENTRY_PATH = '/app/v110.php?v=1127';"),
    'MVP-18.4 must preserve the frozen Telegram Mini App entry identity'
);

fwrite(STDOUT, 'PASS: MVP-18.4 universal invite landing contract (' . $assertions . " assertions)\n");
