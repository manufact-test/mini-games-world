<?php
declare(strict_types=1);

require __DIR__ . '/core/bootstrap.php';

const MGW_V111_PREPARATION_TIMEOUT_SEC = 10;
const MGW_V111_COUNTDOWN_SEC = 3;
const MGW_V111_TURN_HANDOFF_SEC = 1;
const MGW_V111_MOVE_TIMEOUT_SEC = 60;
const MGW_V111_RECENT_GAME_WINDOW_SEC = 20;

function mgw_v111_epoch_ms(?string $value): ?int
{
    $timestamp = strtotime((string)$value);
    return $timestamp === false ? null : $timestamp * 1000;
}

function mgw_v111_initialize_launch(array &$game): void
{
    if (isset($game['launch_phase'])) return;

    $createdAt = strtotime((string)($game['created_at'] ?? '')) ?: 0;
    $isRecent = $createdAt > 0 && time() - $createdAt <= MGW_V111_RECENT_GAME_WINDOW_SEC;
    if ((string)($game['status'] ?? '') !== 'active' || !$isRecent) {
        $game['launch_phase'] = (string)($game['status'] ?? '') === 'active' ? 'active' : 'finished';
        $game['v111_clock_turn'] = (string)($game['turn'] ?? '');
        $game['v111_clock_revision'] = max(1, (int)($game['v111_clock_revision'] ?? 0));
        if (empty($game['turn_starts_at'])) {
            $game['turn_starts_at'] = (string)($game['turn_started_at'] ?? $game['last_move_at'] ?? $game['created_at'] ?? now_iso());
        }
        if (empty($game['turn_deadline_at'])) {
            $started = strtotime((string)$game['turn_starts_at']) ?: time();
            $game['turn_deadline_at'] = gmdate('c', $started + MGW_V111_MOVE_TIMEOUT_SEC);
        }
        return;
    }

    $now = time();
    $deadline = $now + MGW_V111_PREPARATION_TIMEOUT_SEC;
    $game['launch_phase'] = 'preparing';
    $game['preparing_started_at'] = gmdate('c', $now);
    $game['preparation_deadline_at'] = gmdate('c', $deadline);
    $game['v111_ready_devices'] = [];
    $game['starts_at'] = null;
    $game['turn_starts_at'] = null;
    $game['turn_deadline_at'] = null;
    $game['v111_clock_turn'] = '';
    $game['v111_clock_revision'] = 0;
    // Keep legacy cleanup from consuming the turn while both clients prepare.
    $game['turn_started_at'] = gmdate('c', $deadline);
    unset($game['bot_move_after_at']);
    $game['updated_at'] = now_iso();
}

function mgw_v111_mark_ready(array &$game, string $userId, string $sessionId): void
{
    if ((string)($game['launch_phase'] ?? '') !== 'preparing') return;
    if (!isset($game['v111_ready_devices']) || !is_array($game['v111_ready_devices'])) {
        $game['v111_ready_devices'] = [];
    }

    $game['v111_ready_devices'][$userId] = [
        'device' => hash('sha256', $sessionId),
        'ready_at' => now_iso(),
    ];

    foreach (array_map('strval', $game['player_ids'] ?? []) as $playerId) {
        if ($playerId !== '' && str_starts_with($playerId, 'bot_')) {
            $game['v111_ready_devices'][$playerId] = [
                'device' => 'server-bot',
                'ready_at' => now_iso(),
            ];
        }
    }
}

function mgw_v111_all_ready(array $game): bool
{
    $ready = is_array($game['v111_ready_devices'] ?? null) ? $game['v111_ready_devices'] : [];
    foreach (array_map('strval', $game['player_ids'] ?? []) as $playerId) {
        if ($playerId === '' || !isset($ready[$playerId])) return false;
    }
    return count($game['player_ids'] ?? []) >= 2;
}

function mgw_v111_start_countdown(array &$game): void
{
    if ((string)($game['launch_phase'] ?? '') !== 'preparing' || !mgw_v111_all_ready($game)) return;

    $startsAt = time() + MGW_V111_COUNTDOWN_SEC;
    $game['launch_phase'] = 'countdown';
    $game['starts_at'] = gmdate('c', $startsAt);
    $game['turn_started_at'] = gmdate('c', $startsAt);
    $game['turn_starts_at'] = gmdate('c', $startsAt);
    $game['turn_deadline_at'] = gmdate('c', $startsAt + MGW_V111_MOVE_TIMEOUT_SEC);
    $game['v111_clock_turn'] = (string)($game['turn'] ?? '');
    $game['v111_clock_revision'] = 1;
    $game['updated_at'] = now_iso();

    $botId = (string)($game['bot_id'] ?? '');
    if (!empty($game['is_bot_game']) && $botId !== '' && (string)($game['turn'] ?? '') === $botId) {
        $game['bot_move_after_at'] = gmdate('c', $startsAt + 1);
    }
}

