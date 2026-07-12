<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/games/chess/ChessBotService.php';
require_once dirname(__DIR__) . '/games/chess/ChessService.php';

/**
 * Adds the Chess engine without changing the stable runtime paths of the five
 * already released games. Non-Chess calls are delegated unchanged.
 */
final class ChessRuntimeService
{
    private GameRuntimeService $base;
    private ChessService $chess;

    public function __construct(
        private array $config,
        private GameCatalogService $catalog,
        private GameService $legacyGame
    ) {
        $this->base = new GameRuntimeService($config, $catalog, $legacyGame);
        $this->chess = new ChessService($config, new GameSettlementService($config));
    }

    public function cleanup(array &$db): void
    {
        $this->base->cleanup($db);
        $this->chess->cleanup($db);
    }

    public function cleanupQueue(array &$db): void
    {
        $this->base->cleanupQueue($db);
    }

    public function refreshSearch(array &$db, array &$user): void
    {
        $this->base->refreshSearch($db, $user);
    }

    public function maybeCreateBotGameForSearchingUser(array &$db, array &$user): ?array
    {
        $game = $this->base->maybeCreateBotGameForSearchingUser($db, $user);
        if (!is_array($game)) return null;

        $gameId = (string)($game['id'] ?? '');
        if ($gameId === '' || !isset($db['games'][$gameId]) || !is_array($db['games'][$gameId])) {
            return $game;
        }

        if ((string)($db['games'][$gameId]['game_type'] ?? '') === 'chess') {
            $this->prepareStoredChessGame($db, $gameId);
            return $db['games'][$gameId];
        }

        return $game;
    }

    public function startSearch(
        array &$db,
        array &$user,
        string $room,
        int $bet,
        int $boardSize,
        ?string $gameType = null
    ): array {
        $gameType = $this->catalog->normalizeGameType($gameType);
        if ($gameType !== 'chess') {
            return $this->base->startSearch($db, $user, $room, $bet, $boardSize, $gameType);
        }

        $room = $room === 'gold' ? 'gold' : 'match';
        if (!$this->catalog->supportsRoom('chess', $room)) {
            throw new RuntimeException('Шахматы недоступны в выбранной комнате.');
        }

        $userId = (string)($user['id'] ?? '');
        $active = $this->findActiveGameForUser($db, $userId);
        if ($active) return ['game' => $this->publicGame($active, $userId)];

        $requestedBoardSize = 8;
        $legacyBoardSize = 9;
        $result = $this->withIsolatedQueue(
            $db,
            'chess',
            function () use (&$db, &$user, $room, $bet, $legacyBoardSize): array {
                return $this->legacyGame->startSearch($db, $user, $room, $bet, $legacyBoardSize);
            },
            $userId
        );

        if (isset($result['game']) && is_array($result['game'])) {
            $gameId = (string)($result['game']['id'] ?? '');
            if ($gameId !== '' && isset($db['games'][$gameId]) && is_array($db['games'][$gameId])) {
                $db['games'][$gameId]['game_type'] = 'chess';
                $db['games'][$gameId]['board_size'] = $requestedBoardSize;
                $db['games'][$gameId]['board_columns'] = $requestedBoardSize;
                $db['games'][$gameId]['board_rows'] = $requestedBoardSize;
                $this->chess->initializeGame($db['games'][$gameId]);
                $this->syncGameMetadataTransactions($db, $gameId);
                $result['game'] = $this->publicGame($db['games'][$gameId], $userId);
            }
        } else {
            $this->setQueuedChessType($db, $userId);
        }

        return $result;
    }

    public function leaveSearch(array &$db, array &$user): void
    {
        $this->base->leaveSearch($db, $user);
    }

    public function surrenderGame(array &$db, array &$user, string $gameId): array
    {
        $game = $db['games'][$gameId] ?? null;
        if (is_array($game) && (string)($game['game_type'] ?? '') === 'chess') {
            return $this->chess->surrender($db, $user, $gameId);
        }
        return $this->base->surrenderGame($db, $user, $gameId);
    }

    public function findActiveGameForUser(array $db, string $userId): ?array
    {
        return $this->base->findActiveGameForUser($db, $userId);
    }

    public function makeMove(array &$db, array &$user, string $gameId, int $cell): array
    {
        return $this->base->makeMove($db, $user, $gameId, $cell);
    }

    public function dropFourInARowDisc(array &$db, array &$user, string $gameId, int $column): array
    {
        return $this->base->dropFourInARowDisc($db, $user, $gameId, $column);
    }

    public function applyBattleshipAction(array &$db, array &$user, string $gameId, array $action): array
    {
        return $this->base->applyBattleshipAction($db, $user, $gameId, $action);
    }

    public function applyCheckersAction(array &$db, array &$user, string $gameId, array $action): array
    {
        return $this->base->applyCheckersAction($db, $user, $gameId, $action);
    }

    public function applyReversiAction(array &$db, array &$user, string $gameId, array $action): array
    {
        return $this->base->applyReversiAction($db, $user, $gameId, $action);
    }

    public function applyChessAction(array &$db, array &$user, string $gameId, array $action): array
    {
        return $this->chess->applyAction($db, $user, $gameId, $action);
    }

