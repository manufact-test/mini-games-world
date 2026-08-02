<?php
declare(strict_types=1);

trait JsonInviteMatchmakingQueueTrait
{
    private function startSearch(
        JsonBehaviorBaselineFixture $fixture,
        array &$state,
        string $actorId,
        array $step,
        array &$context,
        DateTimeImmutable $now,
        array $config
    ): array {
        $this->assertNoOpenInvite($state, $actorId, '');
        $room = (string)($step['room'] ?? 'match') === 'gold' ? 'gold' : 'match';
        $gameType = strtolower(trim((string)($step['game_type'] ?? 'tictactoe')));
        $boardSize = max(1, (int)($step['board_size'] ?? 3));
        $bet = $room === 'match' ? (int)$config['match_bet'] : max(1, (int)($step['bet'] ?? 10));
        $this->assertBalance($state['users'][$actorId], $room, $bet);
        $state['queue'] = array_values(array_filter($state['queue'] ?? [], static fn($item): bool =>
            !is_array($item) || (string)($item['user_id'] ?? '') !== $actorId
        ));
        $opponentIndex = $this->humanOpponentIndex($state, $actorId, $room, $bet, $boardSize, $gameType, $now);
        if ($opponentIndex !== null) {
            $opponentId = (string)$state['queue'][$opponentIndex]['user_id'];
            array_splice($state['queue'], $opponentIndex, 1);
            [$game, $ledger] = $this->createHumanGame($fixture, $state, $actorId, $opponentId, $room, $bet, $boardSize, $gameType, $now, 'matchmaking', null);
            $context['last_game_id'] = $game['id'];
            return [[
                'game' => $this->publicGame($game),
            ], $this->effects([], [['type' => 'human_match_created', 'game_id' => $game['id']]], $ledger)];
        }
        $state['users'][$actorId]['status'] = 'searching';
        $state['users'][$actorId]['current_game_id'] = null;
        $queue = [
            'id' => $fixture->nextId('queue'),
            'user_id' => $actorId,
            'room' => $room,
            'bet' => $bet,
            'board_size' => $boardSize,
            'game_type' => $gameType,
            'created_at' => $now->format('c'),
            'updated_at' => $now->format('c'),
        ];
        $state['queue'][] = $queue;
        return [['queued' => true, 'queue' => $queue], $this->effects([], [['type' => 'search_queued', 'queue_id' => $queue['id']]], [])];
    }

    private function leaveSearch(array &$state, string $actorId): array
    {
        $removed = [];
        $state['queue'] = array_values(array_filter($state['queue'] ?? [], static function ($item) use ($actorId, &$removed): bool {
            if (is_array($item) && (string)($item['user_id'] ?? '') === $actorId) {
                $removed[] = (string)($item['id'] ?? '');
                return false;
            }
            return true;
        }));
        if ((string)($state['users'][$actorId]['status'] ?? '') === 'searching') {
            $state['users'][$actorId]['status'] = 'idle';
            $state['users'][$actorId]['current_game_id'] = null;
        }
        return [['cancelled' => true, 'removed_queue_ids' => $removed], $this->effects([], [['type' => 'search_cancelled', 'user_id' => $actorId]], [])];
    }

