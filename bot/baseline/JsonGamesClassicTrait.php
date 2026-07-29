<?php
declare(strict_types=1);

trait JsonGamesClassicTrait
{
    private function applyTicTacToe(
        JsonBehaviorBaselineFixture $fixture,
        array &$state,
        array &$game,
        string $actorId,
        array $step,
        DateTimeImmutable $now,
        array $config
    ): array {
        if ((string)($game['turn'] ?? '') !== $actorId) throw new RuntimeException('Сейчас не ваш ход.');
        $cell = filter_var($step['cell'] ?? null, FILTER_VALIDATE_INT);
        $board = (string)($game['board'] ?? '');
        if ($cell === false || $cell < 0 || $cell >= strlen($board) || ($board[$cell] ?? '-') !== '-') {
            throw new RuntimeException('Клетка недоступна.');
        }
        $symbol = (string)($game['symbols'][$actorId] ?? '');
        if ($symbol === '') throw new RuntimeException('Вы не участник этой игры.');
        $board[$cell] = $symbol;
        $game['board'] = $board;
        $game['last_move_at'] = $now->format('c');
        $game['updated_at'] = $now->format('c');
        $game['turn_started_at'] = $now->format('c');
        $winner = $this->ticTacToeWinner($board, (int)($game['board_size'] ?? 3));
        if ($winner !== null) {
            $winnerId = array_search($winner, $game['symbols'] ?? [], true);
            return $this->finishGame($fixture, $state, $game, (string)$winnerId, 'normal_win', $now, $config);
        }
        if (!str_contains($board, '-')) {
            return $this->finishGame($fixture, $state, $game, null, 'draw', $now, $config);
        }
        $game['turn'] = $this->otherPlayerId($game, $actorId);
        return [];
    }

    private function ticTacToeWinner(string $board, int $size): ?string
    {
        for ($row = 0; $row < $size; $row++) {
            $first = $board[$row * $size] ?? '-';
            if ($first !== '-') {
                $win = true;
                for ($col = 1; $col < $size; $col++) {
                    if (($board[$row * $size + $col] ?? '-') !== $first) { $win = false; break; }
                }
                if ($win) return $first;
            }
        }
        for ($col = 0; $col < $size; $col++) {
            $first = $board[$col] ?? '-';
            if ($first !== '-') {
                $win = true;
                for ($row = 1; $row < $size; $row++) {
                    if (($board[$row * $size + $col] ?? '-') !== $first) { $win = false; break; }
                }
                if ($win) return $first;
            }
        }
        $first = $board[0] ?? '-';
        if ($first !== '-') {
            $win = true;
            for ($i = 1; $i < $size; $i++) if (($board[$i * $size + $i] ?? '-') !== $first) { $win = false; break; }
            if ($win) return $first;
        }
        $first = $board[$size - 1] ?? '-';
        if ($first !== '-') {
            $win = true;
            for ($i = 1; $i < $size; $i++) if (($board[$i * $size + ($size - 1 - $i)] ?? '-') !== $first) { $win = false; break; }
            if ($win) return $first;
        }
        return null;
    }

    private function applyFourInARow(
        JsonBehaviorBaselineFixture $fixture,
        array &$state,
        array &$game,
        string $actorId,
        array $step,
        DateTimeImmutable $now,
        array $config
    ): array {
        if ((string)($game['turn'] ?? '') !== $actorId) throw new RuntimeException('Сейчас не ваш ход.');
        $columns = (int)($game['board_columns'] ?? 7);
        $rows = (int)($game['board_rows'] ?? 6);
        $column = filter_var($step['column'] ?? null, FILTER_VALIDATE_INT);
        if ($column === false || $column < 0 || $column >= $columns) throw new RuntimeException('Выберите доступный столбец.');
        $board = (string)($game['board'] ?? str_repeat('-', $columns * $rows));
        $cell = null;
        for ($row = $rows - 1; $row >= 0; $row--) {
            $candidate = $row * $columns + $column;
            if (($board[$candidate] ?? '-') === '-') { $cell = $candidate; break; }
        }
        if ($cell === null) throw new RuntimeException('Этот столбец уже заполнен.');
        $symbol = (string)($game['symbols'][$actorId] ?? '');
        $board[$cell] = $symbol;
        $game['board'] = $board;
        $game['last_move'] = $cell;
        $game['last_move_at'] = $now->format('c');
        $game['updated_at'] = $now->format('c');
        $winning = $this->fourWinningCells($board, $columns, $rows, $symbol);
        if ($winning !== []) {
            $game['winning_cells'] = $winning;
            return $this->finishGame($fixture, $state, $game, $actorId, 'normal_win', $now, $config);
        }
        if (!str_contains($board, '-')) {
            $game['winning_cells'] = [];
            return $this->finishGame($fixture, $state, $game, null, 'draw', $now, $config);
        }
        $game['turn'] = $this->otherPlayerId($game, $actorId);
        $game['turn_started_at'] = $now->format('c');
        return [];
    }

    private function fourWinningCells(string $board, int $columns, int $rows, string $symbol): array
    {
        foreach ([[0,1],[1,0],[1,1],[1,-1]] as [$dr,$dc]) {
            for ($row = 0; $row < $rows; $row++) {
                for ($col = 0; $col < $columns; $col++) {
                    $cells = [];
                    for ($step = 0; $step < 4; $step++) {
                        $r = $row + $dr * $step;
                        $c = $col + $dc * $step;
                        if ($r < 0 || $r >= $rows || $c < 0 || $c >= $columns) { $cells = []; break; }
                        $index = $r * $columns + $c;
                        if (($board[$index] ?? '-') !== $symbol) { $cells = []; break; }
                        $cells[] = $index;
                    }
                    if (count($cells) === 4) return $cells;
                }
            }
        }
        return [];
    }

