<?php
declare(strict_types=1);

final class GameInviteFlowService
{
    private const START_CONFIRM_TTL_SEC = 90;

    public function __construct(
        private array $config,
        private GameCatalogService $catalog,
        private ChessRuntimeService $games
    ) {}

    public function resolve(array &$db, array $user, string $token): array
    {
        $this->cleanup($db);
        $index = $this->findIndex($db, $token);
        if ($index === null) {
            throw new RuntimeException('Приглашение не найдено или уже недоступно.');
        }

        return $this->publicInvite($db['invites'][$index], (string)($user['id'] ?? ''));
    }

    public function activeForUser(array &$db, array $user): ?array
    {
        $this->cleanup($db);
        $userId = trim((string)($user['id'] ?? ''));
        if ($userId === '') return null;

        foreach (array_reverse($db['invites'] ?? []) as $invite) {
            if (!is_array($invite)) continue;
            $status = (string)($invite['status'] ?? 'pending');
            $isOwner = (string)($invite['inviter_id'] ?? '') === $userId;
            $isInvitee = (string)($invite['invitee_id'] ?? '') === $userId;

            if ($status === 'pending' && $isOwner) {
                return $this->publicInvite($invite, $userId);
            }
            if ($status === 'awaiting_start' && ($isOwner || $isInvitee)) {
                return $this->publicInvite($invite, $userId);
            }
        }

        return null;
    }

    public function accept(array &$db, array &$user, string $token): array
    {
        $this->cleanup($db);
        $index = $this->requireIndex($db, $token);
        $invite =& $db['invites'][$index];
        $userId = trim((string)($user['id'] ?? ''));
        if ($userId === '') throw new RuntimeException('Пользователь не найден.');

        $inviterId = (string)($invite['inviter_id'] ?? '');
        if ($inviterId === $userId) {
            throw new RuntimeException('Нельзя принять собственное приглашение.');
        }

        $status = (string)($invite['status'] ?? 'pending');
        if ($status === 'awaiting_start' && (string)($invite['invitee_id'] ?? '') === $userId) {
            return $this->publicInvite($invite, $userId);
        }
        if ($status !== 'pending') {
            throw new RuntimeException('Это приглашение уже ' . mb_strtolower($this->statusLabel($status)) . '.');
        }

        if ($inviterId === '' || !isset($db['users'][$inviterId]) || !is_array($db['users'][$inviterId])) {
            throw new RuntimeException('Пригласивший игрок больше недоступен.');
        }

        $inviter =& $db['users'][$inviterId];
        $invitee =& $db['users'][$userId];
        $this->assertAvailable($db, $invitee, 'Сначала завершите текущий поиск или игру.');
        $this->assertAvailable($db, $inviter, 'Пригласивший игрок сейчас занят в другой игре.');
        $this->assertBalances($inviter, $invitee, $invite);

        $now = now_iso();
        $invite['status'] = 'awaiting_start';
        $invite['invitee_id'] = $userId;
        $invite['invitee_name'] = $this->userName($invitee);
        $invite['accepted_at'] = $now;
        $invite['start_deadline_at'] = gmdate('c', time() + self::START_CONFIRM_TTL_SEC);
        $invite['updated_at'] = $now;

        $this->addNotification(
            $db,
            $inviterId,
            'invite:' . (string)($invite['id'] ?? $token) . ':accepted',
            'invite_accepted',
            'Соперник согласен',
            $invite['invitee_name'] . ' готов сыграть в «' . (string)($invite['game_title'] ?? 'игру') . '». Запустите матч в течение 90 секунд.',
            'success',
            (string)($invite['token'] ?? '')
        );

        return $this->publicInvite($invite, $userId);
    }

