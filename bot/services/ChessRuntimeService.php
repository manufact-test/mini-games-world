<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/games/chess/ChessBotService.php';
require_once dirname(__DIR__) . '/games/chess/ChessService.php';
require_once dirname(__DIR__) . '/games/go/GoBotService.php';
require_once dirname(__DIR__) . '/games/go/GoService.php';
require_once dirname(__DIR__) . '/games/domino/DominoBotService.php';
require_once dirname(__DIR__) . '/games/domino/DominoService.php';

/**
 * Adds the newest isolated engines without changing the stable runtime paths of
 * previously released games. Calls outside Chess, Go and Domino are delegated unchanged.
 */
final class ChessRuntimeService
{
    private GameRuntimeService $base;
    private ChessService $chess;
    private GoService $go;
    private DominoService $domino;

    public function __construct(
        private array $config,
        private GameCatalogService $catalog,
        private GameService $legacyGame
    ) {
        $settlement = new GameSettlementService($config);
        $this->base = new GameRuntimeService($config, $catalog, $legacyGame);
        $this->chess = new ChessService($config, $settlement);
        $this->go = new GoService($config, $settlement);
        $this->domino = new DominoService($config, $settlement);
    }

    public function cleanup(array &$db): void
    {
        $this->base->cleanup($db);
        $this->chess->cleanup($db);
        $this->go->cleanup($db);
        $this->domino->cleanup($db);
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
        $userId = (string)($user['id'] ?? '');
        $queuedGoSize = null;
        foreach ($db['queue'] ?? [] as $queueItem) {
            if (!is_array($queueItem) || (string)($queueItem['user_id'] ?? '') !== $userId) continue;
            if ((string)($queueItem['game_type'] ?? '') === 'go') {
                $queuedGoSize = $this->catalog->normalizeBoardSize(
                    'go',
                    (int)($queueItem['requested_board_size'] ?? $queueItem['board_size'] ?? 9)
                );
            }
            break;
        }

        $game = $this->base->maybeCreateBotGameForSearchingUser($db, $user);
        if (!is_array($game)) return null;

        $gameId = (string)($game['id'] ?? '');
        if ($gameId === '' || !isset($db['games'][$gameId]) || !is_array($db['games'][$gameId])) {
            return $game;
        }

        $gameType = (string)($db['games'][$gameId]['game_type'] ?? '');
        if ($gameType === 'chess') {
            $this->prepareStoredChessGame($db, $gameId);
            return $db['games'][$gameId];
        }
        if ($gameType === 'go') {
            $this->prepareStoredGoGame($db, $gameId, $queuedGoSize);
            return $db['games'][$gameId];
        }
        if ($gameType === 'domino') {
            $this->prepareStoredDominoGame($db, $gameId);
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
        $this->assertNoInviteReadyCheck($db, $user);
        $gameType = $this->catalog->normalizeGameType($gameType);
        if (!in_array($gameType, ['chess', 'go', 'domino'], true)) {
            return $this->base->startSearch($db, $user, $room, $bet, $boardSize, $gameType);
        }

        $room = $room === 'gold' ? 'gold' : 'match';
        if (!$this->catalog->supportsRoom($gameType, $room)) {
            throw new RuntimeException('Эта игра недоступна в выбранной комнате.');
        }

        $userId = (string)($user['id'] ?? '');
        $active = $this->findActiveGameForUser($db, $userId);
        if ($active) return ['game' => $this->publicGame($active, $userId)];

        $requestedBoardSize = match ($gameType) {
            'chess' => 8,
            'domino' => 7,
            default => $this->catalog->normalizeBoardSize('go', $boardSize),
        };
        $legacyBoardSize = match ($gameType) {
            'chess' => 9,
            'domino' => 3,
            default => $requestedBoardSize === 9 ? 9 : 5,
        };

        $result = $this->withIsolatedQueue(
            $db,
            $gameType,
            function () use (&$db, &$user, $room, $bet, $legacyBoardSize): array {
                return $this->legacyGame->startSearch($db, $user, $room, $bet, $legacyBoardSize);
            },
            $userId
        );

        if (isset($result['game']) && is_array($result['game'])) {
            $gameId = (string)($result['game']['id'] ?? '');
            if ($gameId !== '' && isset($db['games'][$gameId]) && is_array($db['games'][$gameId])) {
                $db['games'][$gameId]['game_type'] = $gameType;
                if ($gameType === 'domino') {
                    $db['games'][$gameId]['board_size'] = 7;
                    $db['games'][$gameId]['board_columns'] = 7;
                    $db['games'][$gameId]['board_rows'] = 1;
                    $this->domino->initializeGame($db['games'][$gameId]);
                } else {
                    $db['games'][$gameId]['board_size'] = $requestedBoardSize;
                    $db['games'][$gameId]['board_columns'] = $requestedBoardSize;
                    $db['games'][$gameId]['board_rows'] = $requestedBoardSize;
                    if ($gameType === 'chess') $this->chess->initializeGame($db['games'][$gameId]);
                    else $this->go->initializeGame($db['games'][$gameId]);
                }
                $this->syncGameMetadataTransactions($db, $gameId, $gameType, $requestedBoardSize);
                $result['game'] = $this->publicGame($db['games'][$gameId], $userId);
            }
        } else {
            $this->setQueuedSpecialType($db, $userId, $gameType, $requestedBoardSize, $legacyBoardSize);
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
        if (is_array($game)) {
            $gameType = (string)($game['game_type'] ?? '');
            if ($gameType === 'chess') return $this->chess->surrender($db, $user, $gameId);
            if ($gameType === 'go') return $this->go->surrender($db, $user, $gameId);
            if ($gameType === 'domino') return $this->domino->surrender($db, $user, $gameId);
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

    public function applyGoAction(array &$db, array &$user, string $gameId, array $action): array
    {
        return $this->go->applyAction($db, $user, $gameId, $action);
    }

    public function applyDominoAction(array &$db, array &$user, string $gameId, array $action): array
    {
        return $this->domino->applyAction($db, $user, $gameId, $action);
    }

    public function publicGame(array $game, string $viewerId): array
    {
        $gameType = (string)($game['game_type'] ?? '');
        if (!in_array($gameType, ['chess', 'go', 'domino'], true)) {
            return $this->base->publicGame($game, $viewerId);
        }

        $definition = $this->catalog->publicGameDefinition($gameType);
        $public = match ($gameType) {
            'chess' => $this->chess->publicGame($game, $viewerId),
            'go' => $this->go->publicGame($game, $viewerId),
            'domino' => $this->domino->publicGame($game, $viewerId),
        };

        return [
            'game_type' => $gameType,
            'game_title' => (string)$definition['title'],
            'renderer' => (string)$definition['renderer'],
            'action_type' => (string)$definition['action_type'],
        ] + $public;
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
        $this->syncGameMetadataTransactions($db, $gameId, 'chess', 8);
    }

    private function prepareStoredGoGame(array &$db, string $gameId, ?int $requestedSize = null): void
    {
        $queuedSize = $requestedSize ?? (int)($db['games'][$gameId]['requested_board_size'] ?? $db['games'][$gameId]['board_size'] ?? 9);
        $size = $this->catalog->normalizeBoardSize('go', $queuedSize);
        $db['games'][$gameId]['game_type'] = 'go';
        $db['games'][$gameId]['board_size'] = $size;
        $db['games'][$gameId]['board_columns'] = $size;
        $db['games'][$gameId]['board_rows'] = $size;
        $this->go->initializeGame($db['games'][$gameId]);
        $this->syncGameMetadataTransactions($db, $gameId, 'go', $size);
    }

    private function prepareStoredDominoGame(array &$db, string $gameId): void
    {
        $db['games'][$gameId]['game_type'] = 'domino';
        $db['games'][$gameId]['board_size'] = 7;
        $db['games'][$gameId]['board_columns'] = 7;
        $db['games'][$gameId]['board_rows'] = 1;
        $this->domino->initializeGame($db['games'][$gameId]);
        $this->syncGameMetadataTransactions($db, $gameId, 'domino', 7);
    }

    private function setQueuedSpecialType(
        array &$db,
        string $userId,
        string $gameType,
        int $requestedBoardSize,
        int $legacyBoardSize
    ): void {
        if (!isset($db['queue']) || !is_array($db['queue'])) return;

        foreach ($db['queue'] as &$item) {
            if (!is_array($item) || (string)($item['user_id'] ?? '') !== $userId) continue;
            $item['game_type'] = $gameType;
            $item['requested_board_size'] = $requestedBoardSize;
            $item['board_size'] = $legacyBoardSize;
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

    private function assertNoInviteReadyCheck(array &$db, array $user): void
    {
        $userId = trim((string)($user['id'] ?? ''));
        if ($userId === '') return;

        foreach ($db['invites'] ?? [] as &$invite) {
            if (!is_array($invite) || (string)($invite['status'] ?? '') !== 'awaiting_start') continue;
            $isParticipant = (string)($invite['inviter_id'] ?? '') === $userId
                || (string)($invite['invitee_id'] ?? '') === $userId;
            if (!$isParticipant) continue;

            $deadline = strtotime((string)($invite['start_deadline_at'] ?? '')) ?: 0;
            if ($deadline > 0 && $deadline <= time()) {
                $invite['status'] = 'timed_out';
                $invite['updated_at'] = now_iso();
                continue;
            }

            unset($invite);
            throw new RuntimeException('Сначала запустите или отмените подтверждённое приглашение.');
        }
        unset($invite);
    }

    private function syncGameMetadataTransactions(
        array &$db,
        string $gameId,
        string $gameType,
        int $boardSize
    ): void {
        if (!isset($db['transactions']) || !is_array($db['transactions'])) return;
        foreach ($db['transactions'] as &$transaction) {
            if (!is_array($transaction) || (string)($transaction['game_id'] ?? '') !== $gameId) continue;
            $transaction['game_type'] = $gameType;
            $transaction['board_size'] = $boardSize;
            $transaction['board_columns'] = $boardSize;
            $transaction['board_rows'] = $gameType === 'domino' ? 1 : $boardSize;
        }
        unset($transaction);
    }
}
