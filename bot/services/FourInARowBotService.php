<?php
declare(strict_types=1);

final class FourInARowBotService
{
    private const COLUMNS = 7;
    private const ROWS = 6;

    public function chooseColumn(string $board, string $bot, string $human, string $difficulty): ?int
    {
        $available = $this->orderedColumns($board);
        if (!$available) return null;
        if ($difficulty === 'easy') return $available[array_rand($available)];

        $win = $this->immediateWin($board, $bot);
        if ($win !== null) return $win;
        $block = $this->immediateWin($board, $human);
        if ($block !== null) return $block;

        return $difficulty === 'hard'
            ? $this->bestMinimax($board, $bot, $human, 5)
            : $this->bestHeuristic($board, $bot, $human);
    }

    private function immediateWin(string $board, string $symbol): ?int
    {
        foreach ($this->orderedColumns($board) as $column) {
            [$next] = $this->drop($board, $column, $symbol);
            if ($this->hasWin($next, $symbol)) return $column;
        }
        return null;
    }

    private function bestHeuristic(string $board, string $bot, string $human): int
    {
        $bestScore = -PHP_INT_MAX;
        $best = [];
        foreach ($this->orderedColumns($board) as $column) {
            [$next] = $this->drop($board, $column, $bot);
            $score = $this->evaluate($next, $bot, $human) + (3 - abs(3 - $column)) * 7;
            if ($score > $bestScore) { $bestScore = $score; $best = [$column]; }
            elseif ($score === $bestScore) { $best[] = $column; }
        }
        return $best[array_rand($best)];
    }

    private function bestMinimax(string $board, string $bot, string $human, int $depth): int
    {
        $bestScore = -PHP_INT_MAX;
        $best = [];
        foreach ($this->orderedColumns($board) as $column) {
            [$next] = $this->drop($board, $column, $bot);
            $score = $this->minimax($next, $depth - 1, false, $bot, $human, -100000000, 100000000);
            if ($score > $bestScore) { $bestScore = $score; $best = [$column]; }
            elseif ($score === $bestScore) { $best[] = $column; }
        }
        return $best ? $best[array_rand($best)] : $this->bestHeuristic($board, $bot, $human);
    }

    private function minimax(string $board, int $depth, bool $max, string $bot, string $human, int $alpha, int $beta): int
    {
        if ($this->hasWin($board, $bot)) return 100000 + $depth;
        if ($this->hasWin($board, $human)) return -100000 - $depth;
        $columns = $this->orderedColumns($board);
        if ($depth <= 0 || !$columns) return $this->evaluate($board, $bot, $human);

        if ($max) {
            $value = -100000000;
            foreach ($columns as $column) {
                [$next] = $this->drop($board, $column, $bot);
                $value = max($value, $this->minimax($next, $depth - 1, false, $bot, $human, $alpha, $beta));
                $alpha = max($alpha, $value);
                if ($alpha >= $beta) break;
            }
            return $value;
        }

        $value = 100000000;
        foreach ($columns as $column) {
            [$next] = $this->drop($board, $column, $human);
            $value = min($value, $this->minimax($next, $depth - 1, true, $bot, $human, $alpha, $beta));
            $beta = min($beta, $value);
            if ($alpha >= $beta) break;
        }
        return $value;
    }

    private function evaluate(string $board, string $bot, string $human): int
    {
        $score = 0;
        for ($row = 0; $row < self::ROWS; $row++) {
            $center = $board[$row * self::COLUMNS + 3] ?? '-';
            if ($center === $bot) $score += 6;
            elseif ($center === $human) $score -= 6;
        }

        foreach ([[0,1],[1,0],[1,1],[1,-1]] as [$dr,$dc]) {
            for ($row = 0; $row < self::ROWS; $row++) {
                for ($col = 0; $col < self::COLUMNS; $col++) {
                    $endRow = $row + $dr * 3;
                    $endCol = $col + $dc * 3;
                    if ($endRow < 0 || $endRow >= self::ROWS || $endCol < 0 || $endCol >= self::COLUMNS) continue;
                    $b = $h = $empty = 0;
                    for ($step = 0; $step < 4; $step++) {
                        $value = $board[($row + $dr * $step) * self::COLUMNS + ($col + $dc * $step)] ?? '-';
                        if ($value === $bot) $b++;
                        elseif ($value === $human) $h++;
                        else $empty++;
                    }
                    if ($b > 0 && $h > 0) continue;
                    if ($b === 3 && $empty === 1) $score += 90;
                    elseif ($b === 2 && $empty === 2) $score += 18;
                    elseif ($b === 1 && $empty === 3) $score += 3;
                    if ($h === 3 && $empty === 1) $score -= 110;
                    elseif ($h === 2 && $empty === 2) $score -= 22;
                    elseif ($h === 1 && $empty === 3) $score -= 3;
                }
            }
        }
        return $score;
    }

    private function hasWin(string $board, string $symbol): bool
    {
        foreach ([[0,1],[1,0],[1,1],[1,-1]] as [$dr,$dc]) {
            for ($row = 0; $row < self::ROWS; $row++) {
                for ($col = 0; $col < self::COLUMNS; $col++) {
                    $ok = true;
                    for ($step = 0; $step < 4; $step++) {
                        $r = $row + $dr * $step; $c = $col + $dc * $step;
                        if ($r < 0 || $r >= self::ROWS || $c < 0 || $c >= self::COLUMNS
                            || ($board[$r * self::COLUMNS + $c] ?? '-') !== $symbol) { $ok = false; break; }
                    }
                    if ($ok) return true;
                }
            }
        }
        return false;
    }

    private function drop(string $board, int $column, string $symbol): array
    {
        for ($row = self::ROWS - 1; $row >= 0; $row--) {
            $index = $row * self::COLUMNS + $column;
            if (($board[$index] ?? '-') === '-') { $board[$index] = $symbol; return [$board, $index]; }
        }
        return [$board, null];
    }

    private function orderedColumns(string $board): array
    {
        $available = [];
        for ($column = 0; $column < self::COLUMNS; $column++) if (($board[$column] ?? '-') === '-') $available[$column] = true;
        return array_values(array_filter([3,2,4,1,5,0,6], fn(int $column): bool => isset($available[$column])));
    }
}
