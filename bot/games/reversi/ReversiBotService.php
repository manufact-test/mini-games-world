<?php
declare(strict_types=1);

final class ReversiBotService
{
    private const DIRECTIONS = [
        [-1, -1], [-1, 0], [-1, 1],
        [0, -1],           [0, 1],
        [1, -1],  [1, 0],  [1, 1],
    ];

    public function chooseCell(
        string $board,
        int $size,
        string $symbol,
        string $opponent,
        string $difficulty
    ): int {
        $moves = $this->legalMoves($board, $size, $symbol, $opponent);
        if ($moves === []) {
            throw new RuntimeException('У бота нет допустимых ходов.');
        }

        $difficulty = in_array($difficulty, ['easy', 'medium', 'hard'], true)
            ? $difficulty
            : 'medium';

        if ($difficulty === 'easy') {
            if (random_int(1, 100) <= 22) {
                return (int)$moves[array_rand($moves)]['cell'];
            }
            return $this->chooseFromHeuristicPool($board, $size, $symbol, $opponent, $moves, 5, 28);
        }

        if ($difficulty === 'medium') {
            return $this->chooseFromHeuristicPool($board, $size, $symbol, $opponent, $moves, 3, 12);
        }

        return $this->chooseHardMove($board, $size, $symbol, $opponent, $moves);
    }

    private function chooseFromHeuristicPool(
        string $board,
        int $size,
        string $symbol,
        string $opponent,
        array $moves,
        int $poolSize,
        int $jitterMax
    ): int {
        $scored = [];
        foreach ($moves as $move) {
            $next = $this->applyMove($board, $symbol, $move);
            $score = $this->moveHeuristic($board, $next, $size, $symbol, $opponent, $move);
            $scored[] = [
                'cell' => (int)$move['cell'],
                'score' => $score + random_int(0, max(0, $jitterMax)),
            ];
        }

        usort($scored, static fn(array $a, array $b): int => $b['score'] <=> $a['score']);
        $pool = array_slice($scored, 0, max(1, min($poolSize, count($scored))));
        return (int)$pool[array_rand($pool)]['cell'];
    }

    private function chooseHardMove(
        string $board,
        int $size,
        string $symbol,
        string $opponent,
        array $moves
    ): int {
        $empty = substr_count($board, '-');
        $depth = $size <= 6 ? 4 : ($size <= 8 ? 3 : 2);
        if ($empty <= 12) $depth++;
        if ($empty <= 8) $depth++;
        $depth = min(6, $depth);

        $nodeLimit = $size <= 6 ? 4000 : ($size <= 8 ? 2500 : 1200);
        $deadline = microtime(true) + 0.08;
        $nodes = 0;
        $bestCell = (int)$moves[0]['cell'];
        $bestScore = -PHP_INT_MAX;

        $ordered = $this->orderMoves($board, $size, $symbol, $opponent, $moves);
        foreach ($ordered as $move) {
            if ($nodes >= $nodeLimit || microtime(true) >= $deadline) break;
            $next = $this->applyMove($board, $symbol, $move);
            $score = $this->minimax(
                $next,
                $size,
                $opponent,
                $symbol,
                $symbol,
                $opponent,
                $depth - 1,
                -PHP_INT_MAX,
                PHP_INT_MAX,
                $nodes,
                $nodeLimit,
                $deadline
            );
            if ($score > $bestScore) {
                $bestScore = $score;
                $bestCell = (int)$move['cell'];
            }
        }

        return $bestCell;
    }

