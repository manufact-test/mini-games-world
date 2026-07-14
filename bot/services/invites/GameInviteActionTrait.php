<?php
declare(strict_types=1);

trait GameInviteActionTrait
{
    public function accept(array &$db, array &$user, string $token): array
    {
        $this->cleanup($db);
        $index = $this->requireIndex($db, $token);
        $invite =& $db['invites'][$index];
        $userId = $this->requireUserId($user);
        if ((string)($invite['invitee_id'] ?? '') !== $userId) {
            throw new RuntimeException('Это приглашение предназначено другому игроку.');
        }

        $status = (string)($invite['status'] ?? '');
        if ($status === 'active') {
            return $this->resultWithGame($db, $invite, $userId);
        }
        if ($status === 'awaiting_start') {
            if ((string)($invite['source'] ?? '') === 'rematch') {
                return $this->startInternal($db, $invite, $userId);
            }
            return ['invite' => $this->publicInvite($invite, $userId), 'game' => null];
        }
        if ($status !== 'pending') {
            throw new RuntimeException('Это приглашение уже ' . mb_strtolower($this->statusLabel($status)) . '.');
        }

        $inviterId = (string)($invite['inviter_id'] ?? '');
        if ($inviterId === '' || !isset($db['users'][$inviterId]) || !is_array($db['users'][$inviterId])) {
            throw new RuntimeException('Пригласивший игрок больше недоступен.');
        }

        $inviter =& $db['users'][$inviterId];
        $invitee =& $db['users'][$userId];
        $this->assertAvailableForStart($db, $invitee, $token, 'Сначала завершите текущий поиск или игру.');
        $this->assertAvailableForStart($db, $inviter, $token, 'Пригласивший игрок сейчас занят в другой игре.');
        $this->assertBalances($inviter, $invitee, $invite);

        $now = now_iso();
        $invite['status'] = 'awaiting_start';
        $invite['accepted_at'] = $now;
        $invite['ready_deadline_at'] = gmdate('c', time() + self::READY_TTL_SEC);
        $invite['start_deadline_at'] = $invite['ready_deadline_at'];
        $invite['updated_at'] = $now;

        $this->addNotification(
            $db,
            $inviterId,
            'invite:' . (string)($invite['id'] ?? $token) . ':accepted',
            'invite_accepted',
            'Соперник согласен',
            (string)($invite['invitee_name'] ?? 'Игрок') . ' готов сыграть в «' . (string)($invite['game_title'] ?? 'игру') . '».',
            'success',
            (string)($invite['token'] ?? '')
        );

        if ((string)($invite['source'] ?? '') === 'rematch') {
            return $this->startInternal($db, $invite, $userId);
        }

        return ['invite' => $this->publicInvite($invite, $userId), 'game' => null];
    }

    public function start(array &$db, array &$user, string $token): array
    {
        $this->cleanup($db);
        $index = $this->requireIndex($db, $token);
        $invite =& $db['invites'][$index];
        $userId = $this->requireUserId($user);
        if ((string)($invite['inviter_id'] ?? '') !== $userId) {
            throw new RuntimeException('Запустить матч может только пригласивший игрок.');
        }
        return $this->startInternal($db, $invite, $userId);
    }

    public function decline(array &$db, array &$user, string $token): array
    {
        $this->cleanup($db);
        $index = $this->requireIndex($db, $token);
        $invite =& $db['invites'][$index];
        $userId = $this->requireUserId($user);
        if ((string)($invite['invitee_id'] ?? '') !== $userId) {
            throw new RuntimeException('Это приглашение предназначено другому игроку.');
        }
        if ((string)($invite['status'] ?? '') !== 'pending') {
            return $this->publicInvite($invite, $userId);
        }

        $now = now_iso();
        $invite['status'] = 'declined';
        $invite['declined_at'] = $now;
        $invite['updated_at'] = $now;
        $this->addNotification(
            $db,
            (string)($invite['inviter_id'] ?? ''),
            'invite:' . (string)($invite['id'] ?? $token) . ':declined',
            'invite_declined',
            'Приглашение отклонено',
            (string)($invite['invitee_name'] ?? 'Игрок') . ' отказался от матча «' . (string)($invite['game_title'] ?? 'Игра') . '».',
            'warning',
            (string)($invite['token'] ?? '')
        );
        return $this->publicInvite($invite, $userId);
    }

