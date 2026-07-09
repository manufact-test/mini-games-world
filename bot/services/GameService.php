<?php
declare(strict_types=1);

final class GameService
{
    public function __construct(private array $config) {}

    public function cleanup(array &$db): void
    {
        $this->cleanupQueue($db);
        $this->processBotTurns($db);
        $this->cleanupActiveGames($db);
    }

    public function cleanupQueue(array &$db): void
    {
        $timeout = $this->queueTimeoutSec();
        $now = time();

        foreach ($db['queue'] ?? [] as $key => $item) {
            $last = $this->queueTimestamp($item);
            if ($last > 0 && $now - $last <= $timeout) {
                continue;
            }

            $userId = (string)($item['user_id'] ?? '');
            if ($userId !== '' && isset($db['users'][$userId])) {
                if (($db['users'][$userId]['status'] ?? '') === 'searching') {
                    $db['users'][$userId]['status'] = 'idle';
                    $db['users'][$userId]['current_game_id'] = null;
                }
            }

            unset($db['queue'][$key]);
        }

        $db['queue'] = array_values($db['queue'] ?? []);
    }

    public function cleanupActiveGames(array &$db): void
    {
        if (!isset($db['games']) || !is_array($db['games'])) {
            $db['games'] = [];
            return;
        }

        foreach ($db['games'] as $gameId => &$game) {
            if (($game['status'] ?? '') !== 'active') {
                continue;
            }

            if (!$this->isTurnExpired($game)) {
                continue;
            }

            $loserId = (string)($game['turn'] ?? '');
            if ($loserId === '' || !in_array($loserId, array_map('strval', $game['player_ids'] ?? []), true)) {
                $loserId = (string)($game['player_ids'][0] ?? '');
            }

            $winnerId = $this->otherPlayerId($game, $loserId);
            $this->finishGame($db, $game, $winnerId, 'timeout', $loserId);
        }
        unset($game);
    }

    public function refreshSearch(array &$db, array &$user): void
    {
        if (($user['status'] ?? '') !== 'searching') {
            return;
        }

        if (!isset($db['queue']) || !is_array($db['queue'])) {
            $db['queue'] = [];
        }

        $userId = (string)$user['id'];
        $found = false;

        foreach ($db['queue'] as &$item) {
            if ((string)($item['user_id'] ?? '') === $userId) {
                $item['updated_at'] = now_iso();
                $found = true;
                break;
            }
        }
        unset($item);

        if (!$found) {
            $user['status'] = 'idle';
            $user['current_game_id'] = null;
        }
    }

    public function maybeCreateBotGameForSearchingUser(array &$db, array &$user): ?array
    {
        if (($user['status'] ?? '') !== 'searching') {
            return null;
        }

        if (!isset($db['queue']) || !is_array($db['queue'])) {
            $db['queue'] = [];
        }

        $userId = (string)$user['id'];
        $queueIndex = null;
        $queueItem = null;

        foreach ($db['queue'] as $index => $item) {
            if ((string)($item['user_id'] ?? '') === $userId) {
                $queueIndex = $index;
                $queueItem = $item;
                break;
            }
        }

        if ($queueIndex === null || !$queueItem) {
            $user['status'] = 'idle';
            $user['current_game_id'] = null;
            return null;
        }

        // Боты подключаются только в Match-комнате. В Gold-комнате — только живые игроки.
        if (($queueItem['room'] ?? 'match') !== 'match') {
            return null;
        }

        $created = strtotime($queueItem['created_at'] ?? '') ?: time();
        if (time() - $created < $this->botAfterSec()) {
            return null;
        }

        $room = 'match';
        $bet = (int)($queueItem['bet'] ?? ($this->config['match_bet'] ?? 10));
        $boardSize = (int)($queueItem['board_size'] ?? 3);

        // Перед ботом ещё раз пытаемся найти живого соперника с такими же условиями.
        $opponentIndex = $this->findHumanOpponentIndex($db, $userId, $room, $bet, $boardSize);
        if ($opponentIndex !== null) {
            $opponentItem = $db['queue'][$opponentIndex];
            $opponentId = (string)$opponentItem['user_id'];
            $opponent =& $db['users'][$opponentId];

            foreach ([$queueIndex, $opponentIndex] as $idx) {
                unset($db['queue'][$idx]);
            }
            $db['queue'] = array_values($db['queue']);

            $game = $this->createGame($db, $user, $opponent, $room, $bet, $boardSize);
            return $game;
        }

        unset($db['queue'][$queueIndex]);
        $db['queue'] = array_values($db['queue']);

        $game = $this->createBotGame($db, $user, $bet, $boardSize);
        return $game;
    }