function mgw_v111_activate_if_due(array &$game): void
{
    if ((string)($game['launch_phase'] ?? '') !== 'countdown') return;
    $startsAt = strtotime((string)($game['starts_at'] ?? '')) ?: 0;
    if ($startsAt > 0 && $startsAt <= time()) {
        $game['launch_phase'] = 'active';
        $game['updated_at'] = now_iso();
    }
}

function mgw_v111_sync_engine_turn(array &$game): void
{
    if ((string)($game['status'] ?? '') !== 'active' || (string)($game['launch_phase'] ?? '') !== 'active') return;
    $turn = (string)($game['turn'] ?? '');
    if ($turn === '') return;

    $knownTurn = (string)($game['v111_clock_turn'] ?? '');
    if ($knownTurn === '') {
        $game['v111_clock_turn'] = $turn;
        $game['v111_clock_revision'] = max(1, (int)($game['v111_clock_revision'] ?? 0));
        return;
    }
    if ($knownTurn === $turn) return;

    $startsAt = time() + MGW_V111_TURN_HANDOFF_SEC;
    $game['turn_started_at'] = gmdate('c', $startsAt);
    $game['turn_starts_at'] = gmdate('c', $startsAt);
    $game['turn_deadline_at'] = gmdate('c', $startsAt + MGW_V111_MOVE_TIMEOUT_SEC);
    $game['v111_clock_turn'] = $turn;
    $game['v111_clock_revision'] = (int)($game['v111_clock_revision'] ?? 0) + 1;
    $game['updated_at'] = now_iso();

    $botId = (string)($game['bot_id'] ?? '');
    if (!empty($game['is_bot_game']) && $botId !== '' && $turn === $botId) {
        $game['bot_move_after_at'] = gmdate('c', $startsAt + 1);
    }
}

function mgw_v111_mark_preparation_timeout(array &$game): void
{
    if ((string)($game['launch_phase'] ?? '') !== 'preparing') return;
    $deadline = strtotime((string)($game['preparation_deadline_at'] ?? '')) ?: 0;
    if ($deadline <= 0 || $deadline > time()) return;

    // Financial settlement must go through api.php so all economy/ledger hooks run.
    $game['launch_phase'] = 'preparation_timeout';
    $game['turn_started_at'] = gmdate('c', time() + 3600);
    $game['updated_at'] = now_iso();
}

function mgw_v111_enrich_public_game(array $game, array $public): array
{
    $serverNowMs = (int)round(microtime(true) * 1000);
    $phase = (string)($game['launch_phase'] ?? ((string)($game['status'] ?? '') === 'active' ? 'active' : 'finished'));
    $turnStartsAtMs = mgw_v111_epoch_ms((string)($game['turn_starts_at'] ?? $game['turn_started_at'] ?? ''));
    $turnDeadlineMs = mgw_v111_epoch_ms((string)($game['turn_deadline_at'] ?? ''));
    if ($turnDeadlineMs === null && $turnStartsAtMs !== null) {
        $turnDeadlineMs = $turnStartsAtMs + (MGW_V111_MOVE_TIMEOUT_SEC * 1000);
    }

    if (in_array($phase, ['preparing', 'countdown', 'preparation_timeout'], true)
        || ($turnStartsAtMs !== null && $serverNowMs < $turnStartsAtMs)) {
        $timeLeft = MGW_V111_MOVE_TIMEOUT_SEC;
    } elseif ($turnDeadlineMs !== null) {
        $timeLeft = max(0, min(MGW_V111_MOVE_TIMEOUT_SEC, (int)ceil(($turnDeadlineMs - $serverNowMs) / 1000)));
    } else {
        $timeLeft = max(0, min(MGW_V111_MOVE_TIMEOUT_SEC, (int)($public['time_left'] ?? MGW_V111_MOVE_TIMEOUT_SEC)));
    }

    $ready = is_array($game['v111_ready_devices'] ?? null) ? $game['v111_ready_devices'] : [];
    return array_replace($public, [
        'launch_phase' => $phase,
        'preparing_started_at' => $game['preparing_started_at'] ?? null,
        'preparation_deadline_at' => $game['preparation_deadline_at'] ?? null,
        'preparation_deadline_ms' => mgw_v111_epoch_ms((string)($game['preparation_deadline_at'] ?? '')),
        'starts_at' => $game['starts_at'] ?? null,
        'starts_at_ms' => mgw_v111_epoch_ms((string)($game['starts_at'] ?? '')),
        'turn_started_at' => $game['turn_started_at'] ?? null,
        'turn_starts_at' => $game['turn_starts_at'] ?? $game['turn_started_at'] ?? null,
        'turn_starts_at_ms' => $turnStartsAtMs,
        'turn_deadline_at' => $game['turn_deadline_at'] ?? null,
        'turn_deadline_ms' => $turnDeadlineMs,
        'server_now_ms' => $serverNowMs,
        'turn_revision' => (int)($game['v111_clock_revision'] ?? 0),
        'ready_count' => count($ready),
        'ready_required' => count($game['player_ids'] ?? []),
        'time_left' => $timeLeft,
        'move_timeout_sec' => MGW_V111_MOVE_TIMEOUT_SEC,
    ]);
}

