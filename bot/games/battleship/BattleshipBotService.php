<?php
declare(strict_types=1);

final class BattleshipBotService
{
    private const BOARD_SIZE = 10;

    public function chooseTarget(array $shots, array $remainingShipSizes, string $difficulty): ?int
    {
        $available = [];
        for ($cell = 0; $cell < self::BOARD_SIZE * self::BOARD_SIZE; $cell++) {
            if (!array_key_exists((string)$cell, $shots) && !array_key_exists($cell, $shots)) {
                $available[] = $cell;
            }
        }

        if ($available === []) {
            return null;
        }

        $difficulty = in_array($difficulty, ['easy', 'medium', 'hard'], true) ? $difficulty : 'medium';

        if ($difficulty === 'easy') {
            return $available[array_rand($available)];
        }

        $target = $this->targetAroundHits($shots, $available);
        if ($target !== null) {
            return $target;
        }

        if ($difficulty === 'medium') {
            $checker = array_values(array_filter($available, function (int $cell): bool {
                $row = intdiv($cell, self::BOARD_SIZE);
                $col = $cell % self::BOARD_SIZE;
                return (($row + $col) % 2) === 0;
            }));

            $pool = $checker !== [] ? $checker : $available;
            return $pool[array_rand($pool)];
        }

        return $this->probabilityTarget($shots, $remainingShipSizes, $available);
    }

    private function targetAroundHits(array $shots, array $available): ?int
    {
        $availableSet = array_fill_keys($available, true);
        $hits = [];

        foreach ($shots as $cell => $result) {
            if ((string)$result === 'hit') {
                $hits[] = (int)$cell;
            }
        }

        if ($hits === []) {
            return null;
        }

        $hitSet = array_fill_keys($hits, true);
        foreach ($hits as $cell) {
            $row = intdiv($cell, self::BOARD_SIZE);
            $col = $cell % self::BOARD_SIZE;

            $horizontal = [];
            for ($c = 0; $c < self::BOARD_SIZE; $c++) {
                $candidate = $row * self::BOARD_SIZE + $c;
                if (isset($hitSet[$candidate])) $horizontal[] = $candidate;
            }
            if (count($horizontal) >= 2) {
                sort($horizontal);
                $left = min($horizontal) - 1;
                $right = max($horizontal) + 1;
                foreach ([$left, $right] as $candidate) {
                    if (isset($availableSet[$candidate]) && intdiv($candidate, self::BOARD_SIZE) === $row) {
                        return $candidate;
                    }
                }
            }

            $vertical = [];
            for ($r = 0; $r < self::BOARD_SIZE; $r++) {
                $candidate = $r * self::BOARD_SIZE + $col;
                if (isset($hitSet[$candidate])) $vertical[] = $candidate;
            }
            if (count($vertical) >= 2) {
                sort($vertical);
                $up = min($vertical) - self::BOARD_SIZE;
                $down = max($vertical) + self::BOARD_SIZE;
                foreach ([$up, $down] as $candidate) {
                    if ($candidate >= 0 && $candidate < 100 && isset($availableSet[$candidate])) {
                        return $candidate;
                    }
                }
            }
        }

        $neighbors = [];
        foreach ($hits as $cell) {
            $row = intdiv($cell, self::BOARD_SIZE);
            $col = $cell % self::BOARD_SIZE;
            foreach ([[-1,0],[1,0],[0,-1],[0,1]] as [$dr, $dc]) {
                $r = $row + $dr;
                $c = $col + $dc;
                if ($r < 0 || $r >= 10 || $c < 0 || $c >= 10) continue;
                $candidate = $r * 10 + $c;
                if (isset($availableSet[$candidate])) $neighbors[$candidate] = true;
            }
        }

        if ($neighbors === []) {
            return null;
        }

        $pool = array_keys($neighbors);
        return $pool[array_rand($pool)];
    }

    private function probabilityTarget(array $shots, array $remainingShipSizes, array $available): int
    {
        $scores = array_fill(0, 100, 0);
        $blocked = [];

        foreach ($shots as $cell => $result) {
            $result = (string)$result;
            if (in_array($result, ['miss', 'sunk'], true)) {
                $blocked[(int)$cell] = true;
            }
        }

        $sizes = array_values(array_filter(array_map('intval', $remainingShipSizes), fn(int $size): bool => $size >= 1 && $size <= 4));
        if ($sizes === []) $sizes = [4,3,3,2,2,2,1,1,1,1];

        foreach ($sizes as $size) {
            foreach (['h', 'v'] as $orientation) {
                $maxRow = $orientation === 'v' ? 10 - $size : 9;
                $maxCol = $orientation === 'h' ? 10 - $size : 9;

                for ($row = 0; $row <= $maxRow; $row++) {
                    for ($col = 0; $col <= $maxCol; $col++) {
                        $cells = [];
                        $valid = true;
                        for ($step = 0; $step < $size; $step++) {
                            $r = $row + ($orientation === 'v' ? $step : 0);
                            $c = $col + ($orientation === 'h' ? $step : 0);
                            $cell = $r * 10 + $c;
                            if (isset($blocked[$cell])) {
                                $valid = false;
                                break;
                            }
                            $cells[] = $cell;
                        }
                        if (!$valid) continue;
                        foreach ($cells as $cell) $scores[$cell]++;
                    }
                }
            }
        }

        $bestScore = -1;
        $best = [];
        foreach ($available as $cell) {
            $score = $scores[$cell] ?? 0;
            if ($score > $bestScore) {
                $bestScore = $score;
                $best = [$cell];
            } elseif ($score === $bestScore) {
                $best[] = $cell;
            }
        }

        return $best[array_rand($best)];
    }
}
