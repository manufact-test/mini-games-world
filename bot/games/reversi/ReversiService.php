<?php
declare(strict_types=1);

final class ReversiService
{
    private const ALLOWED_SIZES = [6, 8, 10];
    private const DEFAULT_SIZE = 8;
    private const DIRECTIONS = [
        [-1, -1], [-1, 0], [-1, 1],
        [0, -1],           [0, 1],
        [1, -1],  [1, 0],  [1, 1],
    ];

    public function __construct(
        private array $config,
        private GameSettlementService $settlement,
        private ?ReversiBotService $bot = null
    ) {
        $this->bot ??= new ReversiBotService();
    }

    public function initializeGame(array &$game): void
    {
        $size = $this->boardSizeForGame($game);
        $cells = $size * $size;

        $game['game_type'] = 'reversi';
        $game['board_size'] = $size;
        $game['board_columns'] = $size;
        $game['board_rows'] = $size;

        $validBoard = is_string($game['board'] ?? null)
            && strlen((string)$game['board']) === $cells
            && $this->hasValidSymbols((string)$game['board']);
        $validSides = isset($game['reversi_sides']) && is_array($game['reversi_sides']);
        if (!empty($game['reversi_initialized']) && $validBoard && $validSides) {
            return;
        }

        $playerIds = array_values(array_map('strval', $game['player_ids'] ?? []));
        if (count($playerIds) < 2) {
            throw new RuntimeException('Для Реверси нужны два игрока.');
        }

        if (random_int(0, 1) === 1) {
            [$playerIds[0], $playerIds[1]] = [$playerIds[1], $playerIds[0]];
        }

        $blackId = $playerIds[0];
        $whiteId = $playerIds[1];
        $now = now_iso();

        $game['board'] = $this->initialBoard($size);
        $game['reversi_sides'] = [
            $blackId => 'black',
            $whiteId => 'white',
        ];
        $game['symbols'] = [
            $blackId => 'B',
            $whiteId => 'W',
        ];
        $game['turn'] = $blackId;
        $game['reversi_last_move'] = null;
        $game['reversi_last_flipped_cells'] = [];
        $game['reversi_last_passed_player_id'] = null;
        $game['reversi_pass_sequence'] = 0;
        $game['reversi_move_count'] = 0;
        $game['reversi_final_counts'] = null;
        $game['turn_started_at'] = $now;
        $game['last_move_at'] = $now;
        $game['updated_at'] = $now;
        $game['reversi_initialized'] = true;
        $game['engine_version'] = 1;

        $this->scheduleBotIfNeeded($game);
    }

    public function cleanup(array &$db): void
    {
        if (!isset($db['games']) || !is_array($db['games'])) return;

        foreach ($db['games'] as &$game) {
            if (!is_array($game) || (string)($game['game_type'] ?? '') !== 'reversi') continue;

            $this->initializeGame($game);
            if (($game['status'] ?? '') !== 'active') continue;

            $this->resolveAutomaticPassOrFinish($db, $game);
            if (($game['status'] ?? '') !== 'active') continue;

            if ($this->isTurnExpired($game)) {
                $loserId = (string)($game['turn'] ?? '');
                $winnerId = $this->otherPlayerId($game, $loserId);
                $this->settlement->finish($db, $game, $winnerId, 'timeout', $loserId);
                continue;
            }

            if (empty($game['is_bot_game'])) continue;
            $botId = (string)($game['bot_id'] ?? '');
            if ($botId === '' || (string)($game['turn'] ?? '') !== $botId) continue;

            $after = strtotime((string)($game['bot_move_after_at'] ?? '')) ?: 0;
            if ($after > time()) continue;

            $this->makeBotMove($db, $game);
        }
        unset($game);
    }

    public function applyAction(array &$db, array &$user, string $gameId, array $action): array
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
        if (($game['status'] ?? '') !== 'active') return $game;

        $type = trim((string)($action['type'] ?? 'cell'));
        if (!in_array($type, ['cell', 'place'], true)) {
            throw new RuntimeException('Некорректное действие для Реверси.');
        }

        $cell = filter_var($action['cell'] ?? null, FILTER_VALIDATE_INT);
        if ($cell === false) {
            throw new RuntimeException('Выберите клетку для хода.');
        }

        return $this->performMove($db, $game, $userId, (int)$cell);
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
        if (($game['status'] ?? '') === 'finished') return $game;

