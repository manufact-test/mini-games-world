<?php
declare(strict_types=1);

final class GameActionService
{
    public function __construct(
        private GameCatalogService $catalog,
        private GameRuntimeService $runtime
    ) {}

    public function apply(array &$db, array &$user, string $gameId, array $action): array
    {
        $gameId = trim($gameId);
        if ($gameId === '' || !isset($db['games'][$gameId]) || !is_array($db['games'][$gameId])) {
            throw new RuntimeException('Игра не найдена.');
        }

        $game = $db['games'][$gameId];
        $gameType = $this->catalog->normalizeGameType((string)($game['game_type'] ?? ''));
        $definition = $this->catalog->get($gameType);
        $expectedActionType = (string)($definition['action_type'] ?? '');
        $actionType = trim((string)($action['type'] ?? $expectedActionType));

        if ($actionType === '' || $actionType !== $expectedActionType) {
            throw new RuntimeException('Это действие не поддерживается выбранной игрой.');
        }

        return match ((string)($definition['engine'] ?? '')) {
            'tictactoe' => $this->applyTicTacToeAction($db, $user, $gameId, $actionType, $action),
            default => throw new RuntimeException('Движок этой игры пока не подключён.'),
        };
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
}
