<?php
declare(strict_types=1);

final class CheckersBotService
{
    public function chooseMove(array $board, array $moves, string $side, string $difficulty): array
    {
        if ($moves === []) {
            throw new RuntimeException('У бота нет допустимых ходов.');
        }

        $difficulty = in_array($difficulty, ['easy', 'medium', 'hard'], true) ? $difficulty : 'medium';
        if ($difficulty === 'easy') {
            return $moves[array_rand($moves)];
        }

        $scored = [];
        foreach ($moves as $move) {
            $score = $this->scoreMove($board, $move, $side, $difficulty);
            $jitter = $difficulty === 'hard' ? random_int(0, 3) : random_int(0, 12);
            $scored[] = ['move' => $move, 'score' => $score + $jitter];
        }

        usort($scored, static fn(array $a, array $b): int => $b['score'] <=> $a['score']);
        $poolSize = $difficulty === 'hard' ? min(2, count($scored)) : min(4, count($scored));
        $pool = array_slice($scored, 0, max(1, $poolSize));

        if ($difficulty === 'hard' || count($pool) === 1) {
            return $pool[0]['move'];
        }

        return $pool[array_rand($pool)]['move'];
    }

    private function scoreMove(array $board, array $move, string $side, string $difficulty): int
    {
        $from = (int)($move['from'] ?? -1);
        $to = (int)($move['to'] ?? -1);
        $piece = (string)($board[$from] ?? '');
        $score = 0;

        if (!empty($move['capture'])) {
            $capturedCell = (int)($move['captured'] ?? -1);
            $capturedPiece = (string)($board[$capturedCell] ?? '');
            $score += ctype_upper($capturedPiece) ? 70 : 36;
        }

        if (!empty($move['promotes'])) {
            $score += 58;
        }

        $row = intdiv(max(0, $to), 8);
        $col = max(0, $to) % 8;
        $centerDistance = abs(3.5 - $row) + abs(3.5 - $col);
        $score += (int)round((7 - $centerDistance) * 3);

        if (ctype_upper($piece)) {
            $score += 8;
        } else {
            $progress = $side === 'white' ? (7 - $row) : $row;
            $score += $progress * 2;
        }

        if ($difficulty === 'hard') {
            $score += $this->edgeSafetyBonus($to);
            $score -= $this->immediateExposurePenalty($board, $move, $side);
        }

        return $score;
    }

    private function edgeSafetyBonus(int $cell): int
    {
        $col = $cell % 8;
        return ($col === 0 || $col === 7) ? 8 : 0;
    }

    private function immediateExposurePenalty(array $board, array $move, string $side): int
    {
        $from = (int)($move['from'] ?? -1);
        $to = (int)($move['to'] ?? -1);
        if ($from < 0 || $to < 0) return 0;

        $nextBoard = array_values($board);
        $piece = (string)($nextBoard[$from] ?? '');
        $nextBoard[$from] = '';
        $nextBoard[$to] = $piece;
        if (!empty($move['capture'])) {
            $captured = (int)($move['captured'] ?? -1);
            if ($captured >= 0 && $captured < 64) $nextBoard[$captured] = '';
        }

        $enemy = $side === 'white' ? 'black' : 'white';
        $penalty = 0;
        foreach ([[-1,-1],[-1,1],[1,-1],[1,1]] as [$dr, $dc]) {
            $row = intdiv($to, 8) + $dr;
            $col = ($to % 8) + $dc;
            $landingRow = intdiv($to, 8) - $dr;
            $landingCol = ($to % 8) - $dc;
            if (!$this->inside($row, $col) || !$this->inside($landingRow, $landingCol)) continue;
            $enemyPiece = (string)($nextBoard[$row * 8 + $col] ?? '');
            if ($this->belongsTo($enemyPiece, $enemy) && (string)($nextBoard[$landingRow * 8 + $landingCol] ?? '') === '') {
                $penalty += 18;
            }
        }
        return $penalty;
    }

    private function belongsTo(string $piece, string $side): bool
    {
        if ($piece === '') return false;
        return $side === 'white' ? strtolower($piece) === 'w' : strtolower($piece) === 'b';
    }

    private function inside(int $row, int $col): bool
    {
        return $row >= 0 && $row < 8 && $col >= 0 && $col < 8;
    }
}
