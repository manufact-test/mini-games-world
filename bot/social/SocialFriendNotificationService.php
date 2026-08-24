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
        if (!in_array($action, ['request', 'accept'], true) || empty($mutation['changed'])) return null;

        $eventAt = trim((string)($mutation['event_at'] ?? ''));
        $actorMgwId = trim($actorMgwId);
        $targetMgwId = trim($targetMgwId);
        if ($eventAt === '' || $actorMgwId === '' || $targetMgwId === '') return null;

        $recipientUserId = trim((string)$this->database->fetchValue(
            'SELECT legacy_user_id FROM mgw_account_ownership
             WHERE mgw_id = :mgw_id AND ownership_status = :status',
            ['mgw_id' => $targetMgwId, 'status' => 'active']
        ));
        if ($recipientUserId === '') {
            $recipientUserId = trim((string)$this->database->fetchValue(
                'SELECT provider_subject FROM mgw_identities
                 WHERE mgw_id = :mgw_id AND provider = :provider',
                ['mgw_id' => $targetMgwId, 'provider' => 'telegram']
            ));
        }

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
}
