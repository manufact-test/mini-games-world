<?php
declare(strict_types=1);

require_once __DIR__ . '/FriendGraphService.php';
require_once dirname(__DIR__) . '/accounts/MgwIdGenerator.php';

final class SocialInviteGuardException extends RuntimeException {}

/**
 * Adapter between the legacy invite runtime ids and canonical MGW social ids.
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
            throw new SocialInviteGuardException('Игрок MGW недоступен.');
        }
        $this->assertNotBlocked($actorMgwId, $targetMgwId);

        $rows = $this->database->fetchAll(
            'SELECT provider_subject FROM mgw_identities
             WHERE mgw_id = :mgw_id AND provider = :provider
             ORDER BY last_authenticated_at_utc DESC LIMIT 1',
            ['mgw_id' => $targetMgwId, 'provider' => $this->normalizeProvider($provider)]
        );
        $subject = trim((string)($rows[0]['provider_subject'] ?? ''));
        if ($subject === '') throw new SocialInviteGuardException('Игрок сейчас недоступен для приглашения.');
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
        try {
            $this->friends->assertPairNotBlocked($actorMgwId, $targetMgwId);
        } catch (FriendGraphException $error) {
            if ($error->reason === 'blocked_pair') {
                throw new SocialInviteGuardException('Приглашение недоступно: один из игроков заблокирован.');
            }
            throw $error;
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
            throw new SocialInviteGuardException('Платформа приглашения недоступна.');
        }
        return $provider;
    }
}
