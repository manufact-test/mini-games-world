<?php
declare(strict_types=1);

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');

require __DIR__ . '/core/bootstrap.php';
require_once __DIR__ . '/accounts/MgwProfileService.php';

function mgw_profile_v2_stats_by_game(array $data, string $userId): array
{
    $gameTypes = ['tictactoe','four_in_a_row','battleship','checkers','reversi','chess','go','domino'];
    $result = [];
    foreach ($gameTypes as $gameType) $result[$gameType] = ['games_played'=>0,'wins'=>0,'losses'=>0,'draws'=>0];
    foreach ($data['games'] ?? [] as $game) {
        if (!is_array($game) || (string)($game['status'] ?? '') !== 'finished') continue;
        $players = array_map('strval', $game['player_ids'] ?? []);
        if (!in_array($userId, $players, true)) continue;
        $gameType = trim((string)($game['game_type'] ?? 'tictactoe'));
        if (!isset($result[$gameType])) continue;
        $result[$gameType]['games_played']++;
        $winnerId = isset($game['winner_id']) ? (string)$game['winner_id'] : '';
        if ($winnerId === '') $result[$gameType]['draws']++;
        elseif ($winnerId === $userId) $result[$gameType]['wins']++;
        else $result[$gameType]['losses']++;
    }
    return $result;
}

function mgw_profile_v2_validation_error(InvalidArgumentException $error): array
{
    return match ($error->getMessage()) {
        MgwIdentityPolicy::NICKNAME_TOO_SHORT_ERROR => ['nickname_too_short', 'Ник должен содержать минимум 3 символа.'],
        MgwIdentityPolicy::NICKNAME_TOO_LONG_ERROR => ['nickname_too_long', 'Ник может содержать максимум 13 символов.'],
        MgwIdentityPolicy::NICKNAME_INVALID_CHARACTERS_ERROR => ['nickname_invalid_characters', 'В нике можно использовать буквы, цифры, пробелы, дефис и подчёркивание.'],
        default => ['profile_update_invalid', 'Не удалось сохранить профиль MGW.'],
    };
}

try {
    if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
        json_response(['ok'=>false,'error'=>'Method not allowed.'], 405);
    }
    $payload = json_decode(file_get_contents('php://input') ?: '{}', true);
    if (!is_array($payload)) json_response(['ok'=>false,'error'=>'Некорректный запрос.'], 400);
    $configRef = $config;
    $authenticatedUser = (new AuthService($configRef))->getUserFromRequest($payload);
    $mgwId = trim((string)($authenticatedUser['mgw_id'] ?? ''));
    if (!MgwIdGenerator::isValid($mgwId)) json_response(['ok'=>false,'error'=>'Профиль MGW недоступен для этой сессии.'], 401);
    $databaseConfig = DatabaseConfig::fromApplicationConfig($configRef);
    $router = new RuntimeStorageRouter($configRef);
    if (!$databaseConfig->enabled() || ($router->enabled() && $router->routeFor('accounts') !== RuntimeStorageRouter::DRIVER_DATABASE)) {
        json_response(['ok'=>false,'error'=>'Профиль MGW временно недоступен.'], 503);
    }
    $profileService = new MgwProfileService(PdoConnectionFactory::create($databaseConfig));
    try {
        $canonicalProfile = isset($payload['profile_update']) && is_array($payload['profile_update'])
            ? $profileService->updateProfile($mgwId, $payload['profile_update'])
            : $profileService->publicProfile($mgwId);
    } catch (InvalidArgumentException $error) {
        [$code, $message] = mgw_profile_v2_validation_error($error);
        json_response(['ok'=>false,'error'=>$message,'code'=>$code], 422);
    } catch (RuntimeException $error) {
        if ($error->getMessage() === MgwIdentityPolicy::NICKNAME_TAKEN_ERROR) {
            json_response(['ok'=>false,'error'=>MgwIdentityPolicy::NICKNAME_TAKEN_ERROR,'code'=>'nickname_taken'], 409);
        }
        throw $error;
    }

    // Auth resolution happens before a profile mutation. Replace the carried
    // visible identity with the just-committed canonical profile before the
    // legacy JSON runtime is synchronized, otherwise an active game can be
    // rewritten back to the previous nickname for one request.
    $runtimeAuthenticatedUser = $authenticatedUser;
    $runtimeAuthenticatedUser['mgw_nickname'] = (string)($canonicalProfile['nickname'] ?? '');
    $runtimeAuthenticatedUser['mgw_avatar_item_id'] = (string)($canonicalProfile['avatar']['item_id'] ?? '');

    $users = new UserService($configRef);
    $historyService = new HistoryService($configRef, $users);
    $storage = StorageFactory::createJson((string)($configRef['data_dir'] ?? (__DIR__ . '/data')));
    $runtime = $storage->transaction(function (array &$data) use ($runtimeAuthenticatedUser, $users, $historyService) {
        $user = $users->ensureUser($data, $runtimeAuthenticatedUser);
        $userId = (string)($user['id'] ?? '');
        $stats = $users->profileStats($user, $data);
        $stats['by_game'] = mgw_profile_v2_stats_by_game($data, $userId);
        return [
            'user' => $users->publicUser($user),
            'stats' => $stats,
            'history' => $historyService->userHistory($data, $userId, 6),
        ];
    });
    $provider = strtolower(trim((string)($authenticatedUser['mgw_identity_provider'] ?? '')));
    json_response([
        'ok'=>true,
        'profile'=>$canonicalProfile,
        'user'=>$runtime['user'] ?? null,
        'stats'=>$runtime['stats'] ?? null,
        'history'=>$runtime['history'] ?? ['matches'=>[],'operations'=>[]],
        'auth'=>['provider'=>$provider !== '' ? $provider : null,'provider_neutral'=>true],
    ]);
} catch (Throwable $error) {
    error_log('[MiniGamesWorld Profile v2] ' . $error->getMessage());
    json_response(['ok'=>false,'error'=>'Не удалось загрузить профиль MGW.'], 500);
}