    public function start(array &$db, array &$user, string $token): array
    {
        $this->cleanup($db);
        $index = $this->requireIndex($db, $token);
        $invite =& $db['invites'][$index];
        $userId = trim((string)($user['id'] ?? ''));
        if ($userId === '') throw new RuntimeException('Пользователь не найден.');

        if ((string)($invite['inviter_id'] ?? '') !== $userId) {
            throw new RuntimeException('Запустить этот матч может только пригласивший игрок.');
        }

        $status = (string)($invite['status'] ?? 'pending');
        if ($status === 'started') return $this->publicInvite($invite, $userId);
        if ($status !== 'awaiting_start') {
            throw new RuntimeException('Соперник ещё не подтвердил приглашение.');
        }

        $inviteeId = (string)($invite['invitee_id'] ?? '');
        if ($inviteeId === '' || !isset($db['users'][$inviteeId]) || !is_array($db['users'][$inviteeId])) {
            throw new RuntimeException('Приглашённый игрок больше недоступен.');
        }

        $inviter =& $db['users'][$userId];
        $invitee =& $db['users'][$inviteeId];
        $this->assertAvailable($db, $inviter, 'Сначала завершите текущий поиск или игру.');
        $this->assertAvailable($db, $invitee, 'Приглашённый игрок сейчас занят в другой игре.');
        $this->assertBalances($inviter, $invitee, $invite);

        /* Temporarily leave awaiting_start so the normal matchmaking guard allows only this private start. */
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
        $invite['status'] = 'started';
        $invite['started_at'] = $now;
        $invite['updated_at'] = $now;
        $invite['game_id'] = $gameId;

        $this->addNotification(
            $db,
            $inviteeId,
            'invite:' . (string)($invite['id'] ?? $token) . ':started',
            'invite_started',
            'Матч готов',
            (string)($invite['inviter_name'] ?? 'Игрок') . ' запустил матч «' . (string)($invite['game_title'] ?? 'игра') . '».',
            'success',
            (string)($invite['token'] ?? '')
        );

        return $this->publicInvite($invite, $userId);
    }

    public function decline(array &$db, array $user, string $token): array
    {
        $this->cleanup($db);
        $index = $this->requireIndex($db, $token);
        $invite =& $db['invites'][$index];
        $userId = trim((string)($user['id'] ?? ''));
        if ($userId === '') throw new RuntimeException('Пользователь не найден.');
        if ((string)($invite['inviter_id'] ?? '') === $userId) {
            throw new RuntimeException('Своё приглашение можно только отменить.');
        }
        if ((string)($invite['status'] ?? '') !== 'pending') {
            return $this->publicInvite($invite, $userId);
        }

        $now = now_iso();
        $invite['status'] = 'declined';
        $invite['invitee_id'] = $userId;
        $invite['invitee_name'] = $this->userName($user);
        $invite['declined_at'] = $now;
        $invite['updated_at'] = $now;
        return $this->publicInvite($invite, $userId);
    }

    public function cancel(array &$db, array $user, string $token): array
    {
        $this->cleanup($db);
        $index = $this->requireIndex($db, $token);
        $invite =& $db['invites'][$index];
        $userId = trim((string)($user['id'] ?? ''));
        if ($userId === '') throw new RuntimeException('Пользователь не найден.');

        $isOwner = (string)($invite['inviter_id'] ?? '') === $userId;
        $isInvitee = (string)($invite['invitee_id'] ?? '') === $userId;
        $status = (string)($invite['status'] ?? 'pending');
        if (!$isOwner && !$isInvitee) throw new RuntimeException('Вы не участвуете в этом приглашении.');
        if (!in_array($status, ['pending', 'awaiting_start'], true)) {
            return $this->publicInvite($invite, $userId);
        }
        if ($status === 'pending' && !$isOwner) {
            throw new RuntimeException('Отклоните приглашение отдельной кнопкой.');
        }

        $now = now_iso();
        $invite['status'] = 'cancelled';
        $invite['cancelled_at'] = $now;
        $invite['cancelled_by'] = $userId;
        $invite['updated_at'] = $now;

        $otherId = $isOwner ? (string)($invite['invitee_id'] ?? '') : (string)($invite['inviter_id'] ?? '');
        if ($otherId !== '') {
            $this->addNotification(
                $db,
                $otherId,
                'invite:' . (string)($invite['id'] ?? $token) . ':cancelled',
                'invite_cancelled',
                'Приглашение отменено',
                'Матч «' . (string)($invite['game_title'] ?? 'Игра') . '» не начался.',
                'warning',
                (string)($invite['token'] ?? '')
            );
        }

        return $this->publicInvite($invite, $userId);
    }