    public function cancel(array &$db, array &$user, string $token): array
    {
        $this->cleanup($db);
        $index = $this->requireIndex($db, $token);
        $invite =& $db['invites'][$index];
        $userId = $this->requireUserId($user);
        $isOwner = (string)($invite['inviter_id'] ?? '') === $userId;
        $isInvitee = (string)($invite['invitee_id'] ?? '') === $userId;
        if (!$isOwner && !$isInvitee) {
            throw new RuntimeException('Вы не участвуете в этом приглашении.');
        }

        $status = (string)($invite['status'] ?? '');
        if (!in_array($status, ['draft', 'pending', 'awaiting_start'], true)) {
            return $this->publicInvite($invite, $userId);
        }
        if ($status === 'pending' && $isInvitee) {
            throw new RuntimeException('Используйте кнопку «Отклонить».');
        }

        $now = now_iso();
        $invite['status'] = 'cancelled';
        $invite['cancelled_at'] = $now;
        $invite['cancelled_by'] = $userId;
        $invite['updated_at'] = $now;

        $otherId = $isOwner ? (string)($invite['invitee_id'] ?? '') : (string)($invite['inviter_id'] ?? '');
        if ($otherId !== '') {
            $title = $isOwner ? 'Приглашение отменено' : 'Соперник отменил участие';
            $message = $isOwner
                ? 'Матч «' . (string)($invite['game_title'] ?? 'Игра') . '» не начался.'
                : (string)($invite['invitee_name'] ?? 'Игрок') . ' отменил участие в матче.';
            $this->addNotification(
                $db,
                $otherId,
                'invite:' . (string)($invite['id'] ?? $token) . ':cancelled:' . $userId,
                'invite_cancelled',
                $title,
                $message,
                'warning',
                (string)($invite['token'] ?? '')
            );
        }

        return $this->publicInvite($invite, $userId);
    }