    public function startSearch(array &$db, array &$user, string $room, int $bet, int $boardSize): array
    {
        $this->cleanup($db);

        $room = $room === 'gold' ? 'gold' : 'match';
        $boardSize = in_array($boardSize, $this->config['board_sizes'] ?? [3, 5, 9], true) ? $boardSize : 3;
        $bet = $room === 'match' ? (int)($this->config['match_bet'] ?? 10) : $bet;

        if ($room === 'gold' && !in_array($bet, $this->config['gold_bets'] ?? [10, 20, 30, 50, 100], true)) {
            throw new RuntimeException('Выберите доступную стоимость участия.');
        }

        $balanceKey = $room === 'gold' ? 'balance_gold' : 'balance_match';
        if ((int)($user[$balanceKey] ?? 0) < $bet) {
            throw new RuntimeException('Недостаточно коинов для участия.');
        }

        if (($user['status'] ?? 'idle') === 'playing' && !empty($user['current_game_id'])) {
            $gameId = (string)$user['current_game_id'];
            if (isset($db['games'][$gameId]) && ($db['games'][$gameId]['status'] ?? '') === 'active') {
                return ['game' => $this->publicGame($db['games'][$gameId], (string)$user['id'])];
            }
        }

        $userId = (string)$user['id'];

        // Один пользователь — одна запись в очереди.
        $db['queue'] = array_values(array_filter(
            $db['queue'] ?? [],
            fn($item) => (string)($item['user_id'] ?? '') !== $userId
        ));

        $opponentIndex = $this->findHumanOpponentIndex($db, $userId, $room, $bet, $boardSize);

        if ($opponentIndex !== null) {
            $opponentItem = $db['queue'][$opponentIndex];
            array_splice($db['queue'], $opponentIndex, 1);

            $opponentId = (string)$opponentItem['user_id'];
            $opponent =& $db['users'][$opponentId];

            if ((int)($opponent[$balanceKey] ?? 0) < $bet) {
                $opponent['status'] = 'idle';
                $opponent['current_game_id'] = null;
                return $this->startSearch($db, $user, $room, $bet, $boardSize);
            }

            $game = $this->createGame($db, $user, $opponent, $room, $bet, $boardSize);
            return ['game' => $this->publicGame($game, $userId)];
        }

        $user['status'] = 'searching';
        $user['current_game_id'] = null;

        $now = now_iso();
        $db['queue'][] = [
            'id' => make_id('queue'),
            'user_id' => $userId,
            'room' => $room,
            'bet' => $bet,
            'board_size' => $boardSize,
            'game_type' => 'tictactoe',
            'created_at' => $now,
            'updated_at' => $now,
        ];

        return ['queued' => true];
    }

    public function leaveSearch(array &$db, array &$user): void
    {
        $userId = (string)$user['id'];
        $db['queue'] = array_values(array_filter(
            $db['queue'] ?? [],
            fn($item) => (string)($item['user_id'] ?? '') !== $userId
        ));

        if (($user['status'] ?? '') === 'searching') {
            $user['status'] = 'idle';
            $user['current_game_id'] = null;
        }
    }

    public function surrenderGame(array &$db, array &$user, string $gameId): array
    {
        if (!isset($db['games'][$gameId])) {
            throw new RuntimeException('Игра не найдена.');
        }

        $game =& $db['games'][$gameId];
        $userId = (string)$user['id'];

        if (!in_array($userId, array_map('strval', $game['player_ids'] ?? []), true)) {
            throw new RuntimeException('Вы не участник этой игры.');
        }

        if (($game['status'] ?? '') === 'finished') {
            return $game;
        }

        if (($game['status'] ?? '') !== 'active') {
            throw new RuntimeException('Игра уже не активна.');
        }

        $winnerId = $this->otherPlayerId($game, $userId);
        $this->finishGame($db, $game, $winnerId, 'player_left', $userId);

        return $game;
    }

    public function findActiveGameForUser(array $db, string $userId): ?array
    {
        foreach ($db['games'] ?? [] as $game) {
            if (($game['status'] ?? '') === 'active' && in_array($userId, array_map('strval', $game['player_ids'] ?? []), true)) {
                return $game;
            }
        }
        return null;
    }

