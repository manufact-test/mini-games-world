<?php
declare(strict_types=1);

require __DIR__ . '/core/bootstrap.php';
require_once __DIR__ . '/services/PresenceService.php';

function mgw_invite_opponent_name(array $user): string
{
    $username = trim((string)($user['username'] ?? ''));
    if ($username !== '') return '@' . ltrim($username, '@');

    $name = trim((string)($user['first_name'] ?? '') . ' ' . (string)($user['last_name'] ?? ''));
    return $name !== '' ? $name : 'Игрок';
}

function mgw_invite_opponent_activity(array $user, bool $presenceOnline): array
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
    if ($presenceOnline) {
        return ['label' => 'онлайн', 'online' => true, 'busy' => false];
    }
    if ($secondsAgo !== null && $secondsAgo <= 3600) {
        return ['label' => 'был недавно', 'online' => false, 'busy' => false];
    }
    if ($secondsAgo !== null && $secondsAgo <= 86400 * 7) {
        return ['label' => 'заходил на этой неделе', 'online' => false, 'busy' => false];
    }

    return ['label' => 'недавний игрок', 'online' => false, 'busy' => false];
}

function mgw_invite_opponents_storage(array $config): StorageAdapterInterface
{
    $environment = strtolower(trim((string)($config['environment'] ?? 'production')));

    // Staging mutations already use DB-primary state through api.php. Reading
    // this endpoint from the lagging JSON projection creates a split-brain list:
    // Presence can know that B is online while the user snapshot still omits B.
    // The picker is read-only, so resolve the same DB-primary state directly.
    if ($environment === 'staging') {
        $databaseConfig = DatabaseConfig::fromApplicationConfig($config);
        if (!$databaseConfig->enabled()) {
            throw new RuntimeException('Authoritative staging opponent storage is unavailable.');
        }
        return new DatabasePrimaryStateStorageAdapter(
            PdoConnectionFactory::create($databaseConfig),
            null
        );
    }

    // Production keeps its existing guarded entrypoint context. Environments
    // without an activated DB-primary context retain the JSON rollback source.
    return StorageFactory::createJson((string)($config['data_dir'] ?? (__DIR__ . '/data')));
}

try {
    $payload = json_decode(file_get_contents('php://input') ?: '{}', true);
    if (!is_array($payload)) api_error('Некорректный запрос.');

    $auth = new AuthService($config);
    $tgUser = $auth->getUserFromRequest($payload);
    $userId = (string)($tgUser['id'] ?? '');
    if ($userId === '') api_error('Пользователь не найден.');

    $presence = new PresenceService();
    $onlineIds = array_fill_keys($presence->onlineAccountIds(), true);
    $storage = mgw_invite_opponents_storage($config);

    $items = $storage->readOnly(function (array $data) use ($userId, $onlineIds): array {
        $lastGameAt = [];
        foreach ($data['games'] ?? [] as $game) {
            if (!is_array($game) || (string)($game['status'] ?? '') !== 'finished' || !empty($game['is_bot_game'])) {
                continue;
            }
            $players = array_values(array_map('strval', $game['player_ids'] ?? []));
            if (count($players) !== 2 || !in_array($userId, $players, true)) continue;
            $opponentId = $players[0] === $userId ? ($players[1] ?? '') : ($players[0] ?? '');
            if ($opponentId === '' || str_starts_with($opponentId, 'bot_')) continue;
            $timestamp = (string)($game['finished_at'] ?? $game['updated_at'] ?? $game['created_at'] ?? '');
            $current = strtotime((string)($lastGameAt[$opponentId] ?? '')) ?: 0;
            $candidate = strtotime($timestamp) ?: 0;
            if ($candidate >= $current) $lastGameAt[$opponentId] = $timestamp;
        }

        $result = [];
        foreach ($data['users'] ?? [] as $candidateId => $candidate) {
            $candidateId = (string)$candidateId;
            if ($candidateId === ''
                || $candidateId === $userId
                || str_starts_with($candidateId, 'bot_')
                || !is_array($candidate)) {
                continue;
            }

            $presenceOnline = isset($onlineIds[$candidateId]);
            $lastSeen = strtotime((string)($candidate['last_seen_at'] ?? '')) ?: 0;
            $hasHistory = isset($lastGameAt[$candidateId]);
            if (!$presenceOnline && !$hasHistory && ($lastSeen <= 0 || time() - $lastSeen > 86400 * 30)) {
                continue;
            }

            $activity = mgw_invite_opponent_activity($candidate, $presenceOnline);
            $gameTime = strtotime((string)($lastGameAt[$candidateId] ?? '')) ?: 0;
            $score = !empty($activity['online']) ? 10000000000 : 0;
            $score += !empty($activity['busy']) ? 1000000000 : 0;
            $score += $hasHistory ? 100000000 : 0;
            $score += max($gameTime, $lastSeen);

            $result[] = [
                'id' => $candidateId,
                'name' => mgw_invite_opponent_name($candidate),
                'activity' => (string)$activity['label'],
                'online' => (bool)$activity['online'],
                'busy' => (bool)$activity['busy'],
                'last_game_at' => (string)($lastGameAt[$candidateId] ?? ''),
                'last_seen_at' => (string)($candidate['last_seen_at'] ?? ''),
                '_score' => $score,
            ];
        }

        usort($result, static function (array $left, array $right): int {
            $scoreCompare = (int)($right['_score'] ?? 0) <=> (int)($left['_score'] ?? 0);
            if ($scoreCompare !== 0) return $scoreCompare;
            return strcasecmp((string)($left['name'] ?? ''), (string)($right['name'] ?? ''));
        });

        $result = array_slice($result, 0, 10);
        foreach ($result as &$item) unset($item['_score']);
        unset($item);
        return $result;
    });

    api_ok([
        'items' => $items,
        'authoritative' => true,
        'storage_driver' => $storage->driver(),
    ]);
} catch (Throwable $e) {
    api_error($e->getMessage());
}
