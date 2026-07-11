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
        if (!$queueItem) {
            return null;
        }

        $gameType = $this->gameTypeFromRecord($queueItem);
        if (!$this->catalog->supportsBot($gameType)) {
            return null;
        }

        $game = $this->withIsolatedQueue(
            $db,
            $gameType,
            function () use (&$db, &$user): ?array {
                return $this->legacyGame->maybeCreateBotGameForSearchingUser($db, $user);
            }
        );

        if (is_array($game)) {
            $this->ensureGameType($game);
            $gameId = (string)($game['id'] ?? '');
            if ($gameId !== '' && isset($db['games'][$gameId]) && is_array($db['games'][$gameId])) {
                $db['games'][$gameId]['game_type'] = $gameType;
                $this->rebalanceNewBotDifficulty($db, $user, $gameId);
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

        $userId = (string)($user['id'] ?? '');
        $result = $this->withIsolatedQueue(
            $db,
            $gameType,
            function () use (&$db, &$user, $room, $bet, $boardSize): array {
                return $this->legacyGame->startSearch($db, $user, $room, $bet, $boardSize);
            },
            $userId
        );

        // Existing GameService currently creates tic-tac-toe records itself.
        // Normalize them through the registry so old and new records share one model.
        $this->normalizeDatabaseGameTypes($db);

        if (isset($result['game']) && is_array($result['game'])) {
            $gameId = (string)($result['game']['id'] ?? '');
            if ($gameId !== '' && isset($db['games'][$gameId]) && is_array($db['games'][$gameId])) {
                $db['games'][$gameId]['game_type'] = $gameType;
                $result['game'] = $this->publicGame($db['games'][$gameId], $userId);
            }
        } else {
            $this->setQueuedGameType($db, $userId, $gameType);
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

    /**
     * Runs the legacy matcher against only one game type, then merges untouched
     * queues back in their original order. This prevents cross-game matches
     * before the legacy GameService itself is split into engines.
     */
    private function withIsolatedQueue(
        array &$db,
        string $gameType,
        callable $callback,
        ?string $dropUserId = null
    ): mixed {
        $originalQueue = is_array($db['queue'] ?? null) ? array_values($db['queue']) : [];
        $workingQueue = [];

        foreach ($originalQueue as $item) {
            if (!is_array($item)) {
                continue;
            }
            if ($dropUserId !== null && (string)($item['user_id'] ?? '') === $dropUserId) {
                continue;
            }
            if ($this->gameTypeFromRecord($item) === $gameType) {
                $workingQueue[] = $item;
            }
        }

        $db['queue'] = $workingQueue;

        try {
            return $callback();
        } finally {
            $updatedWorkingQueue = is_array($db['queue'] ?? null) ? array_values($db['queue']) : [];
            $db['queue'] = $this->mergeIsolatedQueue(
                $originalQueue,
                $updatedWorkingQueue,
                $gameType,
                $dropUserId
            );
        }
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
            if (!is_array($item)) {
                continue;
            }
            $id = trim((string)($item['id'] ?? ''));
            if ($id !== '') {
                $updatedById[$id] = $item;
            } else {
                $updatedWithoutId[] = $item;
            }
        }

        $merged = [];
        foreach ($originalQueue as $item) {
            if (!is_array($item)) {
                continue;
            }

            if ($dropUserId !== null && (string)($item['user_id'] ?? '') === $dropUserId) {
                continue;
            }

            if ($this->gameTypeFromRecord($item) !== $gameType) {
                $merged[] = $item;
                continue;
            }

            $id = trim((string)($item['id'] ?? ''));
            if ($id !== '' && isset($updatedById[$id])) {
                $merged[] = $updatedById[$id];
                unset($updatedById[$id]);
            }
        }

        foreach ($updatedById as $item) {
            $merged[] = $item;
        }
        foreach ($updatedWithoutId as $item) {
            $merged[] = $item;
        }

        return array_values($merged);
    }

    private function rebalanceNewBotDifficulty(array &$db, array $user, string $gameId): void
    {
        $game = $db['games'][$gameId] ?? null;
        if (!is_array($game) || empty($game['is_bot_game'])) {
            return;
        }

        $difficulty = $this->chooseBotDifficulty($user);
        $db['games'][$gameId]['bot_difficulty'] = $difficulty;

        if (!isset($db['transactions']) || !is_array($db['transactions'])) {
            return;
        }

        foreach ($db['transactions'] as &$transaction) {
            if (!is_array($transaction)
                || (string)($transaction['game_id'] ?? '') !== $gameId
                || !array_key_exists('bot_difficulty', $transaction)) {
                continue;
            }
            $transaction['bot_difficulty'] = $difficulty;
        }
        unset($transaction);
    }

    private function chooseBotDifficulty(array $user): string
    {
        $stats = is_array($user['stats'] ?? null) ? $user['stats'] : [];
        $games = (int)($stats['games_played'] ?? 0);
        $wins = (int)($stats['wins'] ?? 0);
        $botGames = (int)($stats['bot_games_played'] ?? 0);
        $botWins = (int)($stats['bot_wins'] ?? 0);
        $botStreak = (int)($stats['bot_win_streak'] ?? 0);

        $winRate = $games > 0 ? $wins / max(1, $games) : 0.0;
        $botWinRate = $botGames > 0 ? $botWins / max(1, $botGames) : 0.0;

        if ($botStreak >= 5 || ($botGames >= 8 && $botWinRate >= 0.70) || ($games >= 30 && $winRate >= 0.65)) {
            return $this->weightedDifficulty(['medium' => 20, 'hard' => 80]);
        }

        if ($botStreak >= 3 || ($games >= 20 && $winRate >= 0.55)) {
            return $this->weightedDifficulty(['medium' => 45, 'hard' => 55]);
        }

        if ($games < 5 && $botGames < 3) {
            return $this->weightedDifficulty(['easy' => 8, 'medium' => 62, 'hard' => 30]);
        }

        return $this->weightedDifficulty(['easy' => 5, 'medium' => 65, 'hard' => 30]);
    }

    private function weightedDifficulty(array $weights): string
    {
        $total = array_sum($weights);
        $roll = random_int(1, max(1, $total));
        $acc = 0;

        foreach ($weights as $difficulty => $weight) {
            $acc += (int)$weight;
            if ($roll <= $acc) {
                return (string)$difficulty;
            }
        }

        return 'medium';
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