    public function makeMove(array &$db, array &$user, string $gameId, int $cell): array
    {
        if (!isset($db['games'][$gameId])) {
            throw new RuntimeException('Игра не найдена.');
        }

        $game =& $db['games'][$gameId];

        if (($game['status'] ?? '') === 'active' && $this->isTurnExpired($game)) {
            $loserId = (string)($game['turn'] ?? '');
            $winnerId = $this->otherPlayerId($game, $loserId);
            $this->finishGame($db, $game, $winnerId, 'timeout', $loserId);
            return $game;
        }

        if (($game['status'] ?? '') !== 'active') {
            return $game;
        }

        $userId = (string)$user['id'];
        if (!in_array($userId, array_map('strval', $game['player_ids'] ?? []), true)) {
            throw new RuntimeException('Вы не участник этой игры.');
        }

        if ($this->isBotId((string)($game['turn'] ?? ''))) {
            return $game;
        }

        if ((string)($game['turn'] ?? '') !== $userId) {
            throw new RuntimeException('Сейчас не ваш ход.');
        }

        $board = (string)$game['board'];
        if ($cell < 0 || $cell >= strlen($board) || $board[$cell] !== '-') {
            throw new RuntimeException('Клетка недоступна.');
        }

        $symbol = $game['symbols'][$userId] ?? null;
        if (!$symbol) {
            throw new RuntimeException('Вы не участник этой игры.');
        }

        $board[$cell] = $symbol;
        $game['board'] = $board;
        $game['last_move_at'] = now_iso();

        $winnerSymbol = $this->checkWinner($board, (int)$game['board_size']);
        if ($winnerSymbol !== null) {
            $winnerId = array_search($winnerSymbol, $game['symbols'], true);
            $this->finishGame($db, $game, (string)$winnerId, 'normal_win');
        } elseif (!str_contains($board, '-')) {
            $this->finishGame($db, $game, null, 'draw');
        } else {
            $game['turn'] = $this->otherPlayerId($game, $userId);
            $game['turn_started_at'] = now_iso();
            $game['updated_at'] = now_iso();

            if ($this->isBotId((string)$game['turn'])) {
                $game['bot_move_after_at'] = gmdate('c', time() + $this->botMoveDelaySec((int)$game['board_size']));
            }
        }

        return $game;
    }

    public function publicGame(array $game, string $viewerId): array
    {
        $players = [];
        foreach ($game['player_ids'] as $playerId) {
            $players[] = [
                'id' => (string)$playerId,
                'name' => $game['player_names'][(string)$playerId] ?? 'Игрок',
                'symbol' => $game['symbols'][(string)$playerId] ?? '?',
            ];
        }

        $timeLeft = $this->moveTimeoutSec();
        if (($game['status'] ?? '') === 'active') {
            $started = strtotime($game['turn_started_at'] ?? $game['last_move_at'] ?? $game['created_at'] ?? now_iso()) ?: time();
            $timeLeft = max(0, $this->moveTimeoutSec() - (time() - $started));
        }

        return [
            'id' => $game['id'],
            'room' => $game['room'],
            'room_name' => $game['room'] === 'gold' ? 'Gold-комната' : 'Матч-комната',
            'bet' => (int)$game['bet'],
            'board_size' => (int)$game['board_size'],
            'board' => $game['board'],
            'turn' => (string)($game['turn'] ?? ''),
            'players' => $players,
            'status' => $game['status'],
            'winner_id' => $game['winner_id'] ?? null,
            'loser_id' => $game['loser_id'] ?? null,
            'finish_reason' => $game['finish_reason'] ?? null,
            'payout' => $game['payout'] ?? null,
            'commission' => $game['commission'] ?? 0,
            'time_left' => $timeLeft,
            'move_timeout_sec' => $this->moveTimeoutSec(),
            'is_bot_game' => !empty($game['is_bot_game']),
        ];
    }

    private function findHumanOpponentIndex(array $db, string $userId, string $room, int $bet, int $boardSize): ?int
    {
        foreach ($db['queue'] ?? [] as $index => $item) {
            $opponentId = (string)($item['user_id'] ?? '');
            if ($opponentId === $userId || $opponentId === '' || !isset($db['users'][$opponentId])) {
                continue;
            }

            if (($item['room'] ?? '') !== $room
                || (int)($item['bet'] ?? 0) !== $bet
                || (int)($item['board_size'] ?? 0) !== $boardSize) {
                continue;
            }

            if (time() - $this->queueTimestamp($item) > $this->queueTimeoutSec()) {
                continue;
            }

            if (($db['users'][$opponentId]['status'] ?? '') !== 'searching') {
                continue;
            }

            return $index;
        }

        return null;
    }

