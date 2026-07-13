<?php
declare(strict_types=1);

final class DominoBotService
{
    /**
     * The bot receives only information that a real player could know:
     * its own hand, the public chain, stock size and opponent tile count.
     */
    public function chooseAction(
        array $hand,
        array $chain,
        int $stockCount,
        int $opponentCount,
        string $difficulty,
        array $opponentVoidValues = []
    ): array {
        $difficulty = in_array($difficulty, ['easy', 'medium', 'hard'], true) ? $difficulty : 'medium';
        $legal = $this->legalPlays($hand, $chain);

        if ($legal === []) {
            return $stockCount > 0 ? ['type' => 'draw'] : ['type' => 'pass'];
        }

        if ($difficulty === 'easy') {
            return $legal[array_rand($legal)];
        }

        $deadline = microtime(true) + ($difficulty === 'hard' ? 0.035 : 0.012);
        $scored = [];
        foreach ($legal as $play) {
            if (microtime(true) >= $deadline) break;
            $score = $this->scorePlay(
                $play,
                $hand,
                $chain,
                $stockCount,
                $opponentCount,
                $difficulty,
                $opponentVoidValues
            );
            $scored[] = $play + ['score' => $score];
        }

        if ($scored === []) return $legal[0];
        usort($scored, static fn(array $a, array $b): int => $b['score'] <=> $a['score']);

        if ($difficulty === 'medium') {
            $pool = array_slice($scored, 0, min(2, count($scored)));
            return $pool[array_rand($pool)];
        }

        return $scored[0];
    }

    private function scorePlay(
        array $play,
        array $hand,
        array $chain,
        int $stockCount,
        int $opponentCount,
        string $difficulty,
        array $opponentVoidValues
    ): float {
        [$a, $b] = $this->parseTile((string)$play['tile']);
        $score = ($a + $b) * 7.0;
        if ($a === $b) $score += count($hand) <= 3 ? 15.0 : 4.0;

        $remaining = array_values(array_filter(
            $hand,
            static fn(string $tile): bool => $tile !== (string)$play['tile']
        ));
        [$newLeft, $newRight] = $this->endsAfterPlay($chain, $play);
        $futureMoves = 0;
        $futureValues = [];
        foreach ($remaining as $tile) {
            [$x, $y] = $this->parseTile((string)$tile);
            if ($x === $newLeft || $y === $newLeft || $x === $newRight || $y === $newRight) $futureMoves++;
            $futureValues[$x] = true;
            $futureValues[$y] = true;
        }
        $score += $futureMoves * 5.0;
        $score += count($futureValues) * 1.8;

        if ($difficulty === 'hard') {
            $visible = [];
            foreach ($chain as $item) {
                $visible[] = (string)($item['tile'] ?? '');
            }
            $unseen = $this->allTiles();
            $known = array_fill_keys(array_merge($remaining, $visible, [(string)$play['tile']]), true);
            $unseen = array_values(array_filter($unseen, static fn(string $tile): bool => !isset($known[$tile])));

            $matchCount = 0;
            foreach ($unseen as $tile) {
                [$x, $y] = $this->parseTile($tile);
                if ($x === $newLeft || $y === $newLeft || $x === $newRight || $y === $newRight) $matchCount++;
            }
            $opponentMoveProbability = $this->atLeastOneMatchProbability(
                count($unseen),
                $matchCount,
                max(0, min($opponentCount, count($unseen)))
            );
            $blockWeight = $opponentCount <= 2 ? 28.0 : ($opponentCount <= 4 ? 18.0 : 10.0);
            $score += (1.0 - $opponentMoveProbability) * $blockWeight;

            foreach (array_unique(array_map('intval', $opponentVoidValues)) as $voidValue) {
                if ($newLeft === $voidValue) $score += 7.0;
                if ($newRight === $voidValue) $score += 7.0;
            }

            if ($stockCount === 0) {
                $score += (1.0 - $opponentMoveProbability) * 12.0;
            }
        }

        $score += random_int(0, 100) / 1000;
        return $score;
    }

    private function legalPlays(array $hand, array $chain): array
    {
        [$left, $right] = $this->openEnds($chain);
        $plays = [];
        foreach ($hand as $tile) {
            [$a, $b] = $this->parseTile((string)$tile);
            $sides = [];
            if ($left === null || $a === $left || $b === $left) $sides[] = 'left';
            if ($right === null || $a === $right || $b === $right) $sides[] = 'right';
            foreach (array_values(array_unique($sides)) as $side) {
                $plays[] = ['type' => 'play', 'tile' => (string)$tile, 'side' => $side];
            }
        }
        return $plays;
    }

    private function endsAfterPlay(array $chain, array $play): array
    {
        [$left, $right] = $this->openEnds($chain);
        [$a, $b] = $this->parseTile((string)$play['tile']);
        if ($left === null || $right === null) return [$a, $b];

        if (($play['side'] ?? 'right') === 'left') {
            $newLeft = $a === $left ? $b : $a;
            return [$newLeft, $right];
        }

        $newRight = $a === $right ? $b : $a;
        return [$left, $newRight];
    }

    private function openEnds(array $chain): array
    {
        if ($chain === []) return [null, null];
        $first = $chain[0] ?? [];
        $last = $chain[count($chain) - 1] ?? [];
        return [(int)($first['left'] ?? 0), (int)($last['right'] ?? 0)];
    }

    private function atLeastOneMatchProbability(int $population, int $matching, int $draws): float
    {
        if ($population <= 0 || $matching <= 0 || $draws <= 0) return 0.0;
        if ($matching >= $population || $draws >= $population) return 1.0;

        $misses = $population - $matching;
        $probabilityNoMatch = 1.0;
        for ($i = 0; $i < $draws; $i++) {
            $denominator = $population - $i;
            if ($denominator <= 0) return 1.0;
            $numerator = $misses - $i;
            if ($numerator <= 0) return 1.0;
            $probabilityNoMatch *= $numerator / $denominator;
        }
        return max(0.0, min(1.0, 1.0 - $probabilityNoMatch));
    }

    private function allTiles(): array
    {
        $tiles = [];
        for ($a = 0; $a <= 6; $a++) {
            for ($b = $a; $b <= 6; $b++) {
                $tiles[] = $a . '-' . $b;
            }
        }
        return $tiles;
    }

    private function parseTile(string $tile): array
    {
        $parts = array_map('intval', explode('-', $tile, 2));
        return [(int)($parts[0] ?? 0), (int)($parts[1] ?? 0)];
    }
}