    private function applyBattleship(
        JsonBehaviorBaselineFixture $fixture,
        array &$state,
        array &$game,
        string $actorId,
        array $step,
        DateTimeImmutable $now,
        array $config
    ): array {
        if ((string)($game['phase'] ?? '') !== 'battle') throw new RuntimeException('Сначала завершите расстановку кораблей.');
        $cell = filter_var($step['cell'] ?? null, FILTER_VALIDATE_INT);
        if ($cell === false || $cell < 0 || $cell >= 100) throw new RuntimeException('Выберите клетку для выстрела.');
        if ((string)($game['turn'] ?? '') !== $actorId) throw new RuntimeException('Сейчас не ваш ход.');
        $shots = is_array($game['battleship_shots'][$actorId] ?? null) ? $game['battleship_shots'][$actorId] : [];
        if (array_key_exists((string)$cell, $shots) || array_key_exists($cell, $shots)) {
            throw new RuntimeException('Вы уже стреляли в эту клетку.');
        }
        $targetId = $this->otherPlayerId($game, $actorId);
        $ships = array_values($game['battleship_fleets'][$targetId]['ships'] ?? []);
        $hitIndex = null;
        foreach ($ships as $index => $ship) {
            if (in_array((int)$cell, array_map('intval', $ship['cells'] ?? []), true)) { $hitIndex = $index; break; }
        }
        $result = 'miss';
        if ($hitIndex === null) {
            $game['battleship_shots'][$actorId][(string)$cell] = 'miss';
            $game['turn'] = $targetId;
        } else {
            $hits = array_values(array_unique(array_map('intval', $ships[$hitIndex]['hits'] ?? [])));
            if (!in_array((int)$cell, $hits, true)) $hits[] = (int)$cell;
            sort($hits);
            $ships[$hitIndex]['hits'] = $hits;
            $ships[$hitIndex]['sunk'] = count($hits) >= (int)($ships[$hitIndex]['size'] ?? 0);
            $game['battleship_fleets'][$targetId]['ships'] = $ships;
            $result = $ships[$hitIndex]['sunk'] ? 'sunk' : 'hit';
            foreach ($ships[$hitIndex]['cells'] ?? [] as $shipCell) {
                if ($ships[$hitIndex]['sunk']) $game['battleship_shots'][$actorId][(string)(int)$shipCell] = 'sunk';
            }
            if (!$ships[$hitIndex]['sunk']) $game['battleship_shots'][$actorId][(string)$cell] = 'hit';
            if ($this->allBattleshipShipsSunk($ships)) {
                $game['battleship_last_shot'] = (int)$cell;
                $game['battleship_last_result'] = $result;
                $game['battleship_last_shooter_id'] = $actorId;
                return $this->finishGame($fixture, $state, $game, $actorId, 'normal_win', $now, $config);
            }
            $game['turn'] = $actorId;
        }
        $game['battleship_last_shot'] = (int)$cell;
        $game['battleship_last_result'] = $result;
        $game['battleship_last_shooter_id'] = $actorId;
        $game['turn_started_at'] = $now->format('c');
        $game['last_move_at'] = $now->format('c');
        $game['updated_at'] = $now->format('c');
        return [];
    }

    private function allBattleshipShipsSunk(array $ships): bool
    {
        if ($ships === []) return false;
        foreach ($ships as $ship) if (empty($ship['sunk'])) return false;
        return true;
    }

    private function applyReversi(
        JsonBehaviorBaselineFixture $fixture,
        array &$state,
        array &$game,
        string $actorId,
        array $step,
        DateTimeImmutable $now,
        array $config
    ): array {
        if ((string)($game['turn'] ?? '') !== $actorId) throw new RuntimeException('Сейчас ход соперника.');
        $cell = filter_var($step['cell'] ?? null, FILTER_VALIDATE_INT);
        if ($cell === false) throw new RuntimeException('Выберите клетку для хода.');
        $legal = $game['reversi_legal_moves'] ?? [];
        $flips = $legal[(string)$cell] ?? $legal[$cell] ?? null;
        if (!is_array($flips) || $flips === []) {
            throw new RuntimeException('Сюда нельзя поставить фишку. Выберите подсвеченную клетку.');
        }
        $board = (string)($game['board'] ?? '');
        $side = (string)($game['reversi_sides'][$actorId] ?? 'black');
        $symbol = $side === 'black' ? 'B' : 'W';
        $board[$cell] = $symbol;
        foreach ($flips as $flip) $board[(int)$flip] = $symbol;
        $game['board'] = $board;
        $game['reversi_last_move'] = ['cell' => (int)$cell, 'player_id' => $actorId, 'side' => $side, 'flipped' => count($flips)];
        $game['reversi_last_flipped_cells'] = array_values(array_map('intval', $flips));
        $game['last_move_at'] = $now->format('c');
        $game['updated_at'] = $now->format('c');
        if (!empty($game['reversi_finish_after_move']) || !str_contains($board, '-')) {
            $black = substr_count($board, 'B');
            $white = substr_count($board, 'W');
            $game['reversi_final_counts'] = ['black' => $black, 'white' => $white];
            if ($black === $white) return $this->finishGame($fixture, $state, $game, null, 'draw', $now, $config);
            $winnerSide = $black > $white ? 'black' : 'white';
            $winnerId = array_search($winnerSide, $game['reversi_sides'] ?? [], true);
            return $this->finishGame($fixture, $state, $game, (string)$winnerId, 'normal_win', $now, $config);
        }
        $game['turn'] = $this->otherPlayerId($game, $actorId);
        $game['turn_started_at'] = $now->format('c');
        return [];
    }
}
