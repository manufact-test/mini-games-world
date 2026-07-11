<?php
declare(strict_types=1);

final class FourInARowService
{
    private const COLUMNS = 7;
    private const ROWS = 6;
    private const CELLS = self::COLUMNS * self::ROWS;

    public function __construct(
        private array $config,
        private GameSettlementService $settlement,
        private ?FourInARowBotService $bot = null
    ) {
        $this->bot ??= new FourInARowBotService();
    }

    public function initializeGame(array &$game): void
    {
        $game['game_type'] = 'four_in_a_row';
        $game['board_size'] = self::COLUMNS;
        $game['board_columns'] = self::COLUMNS;
        $game['board_rows'] = self::ROWS;
        $game['connect_length'] = 4;

        $validBoard = is_string($game['board'] ?? null) && strlen((string)$game['board']) === self::CELLS;
        if (!empty($game['four_in_a_row_initialized']) && $validBoard) {
            return;
        }

        $playerIds = array_values(array_map('strval', $game['player_ids'] ?? []));
        if (count($playerIds) < 2) {
            throw new RuntimeException('Для игры «4 в ряд» нужны два игрока.');
        }

        $first = random_int(0, 1);
        $yellowId = $playerIds[$first];
        $redId = $playerIds[1 - $first];
        $now = now_iso();

        $game['board'] = str_repeat('-', self::CELLS);
        $game['symbols'] = [
            $yellowId => 'Y',
            $redId => 'R',
        ];
        $game['turn'] = $yellowId;
        $game['winning_cells'] = [];
        $game['last_move'] = null;
        $game['move_count'] = 0;
        $game['turn_started_at'] = $now;
        $game['last_move_at'] = $now;
        $game['updated_at'] = $now;
        $game['four_in_a_row_initialized'] = true;
        $game['engine_version'] = 1;

        if (!empty($game['is_bot_game'])) {
            $botId = (string)($game['bot_id'] ?? '');
            if ($botId !== '' && $yellowId === $botId) {
                $game['bot_move_after_at'] = gmdate('c', time() + $this->botMoveDelaySec());
            } else {
                unset($game['bot_move_after_at']);
            }
        }
    }

    public function cleanup(array &$db): void
    {
        if (!isset($db['games']) || !is_array($db['games'])) {
            return;
        }

        foreach ($db['games'] as &$game) {
            if (!is_array($game) || (string)($game['game_type'] ?? '') !== 'four_in_a_row') {
                continue;
            }

            $this->initializeGame($game);
            if (($game['status'] ?? '') !== 'active') {
                continue;
            }

            if ($this->isTurnExpired($game)) {
                $loserId = (string)($game['turn'] ?? '');
                $winnerId = $this->otherPlayerId($game, $loserId);
                $this->settlement->finish($db, $game, $winnerId, 'timeout', $loserId);
                continue;
            }

            if (empty($game['is_bot_game'])) {
                continue;
            }

            $botId = (string)($game['bot_id'] ?? '');
            if ($botId === '' || (string)($game['turn'] ?? '') !== $botId) {
                continue;
            }

            $after = strtotime((string)($game['bot_move_after_at'] ?? '')) ?: 0;
            if ($after > time()) {
                continue;
            }

            $this->makeBotMove($db, $game);
        }
        unset($game);
    }

