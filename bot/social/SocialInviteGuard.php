<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/localization/ServerLocalization.php';

require_once __DIR__ . '/FriendGraphService.php';
require_once dirname(__DIR__) . '/accounts/MgwIdGenerator.php';
require_once dirname(__DIR__) . '/accounts/RuntimeAccountOwnershipService.php';

final class SocialInviteGuardException extends RuntimeException {}

/**
 * Adapter between legacy invite runtime ids and canonical MGW social ids.
 * Block ownership remains exclusively in FriendGraphService.
 */
final class SocialInviteGuard
{
    private FriendGraphService $friends;

    public function __construct(private DatabaseConnectionInterface $database)
    {
        $this->friends = new FriendGraphService($database);
    }

    public function runtimeSubjectForMgwId(string $actorMgwId, string $targetMgwId, string $provider): string
    {
        $actorMgwId = strtoupper(trim($actorMgwId));
        $targetMgwId = strtoupper(trim($targetMgwId));
        if (!MgwIdGenerator::isValid($actorMgwId) || !MgwIdGenerator::isValid($targetMgwId)) {
            throw new SocialInviteGuardException(ServerLocalization::copy('server.social_invite.mgw_player_unavailable', 'The MGW player is unavailable.'));
        }
        $this->assertNotBlocked($actorMgwId, $targetMgwId);

        // Provider-neutral runtime ownership is the canonical bridge from MGW
        // account identity back to the active legacy/runtime subject. This keeps
        // Android-linked and Android-only accounts inviteable without inventing
        // a second invite owner. Historical provider lookup remains fallback.
        $ownership = null;
        try {
            $ownership = (new RuntimeAccountOwnershipService($this->database))->findByMgwId($targetMgwId);
        } catch (Throwable $ownershipError) {
            $message = strtolower($ownershipError->getMessage());
            if (str_contains($message, 'not active')
                || str_contains($message, 'collides')
                || str_contains($message, 'invalid')) {
                throw $ownershipError;
            }
            // Historical/minimal test schemas may predate runtime ownership.
            // Keep the established provider lookup as compatibility fallback.
        }
        $ownedRuntimeSubject = trim((string)($ownership['legacy_user_id'] ?? ''));
        if ($ownedRuntimeSubject !== '') return $ownedRuntimeSubject;

        $rows = $this->database->fetchAll(
            'SELECT provider_subject FROM mgw_identities
             WHERE mgw_id = :mgw_id AND provider = :provider
             ORDER BY last_authenticated_at_utc DESC LIMIT 1',
            ['mgw_id' => $targetMgwId, 'provider' => $this->normalizeProvider($provider)]
        );
        $subject = trim((string)($rows[0]['provider_subject'] ?? ''));
        if ($subject === '') throw new SocialInviteGuardException(ServerLocalization::copy('server.social_invite.player_unavailable', 'The player is currently unavailable for invitations.'));
        return $subject;
    }

    public function assertRuntimeSubjectNotBlocked(
        string $actorMgwId,
        string $targetRuntimeSubject,
        string $provider
    ): void {
        $targetMgwId = $this->mgwIdForRuntimeSubject($targetRuntimeSubject, $provider);
        if ($targetMgwId === '') return;
        $this->assertNotBlocked($actorMgwId, $targetMgwId);
    }

    public function mgwIdForRuntimeSubject(string $runtimeSubject, string $provider): string
    {
        $runtimeSubject = trim($runtimeSubject);
        if ($runtimeSubject === '') return '';
        $rows = $this->database->fetchAll(
            'SELECT mgw_id FROM mgw_identities
             WHERE provider = :provider AND provider_subject = :provider_subject
             ORDER BY last_authenticated_at_utc DESC LIMIT 1',
            ['provider' => $this->normalizeProvider($provider), 'provider_subject' => $runtimeSubject]
        );
        return strtoupper(trim((string)($rows[0]['mgw_id'] ?? '')));
    }

    public function assertNotBlocked(string $actorMgwId, string $targetMgwId): void
    {
        if ($actorMgwId === $targetMgwId) return;
        // FriendGraphService::lookupExact is the canonical read boundary and
        // intentionally hides either block direction. Do not duplicate its SQL.
        if ($this->friends->lookupExact($actorMgwId, $targetMgwId) === null) {
            throw new SocialInviteGuardException(ServerLocalization::copy('server.social_invite.blocked_or_unavailable', 'The invitation is unavailable because the player is blocked or unavailable.'));
        }
    }

    public static function providerForAuthenticatedUser(array $user): string
    {
        return !empty($user['is_dev_user']) ? 'development' : 'telegram';
    }

    private function normalizeProvider(string $provider): string
    {
        $provider = strtolower(trim($provider));
        if (!in_array($provider, ['telegram', 'development'], true)) {
            throw new SocialInviteGuardException(ServerLocalization::copy('server.social_invite.platform_unavailable', 'The invitation platform is unavailable.'));
        }
        return $provider;
    }
}