    private function botFallback(
        JsonBehaviorBaselineFixture $fixture,
        array &$state,
        string $actorId,
        array $step,
        array &$context,
        DateTimeImmutable $now,
        array $config
    ): array {
        $queueIndex = null;
        foreach ($state['queue'] ?? [] as $index => $item) {
            if (is_array($item) && (string)($item['user_id'] ?? '') === $actorId) {
                $queueIndex = (int)$index;
                break;
            }
        }
        if ($queueIndex === null || (string)($state['users'][$actorId]['status'] ?? '') !== 'searching') {
            throw new RuntimeException('Bot fallback requires an active search.');
        }
        $queue = $state['queue'][$queueIndex];
        if ((string)($queue['room'] ?? 'match') !== 'match') {
            return [['game' => null, 'bot_allowed' => false], $this->effects([], [], [])];
        }
        $created = new DateTimeImmutable((string)$queue['created_at']);
        if ($now->getTimestamp() - $created->getTimestamp() < (int)$config['bot_after_sec']) {
            return [['game' => null, 'waiting' => true], $this->effects([], [], [])];
        }
        $opponentIndex = $this->humanOpponentIndex(
            $state,
            $actorId,
            'match',
            (int)$queue['bet'],
            (int)$queue['board_size'],
            (string)$queue['game_type'],
            $now
        );
        if ($opponentIndex !== null) {
            $opponentId = (string)$state['queue'][$opponentIndex]['user_id'];
            foreach ([$queueIndex, $opponentIndex] as $index) unset($state['queue'][$index]);
            $state['queue'] = array_values($state['queue']);
            [$game, $ledger] = $this->createHumanGame($fixture, $state, $actorId, $opponentId, 'match', (int)$queue['bet'], (int)$queue['board_size'], (string)$queue['game_type'], $now, 'matchmaking', null);
            $context['last_game_id'] = $game['id'];
            return [['game' => $this->publicGame($game), 'human_preferred' => true], $this->effects([], [['type' => 'human_match_created_before_bot', 'game_id' => $game['id']]], $ledger)];
        }
        unset($state['queue'][$queueIndex]);
        $state['queue'] = array_values($state['queue']);
        [$game, $ledger] = $this->createBotGame($fixture, $state, $actorId, $queue, $step, $now);
        $context['last_game_id'] = $game['id'];
        return [['game' => $this->publicGame($game), 'human_preferred' => false], $this->effects([], [['type' => 'bot_match_created', 'game_id' => $game['id']]], $ledger)];
    }

    private function newInvite(
        JsonBehaviorBaselineFixture $fixture,
        array $state,
        string $actorId,
        array $step,
        string $source,
        string $status,
        DateTimeImmutable $now,
        array $config
    ): array {
        $room = (string)($step['room'] ?? 'match') === 'gold' ? 'gold' : 'match';
        $gameType = strtolower(trim((string)($step['game_type'] ?? 'tictactoe')));
        $boardSize = max(1, (int)($step['board_size'] ?? 3));
        $bet = $room === 'match' ? (int)$config['match_bet'] : max(1, (int)($step['bet'] ?? 10));
        $this->assertBalance($state['users'][$actorId], $room, $bet);
        [$columns, $rows] = $this->dimensions($gameType, $boardSize);
        return [
            'id' => $fixture->nextId('invite'),
            'token' => $fixture->nextId('token'),
            'status' => $status,
            'source' => $source,
            'inviter_id' => $actorId,
            'inviter_name' => $this->userName($state['users'][$actorId]),
            'invitee_id' => null,
            'invitee_name' => null,
            'game_type' => $gameType,
            'game_title' => $this->gameTitle($gameType),
            'room' => $room,
            'bet' => $bet,
            'board_size' => $boardSize,
            'board_columns' => $columns,
            'board_rows' => $rows,
            'created_at' => $now->format('c'),
            'updated_at' => $now->format('c'),
            'expires_at' => $now->modify('+' . self::INVITE_TTL_SEC . ' seconds')->format('c'),
            'shared_at' => null,
            'opened_at' => null,
            'open_requested_at' => null,
            'accepted_at' => null,
            'ready_deadline_at' => null,
            'start_deadline_at' => null,
            'started_at' => null,
            'declined_at' => null,
            'cancelled_at' => null,
            'cancelled_by' => null,
            'source_game_id' => null,
            'game_id' => null,
        ];
    }