    public function createRematch(array &$db, array &$user, string $gameId): array
    {
        $this->cleanup($db);
        $userId = $this->requireUserId($user);
        $game = $db['games'][$gameId] ?? null;
        if (!is_array($game) || (string)($game['status'] ?? '') !== 'finished') {
            throw new RuntimeException('Реванш доступен только после завершённой партии.');
        }
        if (!empty($game['is_bot_game'])) {
            throw new RuntimeException('Реванш доступен только с живым соперником.');
        }

        $playerIds = array_values(array_map('strval', $game['player_ids'] ?? []));
        if (count($playerIds) !== 2 || !in_array($userId, $playerIds, true)) {
            throw new RuntimeException('Вы не участвуете в этом матче.');
        }
        $opponentId = $playerIds[0] === $userId ? $playerIds[1] : $playerIds[0];
        if ($opponentId === '' || !isset($db['users'][$opponentId]) || !is_array($db['users'][$opponentId])) {
            throw new RuntimeException('Соперник для реванша недоступен.');
        }

        $existingIndex = $this->findOpenRematchIndex($db, $gameId, $playerIds);
        if ($existingIndex !== null) {
            $existing =& $db['invites'][$existingIndex];
            $status = (string)($existing['status'] ?? '');
            if ($status === 'active') {
                return $this->resultWithGame($db, $existing, $userId) + ['reused' => true];
            }
            if ($status === 'awaiting_start') {
                return $this->startInternal($db, $existing, $userId) + ['reused' => true];
            }
            if ($status === 'pending') {
                if ((string)($existing['inviter_id'] ?? '') === $userId) {
                    return ['invite' => $this->publicInvite($existing, $userId), 'game' => null, 'reused' => true];
                }
                if ((string)($existing['invitee_id'] ?? '') === $userId) {
                    $existingToken = (string)($existing['token'] ?? '');
                    unset($existing);
                    $acceptResult = $this->accept($db, $user, $existingToken);
                    return $acceptResult + ['reused' => true];
                }
            }
        }

        $this->assertAvailableForInvite($db, $user, 'Сначала завершите текущий поиск, матч или приглашение.');
        $opponent =& $db['users'][$opponentId];
        $this->assertAvailableForInvite($db, $opponent, 'Соперник сейчас занят поиском, матчем или другим приглашением.');

        $gameType = $this->catalog->normalizeGameType((string)($game['game_type'] ?? 'tictactoe'));
        $room = (string)($game['room'] ?? 'match') === 'gold' ? 'gold' : 'match';
        $bet = (int)($game['bet'] ?? ($room === 'match' ? 10 : 0));
        $boardSize = (int)($game['board_size'] ?? 3);

        $invite = $this->newInvite($db, $user, $gameType, $room, $bet, $boardSize, 'rematch', 'pending');
        $invite['invitee_id'] = $opponentId;
        $invite['invitee_name'] = $this->userName($opponent);
        $invite['source_game_id'] = $gameId;
        $invite['shared_at'] = $invite['created_at'];
        $db['invites'][] = $invite;
        $this->addReceivedNotification($db, $invite);

        return [
            'invite' => $this->publicInvite($invite, $userId),
            'game' => null,
            'opponent_id' => $opponentId,
            'opponent_name' => $this->userName($opponent),
            'reused' => false,
        ];
    }

    public function markSeen(array &$db, string $userId, string $token): void
    {
        if ($userId === '' || $token === '') return;
        if (!isset($db['notifications']) || !is_array($db['notifications'])) return;
        $now = now_iso();
        foreach ($db['notifications'] as &$notification) {
            if (!is_array($notification)) continue;
            if ((string)($notification['user_id'] ?? '') !== $userId) continue;
            if ((string)($notification['invite_token'] ?? '') !== $token) continue;
            if (empty($notification['read_at'])) $notification['read_at'] = $now;
        }
        unset($notification);
    }

    public function cleanup(array &$db): void
    {
        if (!isset($db['invites']) || !is_array($db['invites'])) $db['invites'] = [];
        $now = time();
        foreach ($db['invites'] as &$invite) {
            if (!is_array($invite)) continue;
            $this->normalizeLegacy($invite);
            $this->expireIfDue($db, $invite, $now);
        }
        unset($invite);

        $db['invites'] = array_values(array_filter($db['invites'], static function ($invite) use ($now): bool {
            if (!is_array($invite)) return false;
            $activity = strtotime((string)($invite['updated_at'] ?? $invite['created_at'] ?? '')) ?: $now;
            return $now - $activity <= self::RETENTION_SEC;
        }));
    }

