<?php
declare(strict_types=1);

namespace Mgw\CleanRuntime\Server\Match;

use Mgw\CleanRuntime\Server\Context\RuntimeRequestContext;
use Mgw\CleanRuntime\Server\RuntimeConfig;

final readonly class RuntimeMatchService
{
    public function __construct(
        private RuntimeConfig $config,
        private TicTacToeRules $rules,
    ) {}

    /** @param array<string,mixed> $state @return array<string,mixed> */
    public function startSearch(array &$state, RuntimeRequestContext $context, string $commandId, int $nowEpoch): array
    {
        $accountId = $context->accountId();
        $this->reconcile($state, $accountId, $nowEpoch);
        if ($this->commandSeen($state, $accountId, $commandId, 'start_search')) {
            return $this->projection($state, $accountId, $nowEpoch);
        }

        $account =& $this->account($state, $accountId);
        if ((string)($account['status'] ?? 'idle') === 'playing' && $this->activeGame($state, $accountId) !== null) {
            $this->rememberCommand($state, $accountId, $commandId, 'start_search', $nowEpoch);
            return $this->projection($state, $accountId, $nowEpoch);
        }
        if ((int)($account['balance_match'] ?? 0) < $this->config->matchBet) {
            throw new \RuntimeException('Недостаточно тестовых Match-коинов для поиска.');
        }

        unset($state['queue'][$accountId]);
        $opponentId = $this->findOpponent($state, $accountId, $nowEpoch);
        if ($opponentId !== null) {
            $this->createGame($state, $accountId, $opponentId, $nowEpoch);
        } else {
            $now = gmdate('c', $nowEpoch);
            $account['status'] = 'searching';
            $account['current_game_id'] = null;
            $account['active_session_id'] = $context->sessionId;
            $account['active_session_at'] = $now;
            $account['updated_at'] = $now;
            $state['queue'][$accountId] = [
                'id' => $this->id('queue'),
                'account_id' => $accountId,
                'session_id' => $context->sessionId,
                'game_type' => 'tictactoe',
                'room' => 'match',
                'bet' => $this->config->matchBet,
                'board_size' => TicTacToeRules::BOARD_SIZE,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        $this->rememberCommand($state, $accountId, $commandId, 'start_search', $nowEpoch);
        return $this->projection($state, $accountId, $nowEpoch);
    }

    /** @param array<string,mixed> $state @return array<string,mixed> */
    public function cancelSearch(array &$state, RuntimeRequestContext $context, string $commandId, int $nowEpoch): array
    {
        $accountId = $context->accountId();
        $this->reconcile($state, $accountId, $nowEpoch);
        if ($this->commandSeen($state, $accountId, $commandId, 'cancel_search')) {
            return $this->projection($state, $accountId, $nowEpoch);
        }

        $account =& $this->account($state, $accountId);
        unset($state['queue'][$accountId]);
        if ((string)($account['status'] ?? '') === 'searching') {
            $account['status'] = 'idle';
            $account['current_game_id'] = null;
            $account['updated_at'] = gmdate('c', $nowEpoch);
        }

        $this->rememberCommand($state, $accountId, $commandId, 'cancel_search', $nowEpoch);
        return $this->projection($state, $accountId, $nowEpoch);
    }

    /** @param array<string,mixed> $state @return array<string,mixed> */
    public function sync(array &$state, RuntimeRequestContext $context, int $nowEpoch): array
    {
        $this->reconcile($state, $context->accountId(), $nowEpoch);
        return $this->projection($state, $context->accountId(), $nowEpoch);
    }

    /** @param array<string,mixed> $state @return array<string,mixed> */
    public function move(
        array &$state,
        RuntimeRequestContext $context,
        string $gameId,
        int $cell,
        string $commandId,
        int $nowEpoch,
    ): array {
        $accountId = $context->accountId();
        $this->reconcile($state, $accountId, $nowEpoch);
        if ($this->commandSeen($state, $accountId, $commandId, 'move')) {
            return $this->projection($state, $accountId, $nowEpoch);
        }

        $game =& $this->game($state, $gameId);
        if (!in_array($accountId, array_map('strval', $game['player_ids'] ?? []), true)) {
            throw new \RuntimeException('Вы не участник этого матча.');
        }
        if ((string)($game['status'] ?? '') !== 'active') {
            $this->rememberCommand($state, $accountId, $commandId, 'move', $nowEpoch);
            return $this->projection($state, $accountId, $nowEpoch);
        }
        if ((string)($game['turn'] ?? '') !== $accountId) {
            throw new \RuntimeException('Сейчас ход соперника.');
        }

        $symbol = (string)($game['symbols'][$accountId] ?? '');
        $board = $this->rules->place((string)$game['board'], $cell, $symbol);
        $now = gmdate('c', $nowEpoch);
        $game['board'] = $board;
        $game['last_move_at'] = $now;
        $game['updated_at'] = $now;
        $game['revision'] = max(1, (int)($game['revision'] ?? 1)) + 1;

        $winnerSymbol = $this->rules->winnerSymbol($board);
        if ($winnerSymbol !== null) {
            $winnerId = (string)array_search($winnerSymbol, $game['symbols'], true);
            $this->finishGame($state, $game, $winnerId, 'normal_win', $nowEpoch);
        } elseif ($this->rules->isDraw($board)) {
            $this->finishGame($state, $game, null, 'draw', $nowEpoch);
        } else {
            $game['turn'] = $this->otherPlayer($game, $accountId);
            $game['turn_started_at'] = $now;
        }

        $this->rememberCommand($state, $accountId, $commandId, 'move', $nowEpoch);
        return $this->projection($state, $accountId, $nowEpoch);
    }

    /** @param array<string,mixed> $state @return array<string,mixed> */
    public function surrender(
        array &$state,
        RuntimeRequestContext $context,
        string $gameId,
        string $commandId,
        int $nowEpoch,
    ): array {
        $accountId = $context->accountId();
        $this->reconcile($state, $accountId, $nowEpoch);
        if ($this->commandSeen($state, $accountId, $commandId, 'surrender')) {
            return $this->projection($state, $accountId, $nowEpoch);
        }

        $game =& $this->game($state, $gameId);
        if (!in_array($accountId, array_map('strval', $game['player_ids'] ?? []), true)) {
            throw new \RuntimeException('Вы не участник этого матча.');
        }
        if ((string)($game['status'] ?? '') === 'active') {
            $this->finishGame($state, $game, $this->otherPlayer($game, $accountId), 'surrender', $nowEpoch, $accountId);
        }

        $this->rememberCommand($state, $accountId, $commandId, 'surrender', $nowEpoch);
        return $this->projection($state, $accountId, $nowEpoch);
    }

    /** @param array<string,mixed> $state @return array<string,mixed> */
    public function dismissResult(array &$state, RuntimeRequestContext $context, string $commandId, int $nowEpoch): array
    {
        $accountId = $context->accountId();
        if ($this->commandSeen($state, $accountId, $commandId, 'dismiss_result')) {
            return $this->projection($state, $accountId, $nowEpoch);
        }
        $account =& $this->account($state, $accountId);
        $account['last_result_game_id'] = null;
        $account['updated_at'] = gmdate('c', $nowEpoch);
        $this->rememberCommand($state, $accountId, $commandId, 'dismiss_result', $nowEpoch);
        return $this->projection($state, $accountId, $nowEpoch);
    }

    /** @param array<string,mixed> $state */
    public function reconcile(array &$state, string $accountId, int $nowEpoch): void
    {
        if (!isset($state['accounts'][$accountId]) || !is_array($state['accounts'][$accountId])) {
            return;
        }
        $account =& $state['accounts'][$accountId];

        if ((string)($account['status'] ?? '') === 'searching') {
            $queue = is_array($state['queue'][$accountId] ?? null) ? $state['queue'][$accountId] : null;
            $updated = strtotime((string)($queue['updated_at'] ?? '')) ?: 0;
            if ($queue === null || $updated <= 0 || $nowEpoch - $updated > $this->config->queueTimeoutSec) {
                unset($state['queue'][$accountId]);
                $account['status'] = 'idle';
                $account['current_game_id'] = null;
                $account['updated_at'] = gmdate('c', $nowEpoch);
            }
        }

        $gameId = trim((string)($account['current_game_id'] ?? ''));
        if ($gameId === '' || !isset($state['games'][$gameId]) || !is_array($state['games'][$gameId])) {
            if ((string)($account['status'] ?? '') === 'playing') {
                $account['status'] = 'idle';
                $account['current_game_id'] = null;
            }
            return;
        }

        $game =& $state['games'][$gameId];
        if ((string)($game['status'] ?? '') !== 'active') {
            $account['status'] = 'idle';
            $account['current_game_id'] = null;
            return;
        }

        $turnStarted = strtotime((string)($game['turn_started_at'] ?? '')) ?: 0;
        if ($turnStarted > 0 && $nowEpoch - $turnStarted > $this->config->moveTimeoutSec) {
            $loserId = (string)($game['turn'] ?? '');
            $winnerId = $this->otherPlayer($game, $loserId);
            $this->finishGame($state, $game, $winnerId, 'timeout', $nowEpoch, $loserId);
        }
    }

    /** @param array<string,mixed> $state @return array<string,mixed> */
    public function projection(array $state, string $accountId, int $nowEpoch): array
    {
        $account = is_array($state['accounts'][$accountId] ?? null) ? $state['accounts'][$accountId] : [];
        $queue = is_array($state['queue'][$accountId] ?? null) ? $state['queue'][$accountId] : null;
        $active = $this->activeGame($state, $accountId);
        $resultGameId = trim((string)($account['last_result_game_id'] ?? ''));
        $resultGame = $resultGameId !== '' && is_array($state['games'][$resultGameId] ?? null)
            ? $state['games'][$resultGameId]
            : null;

        return [
            'matchmaking' => $queue !== null && (string)($account['status'] ?? '') === 'searching'
                ? [
                    'status' => 'searching',
                    'queue_id' => $queue['id'],
                    'game_type' => 'tictactoe',
                    'room' => 'match',
                    'bet' => $this->config->matchBet,
                    'board_size' => TicTacToeRules::BOARD_SIZE,
                    'started_at' => $queue['created_at'],
                    'expires_at' => gmdate('c', (strtotime((string)$queue['updated_at']) ?: $nowEpoch) + $this->config->queueTimeoutSec),
                    'legal_actions' => ['cancel_search'],
                ]
                : null,
            'active_match' => $active !== null ? $this->publicMatch($active, $accountId, $nowEpoch) : null,
            'match_result' => $resultGame !== null && (string)($resultGame['status'] ?? '') === 'finished'
                ? $this->publicResult($resultGame, $accountId)
                : null,
            'balances' => [
                'match' => max(0, (int)($account['balance_match'] ?? 0)),
            ],
        ];
    }

    /** @param array<string,mixed> $state */
    private function findOpponent(array &$state, string $accountId, int $nowEpoch): ?string
    {
        foreach ($state['queue'] ?? [] as $otherId => $queue) {
            $otherId = (string)$otherId;
            if ($otherId === '' || $otherId === $accountId || !is_array($queue)) {
                continue;
            }
            $updated = strtotime((string)($queue['updated_at'] ?? '')) ?: 0;
            if ($updated <= 0 || $nowEpoch - $updated > $this->config->queueTimeoutSec) {
                unset($state['queue'][$otherId]);
                if (isset($state['accounts'][$otherId]) && is_array($state['accounts'][$otherId])) {
                    $state['accounts'][$otherId]['status'] = 'idle';
                    $state['accounts'][$otherId]['current_game_id'] = null;
                }
                continue;
            }
            if ((string)($queue['game_type'] ?? '') !== 'tictactoe'
                || (string)($queue['room'] ?? '') !== 'match'
                || (int)($queue['bet'] ?? 0) !== $this->config->matchBet
                || (int)($queue['board_size'] ?? 0) !== TicTacToeRules::BOARD_SIZE) {
                continue;
            }
            $other = is_array($state['accounts'][$otherId] ?? null) ? $state['accounts'][$otherId] : null;
            if ($other === null || (string)($other['status'] ?? '') !== 'searching') {
                continue;
            }
            $sessionId = (string)($queue['session_id'] ?? '');
            $presence = is_array($state['presence'][$sessionId] ?? null) ? $state['presence'][$sessionId] : null;
            $expires = strtotime((string)($presence['expires_at'] ?? '')) ?: 0;
            if ($presence === null || $expires <= $nowEpoch) {
                continue;
            }
            if ((string)($other['active_session_id'] ?? '') !== $sessionId) {
                continue;
            }
            return $otherId;
        }
        return null;
    }

    /** @param array<string,mixed> $state */
    private function createGame(array &$state, string $firstId, string $secondId, int $nowEpoch): void
    {
        $first =& $this->account($state, $firstId);
        $second =& $this->account($state, $secondId);
        foreach ([$firstId => &$first, $secondId => &$second] as $id => &$account) {
            if ((int)($account['balance_match'] ?? 0) < $this->config->matchBet) {
                unset($state['queue'][$id]);
                $account['status'] = 'idle';
                throw new \RuntimeException('Один из игроков больше не может участвовать в матче.');
            }
        }
        unset($account);

        $xId = random_int(0, 1) === 0 ? $firstId : $secondId;
        $oId = $xId === $firstId ? $secondId : $firstId;
        $gameId = $this->id('game');
        $now = gmdate('c', $nowEpoch);

        foreach ([$firstId, $secondId] as $playerId) {
            $state['accounts'][$playerId]['balance_match'] = (int)$state['accounts'][$playerId]['balance_match'] - $this->config->matchBet;
            $state['accounts'][$playerId]['status'] = 'playing';
            $state['accounts'][$playerId]['current_game_id'] = $gameId;
            $state['accounts'][$playerId]['last_result_game_id'] = null;
            $state['accounts'][$playerId]['updated_at'] = $now;
            unset($state['queue'][$playerId]);
            $state['ledger'][] = [
                'id' => $this->id('ledger'),
                'type' => 'match_entry',
                'account_id' => $playerId,
                'game_id' => $gameId,
                'amount' => -$this->config->matchBet,
                'balance_after' => (int)$state['accounts'][$playerId]['balance_match'],
                'created_at' => $now,
            ];
        }

        $state['games'][$gameId] = [
            'id' => $gameId,
            'game_type' => 'tictactoe',
            'room' => 'match',
            'bet' => $this->config->matchBet,
            'bank' => $this->config->matchBet * 2,
            'board_size' => TicTacToeRules::BOARD_SIZE,
            'board' => TicTacToeRules::EMPTY_BOARD,
            'player_ids' => [$firstId, $secondId],
            'player_names' => [
                $firstId => $this->accountName($first),
                $secondId => $this->accountName($second),
            ],
            'symbols' => [$xId => 'X', $oId => 'O'],
            'turn' => $xId,
            'status' => 'active',
            'winner_id' => null,
            'loser_id' => null,
            'finish_reason' => null,
            'payout' => 0,
            'commission' => 0,
            'payout_done' => false,
            'revision' => 1,
            'created_at' => $now,
            'updated_at' => $now,
            'last_move_at' => $now,
            'turn_started_at' => $now,
        ];
    }

    /** @param array<string,mixed> $state @param array<string,mixed> $game */
    private function finishGame(
        array &$state,
        array &$game,
        ?string $winnerId,
        string $reason,
        int $nowEpoch,
        ?string $loserId = null,
    ): void {
        if (!empty($game['payout_done'])) {
            return;
        }
        if ((string)($game['status'] ?? '') === 'finished') {
            return;
        }

        $now = gmdate('c', $nowEpoch);
        $players = array_values(array_map('strval', $game['player_ids'] ?? []));
        $loserId = $winnerId !== null ? ($loserId ?: $this->otherPlayer($game, $winnerId)) : null;
        $bank = $this->config->matchBet * max(2, count($players));
        $commission = 0;
        $payout = 0;

        if ($winnerId === null) {
            foreach ($players as $playerId) {
                if (!isset($state['accounts'][$playerId])) continue;
                $state['accounts'][$playerId]['balance_match'] = (int)$state['accounts'][$playerId]['balance_match'] + $this->config->matchBet;
                $state['ledger'][] = [
                    'id' => $this->id('ledger'),
                    'type' => 'match_draw_refund',
                    'account_id' => $playerId,
                    'game_id' => $game['id'],
                    'amount' => $this->config->matchBet,
                    'balance_after' => (int)$state['accounts'][$playerId]['balance_match'],
                    'created_at' => $now,
                ];
            }
            $payout = $this->config->matchBet;
        } else {
            $commission = (int)ceil($bank * $this->config->commissionRate);
            $payout = max(0, $bank - $commission);
            if (isset($state['accounts'][$winnerId])) {
                $state['accounts'][$winnerId]['balance_match'] = (int)$state['accounts'][$winnerId]['balance_match'] + $payout;
                $state['ledger'][] = [
                    'id' => $this->id('ledger'),
                    'type' => 'match_win',
                    'account_id' => $winnerId,
                    'game_id' => $game['id'],
                    'amount' => $payout,
                    'balance_after' => (int)$state['accounts'][$winnerId]['balance_match'],
                    'created_at' => $now,
                ];
            }
            $state['system']['fees_match'] = (int)($state['system']['fees_match'] ?? 0) + $commission;
        }

        $game['status'] = 'finished';
        $game['winner_id'] = $winnerId;
        $game['loser_id'] = $loserId;
        $game['finish_reason'] = $reason;
        $game['payout'] = $payout;
        $game['commission'] = $commission;
        $game['payout_done'] = true;
        $game['finished_at'] = $now;
        $game['updated_at'] = $now;
        $game['revision'] = max(1, (int)($game['revision'] ?? 1)) + 1;

        foreach ($players as $playerId) {
            if (!isset($state['accounts'][$playerId]) || !is_array($state['accounts'][$playerId])) continue;
            $state['accounts'][$playerId]['status'] = 'idle';
            $state['accounts'][$playerId]['current_game_id'] = null;
            $state['accounts'][$playerId]['last_result_game_id'] = $game['id'];
            $state['accounts'][$playerId]['updated_at'] = $now;
            unset($state['queue'][$playerId]);
        }

        $state['ledger'][] = [
            'id' => $this->id('ledger'),
            'type' => 'match_finish',
            'game_id' => $game['id'],
            'winner_id' => $winnerId,
            'loser_id' => $loserId,
            'reason' => $reason,
            'bank' => $bank,
            'commission' => $commission,
            'payout' => $payout,
            'created_at' => $now,
        ];
    }

    /** @param array<string,mixed> $state */
    private function activeGame(array $state, string $accountId): ?array
    {
        $account = is_array($state['accounts'][$accountId] ?? null) ? $state['accounts'][$accountId] : [];
        $gameId = trim((string)($account['current_game_id'] ?? ''));
        $game = $gameId !== '' && is_array($state['games'][$gameId] ?? null) ? $state['games'][$gameId] : null;
        return $game !== null && (string)($game['status'] ?? '') === 'active' ? $game : null;
    }

    /** @param array<string,mixed> $game @return array<string,mixed> */
    private function publicMatch(array $game, string $viewerId, int $nowEpoch): array
    {
        $turnStarted = strtotime((string)($game['turn_started_at'] ?? '')) ?: $nowEpoch;
        $players = [];
        foreach ($game['player_ids'] ?? [] as $playerId) {
            $playerId = (string)$playerId;
            $players[] = [
                'id' => $playerId,
                'name' => (string)($game['player_names'][$playerId] ?? 'Игрок'),
                'symbol' => (string)($game['symbols'][$playerId] ?? '?'),
            ];
        }
        return [
            'id' => $game['id'],
            'game_type' => 'tictactoe',
            'room' => 'match',
            'bet' => (int)$game['bet'],
            'board_size' => TicTacToeRules::BOARD_SIZE,
            'board' => (string)$game['board'],
            'turn' => (string)$game['turn'],
            'viewer_id' => $viewerId,
            'viewer_symbol' => (string)($game['symbols'][$viewerId] ?? '?'),
            'players' => $players,
            'status' => 'active',
            'revision' => (int)$game['revision'],
            'time_left' => max(0, $this->config->moveTimeoutSec - ($nowEpoch - $turnStarted)),
            'move_timeout_sec' => $this->config->moveTimeoutSec,
            'legal_actions' => array_values(array_filter([
                'surrender',
                (string)$game['turn'] === $viewerId ? 'move' : null,
            ])),
        ];
    }

    /** @param array<string,mixed> $game @return array<string,mixed> */
    private function publicResult(array $game, string $viewerId): array
    {
        $winnerId = $game['winner_id'] !== null ? (string)$game['winner_id'] : null;
        $outcome = $winnerId === null ? 'draw' : ($winnerId === $viewerId ? 'win' : 'loss');
        return [
            'game_id' => $game['id'],
            'game_type' => 'tictactoe',
            'outcome' => $outcome,
            'winner_id' => $winnerId,
            'loser_id' => $game['loser_id'] !== null ? (string)$game['loser_id'] : null,
            'finish_reason' => (string)$game['finish_reason'],
            'board' => (string)$game['board'],
            'players' => array_map(
                fn(string $playerId): array => [
                    'id' => $playerId,
                    'name' => (string)($game['player_names'][$playerId] ?? 'Игрок'),
                    'symbol' => (string)($game['symbols'][$playerId] ?? '?'),
                ],
                array_values(array_map('strval', $game['player_ids'] ?? []))
            ),
            'payout' => (int)$game['payout'],
            'commission' => (int)$game['commission'],
            'finished_at' => (string)($game['finished_at'] ?? ''),
            'legal_actions' => ['dismiss_result', 'start_search'],
        ];
    }

    /** @param array<string,mixed> $state */
    private function commandSeen(array $state, string $accountId, string $commandId, string $action): bool
    {
        $commandId = $this->commandId($commandId);
        $existing = is_array($state['commands'][$accountId][$commandId] ?? null)
            ? $state['commands'][$accountId][$commandId]
            : null;
        if ($existing === null) return false;
        if ((string)($existing['action'] ?? '') !== $action) {
            throw new \RuntimeException('Command identifier was already used for another action.');
        }
        return true;
    }

    /** @param array<string,mixed> $state */
    private function rememberCommand(array &$state, string $accountId, string $commandId, string $action, int $nowEpoch): void
    {
        $commandId = $this->commandId($commandId);
        if (!isset($state['commands'][$accountId]) || !is_array($state['commands'][$accountId])) {
            $state['commands'][$accountId] = [];
        }
        $state['commands'][$accountId][$commandId] = [
            'action' => $action,
            'created_at' => gmdate('c', $nowEpoch),
        ];
        if (count($state['commands'][$accountId]) > 100) {
            $state['commands'][$accountId] = array_slice($state['commands'][$accountId], -100, null, true);
        }
    }

    private function commandId(string $commandId): string
    {
        $commandId = trim($commandId);
        if (!preg_match('/^[a-zA-Z0-9_-]{20,96}$/', $commandId)) {
            throw new \InvalidArgumentException('Invalid clean match command identifier.');
        }
        return $commandId;
    }

    /** @param array<string,mixed> $state @return array<string,mixed> */
    private function &account(array &$state, string $accountId): array
    {
        if (!isset($state['accounts'][$accountId]) || !is_array($state['accounts'][$accountId])) {
            throw new \RuntimeException('Clean match account is not initialized.');
        }
        return $state['accounts'][$accountId];
    }

    /** @param array<string,mixed> $state @return array<string,mixed> */
    private function &game(array &$state, string $gameId): array
    {
        $gameId = trim($gameId);
        if (!preg_match('/^game_[a-f0-9]{24}$/', $gameId)
            || !isset($state['games'][$gameId])
            || !is_array($state['games'][$gameId])) {
            throw new \RuntimeException('Матч не найден.');
        }
        return $state['games'][$gameId];
    }

    /** @param array<string,mixed> $game */
    private function otherPlayer(array $game, string $accountId): string
    {
        foreach ($game['player_ids'] ?? [] as $playerId) {
            $playerId = (string)$playerId;
            if ($playerId !== '' && $playerId !== $accountId) return $playerId;
        }
        throw new \RuntimeException('Соперник не найден.');
    }

    /** @param array<string,mixed> $account */
    private function accountName(array $account): string
    {
        $username = trim((string)($account['username'] ?? ''));
        if ($username !== '') return '@' . ltrim($username, '@');
        $name = trim((string)($account['first_name'] ?? ''));
        return $name !== '' ? $name : 'Игрок';
    }

    private function id(string $prefix): string
    {
        return $prefix . '_' . bin2hex(random_bytes(12));
    }
}
