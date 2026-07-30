<?php
declare(strict_types=1);

namespace Mgw\CleanRuntime\Server\Match;

final readonly class TicTacToeRules
{
    public const BOARD_SIZE = 3;
    public const EMPTY_BOARD = '---------';

    public function place(string $board, int $cell, string $symbol): string
    {
        $this->assertBoard($board);
        if ($cell < 0 || $cell >= 9 || $board[$cell] !== '-') {
            throw new \RuntimeException('Клетка недоступна.');
        }
        if (!in_array($symbol, ['X', 'O'], true)) {
            throw new \RuntimeException('Некорректный символ игрока.');
        }
        $board[$cell] = $symbol;
        return $board;
    }

    public function winnerSymbol(string $board): ?string
    {
        $this->assertBoard($board);
        foreach ([[0,1,2],[3,4,5],[6,7,8],[0,3,6],[1,4,7],[2,5,8],[0,4,8],[2,4,6]] as $line) {
            [$a, $b, $c] = $line;
            if ($board[$a] !== '-' && $board[$a] === $board[$b] && $board[$a] === $board[$c]) {
                return $board[$a];
            }
        }
        return null;
    }

    public function isDraw(string $board): bool
    {
        $this->assertBoard($board);
        return !str_contains($board, '-') && $this->winnerSymbol($board) === null;
    }

    private function assertBoard(string $board): void
    {
        if (strlen($board) !== 9 || preg_match('/[^XO-]/', $board)) {
            throw new \RuntimeException('Состояние поля повреждено.');
        }
    }
}
