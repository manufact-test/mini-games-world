<?php
declare(strict_types=1);

trait GameInviteCreationTrait
{
    public function createLinkDraft(
        array &$db,
        array &$user,
        string $gameType,
        string $room,
        int $bet,
        int $boardSize
    ): array {
        $this->cleanup($db);
        $userId = $this->requireUserId($user);
        $this->assertAvailableForInvite($db, $user, 'Сначала завершите текущий поиск, матч или приглашение.');
        $this->discardOwnerDrafts($db, $userId);

        $invite = $this->newInvite($db, $user, $gameType, $room, $bet, $boardSize, 'link', 'draft');
        $db['invites'][] = $invite;
        return $this->publicInvite($invite, $userId);
    }

    public function createDirect(
        array &$db,
        array &$user,
        array &$invitee,
        string $gameType,
        string $room,
        int $bet,
        int $boardSize
    ): array {
        $this->cleanup($db);
        $userId = $this->requireUserId($user);
        $inviteeId = $this->requireUserId($invitee);
        if ($inviteeId === $userId || str_starts_with($inviteeId, 'bot_')) {
            throw new RuntimeException('Выберите другого игрока.');
        }

        $this->assertAvailableForInvite($db, $user, 'Сначала завершите текущий поиск, матч или приглашение.');
        $this->assertAvailableForInvite($db, $invitee, 'Игрок сейчас занят поиском, матчем или другим приглашением.');

        $invite = $this->newInvite($db, $user, $gameType, $room, $bet, $boardSize, 'direct', 'pending');
        $invite['invitee_id'] = $inviteeId;
        $invite['invitee_name'] = $this->userName($invitee);
        $invite['shared_at'] = $invite['created_at'];
        $db['invites'][] = $invite;
        $this->addReceivedNotification($db, $invite);

        return $this->publicInvite($invite, $userId);
    }

    public function confirmShared(array &$db, array &$user, string $token): array
    {
        $this->cleanup($db);
        $index = $this->requireIndex($db, $token);
        $invite =& $db['invites'][$index];
        $userId = $this->requireUserId($user);
        if ((string)($invite['inviter_id'] ?? '') !== $userId) {
            throw new RuntimeException('Подтвердить отправку может только создатель приглашения.');
        }

        if ((string)($invite['status'] ?? '') === 'draft') {
            $now = now_iso();
            $invite['status'] = 'pending';
            $invite['shared_at'] = $now;
            $invite['updated_at'] = $now;
        }

        return $this->publicInvite($invite, $userId);
    }

    public function discardDraft(array &$db, array &$user, string $token): array
    {
        $this->cleanup($db);
        $index = $this->requireIndex($db, $token);
        $invite =& $db['invites'][$index];
        $userId = $this->requireUserId($user);
        if ((string)($invite['inviter_id'] ?? '') !== $userId) {
            throw new RuntimeException('Вы не создавали это приглашение.');
        }

        if ((string)($invite['status'] ?? '') === 'draft') {
            $now = now_iso();
            $invite['status'] = 'cancelled';
            $invite['cancelled_at'] = $now;
            $invite['cancelled_by'] = $userId;
            $invite['updated_at'] = $now;
        }

        return $this->publicInvite($invite, $userId);
    }

    public function bindFromLink(
        array &$db,
        array &$user,
        string $token,
        bool $requestOpen = true,
        bool $suppressReceivedNotification = false
    ): array
    {
        $this->cleanup($db);
        $index = $this->requireIndex($db, $token);
        $invite =& $db['invites'][$index];
        $userId = $this->requireUserId($user);
        $inviterId = (string)($invite['inviter_id'] ?? '');
        if ($userId === $inviterId) {
            throw new RuntimeException('Нельзя открыть собственное приглашение как соперник.');
        }

        $status = (string)($invite['status'] ?? '');
        if ($status === 'draft') {
            $invite['status'] = 'pending';
            $invite['shared_at'] = (string)($invite['shared_at'] ?? now_iso());
            $status = 'pending';
        }
        if ($status !== 'pending') {
            return $this->publicInvite($invite, $userId);
        }

        $boundId = trim((string)($invite['invitee_id'] ?? ''));
        if ($boundId !== '' && $boundId !== $userId) {
            throw new RuntimeException('Это приглашение уже предназначено другому игроку.');
        }
        if ($boundId === '') {
            $this->assertNoOpenInvite($db, $userId, (string)($invite['token'] ?? ''), 'Сначала завершите другое приглашение.');
        }

        $now = now_iso();
        $invite['invitee_id'] = $userId;
        $invite['invitee_name'] = $this->userName($user);
        $invite['opened_at'] = (string)($invite['opened_at'] ?? $now);
        if ($requestOpen) {
            $invite['open_requested_at'] = $now;
        }
        $invite['updated_at'] = $now;
        $this->addReceivedNotification($db, $invite);
        if ($suppressReceivedNotification) {
            $this->hideReceivedNotification($db, $userId, (string)($invite['token'] ?? ''));
        }

        return $this->publicInvite($invite, $userId);
    }

    public function resolve(array &$db, array $user, string $token): array
    {
        $this->cleanup($db);
        $index = $this->requireIndex($db, $token);
        $invite = $db['invites'][$index];
        $userId = $this->requireUserId($user);
        if (!$this->isParticipant($invite, $userId) && (string)($invite['status'] ?? '') !== 'draft') {
            throw new RuntimeException('Это приглашение предназначено другому игроку.');
        }
        return $this->publicInvite($invite, $userId);
    }

    public function sync(array &$db, array $user, string $trackedToken = ''): array
    {
        $this->cleanup($db);
        $userId = $this->requireUserId($user);
        $activeGame = $this->games->findActiveGameForUser($db, $userId);

        $trackedInvite = null;
        if ($trackedToken !== '') {
            $index = $this->findIndex($db, $trackedToken);
            if ($index !== null && $this->isParticipant($db['invites'][$index], $userId)) {
                $trackedInvite = $this->publicInvite($db['invites'][$index], $userId);
            }
        }

        return [
            'invite' => $this->activeForUser($db, $userId),
            'tracked_invite' => $trackedInvite,
            'active_game' => is_array($activeGame) ? $this->games->publicGame($activeGame, $userId) : null,
            'invite_events' => $this->inviteEventsForUser($db, $userId),
            'unread_count' => $this->unreadNotificationCount($db, $userId),
            'server_time' => now_iso(),
        ];
    }
}
