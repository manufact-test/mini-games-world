<?php
declare(strict_types=1);

final class GameInviteInboxService
{
    public function registerRecipient(array &$db, array $user, string $token): ?array
    {
        $index = $this->findIndex($db, $token);
        if ($index === null) {
            return null;
        }

        $invite =& $db['invites'][$index];
        $this->expirePendingIfDue($invite);

        if ((string)($invite['status'] ?? '') !== 'pending') {
            return null;
        }

        $userId = trim((string)($user['id'] ?? ''));
        $inviterId = trim((string)($invite['inviter_id'] ?? ''));
        if ($userId === '' || $userId === $inviterId) {
            return null;
        }

        $boundInviteeId = trim((string)($invite['invitee_id'] ?? ''));
        if ($boundInviteeId !== '' && $boundInviteeId !== $userId) {
            return null;
        }

        $now = now_iso();
        $invite['invitee_id'] = $userId;
        $invite['invitee_name'] = $this->userName($user);
        $invite['opened_at'] = (string)($invite['opened_at'] ?? $now);
        $invite['updated_at'] = $now;

        $this->addReceivedNotification($db, $invite, $userId);
        return $this->publicInvite($invite, $userId);
    }

    public function actionableForUser(array &$db, array $user): ?array
    {
        $userId = trim((string)($user['id'] ?? ''));
        if ($userId === '') {
            return null;
        }

        if (!isset($db['invites']) || !is_array($db['invites'])) {
            $db['invites'] = [];
            return null;
        }

        $now = time();
        foreach ($db['invites'] as &$invite) {
            if (!is_array($invite)) {
                continue;
            }
            $this->expirePendingIfDue($invite, $now);
        }
        unset($invite);

        foreach (array_reverse($db['invites']) as $invite) {
            if (!is_array($invite)) {
                continue;
            }

            $status = (string)($invite['status'] ?? 'pending');
            $isOwner = (string)($invite['inviter_id'] ?? '') === $userId;
            $isInvitee = (string)($invite['invitee_id'] ?? '') === $userId;

            if ($status === 'pending' && $isInvitee) {
                return $this->publicInvite($invite, $userId);
            }

            if ($status === 'awaiting_start' && ($isOwner || $isInvitee)) {
                return $this->publicInvite($invite, $userId);
            }
        }

        return null;
    }

    private function addReceivedNotification(array &$db, array $invite, string $userId): void
    {
        if (!isset($db['notifications']) || !is_array($db['notifications'])) {
            $db['notifications'] = [];
        }

        $inviteId = (string)($invite['id'] ?? $invite['token'] ?? '');
        $eventKey = 'invite:' . $inviteId . ':received:' . $userId;
        foreach ($db['notifications'] as $notification) {
            if (!is_array($notification)) {
                continue;
            }
            if ((string)($notification['event_key'] ?? '') === $eventKey
                && (string)($notification['user_id'] ?? '') === $userId) {
                return;
            }
        }

        $inviter = (string)($invite['inviter_name'] ?? 'Игрок');
        $game = (string)($invite['game_title'] ?? 'игру');
        $db['notifications'][] = [
            'id' => make_id('notification'),
            'event_key' => $eventKey,
            'user_id' => $userId,
            'type' => 'invite_received',
            'title' => 'Вас пригласили сыграть',
            'message' => $inviter . ' приглашает вас в «' . $game . '». Откройте уведомление, чтобы принять приглашение.',
            'tone' => 'info',
            'invite_token' => (string)($invite['token'] ?? ''),
            'created_at' => now_iso(),
            'read_at' => null,
        ];
    }

    private function publicInvite(array $invite, string $viewerId): array
    {
        $status = (string)($invite['status'] ?? 'pending');
        $inviterId = (string)($invite['inviter_id'] ?? '');
        $inviteeId = (string)($invite['invitee_id'] ?? '');
        $isOwner = $viewerId !== '' && $viewerId === $inviterId;
        $isInvitee = $viewerId !== '' && $viewerId === $inviteeId;
        $deadline = (string)($invite['start_deadline_at'] ?? '');
        $deadlineTs = strtotime($deadline) ?: 0;

        return [
            'token' => (string)($invite['token'] ?? ''),
            'status' => $status,
            'status_label' => $this->statusLabel($status),
            'is_owner' => $isOwner,
            'is_invitee' => $isInvitee,
            'can_accept' => $isInvitee && $status === 'pending',
            'can_decline' => $isInvitee && $status === 'pending',
            'can_start' => $isOwner && $status === 'awaiting_start',
            'can_cancel' => ($isOwner || $isInvitee) && $status === 'awaiting_start',
            'inviter_name' => (string)($invite['inviter_name'] ?? 'Игрок'),
            'invitee_name' => (string)($invite['invitee_name'] ?? ''),
            'game_type' => (string)($invite['game_type'] ?? 'tictactoe'),
            'game_title' => (string)($invite['game_title'] ?? 'Игра'),
            'room' => (string)($invite['room'] ?? 'match'),
            'room_label' => (string)($invite['room'] ?? 'match') === 'gold' ? 'Gold-комната' : 'Матч-комната',
            'bet' => (int)($invite['bet'] ?? 0),
            'board_size' => (int)($invite['board_size'] ?? 0),
            'board_columns' => (int)($invite['board_columns'] ?? 0),
            'board_rows' => (int)($invite['board_rows'] ?? 0),
            'expires_at' => (string)($invite['expires_at'] ?? ''),
            'start_deadline_at' => $deadline,
            'waiting_seconds' => $deadlineTs > 0 ? max(0, $deadlineTs - time()) : 0,
        ];
    }

    private function expirePendingIfDue(array &$invite, ?int $now = null): void
    {
        if ((string)($invite['status'] ?? '') !== 'pending') {
            return;
        }

        $expiresAt = strtotime((string)($invite['expires_at'] ?? '')) ?: 0;
        $now ??= time();
        if ($expiresAt > 0 && $expiresAt <= $now) {
            $invite['status'] = 'expired';
            $invite['updated_at'] = now_iso();
        }
    }

    private function findIndex(array $db, string $token): ?int
    {
        $token = strtolower(trim($token));
        if (!preg_match('/^[a-f0-9]{24}$/', $token)) {
            return null;
        }

        foreach ($db['invites'] ?? [] as $index => $invite) {
            if (is_array($invite) && hash_equals((string)($invite['token'] ?? ''), $token)) {
                return (int)$index;
            }
        }

        return null;
    }

    private function userName(array $user): string
    {
        $username = trim((string)($user['username'] ?? ''));
        if ($username !== '') {
            return '@' . ltrim($username, '@');
        }

        $name = trim((string)($user['first_name'] ?? '') . ' ' . (string)($user['last_name'] ?? ''));
        return $name !== '' ? $name : 'Игрок';
    }

    private function statusLabel(string $status): string
    {
        return match ($status) {
            'pending' => 'Ожидает ответа',
            'awaiting_start' => 'Ожидает запуска',
            'started', 'accepted' => 'Матч начат',
            'declined' => 'Отклонено',
            'expired' => 'Срок истёк',
            'timed_out' => 'Время ожидания истекло',
            'cancelled' => 'Отменено',
            default => 'Недоступно',
        };
    }
}
