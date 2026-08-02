<?php
declare(strict_types=1);

require_once __DIR__ . '/invites/GameInviteCreationTrait.php';
require_once __DIR__ . '/invites/GameInviteActionTrait.php';
require_once __DIR__ . '/invites/GameInviteStorageTrait.php';
require_once __DIR__ . '/invites/GameInviteValidationTrait.php';

final class GameInviteService
{
    use GameInviteCreationTrait;
    use GameInviteActionTrait;
    use GameInviteStorageTrait;
    use GameInviteValidationTrait;

    private const INVITE_TTL_SEC = 900;
    private const READY_TTL_SEC = 90;
    private const RETENTION_SEC = 604800;

    public function __construct(
        private array $config,
        private GameCatalogService $catalog,
        private ChessRuntimeService $games
    ) {}

    /**
     * The live invite event is the same authoritative object used by the toast.
     * It must already contain its legal actions and status so opening that toast
     * can paint a complete mobile first frame before any follow-up request.
     */
    private function inviteEventsForUser(array $db, string $userId): array
    {
        $events = [];
        $invites = $this->invitesByToken($db);

        foreach ($db['notifications'] ?? [] as $notification) {
            if (!is_array($notification)) continue;
            if ((string)($notification['user_id'] ?? '') !== $userId) continue;
            if (!empty($notification['hidden_at'])) continue;
            if (!str_starts_with((string)($notification['type'] ?? ''), 'invite_')) continue;
            if (!$this->inviteNotificationVisible($notification, $invites)) continue;

            $token = (string)($notification['invite_token'] ?? '');
            $invite = $token !== '' ? ($invites[$token] ?? null) : null;
            $events[] = [
                'id' => (string)($notification['id'] ?? ''),
                'type' => (string)($notification['type'] ?? ''),
                'title' => (string)($notification['title'] ?? ''),
                'message' => (string)($notification['message'] ?? ''),
                'tone' => (string)($notification['tone'] ?? 'info'),
                'invite_token' => $token,
                'invite_status' => $this->liveInviteStatus($invite),
                'invite_is_owner' => is_array($invite)
                    && (string)($invite['inviter_id'] ?? '') === $userId,
                'actions' => $this->liveInviteActions($invite, $userId),
                'created_at' => (string)($notification['created_at'] ?? ''),
                'read' => !empty($notification['read_at']),
            ];
        }

        usort($events, static function (array $left, array $right): int {
            $leftTime = strtotime((string)($left['created_at'] ?? '')) ?: 0;
            $rightTime = strtotime((string)($right['created_at'] ?? '')) ?: 0;
            if ($leftTime !== $rightTime) return $rightTime <=> $leftTime;
            return strcmp((string)($right['id'] ?? ''), (string)($left['id'] ?? ''));
        });

        return array_slice($events, 0, 20);
    }

    private function liveInviteStatus(?array $invite): string
    {
        if (!is_array($invite)) return '';
        $status = (string)($invite['status'] ?? '');
        return $status === 'awaiting_start' ? 'accepted' : $status;
    }

    private function liveInviteActions(?array $invite, string $userId): array
    {
        if (!is_array($invite)) return [];

        $status = (string)($invite['status'] ?? '');
        $isOwner = (string)($invite['inviter_id'] ?? '') === $userId;
        $isInvitee = (string)($invite['invitee_id'] ?? '') === $userId;

        if ($status === 'pending' && $isInvitee) return ['accept', 'decline'];
        if ($status === 'awaiting_start' && $isOwner) return ['start', 'cancel'];
        if ($status === 'awaiting_start' && $isInvitee) return ['cancel'];
        return [];
    }
}
