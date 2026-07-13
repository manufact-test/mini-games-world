<?php
declare(strict_types=1);

final class GameInviteService
{
    private const DEFAULT_TTL_SEC = 900;
    private const RETENTION_SEC = 604800;

    public function __construct(
        private array $config,
        private GameCatalogService $catalog,
        private ChessRuntimeService $games
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

        $this->assertAvailable($db, $user, 'Сначала завершите текущий поиск или игру.');

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
        [$columns, $rows] = $this->dimensions($gameType, $boardSize);
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

    public function accept(array &$db, array &$user, string $token): array
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

        $inviterId = (string)($invite['inviter_id'] ?? '');
        if ($inviterId === $userId) {
            throw new RuntimeException('Нельзя принять собственное приглашение.');
        }

        $status = (string)($invite['status'] ?? 'pending');
        if ($status === 'accepted' && (string)($invite['invitee_id'] ?? '') === $userId) {
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

        $room = (string)($invite['room'] ?? 'match') === 'gold' ? 'gold' : 'match';
        $bet = (int)($invite['bet'] ?? 0);
        $balanceKey = $room === 'gold' ? 'balance_gold' : 'balance_match';
        if ((int)($invitee[$balanceKey] ?? 0) < $bet) {
            throw new RuntimeException('Недостаточно коинов для принятия приглашения.');
        }
        if ((int)($inviter[$balanceKey] ?? 0) < $bet) {
            throw new RuntimeException('У пригласившего игрока недостаточно коинов. Попросите создать новое приглашение.');
        }

        $game = $this->createIsolatedGame($db, $inviter, $invitee, $invite);
        $gameId = (string)($game['id'] ?? '');
        if ($gameId === '') {
            throw new RuntimeException('Не удалось создать приватный матч.');
        }

        $now = now_iso();
        $invite['status'] = 'accepted';
        $invite['invitee_id'] = $userId;
        $invite['invitee_name'] = $this->userName($invitee);
        $invite['accepted_at'] = $now;
        $invite['updated_at'] = $now;
        $invite['game_id'] = $gameId;

        return $this->publicInvite($invite, $userId);
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
            if (!empty($first['game'])) {
                throw new RuntimeException('Пригласивший игрок уже начал другой матч.');
            }

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

        if (random_int(0, 1) === 1) {
            [$playerIds[0], $playerIds[1]] = [$playerIds[1], $playerIds[0]];
        }

        $now = now_iso();
        $game['symbols'] = [$playerIds[0] => 'X', $playerIds[1] => 'O'];
        $game['turn'] = $playerIds[0];
        $game['turn_started_at'] = $now;
        $game['last_move_at'] = $now;
        $game['updated_at'] = $now;
        $game['symbols_randomized'] = true;
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

    private function publicInvite(array $invite, string $viewerId): array
    {
        $status = (string)($invite['status'] ?? 'pending');
        $inviterId = (string)($invite['inviter_id'] ?? '');
        $inviteeId = (string)($invite['invitee_id'] ?? '');
        $isOwner = $viewerId !== '' && $viewerId === $inviterId;
        $isInvitee = $viewerId !== '' && $viewerId === $inviteeId;
        $isParticipant = $isOwner || $isInvitee;

        return [
            'token' => (string)($invite['token'] ?? ''),
            'status' => $status,
            'status_label' => $this->statusLabel($status),
            'is_owner' => $isOwner,
            'is_participant' => $isParticipant,
            'can_decline' => !$isOwner && $status === 'pending',
            'can_accept' => !$isOwner && $status === 'pending',
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
            'game_id' => $isParticipant ? (string)($invite['game_id'] ?? '') : '',
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

    private function dimensions(string $gameType, int $boardSize): array
    {
        if ($gameType === 'four_in_a_row') return [$boardSize, max(5, $boardSize - 1)];
        if ($gameType === 'domino') return [7, 1];
        return [$boardSize, $boardSize];
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
