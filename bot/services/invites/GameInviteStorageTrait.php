<?php
declare(strict_types=1);

trait GameInviteStorageTrait
{
    private function newInvite(
        array &$db,
        array $user,
        string $gameType,
        string $room,
        int $bet,
        int $boardSize,
        string $source,
        string $status
    ): array {
        if (!isset($db['invites']) || !is_array($db['invites'])) $db['invites'] = [];
        $gameType = $this->catalog->normalizeGameType($gameType);
        $room = $room === 'gold' ? 'gold' : 'match';
        if (!$this->catalog->supportsRoom($gameType, $room)) {
            throw new RuntimeException('Эта игра недоступна в выбранной комнате.');
        }
        $boardSize = $this->catalog->normalizeBoardSize($gameType, $boardSize);
        $bet = $this->normalizeBet($room, $bet);
        $balanceKey = $room === 'gold' ? 'balance_gold' : 'balance_match';
        if ((int)($user[$balanceKey] ?? 0) < $bet) {
            throw new RuntimeException('Недостаточно коинов для выбранной ставки.');
        }

        $definition = $this->catalog->publicGameDefinition($gameType);
        [$columns, $rows] = $this->dimensions($gameType, $boardSize);
        $now = now_iso();
        return [
            'id' => make_id('invite'),
            'token' => $this->uniqueToken($db['invites']),
            'status' => $status,
            'source' => $source,
            'inviter_id' => (string)($user['id'] ?? ''),
            'inviter_name' => $this->userName($user),
            'invitee_id' => null,
            'invitee_name' => null,
            'game_type' => $gameType,
            'game_title' => (string)($definition['title'] ?? $gameType),
            'room' => $room,
            'bet' => $bet,
            'board_size' => $boardSize,
            'board_columns' => $columns,
            'board_rows' => $rows,
            'created_at' => $now,
            'updated_at' => $now,
            'expires_at' => gmdate('c', time() + self::INVITE_TTL_SEC),
            'shared_at' => null,
            'opened_at' => null,
            'open_requested_at' => null,
            'accepted_at' => null,
            'ready_deadline_at' => null,
            'start_deadline_at' => null,
            'started_at' => null,
            'declined_at' => null,
            'cancelled_at' => null,
            'cancelled_by' => null,
            'source_game_id' => null,
            'game_id' => null,
        ];
    }

    private function createIsolatedGame(array &$db, array &$inviter, array &$invitee, array $invite): array
    {
        $inviterId = (string)($inviter['id'] ?? '');
        $inviteeId = (string)($invitee['id'] ?? '');
        $originalQueue = array_values(is_array($db['queue'] ?? null) ? $db['queue'] : []);
        $db['queue'] = [];

        try {
            $room = (string)($invite['room'] ?? 'match');
            $bet = (int)($invite['bet'] ?? 10);
            $boardSize = (int)($invite['board_size'] ?? 3);
            $gameType = (string)($invite['game_type'] ?? 'tictactoe');

            $first = $this->games->startSearch($db, $inviter, $room, $bet, $boardSize, $gameType);
            if (!empty($first['game'])) throw new RuntimeException('Пригласивший игрок уже начал другой матч.');
            $second = $this->games->startSearch($db, $invitee, $room, $bet, $boardSize, $gameType);
            $gameId = (string)($second['game']['id'] ?? '');
            if ($gameId === '' || !isset($db['games'][$gameId]) || !is_array($db['games'][$gameId])) {
                throw new RuntimeException('Не удалось создать приватный матч.');
            }

            $db['games'][$gameId]['match_source'] = (string)($invite['source'] ?? '') === 'rematch' ? 'rematch' : 'invite';
            $db['games'][$gameId]['invite_id'] = (string)($invite['id'] ?? '');
            $db['games'][$gameId]['invite_token'] = (string)($invite['token'] ?? '');
            $db['games'][$gameId]['source_game_id'] = (string)($invite['source_game_id'] ?? '');
            $this->randomizeTicTacToe($db['games'][$gameId]);
            return $db['games'][$gameId];
        } finally {
            $db['queue'] = array_values(array_filter($originalQueue, static function ($item) use ($inviterId, $inviteeId): bool {
                if (!is_array($item)) return false;
                $queuedId = (string)($item['user_id'] ?? '');
                return $queuedId !== $inviterId && $queuedId !== $inviteeId;
            }));
        }
    }

