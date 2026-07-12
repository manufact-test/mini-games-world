<?php
declare(strict_types=1);

final class GoBotService
{
    private const DIRECTIONS = [[-1, 0], [1, 0], [0, -1], [0, 1]];

    public function chooseAction(
        string $board,
        int $size,
        string $symbol,
        string $opponent,
        string $difficulty,
        array $positionHashes,
        int $moveCount,
        int $passSequence
    ): array {
        $difficulty = in_array($difficulty, ['easy', 'medium', 'hard'], true) ? $difficulty : 'medium';
        $deadline = microtime(true) + ($difficulty === 'hard' ? 0.04 : 0.018);
        $moves = $this->legalMoves($board, $size, $symbol, $opponent, $positionHashes, $deadline);

        if ($moves === []) {
            return ['type' => 'pass'];
        }

        $occupied = strlen($board) - substr_count($board, '-');
        $fillRatio = $occupied / max(1, $size * $size);

        if ($difficulty === 'easy') {
            $capturing = array_values(array_filter($moves, static fn(array $move): bool => (int)$move['captured'] > 0));
            if ($capturing !== [] && random_int(1, 100) <= 42) {
                return ['type' => 'cell', 'cell' => (int)$capturing[array_rand($capturing)]['cell']];
            }
            if ($passSequence >= 1 && $fillRatio >= 0.72 && random_int(1, 100) <= 45) {
                return ['type' => 'pass'];
            }
            return ['type' => 'cell', 'cell' => (int)$moves[array_rand($moves)]['cell']];
        }

        $scored = [];
        foreach ($moves as $move) {
            if (microtime(true) >= $deadline) break;
            $score = $this->scoreMove($board, $size, $symbol, $opponent, $move);
            if ($difficulty === 'hard') {
                $score -= $this->bestOpponentReply(
                    (string)$move['board'],
                    $size,
                    $opponent,
                    $symbol,
                    array_merge($positionHashes, [hash('sha256', (string)$move['board'])]),
                    $deadline
                ) * 0.42;
            }
            $scored[] = ['cell' => (int)$move['cell'], 'score' => $score, 'move' => $move];
        }

        if ($scored === []) {
            return ['type' => 'cell', 'cell' => (int)$moves[0]['cell']];
        }

        usort($scored, static fn(array $a, array $b): int => $b['score'] <=> $a['score']);
        $best = $scored[0];
        $bestScore = (float)$best['score'];

        $lateGame = $fillRatio >= ($difficulty === 'hard' ? 0.54 : 0.62);
        $allQuiet = $this->allMovesQuiet($scored);
        if ($passSequence >= 1 && $lateGame && ($bestScore < 8 || $allQuiet)) {
            return ['type' => 'pass'];
        }
        if ($passSequence === 0 && $fillRatio >= 0.82 && $bestScore < 1) {
            return ['type' => 'pass'];
        }

        if ($difficulty === 'medium') {
            $pool = array_slice($scored, 0, min(4, count($scored)));
            return ['type' => 'cell', 'cell' => (int)$pool[array_rand($pool)]['cell']];
        }

        return ['type' => 'cell', 'cell' => (int)$best['cell']];
    }

    private function legalMoves(
        string $board,
        int $size,
        string $symbol,
        string $opponent,
        array $positionHashes,
        float $deadline
    ): array {
        $seenHashes = array_fill_keys(array_map('strval', $positionHashes), true);
        $moves = [];
        $cells = $size * $size;

        for ($cell = 0; $cell < $cells; $cell++) {
            if (microtime(true) >= $deadline) break;
            if (($board[$cell] ?? '-') !== '-') continue;
            $move = $this->simulateMove($board, $size, $cell, $symbol, $opponent);
            if ($move === null) continue;
            if (isset($seenHashes[hash('sha256', (string)$move['board'])])) continue;
            $moves[] = $move;
        }
        return $moves;
    }

