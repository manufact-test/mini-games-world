<?php
declare(strict_types=1);

final class GoService
{
    private const ALLOWED_SIZES = [9, 13];
    private const DEFAULT_SIZE = 9;
    private const KOMI = 6.5;
    private const DIRECTIONS = [[-1, 0], [1, 0], [0, -1], [0, 1]];

    public function __construct(
        private array $config,
        private GameSettlementService $settlement,
        private ?GoBotService $bot = null
    ) {
        $this->bot ??= new GoBotService();
    }

    public function initializeGame(array &$game): void
    {
        $size = $this->boardSizeForGame($game);
        $cells = $size * $size;
        $game['game_type'] = 'go';
        $game['board_size'] = $size;
        $game['board_columns'] = $size;
        $game['board_rows'] = $size;

        $validBoard = is_string($game['board'] ?? null)
            && strlen((string)$game['board']) === $cells
            && strspn((string)$game['board'], '-BW') === $cells;
        $validSides = isset($game['go_sides']) && is_array($game['go_sides']);
        if (!empty($game['go_initialized']) && $validBoard && $validSides) {
            $this->ensureHistory($game);
            return;
        }

        $playerIds = array_values(array_map('strval', $game['player_ids'] ?? []));
        if (count($playerIds) < 2) {
            throw new RuntimeException('Для Го нужны два игрока.');
        }

        if (random_int(0, 1) === 1) {
            [$playerIds[0], $playerIds[1]] = [$playerIds[1], $playerIds[0]];
        }

        $blackId = $playerIds[0];
        $whiteId = $playerIds[1];
        $board = str_repeat('-', $cells);
        $now = now_iso();

        $game['board'] = $board;
        $game['go_sides'] = [$blackId => 'black', $whiteId => 'white'];
        $game['symbols'] = [$blackId => 'B', $whiteId => 'W'];
        $game['turn'] = $blackId;
        $game['go_position_history'] = [hash('sha256', $board)];
        $game['go_last_move'] = null;
        $game['go_last_captured_cells'] = [];
        $game['go_last_passed_player_id'] = null;
        $game['go_consecutive_passes'] = 0;
        $game['go_move_count'] = 0;
        $game['go_captures'] = ['black' => 0, 'white' => 0];
        $game['go_final_score'] = null;
        $game['turn_started_at'] = $now;
        $game['last_move_at'] = $now;
        $game['updated_at'] = $now;
        $game['go_initialized'] = true;
        $game['engine_version'] = 1;
        unset($game['bot_move_after_at']);

        $this->scheduleBotIfNeeded($game);
    }

