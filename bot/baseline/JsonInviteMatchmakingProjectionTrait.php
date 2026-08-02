<?php
declare(strict_types=1);

trait JsonInviteMatchmakingProjectionTrait
{
    private function publicInvite(array $invite, string $viewerId, DateTimeImmutable $now): array
    {
        $storedStatus = (string)($invite['status'] ?? 'pending');
        $status = $storedStatus === 'awaiting_start' ? 'accepted' : $storedStatus;
        $isOwner = $viewerId !== '' && $viewerId === (string)$invite['inviter_id'];
        $isInvitee = $viewerId !== '' && $viewerId === (string)($invite['invitee_id'] ?? '');
        $deadline = (string)($invite['ready_deadline_at'] ?? '');
        $deadlineTs = $deadline !== '' ? (new DateTimeImmutable($deadline))->getTimestamp() : 0;
        return [
            'token' => (string)$invite['token'],
            'status' => $status,
            'status_label' => $this->statusLabel($status),
            'source' => (string)$invite['source'],
            'is_owner' => $isOwner,
            'is_invitee' => $isInvitee,
            'is_participant' => $isOwner || $isInvitee,
            'can_accept' => $isInvitee && $status === 'pending',
            'can_decline' => $isInvitee && $status === 'pending',
            'can_start' => $isOwner && $status === 'accepted',
            'can_cancel' => ($isOwner && in_array($status, ['draft', 'pending', 'accepted'], true))
                || ($isInvitee && $status === 'accepted'),
            'inviter_name' => (string)$invite['inviter_name'],
            'invitee_name' => (string)($invite['invitee_name'] ?? ''),
            'game_type' => (string)$invite['game_type'],
            'game_title' => (string)$invite['game_title'],
            'room' => (string)$invite['room'],
            'room_label' => (string)$invite['room'] === 'gold' ? 'Gold-комната' : 'Матч-комната',
            'bet' => (int)$invite['bet'],
            'board_size' => (int)$invite['board_size'],
            'board_columns' => (int)$invite['board_columns'],
            'board_rows' => (int)$invite['board_rows'],
            'created_at' => (string)$invite['created_at'],
            'updated_at' => (string)$invite['updated_at'],
            'expires_at' => (string)$invite['expires_at'],
            'opened_at' => (string)($invite['opened_at'] ?? ''),
            'open_requested_at' => (string)($invite['open_requested_at'] ?? ''),
            'accepted_at' => (string)($invite['accepted_at'] ?? ''),
            'ready_deadline_at' => $deadline,
            'waiting_seconds' => $deadlineTs > 0 ? max(0, $deadlineTs - $now->getTimestamp()) : 0,
            'source_game_id' => (string)($invite['source_game_id'] ?? ''),
            'game_id' => (string)($invite['game_id'] ?? ''),
        ];
    }

    private function publicGame(array $game): ?array
    {
        if ($game === []) return null;
        return [
            'id' => (string)$game['id'],
            'game_type' => (string)$game['game_type'],
            'room' => (string)$game['room'],
            'bet' => (int)$game['bet'],
            'board_size' => (int)$game['board_size'],
            'board_columns' => (int)$game['board_columns'],
            'board_rows' => (int)$game['board_rows'],
            'player_ids' => array_values(array_map('strval', $game['player_ids'] ?? [])),
            'turn' => (string)$game['turn'],
            'status' => (string)$game['status'],
            'is_bot_game' => !empty($game['is_bot_game']),
            'bot_id' => (string)($game['bot_id'] ?? ''),
            'bot_name' => (string)($game['bot_name'] ?? ''),
            'bot_difficulty' => (string)($game['bot_difficulty'] ?? ''),
            'match_source' => (string)($game['match_source'] ?? ''),
            'invite_token' => (string)($game['invite_token'] ?? ''),
            'source_game_id' => (string)($game['source_game_id'] ?? ''),
        ];
    }

    private function addNotification(
        JsonBehaviorBaselineFixture $fixture,
        array &$state,
        string $userId,
        string $eventKey,
        string $type,
        string $title,
        string $message,
        string $tone,
        string $token,
        DateTimeImmutable $now
    ): ?array {
        foreach ($state['notifications'] ?? [] as $existing) {
            if (is_array($existing)
                && (string)($existing['event_key'] ?? '') === $eventKey
                && (string)($existing['user_id'] ?? '') === $userId) return null;
        }
        $notification = [
            'id' => $fixture->nextId('notification'),
            'event_key' => $eventKey,
            'user_id' => $userId,
            'type' => $type,
            'title' => $title,
            'message' => $message,
            'tone' => $tone,
            'invite_token' => $token,
            'created_at' => $now->format('c'),
            'read_at' => null,
        ];
        $state['notifications'][] = $notification;
        return $notification;
    }

    private function markInviteSeen(array &$state, string $userId, string $token, DateTimeImmutable $now): void
    {
        if (!isset($state['notifications']) || !is_array($state['notifications'])) return;
        foreach ($state['notifications'] as &$notification) {
            if (is_array($notification)
                && (string)($notification['user_id'] ?? '') === $userId
                && (string)($notification['invite_token'] ?? '') === $token
                && empty($notification['read_at'])) {
                $notification['read_at'] = $now->format('c');
            }
        }
        unset($notification);
    }

