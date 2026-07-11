<?php
declare(strict_types=1);

final class GameRuntimeService
{
    public function __construct(
        private array $config,
        private GameCatalogService $catalog,
        private GameService $legacyGame
    ) {}

    public function cleanup(array &$db): void
    {
        $this->normalizeDatabaseGameTypes($db);
        $this->legacyGame->cleanup($db);
    }

    public function cleanupQueue(array &$db): void
    {
        $this->normalizeDatabaseGameTypes($db);
        $this->legacyGame->cleanupQueue($db);
    }

    public function refreshSearch(array &$db, array &$user): void
    {
        $this->normalizeDatabaseGameTypes($db);
        $this->legacyGame->refreshSearch($db, $user);
    }

    public function maybeCreateBotGameForSearchingUser(array &$db, array &$user): ?array
    {
        $this->normalizeDatabaseGameTypes($db);
        $queueItem = $this->queueItemForUser($db, (string)($user['id'] ?? ''));
        if ($queueItem) {
            $gameType = $this->gameTypeFromRecord($queueItem);
            if (!$this->catalog->supportsBot($gameType)) {
                return null;
            }
        }

        $game = $this->legacyGame->maybeCreateBotGameForSearchingUser($db, $user);
        if (is_array($game)) {
            $this->ensureGameType($game);
            $gameId = (string)($game['id'] ?? '');
            if ($gameId !== '' && isset($db['games'][$gameId]) && is_array($db['games'][$gameId])) {
                $this->ensureGameType($db['games'][$gameId]);
                return $db['games'][$gameId];
            }
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
        $this->normalizeDatabaseGameTypes($db);
        $gameType = $this->catalog->normalizeGameType($gameType);
        $room = $room === 'gold' ? 'gold' : 'match';

        if (!$this->catalog->supportsRoom($gameType, $room)) {
            throw new RuntimeException('Эта игра недоступна в выбранной комнате.');
        }

        $boardSize = $this->catalog->normalizeBoardSize($gameType, $boardSize);

        // Until the generic engines are introduced, the only registered engine
        // is the proven tic-tac-toe implementation.
        $definition = $this->catalog->get($gameType);
        if ((string)($definition['engine'] ?? '') !== 'tictactoe') {
            throw new RuntimeException('Движок этой игры пока не подключён.');
        }

        $result = $this->legacyGame->startSearch($db, $user, $room, $bet, $boardSize);

        // Existing GameService currently creates tic-tac-toe records itself.
        // Normalize them through the registry so old and new records share one model.
        $this->normalizeDatabaseGameTypes($db);

        if (isset($result['game']) && is_array($result['game'])) {
            $gameId = (string)($result['game']['id'] ?? '');
            if ($gameId !== '' && isset($db['games'][$gameId]) && is_array($db['games'][$gameId])) {
                $db['games'][$gameId]['game_type'] = $gameType;
                $result['game'] = $this->publicGame($db['games'][$gameId], (string)($user['id'] ?? ''));
            }
        } else {
            $this->setQueuedGameType($db, (string)($user['id'] ?? ''), $gameType);
        }

        return $result;
    }

    public function leaveSearch(array &$db, array &$user): void
    {
        $this->legacyGame->leaveSearch($db, $user);
    }

    public function surrenderGame(array &$db, array &$user, string $gameId): array
    {
        $this->normalizeDatabaseGameTypes($db);
        $game = $this->legacyGame->surrenderGame($db, $user, $gameId);
        $this->ensureGameType($game);
        if (isset($db['games'][$gameId]) && is_array($db['games'][$gameId])) {
            $this->ensureGameType($db['games'][$gameId]);
            return $db['games'][$gameId];
        }
        return $game;
    }

    public function findActiveGameForUser(array $db, string $userId): ?array
    {
        $game = $this->legacyGame->findActiveGameForUser($db, $userId);
        if (!$game) {
            return null;
        }
        $this->ensureGameType($game);
        return $game;
    }

    public function makeMove(array &$db, array &$user, string $gameId, int $cell): array
    {
        $this->normalizeDatabaseGameTypes($db);
        $game = $db['games'][$gameId] ?? null;
        if (is_array($game)) {
            $definition = $this->catalog->get($this->gameTypeFromRecord($game));
            if ((string)($definition['engine'] ?? '') !== 'tictactoe') {
                throw new RuntimeException('Это действие не поддерживается выбранной игрой.');
            }
        }

        $result = $this->legacyGame->makeMove($db, $user, $gameId, $cell);
        if (isset($db['games'][$gameId]) && is_array($db['games'][$gameId])) {
            $this->ensureGameType($db['games'][$gameId]);
            return $db['games'][$gameId];
        }
        $this->ensureGameType($result);
        return $result;
    }

    public function publicGame(array $game, string $viewerId): array
    {
        $this->ensureGameType($game);
        $gameType = $this->gameTypeFromRecord($game);
        $definition = $this->catalog->publicGameDefinition($gameType);
        $public = $this->legacyGame->publicGame($game, $viewerId);

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

    private function normalizeDatabaseGameTypes(array &$db): void
    {
        if (isset($db['games']) && is_array($db['games'])) {
            foreach ($db['games'] as &$game) {
                if (is_array($game)) {
                    $this->ensureGameType($game);
                }
            }
            unset($game);
        }

        if (isset($db['queue']) && is_array($db['queue'])) {
            foreach ($db['queue'] as &$item) {
                if (is_array($item)) {
                    $this->ensureGameType($item);
                }
            }
            unset($item);
        }
    }

    private function ensureGameType(array &$record): void
    {
        $record['game_type'] = $this->gameTypeFromRecord($record);
    }

    private function gameTypeFromRecord(array $record): string
    {
        return $this->catalog->normalizeGameType((string)($record['game_type'] ?? ''));
    }

    private function setQueuedGameType(array &$db, string $userId, string $gameType): void
    {
        if ($userId === '' || !isset($db['queue']) || !is_array($db['queue'])) {
            return;
        }

        foreach ($db['queue'] as &$item) {
            if (is_array($item) && (string)($item['user_id'] ?? '') === $userId) {
                $item['game_type'] = $gameType;
                return;
            }
        }
        unset($item);
    }

    private function queueItemForUser(array $db, string $userId): ?array
    {
        if ($userId === '') {
            return null;
        }

        foreach ($db['queue'] ?? [] as $item) {
            if (is_array($item) && (string)($item['user_id'] ?? '') === $userId) {
                return $item;
            }
        }
        return null;
    }
}