    private function minimax(
        string $board,
        int $size,
        string $turnSymbol,
        string $otherSymbol,
        string $rootSymbol,
        string $rootOpponent,
        int $depth,
        int $alpha,
        int $beta,
        int &$nodes,
        int $nodeLimit,
        float $deadline
    ): int {
        $nodes++;
        if ($nodes >= $nodeLimit || microtime(true) >= $deadline || $depth <= 0) {
            return $this->evaluateBoard($board, $size, $rootSymbol, $rootOpponent);
        }

        $moves = $this->legalMoves($board, $size, $turnSymbol, $otherSymbol);
        if ($moves === []) {
            $otherMoves = $this->legalMoves($board, $size, $otherSymbol, $turnSymbol);
            if ($otherMoves === [] || !str_contains($board, '-')) {
                return $this->terminalScore($board, $rootSymbol, $rootOpponent);
            }
            return $this->minimax(
                $board,
                $size,
                $otherSymbol,
                $turnSymbol,
                $rootSymbol,
                $rootOpponent,
                $depth - 1,
                $alpha,
                $beta,
                $nodes,
                $nodeLimit,
                $deadline
            );
        }

        $maximizing = $turnSymbol === $rootSymbol;
        $ordered = $this->orderMoves($board, $size, $turnSymbol, $otherSymbol, $moves);

        if ($maximizing) {
            $value = -PHP_INT_MAX;
            foreach ($ordered as $move) {
                if ($nodes >= $nodeLimit || microtime(true) >= $deadline) break;
                $next = $this->applyMove($board, $turnSymbol, $move);
                $value = max($value, $this->minimax(
                    $next,
                    $size,
                    $otherSymbol,
                    $turnSymbol,
                    $rootSymbol,
                    $rootOpponent,
                    $depth - 1,
                    $alpha,
                    $beta,
                    $nodes,
                    $nodeLimit,
                    $deadline
                ));
                $alpha = max($alpha, $value);
                if ($beta <= $alpha) break;
            }
            return $value === -PHP_INT_MAX
                ? $this->evaluateBoard($board, $size, $rootSymbol, $rootOpponent)
                : $value;
        }

        $value = PHP_INT_MAX;
        foreach ($ordered as $move) {
            if ($nodes >= $nodeLimit || microtime(true) >= $deadline) break;
            $next = $this->applyMove($board, $turnSymbol, $move);
            $value = min($value, $this->minimax(
                $next,
                $size,
                $otherSymbol,
                $turnSymbol,
                $rootSymbol,
                $rootOpponent,
                $depth - 1,
                $alpha,
                $beta,
                $nodes,
                $nodeLimit,
                $deadline
            ));
            $beta = min($beta, $value);
            if ($beta <= $alpha) break;
        }
        return $value === PHP_INT_MAX
            ? $this->evaluateBoard($board, $size, $rootSymbol, $rootOpponent)
            : $value;
    }

    private function orderMoves(string $board, int $size, string $symbol, string $opponent, array $moves): array
    {
        usort($moves, function (array $a, array $b) use ($board, $size, $symbol, $opponent): int {
            $nextA = $this->applyMove($board, $symbol, $a);
            $nextB = $this->applyMove($board, $symbol, $b);
            $scoreA = $this->moveHeuristic($board, $nextA, $size, $symbol, $opponent, $a);
            $scoreB = $this->moveHeuristic($board, $nextB, $size, $symbol, $opponent, $b);
            return $scoreB <=> $scoreA;
        });
        return $moves;
    }

    private function moveHeuristic(
        string $before,
        string $after,
        int $size,
        string $symbol,
        string $opponent,
        array $move
    ): int {
        $cell = (int)$move['cell'];
        $score = count($move['flips'] ?? []) * 3;
        if ($this->isCorner($cell, $size)) $score += 1000;
        elseif ($this->isEdge($cell, $size)) $score += 70;

        $score += $this->cornerNeighbourAdjustment($before, $cell, $size);
        $opponentMoves = count($this->legalMoves($after, $size, $opponent, $symbol));
        $ownFutureMoves = count($this->legalMoves($after, $size, $symbol, $opponent));
        $score += $ownFutureMoves * 4;
        $score -= $opponentMoves * 18;
        return $score;
    }

