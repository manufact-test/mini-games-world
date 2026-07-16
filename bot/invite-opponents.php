<?php
declare(strict_types=1);

require __DIR__ . '/core/bootstrap.php';

function mgw_recent_opponent_name(array $user): string
{
    $username = trim((string)($user['username'] ?? ''));
    if ($username !== '') return '@' . ltrim($username, '@');

    $name = trim((string)($user['first_name'] ?? '') . ' ' . (string)($user['last_name'] ?? ''));
    return $name !== '' ? $name : 'Игрок';
}

function mgw_recent_opponent_activity(array $user): array
{
    $status = (string)($user['status'] ?? 'idle');
    $lastSeen = strtotime((string)($user['last_seen_at'] ?? '')) ?: 0;
    $secondsAgo = $lastSeen > 0 ? max(0, time() - $lastSeen) : null;

    if ($status === 'playing') {
        return ['label' => 'сейчас играет', 'online' => true, 'busy' => true];
    }
    if ($status === 'searching') {
        return ['label' => 'ищет соперника', 'online' => true, 'busy' => true];
    }
    if ($secondsAgo !== null && $secondsAgo <= 90) {
        return ['label' => 'онлайн', 'online' => true, 'busy' => false];
    }
    if ($secondsAgo !== null && $secondsAgo <= 3600) {
        return ['label' => 'был недавно', 'online' => false, 'busy' => false];
    }

    return ['label' => 'недавний соперник', 'online' => false, 'busy' => false];
}

try {
    $payload = json_decode(file_get_contents('php://input') ?: '{}', true);
    if (!is_array($payload)) api_error('Некорректный запрос.');

    $auth = new AuthService($config);
    $tgUser = $auth->getUserFromRequest($payload);
    $userId = (string)($tgUser['id'] ?? '');
    if ($userId === '') api_error('Пользователь не найден.');

    $db = StorageFactory::createJson((string)($config['data_dir'] ?? (__DIR__ . '/data')));
    $items = $db->readOnly(function (array $data) use ($userId): array {
        $games = array_values(array_filter($data['games'] ?? [], static function ($game) use ($userId): bool {
            if (!is_array($game) || (string)($game['status'] ?? '') !== 'finished') return false;
            if (!empty($game['is_bot_game'])) return false;

            $players = array_values(array_map('strval', $game['player_ids'] ?? []));
            return count($players) === 2 && in_array($userId, $players, true);
        }));

        usort($games, static function (array $left, array $right): int {
            $leftTime = strtotime((string)($left['finished_at'] ?? $left['updated_at'] ?? $left['created_at'] ?? '')) ?: 0;
            $rightTime = strtotime((string)($right['finished_at'] ?? $right['updated_at'] ?? $right['created_at'] ?? '')) ?: 0;
            return $rightTime <=> $leftTime;
        });

        $seen = [];
        $result = [];
        foreach ($games as $game) {
            $players = array_values(array_map('strval', $game['player_ids'] ?? []));
            $opponentId = $players[0] === $userId ? ($players[1] ?? '') : ($players[0] ?? '');
            if ($opponentId === '' || str_starts_with($opponentId, 'bot_') || isset($seen[$opponentId])) continue;
            if (!isset($data['users'][$opponentId]) || !is_array($data['users'][$opponentId])) continue;

            $opponent = $data['users'][$opponentId];
            $activity = mgw_recent_opponent_activity($opponent);
            $result[] = [
                'id' => $opponentId,
                'name' => mgw_recent_opponent_name($opponent),
                'activity' => (string)$activity['label'],
                'online' => (bool)$activity['online'],
                'busy' => (bool)$activity['busy'],
                'last_game_at' => (string)($game['finished_at'] ?? $game['updated_at'] ?? $game['created_at'] ?? ''),
            ];
            $seen[$opponentId] = true;

            if (count($result) >= 10) break;
        }

        usort($result, static function (array $left, array $right): int {
            $onlineCompare = (int)!empty($right['online']) <=> (int)!empty($left['online']);
            if ($onlineCompare !== 0) return $onlineCompare;
            $leftTime = strtotime((string)($left['last_game_at'] ?? '')) ?: 0;
            $rightTime = strtotime((string)($right['last_game_at'] ?? '')) ?: 0;
            return $rightTime <=> $leftTime;
        });

        return $result;
    });

    api_ok(['items' => $items]);
} catch (Throwable $e) {
    api_error($e->getMessage());
}