    private function createGame(array &$db, array &$a, array &$b, string $room, int $bet, int $boardSize): array
    {
        $balanceKey = $room === 'gold' ? 'balance_gold' : 'balance_match';

        $a[$balanceKey] = (int)($a[$balanceKey] ?? 0) - $bet;
        $b[$balanceKey] = (int)($b[$balanceKey] ?? 0) - $bet;

        $a['status'] = 'playing';
        $b['status'] = 'playing';

        $gameId = make_id('game');
        $aId = (string)$a['id'];
        $bId = (string)$b['id'];

        $a['current_game_id'] = $gameId;
        $b['current_game_id'] = $gameId;

        $now = now_iso();

        $game = [
            'id' => $gameId,
            'game_type' => 'tictactoe',
            'room' => $room,
            'bet' => $bet,
            'bank' => $bet * 2,
            'board_size' => $boardSize,
            'board' => str_repeat('-', $boardSize * $boardSize),
            'player_ids' => [$aId, $bId],
            'player_names' => [
                $aId => $a['username'] ?: $a['first_name'],
                $bId => $b['username'] ?: $b['first_name'],
            ],
            'symbols' => [$aId => 'X', $bId => 'O'],
            'turn' => $aId,
            'status' => 'active',
            'winner_id' => null,
            'loser_id' => null,
            'finish_reason' => null,
            'payout_done' => false,
            'created_at' => $now,
            'updated_at' => $now,
            'last_move_at' => $now,
            'turn_started_at' => $now,
        ];

        $db['games'][$gameId] = $game;

        $this->addBalanceChange($db, $a, 'game_entry', $room, -$bet, $gameId, 'Участие в матче против ' . $this->playerNameForHistory($game, $bId), [
            'opponent_id' => $bId,
            'opponent_name' => $this->playerNameForHistory($game, $bId),
            'board_size' => $boardSize,
            'is_bot_game' => false,
        ]);
        $this->addBalanceChange($db, $b, 'game_entry', $room, -$bet, $gameId, 'Участие в матче против ' . $this->playerNameForHistory($game, $aId), [
            'opponent_id' => $aId,
            'opponent_name' => $this->playerNameForHistory($game, $aId),
            'board_size' => $boardSize,
            'is_bot_game' => false,
        ]);

        $db['transactions'][] = [
            'id' => make_id('tx'),
            'type' => 'game_start',
            'game_id' => $gameId,
            'room' => $room,
            'bet' => $bet,
            'players' => [$aId, $bId],
            'created_at' => $now,
        ];

        return $db['games'][$gameId];
    }

    private function createBotGame(array &$db, array &$user, int $bet, int $boardSize): array
    {
        $balanceKey = 'balance_match';
        if ((int)($user[$balanceKey] ?? 0) < $bet) {
            $user['status'] = 'idle';
            $user['current_game_id'] = null;
            throw new RuntimeException('Недостаточно коинов для участия.');
        }

        $user[$balanceKey] = (int)($user[$balanceKey] ?? 0) - $bet;
        $user['status'] = 'playing';

        $gameId = make_id('game');
        $userId = (string)$user['id'];
        $botId = 'bot_' . substr(make_id('leo'), 0, 14);
        $botProfile = $this->chooseBotProfile($user);
        $now = now_iso();

        $user['current_game_id'] = $gameId;

        $game = [
            'id' => $gameId,
            'game_type' => 'tictactoe',
            'room' => 'match',
            'bet' => $bet,
            'bank' => $bet * 2,
            'board_size' => $boardSize,
            'board' => str_repeat('-', $boardSize * $boardSize),
            'player_ids' => [$userId, $botId],
            'player_names' => [
                $userId => $user['username'] ?: $user['first_name'],
                $botId => $botProfile['name'],
            ],
            'symbols' => [$userId => 'X', $botId => 'O'],
            'turn' => $userId,
            'status' => 'active',
            'winner_id' => null,
            'loser_id' => null,
            'finish_reason' => null,
            'payout_done' => false,
            'is_bot_game' => true,
            'bot_id' => $botId,
            'bot_name' => $botProfile['name'],
            'bot_difficulty' => $botProfile['difficulty'],
            'created_at' => $now,
            'updated_at' => $now,
            'last_move_at' => $now,
            'turn_started_at' => $now,
        ];

        $db['games'][$gameId] = $game;

        $this->addBalanceChange($db, $user, 'game_entry', 'match', -$bet, $gameId, 'Участие в матче против ' . $botProfile['name'], [
            'opponent_id' => $botId,
            'opponent_name' => $botProfile['name'],
            'board_size' => $boardSize,
            'is_bot_game' => true,
            'bot_difficulty' => $botProfile['difficulty'],
        ]);

        $db['transactions'][] = [
            'id' => make_id('tx'),
            'type' => 'game_start',
            'game_id' => $gameId,
            'room' => 'match',
            'bet' => $bet,
            'players' => [$userId, $botId],
            'is_bot_game' => true,
            'bot_difficulty' => $botProfile['difficulty'],
            'created_at' => $now,
        ];

        return $db['games'][$gameId];
    }