    private function randomizeTicTacToe(array &$game): void
    {
        if ((string)($game['game_type'] ?? '') !== 'tictactoe') return;
        $playerIds = array_values(array_map('strval', $game['player_ids'] ?? []));
        if (count($playerIds) < 2) return;
        if (random_int(0, 1) === 1) [$playerIds[0], $playerIds[1]] = [$playerIds[1], $playerIds[0]];
        $now = now_iso();
        $game['symbols'] = [$playerIds[0] => 'X', $playerIds[1] => 'O'];
        $game['turn'] = $playerIds[0];
        $game['turn_started_at'] = $now;
        $game['last_move_at'] = $now;
        $game['updated_at'] = $now;
        $game['symbols_randomized'] = true;
    }

    private function addReceivedNotification(array &$db, array $invite): void
    {
        $inviteeId = (string)($invite['invitee_id'] ?? '');
        if ($inviteeId === '') return;
        $isRematch = (string)($invite['source'] ?? '') === 'rematch';
        $this->addNotification(
            $db,
            $inviteeId,
            'invite:' . (string)($invite['id'] ?? $invite['token'] ?? '') . ':received:' . $inviteeId,
            $isRematch ? 'invite_rematch_received' : 'invite_received',
            $isRematch ? 'Вам предлагают реванш' : 'Вас пригласили сыграть',
            (string)($invite['inviter_name'] ?? 'Игрок')
                . ($isRematch ? ' предлагает реванш в «' : ' приглашает вас в «')
                . (string)($invite['game_title'] ?? 'игру') . '».',
            'info',
            (string)($invite['token'] ?? '')
        );
    }

    private function addNotification(
        array &$db,
        string $userId,
        string $eventKey,
        string $type,
        string $title,
        string $message,
        string $tone,
        string $token
    ): void {
        if ($userId === '') return;
        if (!isset($db['notifications']) || !is_array($db['notifications'])) $db['notifications'] = [];
        foreach ($db['notifications'] as $existing) {
            if (!is_array($existing)) continue;
            if ((string)($existing['event_key'] ?? '') === $eventKey
                && (string)($existing['user_id'] ?? '') === $userId) return;
        }
        $db['notifications'][] = [
            'id' => make_id('notification'),
            'event_key' => $eventKey,
            'user_id' => $userId,
            'type' => $type,
            'title' => $title,
            'message' => $message,
            'tone' => $tone,
            'invite_token' => $token,
            'created_at' => now_iso(),
            'read_at' => null,
        ];
    }

    private function hideReceivedNotification(array &$db, string $userId, string $token): void
    {
        if ($userId === '' || $token === '') return;
        if (!isset($db['notifications']) || !is_array($db['notifications'])) return;
        $now = now_iso();
        foreach ($db['notifications'] as &$notification) {
            if (!is_array($notification)) continue;
            if ((string)($notification['user_id'] ?? '') !== $userId) continue;
            if ((string)($notification['invite_token'] ?? '') !== $token) continue;
            if (!in_array((string)($notification['type'] ?? ''), ['invite_received', 'invite_rematch_received'], true)) continue;
            $notification['hidden_at'] = $now;
            if (empty($notification['read_at'])) $notification['read_at'] = $now;
        }
        unset($notification);
    }

    private function unreadNotificationCount(array $db, string $userId): int
    {
        $invites = $this->invitesByToken($db);
        $count = 0;
        foreach ($db['notifications'] ?? [] as $notification) {
            if (!is_array($notification)) continue;
            if ((string)($notification['user_id'] ?? '') !== $userId) continue;
            if (!empty($notification['hidden_at']) || !empty($notification['read_at'])) continue;
            if (!$this->inviteNotificationVisible($notification, $invites)) continue;
            $count++;
        }
        return $count;
    }