    public function dropDisc(array &$db, array &$user, string $gameId, int $column): array
    {
        if (!isset($db['games'][$gameId]) || !is_array($db['games'][$gameId])) {
            throw new RuntimeException('Игра не найдена.');
        }

        $game =& $db['games'][$gameId];
        $this->initializeGame($game);

        if (($game['status'] ?? '') !== 'active') {
            return $game;
        }

        $userId = (string)($user['id'] ?? '');
        if (!in_array($userId, array_map('strval', $game['player_ids'] ?? []), true)) {
            throw new RuntimeException('Вы не участник этой игры.');
        }

        if ((string)($game['turn'] ?? '') !== $userId) {
            throw new RuntimeException('Сейчас не ваш ход.');
        }

        if ($column < 0 || $column >= self::COLUMNS) {
            throw new RuntimeException('Выберите доступный столбец.');
        }

        $symbol = (string)($game['symbols'][$userId] ?? '');
        if ($symbol !== 'Y' && $symbol !== 'R') {
            throw new RuntimeException('Игровая фишка не определена.');
        }

        $board = (string)$game['board'];
        $cell = $this->lowestEmptyCell($board, $column);
        if ($cell === null) {
            throw new RuntimeException('Этот столбец уже заполнен.');
        }

        $board[$cell] = $symbol;
        $game['board'] = $board;
        $game['last_move'] = $cell;
        $game['move_count'] = (int)($game['move_count'] ?? 0) + 1;
        $game['last_move_at'] = now_iso();
        $game['updated_at'] = now_iso();
        unset($game['bot_move_after_at']);

        $winningCells = $this->winningCells($board, $symbol);
        if ($winningCells !== []) {
            $game['winning_cells'] = $winningCells;
            $this->settlement->finish($db, $game, $userId, 'normal_win');
            return $game;
        }

        if (!str_contains($board, '-')) {
            $game['winning_cells'] = [];
            $this->settlement->finish($db, $game, null, 'draw');
            return $game;
        }

        $nextPlayerId = $this->otherPlayerId($game, $userId);
        $game['turn'] = $nextPlayerId;
        $game['turn_started_at'] = now_iso();

        if (!empty($game['is_bot_game']) && $nextPlayerId === (string)($game['bot_id'] ?? '')) {
            $game['bot_move_after_at'] = gmdate('c', time() + $this->botMoveDelaySec());
        }

        return $game;
    }

    public function surrender(array &$db, array &$user, string $gameId): array
    {
        if (!isset($db['games'][$gameId]) || !is_array($db['games'][$gameId])) {
            throw new RuntimeException('Игра не найдена.');
        }

        $game =& $db['games'][$gameId];
        $this->initializeGame($game);
        $userId = (string)($user['id'] ?? '');

        if (!in_array($userId, array_map('strval', $game['player_ids'] ?? []), true)) {
            throw new RuntimeException('Вы не участник этой игры.');
        }

        if (($game['status'] ?? '') === 'finished') {
            return $game;
        }

        $winnerId = $this->otherPlayerId($game, $userId);
        $this->settlement->finish($db, $game, $winnerId, 'player_left', $userId);
        return $game;
    }

    public function publicGame(array $game, string $viewerId): array
    {
        $this->initializeGame($game);
        $players = [];
        foreach ($game['player_ids'] ?? [] as $playerId) {
            $pid = (string)$playerId;
            $players[] = [
                'id' => $pid,
                'name' => (string)($game['player_names'][$pid] ?? 'Игрок'),
                'symbol' => (string)($game['symbols'][$pid] ?? '?'),
            ];
        }

        $timeLeft = $this->moveTimeoutSec();
        if (($game['status'] ?? '') === 'active') {
            $started = strtotime((string)($game['turn_started_at'] ?? $game['last_move_at'] ?? $game['created_at'] ?? now_iso())) ?: time();
            $timeLeft = max(0, $this->moveTimeoutSec() - (time() - $started));
        }

        return [
            'id' => (string)($game['id'] ?? ''),
            'room' => (string)($game['room'] ?? 'match'),
            'room_name' => ($game['room'] ?? 'match') === 'gold' ? 'Gold-комната' : 'Матч-комната',
            'bet' => (int)($game['bet'] ?? 0),
            'board_size' => self::COLUMNS,
            'board_columns' => self::COLUMNS,
            'board_rows' => self::ROWS,
            'board' => (string)$game['board'],
            'turn' => (string)($game['turn'] ?? ''),
            'players' => $players,
            'status' => (string)($game['status'] ?? 'active'),
            'winner_id' => $game['winner_id'] ?? null,
            'loser_id' => $game['loser_id'] ?? null,
            'finish_reason' => $game['finish_reason'] ?? null,
            'payout' => $game['payout'] ?? null,
            'commission' => (int)($game['commission'] ?? 0),
            'time_left' => $timeLeft,
            'move_timeout_sec' => $this->moveTimeoutSec(),
            'is_bot_game' => !empty($game['is_bot_game']),
            'winning_cells' => array_values(array_map('intval', $game['winning_cells'] ?? [])),
            'last_move' => isset($game['last_move']) ? (int)$game['last_move'] : null,
        ];
    }