    private function evaluateBoard(string $board, int $size, string $symbol, string $opponent): int
    {
        $pieceDifference = substr_count($board, $symbol) - substr_count($board, $opponent);
        $mobility = count($this->legalMoves($board, $size, $symbol, $opponent))
            - count($this->legalMoves($board, $size, $opponent, $symbol));
        $cornerDifference = $this->cornerCount($board, $size, $symbol)
            - $this->cornerCount($board, $size, $opponent);
        $edgeDifference = $this->edgeCount($board, $size, $symbol)
            - $this->edgeCount($board, $size, $opponent);
        $empty = substr_count($board, '-');
        $pieceWeight = $empty <= max(10, $size) ? 12 : 2;

        return $pieceDifference * $pieceWeight
            + $mobility * 22
            + $cornerDifference * 900
            + $edgeDifference * 16;
    }

    private function terminalScore(string $board, string $symbol, string $opponent): int
    {
        $difference = substr_count($board, $symbol) - substr_count($board, $opponent);
        if ($difference > 0) return 100000 + $difference * 100;
        if ($difference < 0) return -100000 + $difference * 100;
        return 0;
    }

    private function legalMoves(string $board, int $size, string $symbol, string $opponent): array
    {
        $moves = [];
        $cells = $size * $size;
        for ($cell = 0; $cell < $cells; $cell++) {
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
        $all = [];
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
                    foreach ($line as $flipped) $all[] = $flipped;
                }
                break;
            }
        }
        return array_values(array_unique($all));
    }

    private function applyMove(string $board, string $symbol, array $move): string
    {
        $cell = (int)$move['cell'];
        $board[$cell] = $symbol;
        foreach ($move['flips'] ?? [] as $flip) {
            $index = (int)$flip;
            if ($index >= 0 && $index < strlen($board)) $board[$index] = $symbol;
        }
        return $board;
    }

    private function cornerNeighbourAdjustment(string $board, int $cell, int $size): int
    {
        foreach ($this->cornerNeighbourMaps($size) as $corner => $neighbours) {
            if (($board[$corner] ?? '-') !== '-') continue;
            if (($neighbours['x'] ?? -1) === $cell) return -320;
            if (in_array($cell, $neighbours['c'] ?? [], true)) return -150;
        }
        return 0;
    }

    private function cornerNeighbourMaps(int $size): array
    {
        $last = $size - 1;
        return [
            0 => ['x' => $size + 1, 'c' => [1, $size]],
            $last => ['x' => $size * 2 - 2, 'c' => [$last - 1, $last + $size]],
            $last * $size => ['x' => ($last - 1) * $size + 1, 'c' => [($last - 1) * $size, $last * $size + 1]],
            $size * $size - 1 => ['x' => ($last - 1) * $size + $last - 1, 'c' => [$size * $size - 2, ($last - 1) * $size + $last]],
        ];
    }

    private function cornerCount(string $board, int $size, string $symbol): int
    {
        $last = $size - 1;
        $corners = [0, $last, $last * $size, $size * $size - 1];
        $count = 0;
        foreach ($corners as $cell) if (($board[$cell] ?? '-') === $symbol) $count++;
        return $count;
    }

    private function edgeCount(string $board, int $size, string $symbol): int
    {
        $count = 0;
        for ($row = 0; $row < $size; $row++) {
            for ($col = 0; $col < $size; $col++) {
                if ($row !== 0 && $row !== $size - 1 && $col !== 0 && $col !== $size - 1) continue;
                if (($board[$row * $size + $col] ?? '-') === $symbol) $count++;
            }
        }
        return $count;
    }

    private function isCorner(int $cell, int $size): bool
    {
        $last = $size - 1;
        return in_array($cell, [0, $last, $last * $size, $size * $size - 1], true);
    }

    private function isEdge(int $cell, int $size): bool
    {
        $row = intdiv($cell, $size);
        $col = $cell % $size;
        return $row === 0 || $row === $size - 1 || $col === 0 || $col === $size - 1;
    }

    private function inside(int $row, int $col, int $size): bool
    {
        return $row >= 0 && $row < $size && $col >= 0 && $col < $size;
    }
}