    private function finishGame(array &$db, array &$game, ?string $winnerId, ?string $reason = null, ?string $loserId = null): void
    {
        // Железобетонная защита от повторных начислений по одному и тому же матчу.
        // Даже если будущая правка случайно повторно вызовет finishGame(), деньги второй раз не начислятся.
        if (!empty($game['payout_done'])) {
            $game['status'] = 'finished';
            $game['updated_at'] = now_iso();
            $this->releaseGamePlayers($db, $game);
            return;
        }

        if (($game['status'] ?? '') === 'finished') {
            return;
        }

        $room = $game['room'];
        $balanceKey = $room === 'gold' ? 'balance_gold' : 'balance_match';
        $bet = (int)$game['bet'];
        $playerCount = max(2, count($game['player_ids'] ?? []));
        $bank = $bet * $playerCount;
        $game['bank'] = $bank;
        $commission = 0;
        $payout = 0;
        $isBotGame = !empty($game['is_bot_game']);
        $botId = (string)($game['bot_id'] ?? '');

        if ($winnerId === null) {
            $reason = $reason ?: 'draw';
        } else {
            $reason = $reason ?: 'normal_win';
            $loserId = $loserId ?: $this->otherPlayerId($game, $winnerId);
        }

        $game['status'] = 'finished';
        $game['winner_id'] = $winnerId;
        $game['loser_id'] = $loserId;
        $game['finish_reason'] = $reason;
        $game['finished_at'] = now_iso();
        $game['updated_at'] = now_iso();

        if ($winnerId === null) {
            foreach ($game['player_ids'] as $playerId) {
                $pid = (string)$playerId;
                if (isset($db['users'][$pid])) {
                    $db['users'][$pid][$balanceKey] = (int)($db['users'][$pid][$balanceKey] ?? 0) + $bet;
                    $this->addBalanceChange($db, $db['users'][$pid], 'game_refund', $room, $bet, (string)$game['id'], 'Возврат коинов при ничьей', [
                        'finish_reason' => 'draw',
                        'is_bot_game' => $isBotGame,
                    ]);
                }
            }
            $game['payout'] = $bet;
            $game['commission'] = 0;
        } else {
            $commission = (int)ceil($bank * (float)($this->config['commission_rate'] ?? 0.10));
            $payout = max(0, $bank - $commission);

            if (isset($db['users'][$winnerId])) {
                $db['users'][$winnerId][$balanceKey] = (int)($db['users'][$winnerId][$balanceKey] ?? 0) + $payout;
                $this->addBalanceChange($db, $db['users'][$winnerId], 'game_win', $room, $payout, (string)$game['id'], 'Выигрыш за матч', [
                    'finish_reason' => $reason,
                    'loser_id' => $loserId,
                    'commission' => $commission,
                    'is_bot_game' => $isBotGame,
                    'bot_difficulty' => $game['bot_difficulty'] ?? null,
                ]);
            }

            $db['system']['fees_' . $room] = (int)($db['system']['fees_' . $room] ?? 0) + $commission;
            $game['payout'] = $payout;
            $game['commission'] = $commission;
        }

        foreach ($game['player_ids'] as $playerId) {
            $pid = (string)$playerId;
            if (!isset($db['users'][$pid])) {
                continue;
            }

            $db['users'][$pid]['status'] = 'idle';
            $db['users'][$pid]['current_game_id'] = null;
            $db['users'][$pid]['stats']['games_played'] = (int)($db['users'][$pid]['stats']['games_played'] ?? 0) + 1;

            if ($room === 'match') {
                $db['users'][$pid]['stats']['match_games_this_week'] = (int)($db['users'][$pid]['stats']['match_games_this_week'] ?? 0) + 1;
            }

            if ($room === 'gold') {
                $db['users'][$pid]['gold_wagered_total'] = (int)($db['users'][$pid]['gold_wagered_total'] ?? 0) + $bet;
            }

            if ($winnerId === null) {
                $db['users'][$pid]['stats']['draws'] = (int)($db['users'][$pid]['stats']['draws'] ?? 0) + 1;
            } elseif ($pid === $winnerId) {
                $db['users'][$pid]['stats']['wins'] = (int)($db['users'][$pid]['stats']['wins'] ?? 0) + 1;
            } else {
                $db['users'][$pid]['stats']['losses'] = (int)($db['users'][$pid]['stats']['losses'] ?? 0) + 1;
            }

            if ($isBotGame && $pid !== $botId) {
                $this->updateBotStats($db['users'][$pid], $winnerId, $pid);
            }
        }

        $game['payout_done'] = true;
        $game['payout_done_at'] = now_iso();

        $db['transactions'][] = [
            'id' => make_id('tx'),
            'type' => 'game_finish',
            'game_id' => $game['id'],
            'room' => $room,
            'winner_id' => $winnerId,
            'loser_id' => $loserId,
            'finish_reason' => $reason,
            'bank' => $bank,
            'commission' => $commission,
            'payout' => $payout,
            'is_bot_game' => $isBotGame,
            'bot_difficulty' => $game['bot_difficulty'] ?? null,
            'created_at' => now_iso(),
        ];
    }



    private function releaseGamePlayers(array &$db, array $game): void
    {
        foreach ($game['player_ids'] ?? [] as $playerId) {
            $pid = (string)$playerId;
            if (!isset($db['users'][$pid])) {
                continue;
            }

            if (($db['users'][$pid]['current_game_id'] ?? null) === ($game['id'] ?? null)
                || ($db['users'][$pid]['status'] ?? '') === 'playing') {
                $db['users'][$pid]['status'] = 'idle';
                $db['users'][$pid]['current_game_id'] = null;
            }
        }
    }