        $winnerId = $this->otherPlayerId($game, $userId);
        $this->settlement->finish($db, $game, $winnerId, 'player_left', $userId);
        return $game;
    }

    public function publicGame(array $game, string $viewerId): array
    {
        $this->initializeGame($game);
        $size = $this->boardSizeForGame($game);
        $board = $this->normalizeBoard((string)($game['board'] ?? ''), $size);
        $viewerSide = $this->sideForPlayer($game, $viewerId);
        $viewerSymbol = $viewerSide === 'black' ? 'B' : 'W';
        $opponentSymbol = $viewerSymbol === 'B' ? 'W' : 'B';
        $isViewerTurn = ($game['status'] ?? '') === 'active' && (string)($game['turn'] ?? '') === $viewerId;
        $legalMoves = $isViewerTurn
            ? $this->legalMovesForSymbol($board, $size, $viewerSymbol, $opponentSymbol)
            : [];

        $players = [];
        foreach (array_values(array_map('strval', $game['player_ids'] ?? [])) as $playerId) {
            $side = $this->sideForPlayer($game, $playerId);
            $players[] = [
                'id' => $playerId,
                'name' => (string)($game['player_names'][$playerId] ?? 'Игрок'),
                'side' => $side,
                'symbol' => $side === 'black' ? 'B' : 'W',
            ];
        }

        return [
            'id' => (string)($game['id'] ?? ''),
            'room' => (string)($game['room'] ?? 'match'),
            'room_name' => ($game['room'] ?? 'match') === 'gold' ? 'Gold-комната' : 'Матч-комната',
            'bet' => (int)($game['bet'] ?? 0),
            'board_size' => $size,
            'board_columns' => $size,
            'board_rows' => $size,
            'board' => $board,
            'turn' => (string)($game['turn'] ?? ''),
            'players' => $players,
            'status' => (string)($game['status'] ?? 'active'),
            'winner_id' => $game['winner_id'] ?? null,
            'loser_id' => $game['loser_id'] ?? null,
            'finish_reason' => $game['finish_reason'] ?? null,
            'payout' => $game['payout'] ?? null,
            'commission' => (int)($game['commission'] ?? 0),
            'time_left' => $this->timeLeft($game),
            'move_timeout_sec' => $this->moveTimeoutSec(),
            'is_bot_game' => !empty($game['is_bot_game']),
            'viewer_side' => $viewerSide,
            'legal_moves' => array_map(
                static fn(array $move): array => [
                    'cell' => (int)$move['cell'],
                    'flips' => count($move['flips'] ?? []),
                ],
                array_values($legalMoves)
            ),
            'last_move' => is_array($game['reversi_last_move'] ?? null) ? $game['reversi_last_move'] : null,
            'last_flipped_cells' => array_values(array_map('intval', $game['reversi_last_flipped_cells'] ?? [])),
            'last_passed_player_id' => $game['reversi_last_passed_player_id'] ?? null,
            'pass_sequence' => (int)($game['reversi_pass_sequence'] ?? 0),
            'move_count' => (int)($game['reversi_move_count'] ?? 0),
            'black_count' => substr_count($board, 'B'),
            'white_count' => substr_count($board, 'W'),
            'final_counts' => is_array($game['reversi_final_counts'] ?? null)
                ? $game['reversi_final_counts']
                : null,
        ];
    }

    private function performMove(array &$db, array &$game, string $playerId, int $cell): array
    {
        if ((string)($game['turn'] ?? '') !== $playerId) {
            throw new RuntimeException('Сейчас ход соперника.');
        }

        $size = $this->boardSizeForGame($game);
        if ($cell < 0 || $cell >= $size * $size) {
            throw new RuntimeException('Выберите клетку на поле.');
        }

        $side = $this->sideForPlayer($game, $playerId);
        $symbol = $side === 'black' ? 'B' : 'W';
        $opponentSymbol = $symbol === 'B' ? 'W' : 'B';
        $board = $this->normalizeBoard((string)($game['board'] ?? ''), $size);
        $flips = $this->flipsForCell($board, $size, $cell, $symbol, $opponentSymbol);
        if ($flips === []) {
            throw new RuntimeException('Сюда нельзя поставить фишку. Выберите подсвеченную клетку.');
        }

        $board[$cell] = $symbol;
        foreach ($flips as $flippedCell) $board[$flippedCell] = $symbol;

        $now = now_iso();
        $game['board'] = $board;
        $game['reversi_last_move'] = [
            'cell' => $cell,
            'player_id' => $playerId,
            'side' => $side,
            'flipped' => count($flips),
        ];
        $game['reversi_last_flipped_cells'] = array_values($flips);
        $game['reversi_last_passed_player_id'] = null;
        $game['reversi_move_count'] = (int)($game['reversi_move_count'] ?? 0) + 1;
        $game['last_move_at'] = $now;
        $game['updated_at'] = $now;
        unset($game['bot_move_after_at']);

        if (!str_contains($board, '-')) {
            $this->finishByCount($db, $game);
            return $game;
        }

        $nextPlayerId = $this->otherPlayerId($game, $playerId);
        $nextSymbol = $this->symbolForPlayer($game, $nextPlayerId);
        $nextOpponent = $nextSymbol === 'B' ? 'W' : 'B';
        $nextMoves = $this->legalMovesForSymbol($board, $size, $nextSymbol, $nextOpponent);

        if ($nextMoves !== []) {
            $game['turn'] = $nextPlayerId;
            $game['turn_started_at'] = $now;
            $this->scheduleBotIfNeeded($game);
            return $game;
        }

        $currentMoves = $this->legalMovesForSymbol($board, $size, $symbol, $opponentSymbol);
        if ($currentMoves === []) {
            $this->finishByCount($db, $game);
            return $game;
        }

        $game['turn'] = $playerId;
        $game['turn_started_at'] = $now;
        $game['reversi_last_passed_player_id'] = $nextPlayerId;
        $game['reversi_pass_sequence'] = (int)($game['reversi_pass_sequence'] ?? 0) + 1;
        $this->scheduleBotIfNeeded($game);
        return $game;
    }

    private function makeBotMove(array &$db, array &$game): void
    {
        $botId = (string)($game['bot_id'] ?? '');
        if ($botId === '' || ($game['status'] ?? '') !== 'active' || (string)($game['turn'] ?? '') !== $botId) {
            return;
        }

        $size = $this->boardSizeForGame($game);
        $board = $this->normalizeBoard((string)($game['board'] ?? ''), $size);
        $symbol = $this->symbolForPlayer($game, $botId);
        $opponent = $symbol === 'B' ? 'W' : 'B';
        $moves = $this->legalMovesForSymbol($board, $size, $symbol, $opponent);
        if ($moves === []) {
            $this->resolveAutomaticPassOrFinish($db, $game);
            return;
        }

        $difficulty = (string)($game['bot_difficulty'] ?? 'medium');
        $cell = $this->bot->chooseCell($board, $size, $symbol, $opponent, $difficulty);
        $this->performMove($db, $game, $botId, $cell);
    }

    private function resolveAutomaticPassOrFinish(array &$db, array &$game): void
    {
        if (($game['status'] ?? '') !== 'active') return;

        $size = $this->boardSizeForGame($game);
        $board = $this->normalizeBoard((string)($game['board'] ?? ''), $size);
        $currentId = (string)($game['turn'] ?? '');
        if ($currentId === '') return;

        $currentSymbol = $this->symbolForPlayer($game, $currentId);
        $currentOpponent = $currentSymbol === 'B' ? 'W' : 'B';
        if ($this->legalMovesForSymbol($board, $size, $currentSymbol, $currentOpponent) !== []) return;

        $otherId = $this->otherPlayerId($game, $currentId);
        $otherSymbol = $this->symbolForPlayer($game, $otherId);
        $otherOpponent = $otherSymbol === 'B' ? 'W' : 'B';
        if ($this->legalMovesForSymbol($board, $size, $otherSymbol, $otherOpponent) === []) {
            $this->finishByCount($db, $game);
            return;
        }

        $game['turn'] = $otherId;
        $game['turn_started_at'] = now_iso();
        $game['updated_at'] = now_iso();
        $game['reversi_last_passed_player_id'] = $currentId;
        $game['reversi_pass_sequence'] = (int)($game['reversi_pass_sequence'] ?? 0) + 1;
        $this->scheduleBotIfNeeded($game);
    }

    private function finishByCount(array &$db, array &$game): void
    {
        $size = $this->boardSizeForGame($game);
        $board = $this->normalizeBoard((string)($game['board'] ?? ''), $size);
        $black = substr_count($board, 'B');
        $white = substr_count($board, 'W');
        $game['reversi_final_counts'] = ['black' => $black, 'white' => $white];
        unset($game['bot_move_after_at']);

        if ($black === $white) {
            $this->settlement->finish($db, $game, null, 'draw');
            return;
        }

        $winnerSide = $black > $white ? 'black' : 'white';
        $winnerId = $this->playerIdForSide($game, $winnerSide);
        $loserId = $this->otherPlayerId($game, $winnerId);
        $this->settlement->finish($db, $game, $winnerId, 'normal_win', $loserId);
    }

    private function legalMovesForSymbol(string $board, int $size, string $symbol, string $opponent): array
    {
        $moves = [];
        for ($cell = 0; $cell < $size * $size; $cell++) {
            if (($board[$cell] ?? '-') !== '-') continue;
            $flips = $this->flipsForCell($board, $size, $cell, $symbol, $opponent);
            if ($flips !== []) $moves[] = ['cell' => $cell, 'flips' => $flips];
        }
        return $moves;
    }

    private function flipsForCell(
        string $board,
        int $size,
        int $cell,
        string $symbol,
        string $opponent
    ): array {
        if ($cell < 0 || $cell >= $size * $size || ($board[$cell] ?? '-') !== '-') return [];

        $row = intdiv($cell, $size);
        $col = $cell % $size;
        $flips = [];
        foreach (self::DIRECTIONS as [$dr, $dc]) {
            $line = [];
            $r = $row + $dr;
            $c = $col + $dc;
            while ($this->inside($r, $c, $size)) {
                $index = $r * $size + $c;
                $value = (string)($board[$index] ?? '-');
                if ($value === $opponent) {
                    $line[] = $index;
                    $r += $dr;
                    $c += $dc;
                    continue;
                }
                if ($value === $symbol && $line !== []) {
                    foreach ($line as $flippedCell) $flips[] = $flippedCell;
                }
                break;
            }
        }
        return array_values(array_unique($flips));
    }

    private function initialBoard(int $size): string
    {
        $board = str_repeat('-', $size * $size);
        $upper = intdiv($size, 2) - 1;
        $lower = intdiv($size, 2);
        $board[$upper * $size + $upper] = 'W';
        $board[$lower * $size + $lower] = 'W';
        $board[$upper * $size + $lower] = 'B';
        $board[$lower * $size + $upper] = 'B';
        return $board;
    }

    private function normalizeBoard(string $board, int $size): string
    {
        $cells = $size * $size;
        if (strlen($board) !== $cells) return $this->initialBoard($size);
        for ($i = 0; $i < $cells; $i++) {
            if (!in_array($board[$i], ['-', 'B', 'W'], true)) $board[$i] = '-';
        }
        return $board;
    }

    private function boardSizeForGame(array $game): int
    {
        $requested = (int)($game['board_size'] ?? $game['requested_board_size'] ?? self::DEFAULT_SIZE);
        return in_array($requested, self::ALLOWED_SIZES, true) ? $requested : self::DEFAULT_SIZE;
    }

    private function sideForPlayer(array $game, string $playerId): string
    {
        $side = (string)($game['reversi_sides'][$playerId] ?? '');
        if (in_array($side, ['black', 'white'], true)) return $side;
        $playerIds = array_values(array_map('strval', $game['player_ids'] ?? []));
        return ($playerIds[0] ?? '') === $playerId ? 'black' : 'white';
    }

    private function symbolForPlayer(array $game, string $playerId): string
    {
        return $this->sideForPlayer($game, $playerId) === 'black' ? 'B' : 'W';
    }

    private function playerIdForSide(array $game, string $side): string
    {
        foreach ($game['reversi_sides'] ?? [] as $playerId => $playerSide) {
            if ((string)$playerSide === $side) return (string)$playerId;
        }
        $playerIds = array_values(array_map('strval', $game['player_ids'] ?? []));
        return $side === 'black' ? (string)($playerIds[0] ?? '') : (string)($playerIds[1] ?? '');
    }

    private function otherPlayerId(array $game, string $userId): string
    {
        foreach ($game['player_ids'] ?? [] as $playerId) {
            if ((string)$playerId !== $userId) return (string)$playerId;
        }
        return $userId;
    }

    private function scheduleBotIfNeeded(array &$game): void
    {
        if (empty($game['is_bot_game'])) {
            unset($game['bot_move_after_at']);
            return;
        }
        $botId = (string)($game['bot_id'] ?? '');
        if ($botId !== '' && (string)($game['turn'] ?? '') === $botId && ($game['status'] ?? '') === 'active') {
            $game['bot_move_after_at'] = gmdate('c', time() + $this->botMoveDelaySec());
        } else {
            unset($game['bot_move_after_at']);
        }
    }

    private function hasValidSymbols(string $board): bool
    {
        return strspn($board, '-BW') === strlen($board);
    }

    private function timeLeft(array $game): int
    {
        if (($game['status'] ?? '') !== 'active') return 0;
        $started = strtotime((string)($game['turn_started_at'] ?? $game['last_move_at'] ?? '')) ?: time();
        return max(0, $this->moveTimeoutSec() - (time() - $started));
    }

    private function isTurnExpired(array $game): bool
    {
        return ($game['status'] ?? '') === 'active' && $this->timeLeft($game) <= 0;
    }

    private function moveTimeoutSec(): int
    {
        $value = (int)($this->config['move_timeout_sec'] ?? 60);
        if ($value <= 0 || $value > 60) return 60;
        return max(20, $value);
    }

    private function botMoveDelaySec(): int
    {
        return random_int(1, 3);
    }

    private function inside(int $row, int $col, int $size): bool
    {
        return $row >= 0 && $row < $size && $col >= 0 && $col < $size;
    }
}