    public function cleanup(array &$db): void
    {
        if (!isset($db['games']) || !is_array($db['games'])) return;

        foreach ($db['games'] as &$game) {
            if (!is_array($game) || (string)($game['game_type'] ?? '') !== 'go') continue;
            $this->initializeGame($game);
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
        if ($this->isTurnExpired($game)) {
            $loserId = (string)($game['turn'] ?? '');
            $winnerId = $this->otherPlayerId($game, $loserId);
            $this->settlement->finish($db, $game, $winnerId, 'timeout', $loserId);
            return $game;
        }
        if ((string)($game['turn'] ?? '') !== $userId) {
            throw new RuntimeException('Сейчас ход соперника.');
        }

        $type = trim((string)($action['type'] ?? 'cell'));
        if ($type === 'pass') {
            return $this->performPass($db, $game, $userId);
        }
        if (!in_array($type, ['cell', 'place', 'go_action'], true)) {
            throw new RuntimeException('Некорректное действие для Го.');
        }

        $cell = filter_var($action['cell'] ?? null, FILTER_VALIDATE_INT);
        if ($cell === false) {
            throw new RuntimeException('Выберите точку на поле.');
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
            'last_move' => is_array($game['go_last_move'] ?? null) ? $game['go_last_move'] : null,
            'last_captured_cells' => array_values(array_map('intval', $game['go_last_captured_cells'] ?? [])),
            'last_passed_player_id' => $game['go_last_passed_player_id'] ?? null,
            'pass_sequence' => (int)($game['go_consecutive_passes'] ?? 0),
            'move_count' => (int)($game['go_move_count'] ?? 0),
            'captures' => is_array($game['go_captures'] ?? null) ? $game['go_captures'] : ['black' => 0, 'white' => 0],
            'komi' => self::KOMI,
            'final_score' => is_array($game['go_final_score'] ?? null) ? $game['go_final_score'] : null,
        ];
    }

    private function performMove(array &$db, array &$game, string $playerId, int $cell): array
    {
        $size = $this->boardSizeForGame($game);
        if ($cell < 0 || $cell >= $size * $size) {
            throw new RuntimeException('Выберите точку на поле.');
        }

        $board = $this->normalizeBoard((string)($game['board'] ?? ''), $size);
        if (($board[$cell] ?? '-') !== '-') {
            throw new RuntimeException('Эта точка уже занята.');
        }

        $side = $this->sideForPlayer($game, $playerId);
        $symbol = $side === 'black' ? 'B' : 'W';
        $opponent = $symbol === 'B' ? 'W' : 'B';
        $simulation = $this->simulateMove($board, $size, $cell, $symbol, $opponent);
        if ($simulation === null) {
            throw new RuntimeException('У этой группы не останется свобод.');
        }

        $nextBoard = (string)$simulation['board'];
        $nextHash = hash('sha256', $nextBoard);
        $history = array_fill_keys(array_map('strval', $game['go_position_history'] ?? []), true);
        if (isset($history[$nextHash])) {
            throw new RuntimeException('Сначала сделайте ход в другом месте.');
        }

        $captured = array_values(array_unique(array_map('intval', $simulation['captured_cells'] ?? [])));
        $now = now_iso();
        $game['board'] = $nextBoard;
        $game['go_position_history'][] = $nextHash;
        $game['go_last_move'] = [
            'type' => 'place',
            'cell' => $cell,
            'player_id' => $playerId,
            'side' => $side,
            'captured' => count($captured),
        ];
        $game['go_last_captured_cells'] = $captured;
        $game['go_last_passed_player_id'] = null;
        $game['go_consecutive_passes'] = 0;
        $game['go_move_count'] = (int)($game['go_move_count'] ?? 0) + 1;
        $game['go_captures'][$side] = (int)($game['go_captures'][$side] ?? 0) + count($captured);
        $game['last_move_at'] = $now;
        $game['updated_at'] = $now;
        $game['turn'] = $this->otherPlayerId($game, $playerId);
        $game['turn_started_at'] = $now;
        unset($game['bot_move_after_at']);
        $this->scheduleBotIfNeeded($game);
        return $game;
    }

    private function performPass(array &$db, array &$game, string $playerId): array
    {
        $now = now_iso();
        $side = $this->sideForPlayer($game, $playerId);
        $passes = (int)($game['go_consecutive_passes'] ?? 0) + 1;
        $game['go_last_move'] = [
            'type' => 'pass',
            'cell' => null,
            'player_id' => $playerId,
            'side' => $side,
            'captured' => 0,
        ];
        $game['go_last_captured_cells'] = [];
        $game['go_last_passed_player_id'] = $playerId;
        $game['go_consecutive_passes'] = $passes;
        $game['go_move_count'] = (int)($game['go_move_count'] ?? 0) + 1;
        $game['last_move_at'] = $now;
        $game['updated_at'] = $now;
        unset($game['bot_move_after_at']);

        if ($passes >= 2) {
            $this->finishByArea($db, $game);
            return $game;
        }

        $game['turn'] = $this->otherPlayerId($game, $playerId);
        $game['turn_started_at'] = $now;
        $this->scheduleBotIfNeeded($game);
        return $game;
    }

    private function makeBotMove(array &$db, array &$game): void
    {
        $botId = (string)($game['bot_id'] ?? '');
        if ($botId === '' || ($game['status'] ?? '') !== 'active' || (string)($game['turn'] ?? '') !== $botId) return;

        $size = $this->boardSizeForGame($game);
        $board = $this->normalizeBoard((string)($game['board'] ?? ''), $size);
        $symbol = $this->symbolForPlayer($game, $botId);
        $opponent = $symbol === 'B' ? 'W' : 'B';
        $action = $this->bot->chooseAction(
            $board,
            $size,
            $symbol,
            $opponent,
            (string)($game['bot_difficulty'] ?? 'medium'),
            array_values(array_map('strval', $game['go_position_history'] ?? [])),
            (int)($game['go_move_count'] ?? 0),
            (int)($game['go_consecutive_passes'] ?? 0)
        );

        if (($action['type'] ?? '') === 'pass') {
            $this->performPass($db, $game, $botId);
            return;
        }
        $this->performMove($db, $game, $botId, (int)($action['cell'] ?? -1));
    }

    private function simulateMove(string $board, int $size, int $cell, string $symbol, string $opponent): ?array
    {
        $next = $board;
        $next[$cell] = $symbol;
        $captured = [];
        $checked = [];

        foreach ($this->neighbours($cell, $size) as $neighbour) {
            if (($next[$neighbour] ?? '-') !== $opponent || isset($checked[$neighbour])) continue;
            [$group, $liberties] = $this->groupAndLiberties($next, $size, $neighbour, $opponent);
            foreach ($group as $groupCell) $checked[$groupCell] = true;
            if ($liberties !== []) continue;
            foreach ($group as $groupCell) $captured[] = $groupCell;
        }

        foreach ($captured as $capturedCell) $next[$capturedCell] = '-';
        [, $ownLiberties] = $this->groupAndLiberties($next, $size, $cell, $symbol);
        if ($ownLiberties === []) return null;

        return ['board' => $next, 'captured_cells' => array_values(array_unique($captured))];
    }

    private function finishByArea(array &$db, array &$game): void
    {
        $score = $this->areaScore(
            $this->normalizeBoard((string)($game['board'] ?? ''), $this->boardSizeForGame($game)),
            $this->boardSizeForGame($game)
        );
        $game['go_final_score'] = $score;
        $game['go_end_reason'] = 'two_passes';
        unset($game['bot_move_after_at']);

        $black = (float)$score['black_total'];
        $white = (float)$score['white_total'];
        if (abs($black - $white) < 0.0001) {
            $this->settlement->finish($db, $game, null, 'draw');
            return;
        }

        $winnerSide = $black > $white ? 'black' : 'white';
        $winnerId = $this->playerIdForSide($game, $winnerSide);
        $loserId = $this->otherPlayerId($game, $winnerId);
        $this->settlement->finish($db, $game, $winnerId, 'normal_win', $loserId);
    }

    private function areaScore(string $board, int $size): array
    {
        $blackStones = substr_count($board, 'B');
        $whiteStones = substr_count($board, 'W');
        $blackTerritory = [];
        $whiteTerritory = [];
        $neutral = [];
        $visited = [];
        $cells = $size * $size;

        for ($cell = 0; $cell < $cells; $cell++) {
            if (($board[$cell] ?? '-') !== '-' || isset($visited[$cell])) continue;
            $queue = [$cell];
            $region = [];
            $borders = [];
            while ($queue !== []) {
                $current = array_pop($queue);
                if (isset($visited[$current])) continue;
                $visited[$current] = true;
                if (($board[$current] ?? '-') !== '-') continue;
                $region[] = $current;
                foreach ($this->neighbours($current, $size) as $neighbour) {
                    $value = (string)($board[$neighbour] ?? '-');
                    if ($value === '-' && !isset($visited[$neighbour])) $queue[] = $neighbour;
                    elseif ($value === 'B' || $value === 'W') $borders[$value] = true;
                }
            }

            if (count($borders) === 1 && isset($borders['B'])) {
                foreach ($region as $regionCell) $blackTerritory[] = $regionCell;
            } elseif (count($borders) === 1 && isset($borders['W'])) {
                foreach ($region as $regionCell) $whiteTerritory[] = $regionCell;
            } else {
                foreach ($region as $regionCell) $neutral[] = $regionCell;
            }
        }

        $blackArea = $blackStones + count($blackTerritory);
        $whiteArea = $whiteStones + count($whiteTerritory);
        return [
            'black_stones' => $blackStones,
            'white_stones' => $whiteStones,
            'black_territory' => count($blackTerritory),
            'white_territory' => count($whiteTerritory),
            'neutral' => count($neutral),
            'black_total' => $blackArea,
            'white_total' => $whiteArea + self::KOMI,
            'komi' => self::KOMI,
            'black_territory_cells' => array_values($blackTerritory),
            'white_territory_cells' => array_values($whiteTerritory),
            'neutral_cells' => array_values($neutral),
        ];
    }

    private function groupAndLiberties(string $board, int $size, int $start, string $symbol): array
    {
        $stack = [$start];
        $visited = [];
        $group = [];
        $liberties = [];

        while ($stack !== []) {
            $cell = array_pop($stack);
            if (isset($visited[$cell])) continue;
            $visited[$cell] = true;
            if (($board[$cell] ?? '-') !== $symbol) continue;
            $group[] = $cell;
            foreach ($this->neighbours($cell, $size) as $neighbour) {
                $value = (string)($board[$neighbour] ?? '-');
                if ($value === '-') $liberties[$neighbour] = true;
                elseif ($value === $symbol && !isset($visited[$neighbour])) $stack[] = $neighbour;
            }
        }
        return [array_values($group), array_map('intval', array_keys($liberties))];
    }

    private function neighbours(int $cell, int $size): array
    {
        $row = intdiv($cell, $size);
        $col = $cell % $size;
        $result = [];
        foreach (self::DIRECTIONS as [$dr, $dc]) {
            $r = $row + $dr;
            $c = $col + $dc;
            if ($this->inside($r, $c, $size)) $result[] = $r * $size + $c;
        }
        return $result;
    }

    private function ensureHistory(array &$game): void
    {
        if (!isset($game['go_position_history']) || !is_array($game['go_position_history']) || $game['go_position_history'] === []) {
            $game['go_position_history'] = [hash('sha256', (string)($game['board'] ?? ''))];
        }
    }

    private function normalizeBoard(string $board, int $size): string
    {
        $cells = $size * $size;
        if (strlen($board) !== $cells) return str_repeat('-', $cells);
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
        $side = (string)($game['go_sides'][$playerId] ?? '');
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
        foreach ($game['go_sides'] ?? [] as $playerId => $playerSide) {
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
            $game['bot_move_after_at'] = gmdate('c', time() + random_int(1, 3));
        } else {
            unset($game['bot_move_after_at']);
        }
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

    private function inside(int $row, int $col, int $size): bool
    {
        return $row >= 0 && $row < $size && $col >= 0 && $col < $size;
    }
}
