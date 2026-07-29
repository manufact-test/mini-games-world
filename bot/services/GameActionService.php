<?php
declare(strict_types=1);

final class GameActionService
{
    private const TURN_HANDOFF_DELAY_SEC = 1;
    private const MOVE_TIMEOUT_SEC = 60;

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

        $this->activateLaunchIfDue($db['games'][$gameId]);
        $game = $db['games'][$gameId];
        $userId = (string)($user['id'] ?? '');
        $playerIds = array_map('strval', $game['player_ids'] ?? []);

        if ($userId === '' || !in_array($userId, $playerIds, true)) {
            throw new RuntimeException('Вы не участвуете в этой игре.');
        }

        if ((string)($game['status'] ?? '') === 'finished') {
            return $game;
        }

        $this->assertLaunchReady($game);
        $previousTurn = (string)($game['turn'] ?? '');

        // Runtime flags block only creation of new games. An already active match
        // must keep resolving its engine and accepting legal actions safely.
        $gameType = trim((string)($game['game_type'] ?? ''));
        if ($gameType === '') $gameType = $this->catalog->defaultGameType();
        $definition = $this->catalog->get($gameType);
        $engine = (string)($definition['engine'] ?? '');
        $expectedActionType = (string)($definition['action_type'] ?? '');
        $actionType = trim((string)($action['type'] ?? $expectedActionType));

        $result = match ($engine) {
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

        return $this->synchronizeTurnHandoff($db, $gameId, $previousTurn, $result);
    }

    private function activateLaunchIfDue(array &$game): void
    {
        if ((string)($game['launch_phase'] ?? '') !== 'countdown') return;
        $startsAt = strtotime((string)($game['starts_at'] ?? '')) ?: 0;
        if ($startsAt <= 0 || $startsAt > time()) return;
        $game['launch_phase'] = 'active';
        $game['updated_at'] = now_iso();
    }

    private function assertLaunchReady(array $game): void
    {
        $phase = (string)($game['launch_phase'] ?? 'active');
        if ($phase === 'preparing') {
            throw new RuntimeException('Матч ещё синхронизирует игроков.');
        }
        if ($phase === 'countdown') {
            $startsAt = strtotime((string)($game['starts_at'] ?? '')) ?: 0;
            if ($startsAt <= 0 || $startsAt > time()) {
                throw new RuntimeException('Матч начнётся после обратного отсчёта.');
            }
        }
    }

    private function synchronizeTurnHandoff(array &$db, string $gameId, string $previousTurn, array $fallback): array
    {
        if (!isset($db['games'][$gameId]) || !is_array($db['games'][$gameId])) return $fallback;
        $game =& $db['games'][$gameId];
        if ((string)($game['status'] ?? '') !== 'active') return $game;

        $currentTurn = (string)($game['turn'] ?? '');
        if ($currentTurn === '' || $currentTurn === $previousTurn) return $game;

        $startsAt = time() + self::TURN_HANDOFF_DELAY_SEC;
        $game['launch_phase'] = 'active';
        $game['turn_started_at'] = gmdate('c', $startsAt);
        $game['turn_starts_at'] = gmdate('c', $startsAt);
        $game['turn_deadline_at'] = gmdate('c', $startsAt + self::MOVE_TIMEOUT_SEC);
        $game['v111_clock_turn'] = $currentTurn;
        $game['v111_clock_revision'] = (int)($game['v111_clock_revision'] ?? 0) + 1;
        $game['updated_at'] = now_iso();

        $botId = (string)($game['bot_id'] ?? '');
        if (!empty($game['is_bot_game']) && $botId !== '' && $currentTurn === $botId) {
            $game['bot_move_after_at'] = gmdate('c', $startsAt + 1);
        }

        return $game;
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

        $normalized = [];
        $counts = [1 => 0, 2 => 0, 3 => 0, 4 => 0];
        foreach ($ships as $ship) {
            if (!is_array($ship)) throw new RuntimeException('Некорректный корабль в случайной расстановке.');
            [$size, $startCell, $orientation] = $this->normalizeBattleshipShip($ship);
            $counts[$size]++;
            $normalized[] = [$size, $startCell, $orientation];
        }
        if ($counts !== [1 => 4, 2 => 3, 3 => 2, 4 => 1]) {
            throw new RuntimeException('Случайная расстановка содержит неправильный состав флота.');
        }

        $this->runtime->applyBattleshipAction($db, $user, $gameId, ['type' => 'clear_fleet']);
        $result = $db['games'][$gameId];
        foreach ($normalized as [$size, $startCell, $orientation]) {
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
        $size = filter_var($ship['size'] ?? null, FILTER_VALIDATE_INT);
        $rawCells = $ship['cells'] ?? null;
        if ($size === false || !is_array($rawCells) || !in_array((int)$size, [1, 2, 3, 4], true)) {
            throw new RuntimeException('Некорректный размер корабля в случайной расстановке.');
        }

        $cells = [];
        foreach ($rawCells as $rawCell) {
            $cell = filter_var($rawCell, FILTER_VALIDATE_INT);
            if ($cell === false) {
                throw new RuntimeException('Некорректная клетка корабля в случайной расстановке.');
            }
            $cells[] = (int)$cell;
        }
        $cells = array_values(array_unique($cells));
        sort($cells);
        $size = (int)$size;

        if (count($cells) !== $size) {
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