    private function invitesByToken(array $db): array
    {
        $result = [];
        foreach ($db['invites'] ?? [] as $invite) {
            if (!is_array($invite)) continue;
            $token = (string)($invite['token'] ?? '');
            if ($token !== '') $result[$token] = $invite;
        }
        return $result;
    }

    private function inviteNotificationVisible(array $notification, array $invitesByToken): bool
    {
        $type = (string)($notification['type'] ?? '');
        if (!str_starts_with($type, 'invite_')) return true;
        $token = (string)($notification['invite_token'] ?? '');
        $invite = $token !== '' ? ($invitesByToken[$token] ?? null) : null;
        if (!is_array($invite)) return true;

        $status = (string)($invite['status'] ?? '');
        if (in_array($type, ['invite_received', 'invite_rematch_received'], true)) return $status === 'pending';
        if ($type === 'invite_accepted') return $status === 'awaiting_start';
        if ($type === 'invite_started') return $status === 'active';
        return true;
    }

    private function expireIfDue(array &$db, array &$invite, int $now): void
    {
        $status = (string)($invite['status'] ?? '');
        if (in_array($status, ['draft', 'pending'], true)) {
            $expiresAt = strtotime((string)($invite['expires_at'] ?? '')) ?: 0;
            if ($expiresAt > 0 && $expiresAt <= $now) {
                $invite['status'] = 'expired';
                $invite['updated_at'] = now_iso();
                if ($status === 'pending') {
                    foreach ([(string)($invite['inviter_id'] ?? ''), (string)($invite['invitee_id'] ?? '')] as $userId) {
                        if ($userId === '') continue;
                        $this->addNotification(
                            $db,
                            $userId,
                            'invite:' . (string)($invite['id'] ?? '') . ':expired:' . $userId,
                            'invite_expired',
                            'Срок приглашения истёк',
                            'Матч «' . (string)($invite['game_title'] ?? 'Игра') . '» не начался.',
                            'warning',
                            (string)($invite['token'] ?? '')
                        );
                    }
                }
            }
            return;
        }

        if ($status !== 'awaiting_start') return;
        $deadline = strtotime((string)($invite['ready_deadline_at'] ?? '')) ?: 0;
        if ($deadline <= 0 || $deadline > $now) return;
        $invite['status'] = 'timed_out';
        $invite['updated_at'] = now_iso();
        foreach ([(string)($invite['inviter_id'] ?? ''), (string)($invite['invitee_id'] ?? '')] as $userId) {
            if ($userId === '') continue;
            $this->addNotification(
                $db,
                $userId,
                'invite:' . (string)($invite['id'] ?? '') . ':timed_out:' . $userId,
                'invite_timed_out',
                'Время ожидания истекло',
                'Матч «' . (string)($invite['game_title'] ?? 'Игра') . '» не был запущен.',
                'warning',
                (string)($invite['token'] ?? '')
            );
        }
    }

    private function normalizeLegacy(array &$invite): void
    {
        $status = (string)($invite['status'] ?? 'pending');
        $gameId = (string)($invite['game_id'] ?? '');
        if ($status === 'accepted' && $gameId === '') {
            $invite['status'] = 'awaiting_start';
            $invite['ready_deadline_at'] = (string)($invite['ready_deadline_at'] ?? $invite['start_deadline_at'] ?? '');
        } elseif ($status === 'started' || ($status === 'accepted' && $gameId !== '')) {
            $invite['status'] = 'active';
        } elseif ($status === 'starting') {
            $invite['status'] = $gameId !== '' ? 'active' : 'awaiting_start';
        }
        if (empty($invite['source'])) {
            $invite['source'] = (string)($invite['source_game_id'] ?? '') !== ''
                ? 'rematch'
                : ((string)($invite['invitee_id'] ?? '') !== '' ? 'direct' : 'link');
        }
        if (!array_key_exists('ready_deadline_at', $invite)) {
            $invite['ready_deadline_at'] = (string)($invite['start_deadline_at'] ?? '');
        }
    }
}