    private function startInternal(array &$db, array &$invite, string $viewerId): array
    {
        $status = (string)($invite['status'] ?? '');
        if ($status === 'active') return $this->resultWithGame($db, $invite, $viewerId);
        if ($status !== 'awaiting_start') {
            throw new RuntimeException('Соперник ещё не подтвердил приглашение.');
        }

        $inviterId = (string)($invite['inviter_id'] ?? '');
        $inviteeId = (string)($invite['invitee_id'] ?? '');
        if ($inviterId === '' || $inviteeId === ''
            || !isset($db['users'][$inviterId]) || !is_array($db['users'][$inviterId])
            || !isset($db['users'][$inviteeId]) || !is_array($db['users'][$inviteeId])) {
            throw new RuntimeException('Один из игроков больше недоступен.');
        }

        $inviter =& $db['users'][$inviterId];
        $invitee =& $db['users'][$inviteeId];
        $token = (string)($invite['token'] ?? '');
        $this->assertAvailableForStart($db, $inviter, $token, 'Пригласивший игрок сейчас занят в другой игре.');
        $this->assertAvailableForStart($db, $invitee, $token, 'Приглашённый игрок сейчас занят в другой игре.');
        $this->assertBalances($inviter, $invitee, $invite);

        $invite['status'] = 'starting';
        $invite['updated_at'] = now_iso();
        try {
            $game = $this->createIsolatedGame($db, $inviter, $invitee, $invite);
        } catch (Throwable $e) {
            $invite['status'] = 'awaiting_start';
            $invite['updated_at'] = now_iso();
            throw $e;
        }

        $gameId = (string)($game['id'] ?? '');
        if ($gameId === '') {
            $invite['status'] = 'awaiting_start';
            $invite['updated_at'] = now_iso();
            throw new RuntimeException('Не удалось создать приватный матч.');
        }

        $now = now_iso();
        $invite['status'] = 'active';
        $invite['game_id'] = $gameId;
        $invite['started_at'] = $now;
        $invite['updated_at'] = $now;
        $this->markSeen($db, $inviterId, $token);
        $this->markSeen($db, $inviteeId, $token);

        return [
            'invite' => $this->publicInvite($invite, $viewerId),
            'game' => $this->games->publicGame($db['games'][$gameId], $viewerId),
        ];
    }

    private function resultWithGame(array $db, array $invite, string $viewerId): array
    {
        $gameId = (string)($invite['game_id'] ?? '');
        $game = $gameId !== '' && isset($db['games'][$gameId]) && is_array($db['games'][$gameId])
            ? $db['games'][$gameId]
            : null;
        return [
            'invite' => $this->publicInvite($invite, $viewerId),
            'game' => is_array($game) && (string)($game['status'] ?? '') === 'active'
                ? $this->games->publicGame($game, $viewerId)
                : null,
        ];
    }

    private function activeForUser(array $db, string $userId): ?array
    {
        $candidates = [];
        foreach ($db['invites'] ?? [] as $invite) {
            if (!is_array($invite) || !$this->isParticipant($invite, $userId)) continue;
            $status = (string)($invite['status'] ?? '');
            if (!in_array($status, ['pending', 'awaiting_start'], true)) continue;
            $isOwner = (string)($invite['inviter_id'] ?? '') === $userId;
            $priority = $status === 'awaiting_start' ? 300 : ($isOwner ? 100 : 200);
            $candidates[] = [
                'priority' => $priority,
                'updated' => strtotime((string)($invite['updated_at'] ?? $invite['created_at'] ?? '')) ?: 0,
                'invite' => $invite,
            ];
        }
        if (!$candidates) return null;
        usort($candidates, static function (array $left, array $right): int {
            $priority = $right['priority'] <=> $left['priority'];
            return $priority !== 0 ? $priority : ($right['updated'] <=> $left['updated']);
        });
        return $this->publicInvite($candidates[0]['invite'], $userId);
    }

    private function inviteEventsForUser(array $db, string $userId): array
    {
        $events = [];
        foreach ($db['notifications'] ?? [] as $notification) {
            if (!is_array($notification)) continue;
            if ((string)($notification['user_id'] ?? '') !== $userId) continue;
            if (!empty($notification['hidden_at'])) continue;
            if (!str_starts_with((string)($notification['type'] ?? ''), 'invite_')) continue;
            $events[] = [
                'id' => (string)($notification['id'] ?? ''),
                'type' => (string)($notification['type'] ?? ''),
                'title' => (string)($notification['title'] ?? ''),
                'message' => (string)($notification['message'] ?? ''),
                'tone' => (string)($notification['tone'] ?? 'info'),
                'invite_token' => (string)($notification['invite_token'] ?? ''),
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
}
