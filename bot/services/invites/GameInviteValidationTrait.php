<?php
declare(strict_types=1);

trait GameInviteValidationTrait
{
    private function assertAvailableForInvite(array $db, array $user, string $message): void
    {
        $userId = $this->requireUserId($user);
        if (in_array((string)($user['status'] ?? 'idle'), ['searching', 'playing'], true)) {
            throw new RuntimeException($message);
        }
        if ($this->games->findActiveGameForUser($db, $userId)) throw new RuntimeException($message);
        $this->assertNoOpenInvite($db, $userId, '', $message);
    }

    private function assertCanReceiveInvite(array $db, array $user, string $message): void
    {
        $userId = $this->requireUserId($user);
        $this->assertNoOpenInvite($db, $userId, '', $message);
    }

    private function assertAvailableForStart(array $db, array $user, string $currentToken, string $message): void
    {
        $userId = $this->requireUserId($user);
        if (in_array((string)($user['status'] ?? 'idle'), ['searching', 'playing'], true)) {
            throw new RuntimeException($message);
        }
        if ($this->games->findActiveGameForUser($db, $userId)) throw new RuntimeException($message);
        $this->assertNoOpenInvite($db, $userId, $currentToken, $message);
    }

    private function assertNoOpenInvite(array $db, string $userId, string $exceptToken, string $message): void
    {
        foreach ($db['invites'] ?? [] as $invite) {
            if (!is_array($invite) || !$this->isParticipant($invite, $userId)) continue;
            if ((string)($invite['token'] ?? '') === $exceptToken) continue;
            if (in_array((string)($invite['status'] ?? ''), ['pending', 'awaiting_start'], true)) {
                throw new RuntimeException($message);
            }
        }
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
            throw new RuntimeException('У пригласившего игрока недостаточно коинов.');
        }
    }

    private function discardOwnerDrafts(array &$db, string $userId): void
    {
        $now = now_iso();
        foreach ($db['invites'] ?? [] as &$invite) {
            if (!is_array($invite)) continue;
            if ((string)($invite['inviter_id'] ?? '') !== $userId) continue;
            if ((string)($invite['status'] ?? '') !== 'draft') continue;
            $invite['status'] = 'cancelled';
            $invite['cancelled_at'] = $now;
            $invite['cancelled_by'] = $userId;
            $invite['updated_at'] = $now;
        }
        unset($invite);
    }

    private function findOpenRematchIndex(array $db, string $sourceGameId, array $playerIds): ?int
    {
        sort($playerIds, SORT_STRING);
        foreach ($db['invites'] ?? [] as $index => $invite) {
            if (!is_array($invite) || (string)($invite['source'] ?? '') !== 'rematch') continue;
            if ((string)($invite['source_game_id'] ?? '') !== $sourceGameId) continue;
            if (!in_array((string)($invite['status'] ?? ''), ['pending', 'awaiting_start', 'active'], true)) continue;
            $participants = [(string)($invite['inviter_id'] ?? ''), (string)($invite['invitee_id'] ?? '')];
            sort($participants, SORT_STRING);
            if ($participants === $playerIds) return (int)$index;
        }
        return null;
    }

    private function publicInvite(array $invite, string $viewerId): array
    {
        $storedStatus = (string)($invite['status'] ?? 'pending');
        $status = $storedStatus === 'awaiting_start' ? 'accepted' : $storedStatus;
        $inviterId = (string)($invite['inviter_id'] ?? '');
        $inviteeId = (string)($invite['invitee_id'] ?? '');
        $isOwner = $viewerId !== '' && $viewerId === $inviterId;
        $isInvitee = $viewerId !== '' && $viewerId === $inviteeId;
        $deadline = (string)($invite['ready_deadline_at'] ?? '');
        $deadlineTs = strtotime($deadline) ?: 0;
        return [
            'token' => (string)($invite['token'] ?? ''),
            'status' => $status,
            'status_label' => $this->statusLabel($status),
            'source' => (string)($invite['source'] ?? 'link'),
            'is_owner' => $isOwner,
            'is_invitee' => $isInvitee,
            'is_participant' => $isOwner || $isInvitee,
            'can_accept' => $isInvitee && $status === 'pending',
            'can_decline' => $isInvitee && $status === 'pending',
            'can_start' => $isOwner && $status === 'accepted',
            'can_cancel' => ($isOwner && in_array($status, ['draft', 'pending', 'accepted'], true))
                || ($isInvitee && $status === 'accepted'),
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
            'created_at' => (string)($invite['created_at'] ?? ''),
            'updated_at' => (string)($invite['updated_at'] ?? ''),
            'expires_at' => (string)($invite['expires_at'] ?? ''),
            'opened_at' => (string)($invite['opened_at'] ?? ''),
            'open_requested_at' => (string)($invite['open_requested_at'] ?? ''),
            'accepted_at' => (string)($invite['accepted_at'] ?? ''),
            'ready_deadline_at' => $deadline,
            'waiting_seconds' => $deadlineTs > 0 ? max(0, $deadlineTs - time()) : 0,
            'source_game_id' => (string)($invite['source_game_id'] ?? ''),
            'game_id' => (string)($invite['game_id'] ?? ''),
        ];
    }

    private function isParticipant(array $invite, string $userId): bool
    {
        return $userId !== '' && (
            (string)($invite['inviter_id'] ?? '') === $userId
            || (string)($invite['invitee_id'] ?? '') === $userId
        );
    }

    private function requireUserId(array $user): string
    {
        $userId = trim((string)($user['id'] ?? ''));
        if ($userId === '') throw new RuntimeException('Пользователь не найден.');
        return $userId;
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
        foreach ($db['invites'] ?? [] as $index => $invite) {
            if (is_array($invite) && hash_equals((string)($invite['token'] ?? ''), $token)) return (int)$index;
        }
        return null;
    }

    private function uniqueToken(array $invites): string
    {
        $known = [];
        foreach ($invites as $invite) if (is_array($invite)) $known[(string)($invite['token'] ?? '')] = true;
        do { $token = bin2hex(random_bytes(12)); } while (isset($known[$token]));
        return $token;
    }

    private function normalizeBet(string $room, int $bet): int
    {
        if ($room === 'match') return (int)($this->config['match_bet'] ?? 10);
        $allowed = array_values(array_map('intval', $this->config['gold_bets'] ?? [10, 20, 30, 50, 100]));
        if (!in_array($bet, $allowed, true)) throw new RuntimeException('Выберите доступную стоимость участия.');
        return $bet;
    }

    private function dimensions(string $gameType, int $boardSize): array
    {
        if ($gameType === 'four_in_a_row') return [$boardSize, max(5, $boardSize - 1)];
        if ($gameType === 'domino') return [7, 1];
        return [$boardSize, $boardSize];
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
            'draft' => 'Ссылка подготовлена',
            'pending' => 'Ожидает ответа',
            'accepted', 'awaiting_start' => 'Ожидает запуска',
            'starting' => 'Матч запускается',
            'active' => 'Матч начат',
            'declined' => 'Отклонено',
            'cancelled' => 'Отменено',
            'expired' => 'Срок истёк',
            'timed_out' => 'Время ожидания истекло',
            default => 'Недоступно',
        };
    }
}