    private function humanOpponentIndex(
        array $state,
        string $userId,
        string $room,
        int $bet,
        int $boardSize,
        string $gameType,
        DateTimeImmutable $now
    ): ?int {
        foreach ($state['queue'] ?? [] as $index => $item) {
            if (!is_array($item)) continue;
            $opponentId = (string)($item['user_id'] ?? '');
            if ($opponentId === '' || $opponentId === $userId || !isset($state['users'][$opponentId])) continue;
            if ((string)($item['room'] ?? '') !== $room
                || (int)($item['bet'] ?? 0) !== $bet
                || (int)($item['board_size'] ?? 0) !== $boardSize
                || (string)($item['game_type'] ?? 'tictactoe') !== $gameType) continue;
            $created = new DateTimeImmutable((string)$item['created_at']);
            if ($now->getTimestamp() - $created->getTimestamp() > 120) continue;
            if ((string)($state['users'][$opponentId]['status'] ?? '') !== 'searching') continue;
            return (int)$index;
        }
        return null;
    }

    private function assertInviteAvailable(array $state, string $userId, string $exceptToken = ''): void
    {
        $status = (string)($state['users'][$userId]['status'] ?? 'idle');
        if (in_array($status, ['searching', 'playing'], true)) throw new RuntimeException('User is busy.');
        foreach ($state['games'] ?? [] as $game) {
            if (is_array($game)
                && (string)($game['status'] ?? '') === 'active'
                && in_array($userId, array_map('strval', $game['player_ids'] ?? []), true)) throw new RuntimeException('User has active game.');
        }
        $this->assertNoOpenInvite($state, $userId, $exceptToken);
    }

    private function assertNoOpenInvite(array $state, string $userId, string $exceptToken): void
    {
        foreach ($state['invites'] ?? [] as $invite) {
            if (!is_array($invite)) continue;
            if ((string)($invite['token'] ?? '') === $exceptToken) continue;
            if ((string)($invite['inviter_id'] ?? '') !== $userId
                && (string)($invite['invitee_id'] ?? '') !== $userId) continue;
            if (in_array((string)($invite['status'] ?? ''), ['pending', 'awaiting_start'], true)) {
                throw new RuntimeException('User has another open invite.');
            }
        }
    }

    private function assertGameBalances(array $state, array $invite): void
    {
        foreach ([(string)$invite['inviter_id'], (string)$invite['invitee_id']] as $userId) {
            $this->assertBalance($state['users'][$userId], (string)$invite['room'], (int)$invite['bet']);
        }
    }

    private function assertBalance(array $user, string $room, int $bet): void
    {
        $key = $room === 'gold' ? 'balance_gold' : 'balance_match';
        if ((int)($user[$key] ?? 0) < $bet) throw new RuntimeException('Insufficient balance.');
    }

    private function inviteIndex(array $state, string $token): int
    {
        foreach ($state['invites'] ?? [] as $index => $invite) {
            if (is_array($invite) && (string)($invite['token'] ?? '') === $token) return (int)$index;
        }
        throw new RuntimeException('Invite token is unavailable.');
    }

    private function token(array $step, array $context): string
    {
        $token = trim((string)($step['token'] ?? ''));
        if ($token === '' && (string)($step['token_ref'] ?? 'last') === 'last') $token = (string)$context['last_token'];
        if ($token === '') throw new RuntimeException('Invite token is required.');
        return $token;
    }

    private function userName(array $user): string
    {
        $username = trim((string)($user['username'] ?? ''));
        if ($username !== '') return '@' . ltrim($username, '@');
        $name = trim((string)($user['first_name'] ?? '') . ' ' . (string)($user['last_name'] ?? ''));
        return $name !== '' ? $name : 'Игрок';
    }

    private function gameTitle(string $type): string
    {
        return [
            'tictactoe' => 'Крестики-нолики',
            'four_in_a_row' => '4 в ряд',
            'battleship' => 'Морской бой',
            'checkers' => 'Шашки',
            'reversi' => 'Реверси',
            'chess' => 'Шахматы',
            'go' => 'Го',
            'domino' => 'Домино',
        ][$type] ?? 'Игра';
    }

    private function dimensions(string $gameType, int $boardSize): array
    {
        if ($gameType === 'four_in_a_row') return [$boardSize, max(5, $boardSize - 1)];
        if ($gameType === 'domino') return [7, 1];
        return [$boardSize, $boardSize];
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

    private function effects(array $notifications, array $events, array $ledger): array
    {
        return ['notifications' => $notifications, 'events' => $events, 'ledger' => $ledger];
    }

    private function domainSnapshot(array $state): array
    {
        $users = is_array($state['users'] ?? null) ? $state['users'] : [];
        ksort($users, SORT_STRING);
        $games = is_array($state['games'] ?? null) ? $state['games'] : [];
        ksort($games, SORT_STRING);
        return [
            'users' => $users,
            'queue' => $this->sortedList($state['queue'] ?? []),
            'games' => $games,
            'invites' => $this->sortedList($state['invites'] ?? []),
            'notifications' => $this->sortedList($state['notifications'] ?? []),
            'transactions' => $this->sortedList($state['transactions'] ?? []),
        ];
    }

    private function sortedList(mixed $value): array
    {
        $items = is_array($value) ? array_values(array_filter($value, 'is_array')) : [];
        usort($items, static fn(array $left, array $right): int => strcmp(
            (string)($left['id'] ?? $left['token'] ?? ''),
            (string)($right['id'] ?? $right['token'] ?? '')
        ));
        return $items;
    }
}