    private function addBalanceChange(
        array &$db,
        array $user,
        string $category,
        string $room,
        int $amount,
        string $gameId = '',
        string $description = '',
        array $extra = []
    ): void {
        $balanceKey = $room === 'gold' ? 'balance_gold' : 'balance_match';

        $tx = array_merge([
            'id' => make_id('tx'),
            'type' => 'balance_change',
            'category' => $category,
            'user_id' => (string)($user['id'] ?? ''),
            'username' => (string)($user['username'] ?? ''),
            'room' => $room,
            'amount' => $amount,
            'balance_after' => (int)($user[$balanceKey] ?? 0),
            'game_id' => $gameId,
            'description' => $description,
            'created_at' => now_iso(),
        ], $extra);

        $db['transactions'][] = $tx;
    }

    private function playerNameForHistory(array $game, string $playerId): string
    {
        return (string)($game['player_names'][$playerId] ?? ($playerId !== '' ? $playerId : 'Соперник'));
    }

    private function updateBotStats(array &$user, ?string $winnerId, string $humanId): void
    {
        $user['stats']['bot_games_played'] = (int)($user['stats']['bot_games_played'] ?? 0) + 1;

        if ($winnerId === null) {
            $user['stats']['bot_draws'] = (int)($user['stats']['bot_draws'] ?? 0) + 1;
            return;
        }

        if ((string)$winnerId === $humanId) {
            $user['stats']['bot_wins'] = (int)($user['stats']['bot_wins'] ?? 0) + 1;
            $user['stats']['bot_win_streak'] = (int)($user['stats']['bot_win_streak'] ?? 0) + 1;
            return;
        }

        $user['stats']['bot_losses'] = (int)($user['stats']['bot_losses'] ?? 0) + 1;
        $user['stats']['bot_win_streak'] = 0;
    }

    private function processBotTurns(array &$db): void
    {
        if (!isset($db['games']) || !is_array($db['games'])) {
            return;
        }

        foreach ($db['games'] as &$game) {
            if (($game['status'] ?? '') !== 'active' || empty($game['is_bot_game'])) {
                continue;
            }

            $botId = (string)($game['bot_id'] ?? '');
            if ($botId === '' || (string)($game['turn'] ?? '') !== $botId) {
                continue;
            }

            $after = strtotime($game['bot_move_after_at'] ?? '') ?: 0;
            if ($after > time()) {
                continue;
            }

            $this->makeBotMove($db, $game);
        }
        unset($game);
    }

    private function makeBotMove(array &$db, array &$game): void
    {
        $botId = (string)($game['bot_id'] ?? '');
        $humanId = $this->otherPlayerId($game, $botId);
        $board = (string)$game['board'];
        $size = (int)$game['board_size'];
        $botSymbol = (string)($game['symbols'][$botId] ?? 'O');
        $humanSymbol = (string)($game['symbols'][$humanId] ?? 'X');
        $difficulty = (string)($game['bot_difficulty'] ?? 'medium');

        $cell = $this->chooseBotMove($board, $size, $botSymbol, $humanSymbol, $difficulty);
        if ($cell < 0 || $cell >= strlen($board) || ($board[$cell] ?? '-') !== '-') {
            $empty = $this->emptyCells($board);
            if (!$empty) {
                $this->finishGame($db, $game, null, 'draw');
                return;
            }
            $cell = $empty[array_rand($empty)];
        }

        $board[$cell] = $botSymbol;
        $game['board'] = $board;
        $game['last_move_at'] = now_iso();
        unset($game['bot_move_after_at']);

        $winnerSymbol = $this->checkWinner($board, $size);
        if ($winnerSymbol !== null) {
            $winnerId = array_search($winnerSymbol, $game['symbols'], true);
            $this->finishGame($db, $game, (string)$winnerId, 'normal_win');
        } elseif (!str_contains($board, '-')) {
            $this->finishGame($db, $game, null, 'draw');
        } else {
            $game['turn'] = $humanId;
            $game['turn_started_at'] = now_iso();
            $game['updated_at'] = now_iso();
        }
    }

    private function chooseBotProfile(array $user): array
    {
        $names = ['Leo', 'Mia', 'Max', 'Nika', 'Alex', 'Sam', 'Eva', 'Noah', 'Rio', 'Kim', 'Dan', 'Tess'];
        $name = $names[random_int(0, count($names) - 1)];
        return ['name' => $name, 'difficulty' => $this->chooseBotDifficulty($user)];
    }

