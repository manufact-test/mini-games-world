<?php
declare(strict_types=1);

final class GameActionService
{
    public function __construct(
        private GameCatalogService $catalog,
        private GameRuntimeService|ChessRuntimeService $runtime
    ) {}

    public function apply(array &$db, array &$user, string $gameId, array $action): array
    {
        $gameId = trim($gameId);
        if ($gameId === '' || !isset($db['games'][$gameId]) || !is_array($db['games'][$gameId])) {
            throw new RuntimeException('Игра не найдена.');
        }

        $game = $db['games'][$gameId];
        $userId = (string)($user['id'] ?? '');
        $playerIds = array_map('strval', $game['player_ids'] ?? []);

        if ($userId === '' || !in_array($userId, $playerIds, true)) {
            throw new RuntimeException('Вы не участвуете в этой игре.');
        }

        if ((string)($game['status'] ?? '') === 'finished') {
            return $game;
        }

        // Runtime flags block only creation of new games. An already active match
        // must keep resolving its engine and accepting legal actions safely.
        $gameType = trim((string)($game['game_type'] ?? ''));
        if ($gameType === '') $gameType = $this->catalog->defaultGameType();
        $definition = $this->catalog->get($gameType);
        $engine = (string)($definition['engine'] ?? '');
        $expectedActionType = (string)($definition['action_type'] ?? '');
        $actionType = trim((string)($action['type'] ?? $expectedActionType));

        return match ($engine) {
            'tictactoe' => $this->applyTicTacToeAction($db, $user, $gameId, $actionType, $action),
            'four_in_a_row' => $this->applyFourInARowAction($db, $user, $gameId, $actionType, $action),
            'battleship' => $this->applyBattleshipAction($db, $user, $gameId, $action),
            'checkers' => $this->runtime->applyCheckersAction($db, $user, $gameId, $action),
            'reversi' => $this->runtime->applyReversiAction($db, $user, $gameId, $action),
            'chess' => $this->runtime->applyChessAction($db, $user, $gameId, $action),
            'go' => $this->runtime->applyGoAction($db, $user, $gameId, $action),
            'domino' => $this->runtime->applyDominoAction($db, $user, $gameId, $action),
            default => throw new RuntimeException('Движок этой игры пока не подключён.'),
        };
    }

    private function applyBattleshipAction(
        array &$db,
        array &$user,
        string $gameId,
        array $action
    ): array {
        if ((string)($action['type'] ?? '') !== 'randomize_fleet' || !array_key_exists('ships', $action)) {
            return $this->runtime->applyBattleshipAction($db, $user, $gameId, $action);
        }

        $ships = $action['ships'];
        if (!is_array($ships) || count($ships) !== 10) {
            throw new RuntimeException('Не удалось проверить случайную расстановку флота.');
        }

        $this->runtime->applyBattleshipAction($db, $user, $gameId, ['type' => 'clear_fleet']);
        $result = $db['games'][$gameId];

        foreach ($ships as $ship) {
            if (!is_array($ship)) throw new RuntimeException('Некорректный корабль в случайной расстановке.');
            [$size, $startCell, $orientation] = $this->normalizeBattleshipShip($ship);
            $result = $this->runtime->applyBattleshipAction($db, $user, $gameId, [
                'type' => 'place_ship',
                'size' => $size,
                'cell' => $startCell,
                'orientation' => $orientation,
            ]);
        }

        return $result;
    }

    private function normalizeBattleshipShip(array $ship): array
    {
        $size = (int)($ship['size'] ?? 0);
        $cells = array_values(array_unique(array_map('intval', is_array($ship['cells'] ?? null) ? $ship['cells'] : [])));
        sort($cells);
        if (!in_array($size, [1, 2, 3, 4], true) || count($cells) !== $size) {
            throw new RuntimeException('Некорректный размер корабля в случайной расстановке.');
        }
        if ($cells === [] || $cells[0] < 0 || $cells[count($cells) - 1] >= 100) {
            throw new RuntimeException('Корабль выходит за границы поля.');
        }

        if ($size === 1) return [$size, $cells[0], 'h'];

        $sameRow = true;
        $firstRow = intdiv($cells[0], 10);
        foreach ($cells as $index => $cell) {
            if (intdiv($cell, 10) !== $firstRow || $cell !== $cells[0] + $index) {
                $sameRow = false;
                break;
            }
        }
        if ($sameRow) return [$size, $cells[0], 'h'];

        $sameColumn = true;
        $column = $cells[0] % 10;
        foreach ($cells as $index => $cell) {
            if ($cell % 10 !== $column || $cell !== $cells[0] + ($index * 10)) {
                $sameColumn = false;
                break;
            }
        }
        if ($sameColumn) return [$size, $cells[0], 'v'];

        throw new RuntimeException('Корабль должен идти по прямой без пропусков.');
    }

    private function applyTicTacToeAction(
        array &$db,
        array &$user,
        string $gameId,
        string $actionType,
        array $action
    ): array {
        if ($actionType !== 'cell') {
            throw new RuntimeException('Некорректное действие для этой игры.');
        }

        $cell = filter_var($action['cell'] ?? null, FILTER_VALIDATE_INT);
        if ($cell === false) {
            throw new RuntimeException('Не выбрана клетка.');
        }

        return $this->runtime->makeMove($db, $user, $gameId, (int)$cell);
    }

    private function applyFourInARowAction(
        array &$db,
        array &$user,
        string $gameId,
        string $actionType,
        array $action
    ): array {
        $column = filter_var($action['column'] ?? null, FILTER_VALIDATE_INT);

        // Compatibility fallback for a stale v49 client that rendered the board as
        // tic-tac-toe cells but was already connected to the Four in a Row engine.
        if ($column === false && $actionType === 'cell') {
            $cell = filter_var($action['cell'] ?? null, FILTER_VALIDATE_INT);
            if ($cell !== false) {
                $columns = max(1, (int)($db['games'][$gameId]['board_columns'] ?? 7));
                $column = (int)$cell % $columns;
            }
        }

        if (!in_array($actionType, ['column', 'drop_disc', 'cell'], true) || $column === false) {
            throw new RuntimeException('Выберите столбец для хода.');
        }

        return $this->runtime->dropFourInARowDisc($db, $user, $gameId, (int)$column);
    }
}