    private function simulateMove(string $board, int $size, int $cell, string $symbol, string $opponent): ?array
    {
        if (($board[$cell] ?? '-') !== '-') return null;
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
        [$ownGroup, $ownLiberties] = $this->groupAndLiberties($next, $size, $cell, $symbol);
        if ($ownLiberties === []) return null;

        $adjacentFriend = 0;
        $adjacentOpponent = 0;
        foreach ($this->neighbours($cell, $size) as $neighbour) {
            if (($board[$neighbour] ?? '-') === $symbol) $adjacentFriend++;
            if (($board[$neighbour] ?? '-') === $opponent) $adjacentOpponent++;
        }

        return [
            'cell' => $cell,
            'board' => $next,
            'captured' => count(array_unique($captured)),
            'liberties' => count($ownLiberties),
            'group_size' => count($ownGroup),
            'adjacent_friend' => $adjacentFriend,
            'adjacent_opponent' => $adjacentOpponent,
            'own_eye' => $this->looksLikeOwnEye($board, $size, $cell, $symbol),
        ];
    }

    private function scoreMove(string $board, int $size, string $symbol, string $opponent, array $move): float
    {
        $cell = (int)$move['cell'];
        $row = intdiv($cell, $size);
        $col = $cell % $size;
        $edgeDistance = min($row, $col, $size - 1 - $row, $size - 1 - $col);

        $score = (int)$move['captured'] * 52;
        $score += min(7, (int)$move['liberties']) * 5;
        $score += (int)$move['adjacent_friend'] * 9;
        $score += (int)$move['adjacent_opponent'] * 6;
        $score += min(5, (int)$move['group_size']) * 2;

        if ($edgeDistance === 0) $score += 10;
        elseif ($edgeDistance === 1) $score += 7;
        elseif ($edgeDistance === 2) $score += 3;

        if (!empty($move['own_eye']) && (int)$move['captured'] === 0) $score -= 140;
        if ((int)$move['liberties'] === 1 && (int)$move['captured'] === 0) $score -= 62;
        if ((int)$move['liberties'] === 2 && (int)$move['captured'] === 0) $score -= 18;

        $score += $this->localInfluence((string)$move['board'], $size, $cell, $symbol, $opponent);
        return $score;
    }

    private function bestOpponentReply(
        string $board,
        int $size,
        string $symbol,
        string $opponent,
        array $history,
        float $deadline
    ): float {
        $moves = $this->legalMoves($board, $size, $symbol, $opponent, $history, $deadline);
        $best = 0.0;
        foreach ($moves as $move) {
            if (microtime(true) >= $deadline) break;
            $best = max($best, $this->scoreMove($board, $size, $symbol, $opponent, $move));
        }
        return $best;
    }

    private function allMovesQuiet(array $scored): bool
    {
        foreach (array_slice($scored, 0, min(8, count($scored))) as $item) {
            $move = $item['move'] ?? [];
            if ((int)($move['captured'] ?? 0) > 0) return false;
            if ((int)($move['adjacent_opponent'] ?? 0) > 0 && (float)($item['score'] ?? 0) >= 8) return false;
        }
        return true;
    }

    private function localInfluence(string $board, int $size, int $cell, string $symbol, string $opponent): int
    {
        $originRow = intdiv($cell, $size);
        $originCol = $cell % $size;
        $score = 0;
        for ($dr = -2; $dr <= 2; $dr++) {
            for ($dc = -2; $dc <= 2; $dc++) {
                if ($dr === 0 && $dc === 0) continue;
                $row = $originRow + $dr;
                $col = $originCol + $dc;
                if (!$this->inside($row, $col, $size)) continue;
                $distance = abs($dr) + abs($dc);
                $weight = max(1, 4 - $distance);
                $value = (string)($board[$row * $size + $col] ?? '-');
                if ($value === $symbol) $score += $weight;
                if ($value === $opponent) $score += max(1, $weight - 1);
            }
        }
        return $score;
    }

    private function looksLikeOwnEye(string $board, int $size, int $cell, string $symbol): bool
    {
        $neighbours = $this->neighbours($cell, $size);
        if ($neighbours === []) return false;
        foreach ($neighbours as $neighbour) {
            if (($board[$neighbour] ?? '-') !== $symbol) return false;
        }
        return true;
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

    private function inside(int $row, int $col, int $size): bool
    {
        return $row >= 0 && $row < $size && $col >= 0 && $col < $size;
    }
}