    private function chooseBotDifficulty(array $user): string
    {
        $stats = $user['stats'] ?? [];
        $games = (int)($stats['games_played'] ?? 0);
        $wins = (int)($stats['wins'] ?? 0);
        $botGames = (int)($stats['bot_games_played'] ?? 0);
        $botWins = (int)($stats['bot_wins'] ?? 0);
        $botStreak = (int)($stats['bot_win_streak'] ?? 0);

        $winRate = $games > 0 ? $wins / max(1, $games) : 0.0;
        $botWinRate = $botGames > 0 ? $botWins / max(1, $botGames) : 0.0;

        if ($botStreak >= 5 || ($botGames >= 8 && $botWinRate >= 0.70) || ($games >= 30 && $winRate >= 0.65)) {
            return $this->weightedDifficulty(['medium' => 10, 'hard' => 90]);
        }

        if ($botStreak >= 3 || ($games >= 20 && $winRate >= 0.55)) {
            return $this->weightedDifficulty(['medium' => 35, 'hard' => 65]);
        }

        if ($games < 5 && $botGames < 3) {
            return $this->weightedDifficulty(['easy' => 40, 'medium' => 40, 'hard' => 20]);
        }

        return $this->weightedDifficulty(['easy' => 25, 'medium' => 55, 'hard' => 20]);
    }

    private function weightedDifficulty(array $weights): string
    {
        $total = array_sum($weights);
        $roll = random_int(1, max(1, $total));
        $acc = 0;

        foreach ($weights as $difficulty => $weight) {
            $acc += (int)$weight;
            if ($roll <= $acc) {
                return (string)$difficulty;
            }
        }

        return 'medium';
    }

    private function chooseBotMove(string $board, int $size, string $botSymbol, string $humanSymbol, string $difficulty): int
    {
        $empty = $this->emptyCells($board);
        if (!$empty) {
            return -1;
        }

        if ($difficulty === 'easy' && random_int(1, 100) <= 45) {
            return $empty[array_rand($empty)];
        }

        if ($size === 3 && $difficulty === 'hard') {
            return $this->minimaxMove($board, $botSymbol, $humanSymbol);
        }

        $win = $this->findWinningMove($board, $size, $botSymbol);
        if ($win !== null) {
            return $win;
        }

        $block = $this->findWinningMove($board, $size, $humanSymbol);
        if ($block !== null && $difficulty !== 'easy') {
            return $block;
        }

        if ($block !== null && random_int(1, 100) <= 45) {
            return $block;
        }

        $ranked = $this->rankMoves($board, $size, $botSymbol, $humanSymbol, $difficulty);
        if (!$ranked) {
            return $empty[array_rand($empty)];
        }

        if ($difficulty === 'hard') {
            $top = array_slice($ranked, 0, min(2, count($ranked)));
            return $top[array_rand($top)]['cell'];
        }

        if ($difficulty === 'medium') {
            if (random_int(1, 100) <= 18) {
                $top = array_slice($ranked, 0, min(5, count($ranked)));
                return $top[array_rand($top)]['cell'];
            }
            return $ranked[0]['cell'];
        }

        $top = array_slice($ranked, 0, min(8, count($ranked)));
        return $top[array_rand($top)]['cell'];
    }

    private function findWinningMove(string $board, int $size, string $symbol): ?int
    {
        foreach ($this->emptyCells($board) as $cell) {
            $test = $board;
            $test[$cell] = $symbol;
            if ($this->checkWinner($test, $size) === $symbol) {
                return $cell;
            }
        }
        return null;
    }

    private function rankMoves(string $board, int $size, string $botSymbol, string $humanSymbol, string $difficulty): array
    {
        $moves = [];
        $center = ($size - 1) / 2;

        foreach ($this->emptyCells($board) as $cell) {
            $row = intdiv($cell, $size);
            $col = $cell % $size;
            $distance = abs($row - $center) + abs($col - $center);
            $centerBonus = max(0, $size - $distance) * 1.5;

            $score = $this->linePotentialScore($board, $size, $cell, $botSymbol) * ($difficulty === 'hard' ? 2.4 : 1.9)
                + $this->linePotentialScore($board, $size, $cell, $humanSymbol) * ($difficulty === 'hard' ? 2.1 : 1.5)
                + $centerBonus
                + random_int(0, $difficulty === 'hard' ? 3 : 9);

            $moves[] = ['cell' => $cell, 'score' => $score];
        }

        usort($moves, fn($a, $b) => $b['score'] <=> $a['score']);
        return $moves;
    }

    private function linePotentialScore(string $board, int $size, int $cell, string $symbol): float
    {
        $need = $size === 3 ? 3 : ($size === 5 ? 4 : 5);
        $row = intdiv($cell, $size);
        $col = $cell % $size;
        $dirs = [[1,0],[0,1],[1,1],[1,-1]];
        $score = 0.0;

        foreach ($dirs as [$dr, $dc]) {
            $count = 1;
            $open = 0;

            foreach ([[1, 1], [-1, -1]] as [$direction]) {
                $step = 1;
                while (true) {
                    $nr = $row + $dr * $step * $direction;
                    $nc = $col + $dc * $step * $direction;
                    if ($nr < 0 || $nr >= $size || $nc < 0 || $nc >= $size) {
                        break;
                    }

                    $value = $board[$nr * $size + $nc] ?? '-';
                    if ($value === $symbol) {
                        $count++;
                        $step++;
                        continue;
                    }

                    if ($value === '-') {
                        $open++;
                    }
                    break;
                }
            }

            if ($count >= $need) {
                $score += 10000;
            } else {
                $score += ($count * $count * 12) + ($open * 4);
            }
        }

        return $score;
    }

