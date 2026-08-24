<?php
declare(strict_types=1);

require_once __DIR__ . '/../database/DatabaseConnectionInterface.php';
require_once __DIR__ . '/../storage/contracts/StorageTransactionInterface.php';
require_once __DIR__ . '/../services/NotificationService.php';

final class SocialFriendNotificationService
{
    public function __construct(
        private DatabaseConnectionInterface $database,
        private StorageTransactionInterface $storage,
        private ?NotificationService $notifications = null
    ) {
        $this->notifications ??= new NotificationService();
    }

    /** @param array<string,mixed> $mutation */
    public function publish(string $action, string $actorMgwId, string $targetMgwId, array $mutation): ?array
    {
        if (!in_array($action, ['request', 'accept', 'decline', 'cancel', 'block'], true) || empty($mutation['changed'])) return null;

        $eventAt = trim((string)($mutation['event_at'] ?? ''));
        $actorMgwId = trim($actorMgwId);
        $targetMgwId = trim($targetMgwId);
        if ($actorMgwId === '' || $targetMgwId === '') return null;

        if ($action === 'request') {
            $this->synchronizeResolvedForRecipient($targetMgwId);
        } elseif (in_array($action, ['accept', 'decline'], true)) {
            $this->synchronizeResolvedForRecipient($actorMgwId);
        } elseif ($action === 'cancel') {
            $this->synchronizeResolvedForRecipient($targetMgwId);
        } elseif ($action === 'block') {
            $this->synchronizeResolvedForRecipient($actorMgwId);
            $this->synchronizeResolvedForRecipient($targetMgwId);
        }

        if (!in_array($action, ['request', 'accept'], true)) return null;
        if ($eventAt === '') return null;

        $recipientUserId = $this->legacyUserId($targetMgwId);

        $actorNickname = trim((string)$this->database->fetchValue(
            'SELECT nickname FROM mgw_users WHERE mgw_id = :mgw_id AND status = :status',
            ['mgw_id' => $actorMgwId, 'status' => 'active']
        ));
        if ($recipientUserId === '' || $actorNickname === '') return null;

        return $this->storage->transaction(function (array &$data) use (
            $action,
            $recipientUserId,
            $actorMgwId,
            $targetMgwId,
            $actorNickname,
            $eventAt
        ): ?array {
            if ($action === 'request') {
                return $this->notifications->addFriendRequest(
                    $data,
                    $recipientUserId,
                    $actorMgwId,
                    $targetMgwId,
                    $actorNickname,
                    $eventAt
                );
            }
            return $this->notifications->addFriendAccepted(
                $data,
                $recipientUserId,
                $actorMgwId,
                $targetMgwId,
                $actorNickname,
                $eventAt
            );
        });
    }

    public function synchronizeResolvedForRecipient(string $recipientMgwId): int
    {
        $recipientMgwId = trim($recipientMgwId);
        $recipientUserId = $this->legacyUserId($recipientMgwId);
        if ($recipientMgwId === '' || $recipientUserId === '') return 0;

        $activeRequests = [];
        $rows = $this->database->fetchAll(
            "SELECT requested_by_mgw_id, friend_requested_at_utc
             FROM mgw_social_relations
             WHERE friend_status = 'pending'
               AND requested_by_mgw_id IS NOT NULL
               AND requested_by_mgw_id <> :recipient_mgw_id
               AND (user_low_mgw_id = :recipient_low OR user_high_mgw_id = :recipient_high)
               AND blocked_by_low = 0 AND blocked_by_high = 0",
            [
                'recipient_mgw_id' => $recipientMgwId,
                'recipient_low' => $recipientMgwId,
                'recipient_high' => $recipientMgwId,
            ]
        );
        foreach ($rows as $row) {
            $actorMgwId = trim((string)($row['requested_by_mgw_id'] ?? ''));
            $requestedAt = $this->utcTimestamp((string)($row['friend_requested_at_utc'] ?? ''));
            if ($actorMgwId !== '' && $requestedAt > 0) $activeRequests[$actorMgwId] = $requestedAt;
        }

        return (int)$this->storage->transaction(function (array &$data) use (
            $recipientUserId,
            $recipientMgwId,
            $activeRequests
        ): int {
            return $this->notifications->reconcileFriendRequests(
                $data,
                $recipientUserId,
                $recipientMgwId,
                $activeRequests,
                now_iso()
            );
        });
    }

    private function legacyUserId(string $mgwId): string
    {
        $mgwId = trim($mgwId);
        if ($mgwId === '') return '';
        $userId = trim((string)$this->database->fetchValue(
            'SELECT legacy_user_id FROM mgw_account_ownership
             WHERE mgw_id = :mgw_id AND ownership_status = :status',
            ['mgw_id' => $mgwId, 'status' => 'active']
        ));
        if ($userId !== '') return $userId;
        return trim((string)$this->database->fetchValue(
            'SELECT provider_subject FROM mgw_identities
             WHERE mgw_id = :mgw_id AND provider = :provider',
            ['mgw_id' => $mgwId, 'provider' => 'telegram']
        ));
    }

    private function utcTimestamp(string $value): int
    {
        $value = trim($value);
        if ($value === '') return 0;
        try {
            return (new DateTimeImmutable($value, new DateTimeZone('UTC')))->getTimestamp();
        } catch (Throwable) {
            return 0;
        }
    }
}
