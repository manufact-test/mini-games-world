<?php
declare(strict_types=1);

require_once __DIR__ . '/invites/GameInviteCreationTrait.php';
require_once __DIR__ . '/invites/GameInviteActionTrait.php';
require_once __DIR__ . '/invites/GameInviteStorageTrait.php';
require_once __DIR__ . '/invites/GameInviteValidationTrait.php';

final class GameInviteService
{
    use GameInviteCreationTrait;
    use GameInviteActionTrait { createRematch as private createRematchFromTrait; }
    use GameInviteStorageTrait;
    use GameInviteValidationTrait;

    private const INVITE_TTL_SEC = 120;
    private const DRAFT_TTL_SEC = 900;
    private const READY_TTL_SEC = 90;
    private const RETENTION_SEC = 604800;

    public function __construct(
        private array $config,
        private GameCatalogService $catalog,
        private ChessRuntimeService $games
    ) {}

    /**
     * Preserve the established rematch lifecycle while keeping the public error
     * reason opponent-neutral. Older cached clients may still call this endpoint
     * after a match where direct rematch is not an available capability.
     */
    public function createRematch(array &$db, array &$user, string $gameId): array
    {
        $game = $db['games'][$gameId] ?? null;
        if (is_array($game) && (string)($game['status'] ?? '') === 'finished' && !empty($game['is_bot_game'])) {
            throw new RuntimeException('Реванш сейчас недоступен. Выберите «Сыграть ещё».');
        }
        return $this->createRematchFromTrait($db, $user, $gameId);
    }

    /**
     * Read-only projection for the public /invite/CODE landing.
     *
     * The projection deliberately exposes no player identity, IDs, balance,
     * room, match identifiers or raw token. Lifecycle normalization runs on an
     * in-memory copy so the public GET never becomes a second invite state owner.
     */
    public function landingSnapshot(array $db, string $token): array
    {
        $snapshot = $db;
        $this->cleanup($snapshot);
        $index = $this->findIndex($snapshot, $token);
        if ($index === null || !isset($snapshot['invites'][$index]) || !is_array($snapshot['invites'][$index])) {
            return [
                'available' => false,
                'state' => 'unavailable',
                'phase' => '',
                'waiting_seconds' => 0,
            ];
        }

        $invite = $snapshot['invites'][$index];
        $status = (string)($invite['status'] ?? '');
        $bound = trim((string)($invite['invitee_id'] ?? '')) !== '';
        $available = in_array($status, ['draft', 'pending'], true) && !$bound;
        $deadlineTs = $available
            ? (strtotime((string)($invite['expires_at'] ?? '')) ?: 0)
            : 0;
        $expired = in_array($status, ['expired', 'timed_out'], true)
            || ($available && $deadlineTs > 0 && $deadlineTs <= time());

        return [
            'available' => $available && !$expired,
            'state' => $expired ? 'expired' : ($available ? 'available' : 'unavailable'),
            'phase' => $available ? $status : '',
            'waiting_seconds' => $available && $deadlineTs > 0
                ? max(0, $deadlineTs - time())
                : 0,
        ];
    }

    public function notificationSnapshot(array $invite, string $viewerId): array
    {
        return $this->publicInvite($invite, $viewerId);
    }

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
            $type = (string)($notification['type'] ?? '');
            $inviteSnapshot = is_array($invite) ? $this->publicInvite($invite, $userId) : null;
            $events[] = [
                'id' => (string)($notification['id'] ?? ''),
                'type' => $type,
                'title' => (string)($notification['title'] ?? ''),
                'message' => $this->liveInviteMessage($notification, $invite, $userId),
                'tone' => (string)($notification['tone'] ?? 'info'),
                'invite_token' => $token,
                'invite_status' => $this->liveInviteStatus($invite),
                'invite_is_owner' => is_array($invite)
                    && (string)($invite['inviter_id'] ?? '') === $userId,
                'inviter_name' => is_array($invite) ? (string)($invite['inviter_name'] ?? '') : '',
                'invitee_name' => is_array($invite) ? (string)($invite['invitee_name'] ?? '') : '',
                'game_title' => is_array($invite) ? (string)($invite['game_title'] ?? '') : '',
                'invite_snapshot' => $inviteSnapshot,
                'actions' => $this->liveInviteActions($invite, $userId),
                'created_at' => $type === 'invite_cancelled' && is_array($invite)
                    ? (string)($invite['cancelled_at'] ?? $invite['updated_at'] ?? $notification['created_at'] ?? '')
                    : (string)($notification['created_at'] ?? ''),
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

    private function liveInviteMessage(array $notification, ?array $invite, string $userId): string
    {
        $message = (string)($notification['message'] ?? '');
        if (!is_array($invite) || (string)($notification['type'] ?? '') !== 'invite_cancelled') {
            return $message;
        }

        $status = (string)($invite['status'] ?? '');
        if (!in_array($status, ['cancelled', 'canceled'], true)) return $message;

        $inviterId = (string)($invite['inviter_id'] ?? '');
        $inviteeId = (string)($invite['invitee_id'] ?? '');
        $cancelledBy = (string)($invite['cancelled_by'] ?? '');
        $inviterName = trim((string)($invite['inviter_name'] ?? 'Игрок')) ?: 'Игрок';
        $inviteeName = trim((string)($invite['invitee_name'] ?? 'Игрок')) ?: 'Игрок';
        $gameTitle = trim((string)($invite['game_title'] ?? 'Игра')) ?: 'Игра';
        $inviterCancelled = $cancelledBy !== ''
            ? $cancelledBy === $inviterId
            : $userId === $inviteeId;

        return $inviterCancelled
            ? $inviterName . ' отменил приглашение сыграть в «' . $gameTitle . '».'
            : $inviteeName . ' отменил участие в матче «' . $gameTitle . '».';
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
