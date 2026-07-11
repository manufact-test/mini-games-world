<?php
declare(strict_types=1);

final class FourInARowBotService
{
    public function chooseColumn(
        string $board,
        int $columns,
        int $rows,
        string $bot,
        string $human,
        string $difficulty
    ): ?int {
        $available = $this->orderedColumns($board, $columns);
        if (!$available) {
            return null;
        }

        if ($difficulty === 'easy') {
            return $available[array_rand($available)];
        }

        $win = $this->immediateWin($board, $columns, $rows, $bot);
        if ($win !== null) {
            return $win;
        }

        $block = $this->immediateWin($board, $columns, $rows, $human);
        if ($block !== null) {
            return $block;
        }

        if ($difficulty === 'hard') {
            $depth = $columns >= 8 ? 4 : 5;
            return $this->bestMinimax($board, $columns, $rows, $bot, $human, $depth);
        }

        return $this->bestHeuristic($board, $columns, $rows, $bot, $human);
    }

    private function immediateWin(string $board, int $columns, int $rows, string $symbol): ?int
    {
        foreach ($this->orderedColumns($board, $columns) as $column) {
            [$next] = $this->drop($board, $columns, $rows, $column, $symbol);
            if ($this->hasWin($next, $columns, $rows, $symbol)) {
                return $column;
            }
        }
        return null;
    }

    private function bestHeuristic(
        string $board,
        int $columns,
        int $rows,
        string $bot,
        string $human
    ): int {
        $bestScore = -PHP_INT_MAX;
        $best = [];
        $center = ($columns - 1) / 2;

        foreach ($this->orderedColumns($board, $columns) as $column) {
            [$next] = $this->drop($board, $columns, $rows, $column, $bot);
            $centerBonus = (int)round(max(0, $columns - abs($center - $column)) * 7);
            $score = $this->evaluate($next, $columns, $rows, $bot, $human) + $centerBonus;

            if ($score > $bestScore) {
                $bestScore = $score;
                $best = [$column];
            } elseif ($score === $bestScore) {
                $best[] = $column;
            }
        }

        return $best[array_rand($best)];
    }

    private function bestMinimax(
        string $board,
        int $columns,
        int $rows,
        string $bot,
        string $human,
        int $depth
    ): int {
        $bestScore = -PHP_INT_MAX;
        $best = [];

        foreach ($this->orderedColumns($board, $columns) as $column) {
            [$next] = $this->drop($board, $columns, $rows, $column, $bot);
            $score = $this->minimax(
                $next,
                $columns,
                $rows,
                $depth - 1,
                false,
                $bot,
                $human,
                -100000000,
                100000000
            );

            if ($score > $bestScore) {
                $bestScore = $score;
                $best = [$column];
            } elseif ($score === $bestScore) {
                $best[] = $column;
            }
        }

        return $best ? $best[array_rand($best)] : $this->bestHeuristic($board, $columns, $rows, $bot, $human);
    }

    private function minimax(
        string $board,
        int $columns,
        int $rows,
        int $depth,
        bool $maximizing,
        string $bot,
        string $human,
        int $alpha,
        int $beta
    ): int {
        if ($this->hasWin($board, $columns, $rows, $bot)) {
            return 100000 + $depth;
        }
        if ($this->hasWin($board, $columns, $rows, $human)) {
            return -100000 - $depth;
        }

        $available = $this->orderedColumns($board, $columns);
        if ($depth <= 0 || !$available) {
            return $this->evaluate($board, $columns, $rows, $bot, $human);
        }

        if ($maximizing) {
            $value = -100000000;
            foreach ($available as $column) {
                [$next] = $this->drop($board, $columns, $rows, $column, $bot);
                $value = max(
                    $value,
                    $this->minimax($next, $columns, $rows, $depth - 1, false, $bot, $human, $alpha, $beta)
                );
                $alpha = max($alpha, $value);
                if ($alpha >= $beta) {
                    break;
                }
            }
            return $value;
        }

        $value = 100000000;
        foreach ($available as $column) {
            [$next] = $this->drop($board, $columns, $rows, $column, $human);
            $value = min(
                $value,
                $this->minimax($next, $columns, $rows, $depth - 1, true, $bot, $human, $alpha, $beta)
            );
            $beta = min($beta, $value);
            if ($alpha >= $beta) {
                break;
            }
        }

        return $value;
    }

