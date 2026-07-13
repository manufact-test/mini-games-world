<?php
declare(strict_types=1);

final class GameInviteService
{
    private const DEFAULT_TTL_SEC = 900;
    private const RETENTION_SEC = 604800;

    public function __construct(
        private array $config,
        private GameCatalogService $catalog
    ) {}

    public function create(
        array &$db,
        array $user,
        string $gameType,
        string $room,
        int $bet,
        int $boardSize
    ): array {
        $this->cleanup($db);

        $userId = trim((string)($user['id'] ?? ''));
        if ($userId === '') {
            throw new RuntimeException('Пользователь не найден.');
        }

        $status = (string)($user['status'] ?? 'idle');
        if (in_array($status, ['searching', 'playing'], true)) {
            throw new RuntimeException('Сначала завершите текущий поиск или игру.');
        }

        $gameType = $this->catalog->normalizeGameType($gameType);
        $room = $room === 'gold' ? 'gold' : 'match';
        if (!$this->catalog->supportsRoom($gameType, $room)) {
            throw new RuntimeException('Эта игра недоступна в выбранной комнате.');
        }

        $boardSize = $this->catalog->normalizeBoardSize($gameType, $boardSize);
        $definition = $this->catalog->publicGameDefinition($gameType);
        $bet = $this->normalizeBet($room, $bet);
        $balanceKey = $room === 'gold' ? 'balance_gold' : 'balance_match';
        if ((int)($user[$balanceKey] ?? 0) < $bet) {
            throw new RuntimeException('Недостаточно коинов для выбранной ставки.');
        }

        if (!isset($db['invites']) || !is_array($db['invites'])) {
            $db['invites'] = [];
        }

        $token = $this->uniqueToken($db['invites']);
        $now = now_iso();
        [$columns, $rows] = $this->dimensions($gameType, $boardSize, $definition);
        $invite = [
            'id' => make_id('invite'),
            'token' => $token,
            'status' => 'pending',
            'inviter_id' => $userId,
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
            'expires_at' => gmdate('c', time() + $this->ttlSec()),
            'declined_at' => null,
            'cancelled_at' => null,
            'accepted_at' => null,
            'game_id' => null,
        ];

        $db['invites'][] = $invite;
        return $this->publicInvite($invite, $userId);
    }

    public function resolve(array &$db, array $user, string $token): array
    {
        $this->cleanup($db);
        $index = $this->findIndex($db, $token);
        if ($index === null) {
            throw new RuntimeException('Приглашение не найдено или уже недоступно.');
        }

        $invite =& $db['invites'][$index];
        $this->expireIfDue($invite);
        return $this->publicInvite($invite, (string)($user['id'] ?? ''));
    }

    public function decline(array &$db, array $user, string $token): array
    {
        $this->cleanup($db);
        $index = $this->findIndex($db, $token);
        if ($index === null) {
            throw new RuntimeException('Приглашение не найдено или уже недоступно.');
        }

        $invite =& $db['invites'][$index];
        $this->expireIfDue($invite);
        $userId = trim((string)($user['id'] ?? ''));
        if ($userId === '') {
            throw new RuntimeException('Пользователь не найден.');
        }
        if ((string)($invite['inviter_id'] ?? '') === $userId) {
            throw new RuntimeException('Своё приглашение можно только закрыть.');
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

    public function cleanup(array &$db): void
    {
        if (!isset($db['invites']) || !is_array($db['invites'])) {
            $db['invites'] = [];
            return;
        }

        $now = time();
        foreach ($db['invites'] as &$invite) {
            if (!is_array($invite)) continue;
            $this->expireIfDue($invite, $now);
        }
        unset($invite);

        $db['invites'] = array_values(array_filter($db['invites'], static function ($invite) use ($now): bool {
            if (!is_array($invite)) return false;
            $activity = strtotime((string)($invite['updated_at'] ?? $invite['created_at'] ?? '')) ?: $now;
            return $now - $activity <= self::RETENTION_SEC;
        }));
    }

    private function publicInvite(array $invite, string $viewerId): array
    {
        $status = (string)($invite['status'] ?? 'pending');
        $inviterId = (string)($invite['inviter_id'] ?? '');
        $isOwner = $viewerId !== '' && $viewerId === $inviterId;

        return [
            'token' => (string)($invite['token'] ?? ''),
            'status' => $status,
            'status_label' => $this->statusLabel($status),
            'is_owner' => $isOwner,
            'can_decline' => !$isOwner && $status === 'pending',
            'can_accept' => !$isOwner && $status === 'pending',
            'inviter_name' => (string)($invite['inviter_name'] ?? 'Игрок'),
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
        ];
    }

    private function findIndex(array $db, string $token): ?int
    {
        $token = strtolower(trim($token));
        if (!preg_match('/^[a-f0-9]{24}$/', $token)) return null;

        foreach (($db['invites'] ?? []) as $index => $invite) {
            if (is_array($invite) && hash_equals((string)($invite['token'] ?? ''), $token)) {
                return (int)$index;
            }
        }
        return null;
    }

    private function uniqueToken(array $invites): string
    {
        $known = [];
        foreach ($invites as $invite) {
            if (is_array($invite)) $known[(string)($invite['token'] ?? '')] = true;
        }

        do {
            $token = bin2hex(random_bytes(12));
        } while (isset($known[$token]));
        return $token;
    }

    private function expireIfDue(array &$invite, ?int $now = null): void
    {
        if ((string)($invite['status'] ?? '') !== 'pending') return;
        $expiresAt = strtotime((string)($invite['expires_at'] ?? '')) ?: 0;
        $now ??= time();
        if ($expiresAt > 0 && $expiresAt <= $now) {
            $invite['status'] = 'expired';
            $invite['updated_at'] = now_iso();
        }
    }

    private function normalizeBet(string $room, int $bet): int
    {
        if ($room === 'match') {
            return (int)($this->config['match_bet'] ?? 10);
        }

        $allowed = array_values(array_map('intval', $this->config['gold_bets'] ?? [10, 20, 30, 50, 100]));
        if (!in_array($bet, $allowed, true)) {
            throw new RuntimeException('Выберите доступную стоимость участия.');
        }
        return $bet;
    }

    private function dimensions(string $gameType, int $boardSize, array $definition): array
    {
        if ($gameType === 'four_in_a_row') return [$boardSize, max(5, $boardSize - 1)];
        if ($gameType === 'domino') return [7, 1];
        return [
            (int)($definition['board_columns'] ?? $boardSize),
            (int)($definition['board_rows'] ?? $boardSize),
        ];
    }

    private function ttlSec(): int
    {
        $value = (int)($this->config['game_invite_ttl_sec'] ?? self::DEFAULT_TTL_SEC);
        return max(300, min(3600, $value));
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
            'declined' => 'Отклонено',
            'expired' => 'Срок истёк',
            'cancelled' => 'Отменено',
            'accepted' => 'Принято',
            default => 'Недоступно',
        };
    }
}