    private function minimaxMove(string $board, string $botSymbol, string $humanSymbol): int
    {
        $bestScore = -999;
        $bestMoves = [];

        foreach ($this->emptyCells($board) as $cell) {
            $test = $board;
            $test[$cell] = $botSymbol;
            $score = $this->minimax($test, false, $botSymbol, $humanSymbol, 0);
            if ($score > $bestScore) {
                $bestScore = $score;
                $bestMoves = [$cell];
            } elseif ($score === $bestScore) {
                $bestMoves[] = $cell;
            }
        }

        return $bestMoves ? $bestMoves[array_rand($bestMoves)] : -1;
    }

    private function minimax(string $board, bool $maximizing, string $botSymbol, string $humanSymbol, int $depth): int
    {
        $winner = $this->checkWinner($board, 3);
        if ($winner === $botSymbol) {
            return 10 - $depth;
        }
        if ($winner === $humanSymbol) {
            return $depth - 10;
        }
        if (!str_contains($board, '-')) {
            return 0;
        }

        if ($maximizing) {
            $best = -999;
            foreach ($this->emptyCells($board) as $cell) {
                $test = $board;
                $test[$cell] = $botSymbol;
                $best = max($best, $this->minimax($test, false, $botSymbol, $humanSymbol, $depth + 1));
            }
            return $best;
        }

        $best = 999;
        foreach ($this->emptyCells($board) as $cell) {
            $test = $board;
            $test[$cell] = $humanSymbol;
            $best = min($best, $this->minimax($test, true, $botSymbol, $humanSymbol, $depth + 1));
        }
        return $best;
    }

    private function emptyCells(string $board): array
    {
        $cells = [];
        for ($i = 0, $len = strlen($board); $i < $len; $i++) {
            if ($board[$i] === '-') {
                $cells[] = $i;
            }
        }
        return $cells;
    }

    private function isTurnExpired(array $game): bool
    {
        if (($game['status'] ?? '') !== 'active') {
            return false;
        }

        $started = strtotime($game['turn_started_at'] ?? $game['last_move_at'] ?? $game['created_at'] ?? '') ?: 0;
        if ($started <= 0) {
            return false;
        }

        return time() - $started >= $this->moveTimeoutSec();
    }

    private function queueTimestamp(array $item): int
    {
        return strtotime($item['updated_at'] ?? $item['created_at'] ?? '1970-01-01') ?: 0;
    }

    private function botAfterSec(): int
    {
        $value = (int)($this->config['match_bot_after_sec'] ?? 15);
        if ($value <= 0 || $value > 30) {
            return 15;
        }
        return max(5, $value);
    }

    private function botMoveDelaySec(int $boardSize): int
    {
        if ($boardSize >= 9) {
            return random_int(2, 4);
        }
        return random_int(1, 3);
    }

    private function queueTimeoutSec(): int
    {
        $value = (int)($this->config['queue_timeout_sec'] ?? 25);
        if ($value <= 0 || $value > 25) {
            return 25;
        }
        return max(10, $value);
    }

    private function moveTimeoutSec(): int
    {
        $value = (int)($this->config['move_timeout_sec'] ?? 60);
        if ($value <= 0 || $value > 60) {
            return 60;
        }
        return max(20, $value);
    }

    private function otherPlayerId(array $game, string $userId): string
    {
        foreach ($game['player_ids'] as $playerId) {
            if ((string)$playerId !== $userId) {
                return (string)$playerId;
            }
        }
        return $userId;
    }

    private function isBotId(string $id): bool
    {
        return str_starts_with($id, 'bot_');
    }

    private function checkWinner(string $board, int $size): ?string
    {
        $need = $size === 3 ? 3 : ($size === 5 ? 4 : 5);
        $dirs = [[1,0],[0,1],[1,1],[1,-1]];

        for ($r = 0; $r < $size; $r++) {
            for ($c = 0; $c < $size; $c++) {
                $symbol = $board[$r * $size + $c] ?? '-';
                if ($symbol === '-') {
                    continue;
                }

                foreach ($dirs as [$dr, $dc]) {
                    $count = 1;

                    for ($step = 1; $step < $need; $step++) {
                        $nr = $r + $dr * $step;
                        $nc = $c + $dc * $step;

                        if ($nr < 0 || $nr >= $size || $nc < 0 || $nc >= $size) {
                            break;
                        }

                        if (($board[$nr * $size + $nc] ?? '-') !== $symbol) {
                            break;
                        }

                        $count++;
                    }

                    if ($count >= $need) {
                        return $symbol;
                    }
                }
            }
        }

        return null;
    }
}