    private function evaluate(
        string $board,
        int $columns,
        int $rows,
        string $bot,
        string $human
    ): int {
        $score = 0;
        $centerColumns = $columns % 2 === 0
            ? [intdiv($columns, 2) - 1, intdiv($columns, 2)]
            : [intdiv($columns, 2)];

        for ($row = 0; $row < $rows; $row++) {
            foreach ($centerColumns as $centerColumn) {
                $value = $board[$row * $columns + $centerColumn] ?? '-';
                if ($value === $bot) {
                    $score += 6;
                } elseif ($value === $human) {
                    $score -= 6;
                }
            }
        }

        foreach ([[0, 1], [1, 0], [1, 1], [1, -1]] as [$dr, $dc]) {
            for ($row = 0; $row < $rows; $row++) {
                for ($col = 0; $col < $columns; $col++) {
                    $endRow = $row + $dr * 3;
                    $endCol = $col + $dc * 3;
                    if ($endRow < 0 || $endRow >= $rows || $endCol < 0 || $endCol >= $columns) {
                        continue;
                    }

                    $botCount = 0;
                    $humanCount = 0;
                    $empty = 0;

                    for ($step = 0; $step < 4; $step++) {
                        $value = $board[($row + $dr * $step) * $columns + ($col + $dc * $step)] ?? '-';
                        if ($value === $bot) {
                            $botCount++;
                        } elseif ($value === $human) {
                            $humanCount++;
                        } else {
                            $empty++;
                        }
                    }

                    if ($botCount > 0 && $humanCount > 0) {
                        continue;
                    }

                    if ($botCount === 3 && $empty === 1) {
                        $score += 90;
                    } elseif ($botCount === 2 && $empty === 2) {
                        $score += 18;
                    } elseif ($botCount === 1 && $empty === 3) {
                        $score += 3;
                    }

                    if ($humanCount === 3 && $empty === 1) {
                        $score -= 110;
                    } elseif ($humanCount === 2 && $empty === 2) {
                        $score -= 22;
                    } elseif ($humanCount === 1 && $empty === 3) {
                        $score -= 3;
                    }
                }
            }
        }

        return $score;
    }

    private function hasWin(string $board, int $columns, int $rows, string $symbol): bool
    {
        foreach ([[0, 1], [1, 0], [1, 1], [1, -1]] as [$dr, $dc]) {
            for ($row = 0; $row < $rows; $row++) {
                for ($col = 0; $col < $columns; $col++) {
                    $ok = true;
                    for ($step = 0; $step < 4; $step++) {
                        $r = $row + $dr * $step;
                        $c = $col + $dc * $step;
                        if (
                            $r < 0
                            || $r >= $rows
                            || $c < 0
                            || $c >= $columns
                            || ($board[$r * $columns + $c] ?? '-') !== $symbol
                        ) {
                            $ok = false;
                            break;
                        }
                    }

                    if ($ok) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    private function drop(
        string $board,
        int $columns,
        int $rows,
        int $column,
        string $symbol
    ): array {
        for ($row = $rows - 1; $row >= 0; $row--) {
            $index = $row * $columns + $column;
            if (($board[$index] ?? '-') === '-') {
                $board[$index] = $symbol;
                return [$board, $index];
            }
        }

        return [$board, null];
    }

    private function orderedColumns(string $board, int $columns): array
    {
        $available = [];
        for ($column = 0; $column < $columns; $column++) {
            if (($board[$column] ?? '-') === '-') {
                $available[$column] = true;
            }
        }

        $center = ($columns - 1) / 2;
        $ordered = array_keys($available);
        usort($ordered, static function (int $a, int $b) use ($center): int {
            $distance = abs($a - $center) <=> abs($b - $center);
            return $distance !== 0 ? $distance : ($a <=> $b);
        });

        return array_values($ordered);
    }
}