try {
    $payload = json_decode(file_get_contents('php://input') ?: '{}', true);
    if (!is_array($payload)) api_error('Некорректный запрос.');

    $sessionId = clean_string($payload['sessionId'] ?? '', 120);
    $gameId = clean_string($payload['gameId'] ?? '', 120);
    if ($sessionId === '') api_error('Сессия устройства не найдена.');
    if ($gameId === '') api_error('Игра не найдена.');

    $auth = new AuthService($config);
    $tgUser = $auth->getUserFromRequest($payload);
    $users = new UserService($config);
    $sessions = new SessionService($config);
    $catalog = new GameCatalogService($config);
    $games = new ChessRuntimeService($config, $catalog, new GameService($config));
    $db = StorageFactory::createJson((string)($config['data_dir'] ?? (__DIR__ . '/data')));

    $result = $db->transaction(function (array &$data) use (
        $tgUser,
        $users,
        $sessions,
        $games,
        $sessionId,
        $gameId
    ): array {
        $user = $users->ensureUser($data, $tgUser);
        $userId = (string)($user['id'] ?? '');
        if ($userId === '') throw new RuntimeException('Пользователь не найден.');
        $data['users'][$userId] = $user;
        $user =& $data['users'][$userId];
        $sessions->ensureSessionShape($user);
        $sessions->assertCanPlay($user, $sessionId);
        $sessions->touch($user, $sessionId);

        if (!isset($data['games'][$gameId]) || !is_array($data['games'][$gameId])) {
            throw new RuntimeException('Игра не найдена.');
        }
        $game =& $data['games'][$gameId];
        if (!in_array($userId, array_map('strval', $game['player_ids'] ?? []), true)) {
            throw new RuntimeException('Вы не участвуете в этой игре.');
        }

        mgw_v111_initialize_launch($game);
        mgw_v111_mark_preparation_timeout($game);
        if ((string)($game['status'] ?? '') === 'active'
            && (string)($game['launch_phase'] ?? '') !== 'preparation_timeout') {
            mgw_v111_mark_ready($game, $userId, $sessionId);
            mgw_v111_start_countdown($game);
            mgw_v111_activate_if_due($game);
        }

        // Cleanup now sees future synchronized timestamps and cannot consume the
        // launch window. It may still resolve legitimate active timeout/bot turns.
        $games->cleanup($data);
        if (!isset($data['games'][$gameId]) || !is_array($data['games'][$gameId])) {
            throw new RuntimeException('Игра больше недоступна.');
        }
        $game =& $data['games'][$gameId];
        mgw_v111_activate_if_due($game);
        mgw_v111_sync_engine_turn($game);

        if ((string)($game['status'] ?? '') === 'finished') {
            $sessions->releaseIfCurrent($user, $sessionId);
        }

        $public = $games->publicGame($game, $userId);
        return [
            'user' => $users->publicUser($user),
            'me' => ['id' => $userId],
            'game' => mgw_v111_enrich_public_game($game, $public),
            'session' => $sessions->publicState($user, $sessionId),
            'clock_armed' => (int)($game['v111_clock_revision'] ?? 0) > 0,
        ];
    });

    api_ok($result);
} catch (Throwable $e) {
    api_error($e->getMessage());
}