    private function createHumanGame(
        JsonBehaviorBaselineFixture $fixture,
        array &$state,
        string $aId,
        string $bId,
        string $room,
        int $bet,
        int $boardSize,
        string $gameType,
        DateTimeImmutable $now,
        string $source,
        ?array $invite
    ): array {
        $balanceKey = $room === 'gold' ? 'balance_gold' : 'balance_match';
        $this->assertBalance($state['users'][$aId], $room, $bet);
        $this->assertBalance($state['users'][$bId], $room, $bet);
        $state['users'][$aId][$balanceKey] -= $bet;
        $state['users'][$bId][$balanceKey] -= $bet;
        $gameId = $fixture->nextId('game');
        foreach ([$aId, $bId] as $userId) {
            $state['users'][$userId]['status'] = 'playing';
            $state['users'][$userId]['current_game_id'] = $gameId;
        }
        [$columns, $rows] = $this->dimensions($gameType, $boardSize);
        $game = [
            'id' => $gameId,
            'game_type' => $gameType,
            'room' => $room,
            'bet' => $bet,
            'bank' => $bet * 2,
            'board_size' => $boardSize,
            'board_columns' => $columns,
            'board_rows' => $rows,
            'player_ids' => [$aId, $bId],
            'player_names' => [
                $aId => $this->userName($state['users'][$aId]),
                $bId => $this->userName($state['users'][$bId]),
            ],
            'turn' => $aId,
            'status' => 'active',
            'is_bot_game' => false,
            'match_source' => $source,
            'invite_id' => $invite['id'] ?? null,
            'invite_token' => $invite['token'] ?? null,
            'source_game_id' => $invite['source_game_id'] ?? null,
            'created_at' => $now->format('c'),
            'updated_at' => $now->format('c'),
            'turn_started_at' => $now->format('c'),
        ];
        $state['games'][$gameId] = $game;
        $ledger = [];
        foreach ([[$aId, $bId], [$bId, $aId]] as [$userId, $opponentId]) {
            $entry = [
                'id' => $fixture->nextId('tx'),
                'type' => 'balance_change',
                'category' => 'game_entry',
                'user_id' => $userId,
                'room' => $room,
                'amount' => -$bet,
                'balance_after' => $state['users'][$userId][$balanceKey],
                'game_id' => $gameId,
                'opponent_id' => $opponentId,
                'is_bot_game' => false,
                'created_at' => $now->format('c'),
            ];
            $state['transactions'][] = $entry;
            $ledger[] = $entry;
        }
        $start = [
            'id' => $fixture->nextId('tx'),
            'type' => 'game_start',
            'game_id' => $gameId,
            'room' => $room,
            'bet' => $bet,
            'players' => [$aId, $bId],
            'is_bot_game' => false,
            'created_at' => $now->format('c'),
        ];
        $state['transactions'][] = $start;
        $ledger[] = $start;
        return [$game, $ledger];
    }

    private function createBotGame(
        JsonBehaviorBaselineFixture $fixture,
        array &$state,
        string $userId,
        array $queue,
        array $step,
        DateTimeImmutable $now
    ): array {
        $bet = (int)$queue['bet'];
        $this->assertBalance($state['users'][$userId], 'match', $bet);
        $state['users'][$userId]['balance_match'] -= $bet;
        $gameId = $fixture->nextId('game');
        $botId = $fixture->nextId('bot');
        $botName = trim((string)($step['bot_name'] ?? 'Leo')) ?: 'Leo';
        $difficulty = trim((string)($step['bot_difficulty'] ?? 'medium')) ?: 'medium';
        $state['users'][$userId]['status'] = 'playing';
        $state['users'][$userId]['current_game_id'] = $gameId;
        [$columns, $rows] = $this->dimensions((string)$queue['game_type'], (int)$queue['board_size']);
        $game = [
            'id' => $gameId,
            'game_type' => (string)$queue['game_type'],
            'room' => 'match',
            'bet' => $bet,
            'bank' => $bet * 2,
            'board_size' => (int)$queue['board_size'],
            'board_columns' => $columns,
            'board_rows' => $rows,
            'player_ids' => [$userId, $botId],
            'player_names' => [$userId => $this->userName($state['users'][$userId]), $botId => $botName],
            'turn' => $userId,
            'status' => 'active',
            'is_bot_game' => true,
            'bot_id' => $botId,
            'bot_name' => $botName,
            'bot_difficulty' => $difficulty,
            'match_source' => 'bot_fallback',
            'created_at' => $now->format('c'),
            'updated_at' => $now->format('c'),
            'turn_started_at' => $now->format('c'),
        ];
        $state['games'][$gameId] = $game;
        $entry = [
            'id' => $fixture->nextId('tx'),
            'type' => 'balance_change',
            'category' => 'game_entry',
            'user_id' => $userId,
            'room' => 'match',
            'amount' => -$bet,
            'balance_after' => $state['users'][$userId]['balance_match'],
            'game_id' => $gameId,
            'opponent_id' => $botId,
            'is_bot_game' => true,
            'bot_difficulty' => $difficulty,
            'created_at' => $now->format('c'),
        ];
        $start = [
            'id' => $fixture->nextId('tx'),
            'type' => 'game_start',
            'game_id' => $gameId,
            'room' => 'match',
            'bet' => $bet,
            'players' => [$userId, $botId],
            'is_bot_game' => true,
            'bot_difficulty' => $difficulty,
            'created_at' => $now->format('c'),
        ];
        $state['transactions'][] = $entry;
        $state['transactions'][] = $start;
        return [$game, [$entry, $start]];
    }

}