    private function makeBotMove(array &$db, array &$game): void
    {
        $botId = (string)($game['bot_id'] ?? '');
        $humanId = $this->otherPlayerId($game, $botId);
        $board = (string)$game['board'];
        $botSymbol = (string)($game['symbols'][$botId] ?? 'R');
        $humanSymbol = (string)($game['symbols'][$humanId] ?? 'Y');
        $difficulty = (string)($game['bot_difficulty'] ?? 'medium');

        $column = $this->bot->chooseColumn($board, $botSymbol, $humanSymbol, $difficulty);
        if ($column === null) {
            $this->settlement->finish($db, $game, null, 'draw');
            return;
        }

        $cell = $this->lowestEmptyCell($board, $column);
        if ($cell === null) {
            $available = $this->availableColumns($board);
            if ($available === []) {
                $this->settlement->finish($db, $game, null, 'draw');
                return;
            }
            $column = $available[array_rand($available)];
            $cell = $this->lowestEmptyCell($board, $column);
        }

        if ($cell === null) {
            $this->settlement->finish($db, $game, null, 'draw');
            return;
        }

        $board[$cell] = $botSymbol;
        $game['board'] = $board;
        $game['last_move'] = $cell;
        $game['move_count'] = (int)($game['move_count'] ?? 0) + 1;
        $game['last_move_at'] = now_iso();
        $game['updated_at'] = now_iso();
        unset($game['bot_move_after_at']);

        $winningCells = $this->winningCells($board, $botSymbol);
        if ($winningCells !== []) {
            $game['winning_cells'] = $winningCells;
            $this->settlement->finish($db, $game, $botId, 'normal_win', $humanId);
            return;
        }

        if (!str_contains($board, '-')) {
            $game['winning_cells'] = [];
            $this->settlement->finish($db, $game, null, 'draw');
            return;
        }

        $game['turn'] = $humanId;
        $game['turn_started_at'] = now_iso();
    }

    private function winningCells(string $board, string $symbol): array
    {
        foreach ([[0, 1], [1, 0], [1, 1], [1, -1]] as [$dr, $dc]) {
            for ($row = 0; $row < self::ROWS; $row++) {
                for ($col = 0; $col < self::COLUMNS; $col++) {
                    $cells = [];
                    for ($step = 0; $step < 4; $step++) {
                        $r = $row + ($dr * $step);
                        $c = $col + ($dc * $step);
                        if ($r < 0 || $r >= self::ROWS || $c < 0 || $c >= self::COLUMNS) {
                            $cells = [];
                            break;
                        }
                        $index = $r * self::COLUMNS + $c;
                        if (($board[$index] ?? '-') !== $symbol) {
                            $cells = [];
                            break;
                        }
                        $cells[] = $index;
                    }
                    if (count($cells) === 4) {
                        return $cells;
                    }
                }
            }
        }
        return [];
    }

    private function lowestEmptyCell(string $board, int $column): ?int
    {
        for ($row = self::ROWS - 1; $row >= 0; $row--) {
            $index = $row * self::COLUMNS + $column;
            if (($board[$index] ?? '-') === '-') {
                return $index;
            }
        }
        return null;
    }

    private function availableColumns(string $board): array
    {
        $columns = [];
        for ($column = 0; $column < self::COLUMNS; $column++) {
            if (($board[$column] ?? '-') === '-') {
                $columns[] = $column;
            }
        }
        return $columns;
    }

    private function otherPlayerId(array $game, string $userId): string
    {
        foreach ($game['player_ids'] ?? [] as $playerId) {
            if ((string)$playerId !== $userId) {
                return (string)$playerId;
            }
        }
        return $userId;
    }

    private function isTurnExpired(array $game): bool
    {
        $started = strtotime((string)($game['turn_started_at'] ?? $game['last_move_at'] ?? $game['created_at'] ?? '')) ?: 0;
        return $started > 0 && time() - $started >= $this->moveTimeoutSec();
    }

    private function moveTimeoutSec(): int
    {
        $value = (int)($this->config['move_timeout_sec'] ?? 60);
        if ($value <= 0 || $value > 60) {
            return 60;
        }
        return max(20, $value);
    }

    private function botMoveDelaySec(): int
    {
        return random_int(1, 3);
    }
}
