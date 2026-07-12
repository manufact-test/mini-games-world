<?php
declare(strict_types=1);

final class ChessBotService
{
    /**
     * @param array<int,array<string,mixed>> $moves
     * @param callable(array<string,mixed>,int,int):float $scoreMove
     * @return array<string,mixed>
     */
    public function chooseMove(array $moves, string $difficulty, callable $scoreMove, int $budgetMs = 40): array
    {
        if ($moves === []) {
            throw new RuntimeException('У бота нет допустимых ходов.');
        }

        $difficulty = in_array($difficulty, ['easy', 'medium', 'hard'], true) ? $difficulty : 'medium';
        // The current JSON storage uses one global lock. Keep bot calculation short
        // so one Chess move cannot pause unrelated players or payment requests.
        $deadline = hrtime(true) + max(20, min(40, $budgetMs)) * 1_000_000;
        $depth = $difficulty === 'hard' ? 2 : ($difficulty === 'medium' ? 1 : 0);
        $ranked = [];

        foreach ($moves as $move) {
            if (hrtime(true) >= $deadline) break;
            $score = (float)$scoreMove($move, $depth, $deadline);
            if ($difficulty === 'easy') {
                $score += random_int(-140, 140);
            } elseif ($difficulty === 'medium') {
                $score += random_int(-28, 28);
            } else {
                $score += random_int(-3, 3);
            }
            $ranked[] = ['move' => $move, 'score' => $score];
        }

        if ($ranked === []) {
            return $moves[array_rand($moves)];
        }

        usort($ranked, static fn(array $a, array $b): int => $b['score'] <=> $a['score']);

        if ($difficulty === 'easy') {
            $pool = array_slice($ranked, 0, min(8, count($ranked)));
            return $pool[array_rand($pool)]['move'];
        }

        if ($difficulty === 'medium' && count($ranked) > 1 && random_int(1, 100) <= 20) {
            $pool = array_slice($ranked, 0, min(3, count($ranked)));
            return $pool[array_rand($pool)]['move'];
        }

        $best = $ranked[0]['score'];
        $ties = array_values(array_filter(
            $ranked,
            static fn(array $item): bool => abs((float)$item['score'] - (float)$best) < 0.001
        ));
        return $ties[array_rand($ties)]['move'];
    }
}