    public function publicGame(array $game, string $viewerId): array
    {
        if ((string)($game['game_type'] ?? '') !== 'chess') {
            return $this->base->publicGame($game, $viewerId);
        }

        $definition = $this->catalog->publicGameDefinition('chess');
        return [
            'game_type' => 'chess',
            'game_title' => (string)$definition['title'],
            'renderer' => (string)$definition['renderer'],
            'action_type' => (string)$definition['action_type'],
        ] + $this->chess->publicGame($game, $viewerId);
    }

    public function catalog(): array
    {
        return $this->catalog->publicCatalog();
    }

    private function prepareStoredChessGame(array &$db, string $gameId): void
    {
        $db['games'][$gameId]['game_type'] = 'chess';
        $db['games'][$gameId]['board_size'] = 8;
        $db['games'][$gameId]['board_columns'] = 8;
        $db['games'][$gameId]['board_rows'] = 8;
        $this->chess->initializeGame($db['games'][$gameId]);
        $this->syncGameMetadataTransactions($db, $gameId);
    }

    private function setQueuedChessType(array &$db, string $userId): void
    {
        if (!isset($db['queue']) || !is_array($db['queue'])) return;

        foreach ($db['queue'] as &$item) {
            if (!is_array($item) || (string)($item['user_id'] ?? '') !== $userId) continue;
            $item['game_type'] = 'chess';
            $item['requested_board_size'] = 8;
            $item['board_size'] = 9;
            unset($item);
            return;
        }
        unset($item);
    }

    private function withIsolatedQueue(
        array &$db,
        string $gameType,
        callable $callback,
        ?string $dropUserId = null
    ): mixed {
        $originalQueue = is_array($db['queue'] ?? null) ? array_values($db['queue']) : [];
        $originalGames = is_array($db['games'] ?? null) ? $db['games'] : [];
        $workingQueue = [];
        $legacyGames = [];

        foreach ($originalQueue as $item) {
            if (!is_array($item)) continue;
            if ($dropUserId !== null && (string)($item['user_id'] ?? '') === $dropUserId) continue;
            if ((string)($item['game_type'] ?? 'tictactoe') === $gameType) $workingQueue[] = $item;
        }

        foreach ($originalGames as $id => $game) {
            if (!is_array($game)) continue;
            if ((string)($game['game_type'] ?? 'tictactoe') === 'tictactoe') $legacyGames[$id] = $game;
        }

        $db['queue'] = $workingQueue;
        $db['games'] = $legacyGames;

        try {
            return $callback();
        } finally {
            $updatedWorkingQueue = is_array($db['queue'] ?? null) ? array_values($db['queue']) : [];
            $updatedLegacyGames = is_array($db['games'] ?? null) ? $db['games'] : [];
            $db['queue'] = $this->mergeIsolatedQueue($originalQueue, $updatedWorkingQueue, $gameType, $dropUserId);
            $db['games'] = $this->mergeLegacyGames($originalGames, $updatedLegacyGames);
        }
    }

    private function mergeLegacyGames(array $originalGames, array $updatedLegacyGames): array
    {
        $merged = [];
        foreach ($originalGames as $id => $game) {
            if (!is_array($game)) {
                $merged[$id] = $game;
                continue;
            }
            if ((string)($game['game_type'] ?? 'tictactoe') !== 'tictactoe') {
                $merged[$id] = $game;
                continue;
            }
            if (array_key_exists($id, $updatedLegacyGames)) {
                $merged[$id] = $updatedLegacyGames[$id];
                unset($updatedLegacyGames[$id]);
            }
        }
        foreach ($updatedLegacyGames as $id => $game) $merged[$id] = $game;
        return $merged;
    }

    private function mergeIsolatedQueue(
        array $originalQueue,
        array $updatedWorkingQueue,
        string $gameType,
        ?string $dropUserId
    ): array {
        $updatedById = [];
        $updatedWithoutId = [];
        foreach ($updatedWorkingQueue as $item) {
            if (!is_array($item)) continue;
            $id = trim((string)($item['id'] ?? ''));
            if ($id !== '') $updatedById[$id] = $item;
            else $updatedWithoutId[] = $item;
        }

        $merged = [];
        foreach ($originalQueue as $item) {
            if (!is_array($item)) continue;
            if ($dropUserId !== null && (string)($item['user_id'] ?? '') === $dropUserId) continue;
            if ((string)($item['game_type'] ?? 'tictactoe') !== $gameType) {
                $merged[] = $item;
                continue;
            }
            $id = trim((string)($item['id'] ?? ''));
            if ($id !== '' && isset($updatedById[$id])) {
                $merged[] = $updatedById[$id];
                unset($updatedById[$id]);
            }
        }

        foreach ($updatedById as $item) $merged[] = $item;
        foreach ($updatedWithoutId as $item) $merged[] = $item;
        return array_values($merged);
    }

    private function syncGameMetadataTransactions(array &$db, string $gameId): void
    {
        if (!isset($db['transactions']) || !is_array($db['transactions'])) return;
        foreach ($db['transactions'] as &$transaction) {
            if (!is_array($transaction) || (string)($transaction['game_id'] ?? '') !== $gameId) continue;
            $transaction['game_type'] = 'chess';
            $transaction['board_size'] = 8;
            $transaction['board_columns'] = 8;
            $transaction['board_rows'] = 8;
        }
        unset($transaction);
    }
}