    public function cleanup(array &$db): void
    {
        if (!isset($db['invites']) || !is_array($db['invites'])) {
            $db['invites'] = [];
            return;
        }

        $now = time();
        foreach ($db['invites'] as &$invite) {
            if (!is_array($invite)) continue;
            $this->normalizeLegacyStatus($invite);
            $this->expireIfDue($db, $invite, $now);
        }
        unset($invite);
    }

    private function expireIfDue(array &$db, array &$invite, int $now): void
    {
        $status = (string)($invite['status'] ?? 'pending');
        if ($status === 'pending') {
            $expiresAt = strtotime((string)($invite['expires_at'] ?? '')) ?: 0;
            if ($expiresAt > 0 && $expiresAt <= $now) {
                $invite['status'] = 'expired';
                $invite['updated_at'] = now_iso();
            }
            return;
        }

        if ($status !== 'awaiting_start') return;
        $deadline = strtotime((string)($invite['start_deadline_at'] ?? '')) ?: 0;
        if ($deadline <= 0 || $deadline > $now) return;

        $invite['status'] = 'timed_out';
        $invite['updated_at'] = now_iso();
        foreach ([(string)($invite['inviter_id'] ?? ''), (string)($invite['invitee_id'] ?? '')] as $userId) {
            if ($userId === '') continue;
            $this->addNotification(
                $db,
                $userId,
                'invite:' . (string)($invite['id'] ?? '') . ':timed_out',
                'invite_timed_out',
                'Время ожидания истекло',
                'Матч «' . (string)($invite['game_title'] ?? 'Игра') . '» не был запущен.',
                'warning',
                (string)($invite['token'] ?? '')
            );
        }
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

            /* The isolated queue guarantees that this private match cannot consume a public opponent. */
            $first = $this->games->startSearch($db, $inviter, $room, $bet, $boardSize, $gameType);
            if (!empty($first['game'])) throw new RuntimeException('Пригласивший игрок уже начал другой матч.');

            $second = $this->games->startSearch($db, $invitee, $room, $bet, $boardSize, $gameType);
            $gameId = (string)($second['game']['id'] ?? '');
            if ($gameId === '' || !isset($db['games'][$gameId]) || !is_array($db['games'][$gameId])) {
                throw new RuntimeException('Не удалось создать приватный матч.');
            }

            $db['games'][$gameId]['match_source'] = 'invite';
            $db['games'][$gameId]['invite_id'] = (string)($invite['id'] ?? '');
            $db['games'][$gameId]['invite_token'] = (string)($invite['token'] ?? '');
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

    private function assertBalances(array $inviter, array $invitee, array $invite): void
    {
        $room = (string)($invite['room'] ?? 'match') === 'gold' ? 'gold' : 'match';
        $bet = (int)($invite['bet'] ?? 0);
        $balanceKey = $room === 'gold' ? 'balance_gold' : 'balance_match';
        if ((int)($invitee[$balanceKey] ?? 0) < $bet) {
            throw new RuntimeException('Недостаточно коинов для принятия приглашения.');
        }
        if ((int)($inviter[$balanceKey] ?? 0) < $bet) {
            throw new RuntimeException('У пригласившего игрока недостаточно коинов. Попросите создать новое приглашение.');
        }
    }

    private function assertAvailable(array $db, array $user, string $message): void
    {
        $userId = (string)($user['id'] ?? '');
        if (in_array((string)($user['status'] ?? 'idle'), ['searching', 'playing'], true)) {
            throw new RuntimeException($message);
        }
        if ($userId !== '' && $this->games->findActiveGameForUser($db, $userId)) {
            throw new RuntimeException($message);
        }
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
            if (is_array($existing) && (string)($existing['event_key'] ?? '') === $eventKey) return;
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

    private function publicInvite(array $invite, string $viewerId): array
    {
        $status = (string)($invite['status'] ?? 'pending');
        $inviterId = (string)($invite['inviter_id'] ?? '');
        $inviteeId = (string)($invite['invitee_id'] ?? '');
        $isOwner = $viewerId !== '' && $viewerId === $inviterId;
        $isInvitee = $viewerId !== '' && $viewerId === $inviteeId;
        $isParticipant = $isOwner || $isInvitee;
        $deadline = (string)($invite['start_deadline_at'] ?? '');
        $deadlineTs = strtotime($deadline) ?: 0;

        return [
            'token' => (string)($invite['token'] ?? ''),
            'status' => $status,
            'status_label' => $this->statusLabel($status),
            'is_owner' => $isOwner,
            'is_invitee' => $isInvitee,
            'is_participant' => $isParticipant,
            'can_accept' => !$isOwner && $status === 'pending',
            'can_decline' => !$isOwner && $status === 'pending',
            'can_start' => $isOwner && $status === 'awaiting_start',
            'can_cancel' => ($isOwner && $status === 'pending') || ($isParticipant && $status === 'awaiting_start'),
            'inviter_name' => (string)($invite['inviter_name'] ?? 'Игрок'),
            'invitee_name' => $isParticipant ? (string)($invite['invitee_name'] ?? '') : '',
            'game_type' => (string)($invite['game_type'] ?? 'tictactoe'),
            'game_title' => (string)($invite['game_title'] ?? 'Игра'),
            'room' => (string)($invite['room'] ?? 'match'),
            'room_label' => (string)($invite['room'] ?? 'match') === 'gold' ? 'Gold-комната' : 'Матч-комната',
            'bet' => (int)($invite['bet'] ?? 0),
            'board_size' => (int)($invite['board_size'] ?? 0),
            'board_columns' => (int)($invite['board_columns'] ?? 0),
            'board_rows' => (int)($invite['board_rows'] ?? 0),
            'created_at' => (string)($invite['created_at'] ?? ''),
            'expires_at' => (string)($invite['expires_at'] ?? ''),
            'accepted_at' => $isParticipant ? (string)($invite['accepted_at'] ?? '') : '',
            'start_deadline_at' => $isParticipant ? $deadline : '',
            'waiting_seconds' => $isParticipant && $deadlineTs > 0 ? max(0, $deadlineTs - time()) : 0,
            'game_id' => $isParticipant ? (string)($invite['game_id'] ?? '') : '',
        ];
    }

    private function normalizeLegacyStatus(array &$invite): void
    {
        if ((string)($invite['status'] ?? '') === 'accepted' && (string)($invite['game_id'] ?? '') !== '') {
            $invite['status'] = 'started';
        }
    }

    private function requireIndex(array $db, string $token): int
    {
        $index = $this->findIndex($db, $token);
        if ($index === null) throw new RuntimeException('Приглашение не найдено или уже недоступно.');
        return $index;
    }

    private function findIndex(array $db, string $token): ?int
    {
        $token = strtolower(trim($token));
        if (!preg_match('/^[a-f0-9]{24}$/', $token)) return null;
        foreach (($db['invites'] ?? []) as $index => $invite) {
            if (is_array($invite) && hash_equals((string)($invite['token'] ?? ''), $token)) return (int)$index;
        }
        return null;
    }

    private function userName(array $user): string
    {
        $username = trim((string)($user['username'] ?? ''));
        if ($username !== '') return '@' . ltrim($username, '@');
        $name = trim((string)($user['first_name'] ?? '') . ' ' . (string)($user['last_name'] ?? ''));
        return $name !== '' ? $name : 'Игрок';
    }

    private function statusLabel(string $status): string
    {
        return match ($status) {
            'pending' => 'Ожидает ответа',
            'awaiting_start' => 'Ожидает запуска',
            'starting' => 'Матч запускается',
            'started', 'accepted' => 'Матч начат',
            'declined' => 'Отклонено',
            'expired' => 'Срок истёк',
            'timed_out' => 'Время ожидания истекло',
            'cancelled' => 'Отменено',
            default => 'Недоступно',
        };
    }
}
