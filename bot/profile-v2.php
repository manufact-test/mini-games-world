<?php
declare(strict_types=1);

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');

require __DIR__ . '/core/bootstrap.php';
require_once __DIR__ . '/accounts/MgwProfileService.php';
require_once __DIR__ . '/moderation/ModerationService.php';

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
        MgwIdentityPolicy::NICKNAME_INVALID_CHARACTERS_ERROR => ['nickname_invalid_characters', 'В нике можно использовать буквы, цифры, пробел, дефис и подчёркивание.'],
        default => ['profile_update_invalid', 'Не удалось сохранить профиль MGW.'],
    };
}

$profileStage = 'request';
$profileStartedAt = microtime(true);

try {
    if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
        json_response(['ok'=>false,'error'=>'Method not allowed.'], 405);
    }
    $profileStage = 'decode_request';
    $payload = json_decode(file_get_contents('php://input') ?: '{}', true);
    if (!is_array($payload)) json_response(['ok'=>false,'error'=>'Некорректный запрос.'], 400);
    $configRef = $config;
    $profileStage = 'authenticate';
    $authenticatedUser = (new AuthService($configRef))->getUserFromRequest($payload);
    $mgwId = trim((string)($authenticatedUser['mgw_id'] ?? ''));
    if (!MgwIdGenerator::isValid($mgwId)) json_response(['ok'=>false,'error'=>'Профиль MGW недоступен для этой сессии.'], 401);
    $profileStage = 'storage_route';
    $databaseConfig = DatabaseConfig::fromApplicationConfig($configRef);
    $router = new RuntimeStorageRouter($configRef);
    if (!$databaseConfig->enabled() || ($router->enabled() && $router->routeFor('accounts') !== RuntimeStorageRouter::DRIVER_DATABASE)) {
        json_response(['ok'=>false,'error'=>'Профиль MGW временно недоступен.'], 503);
    }

    // One DB connection, one canonical ownership/equip owner. Profile consumes
    // ProductInventoryService snapshots; it never recreates inventory state.
    $profileStage = 'database_connect';
    $database = PdoConnectionFactory::create($databaseConfig);

    if (($payload['tournament_prestige_only'] ?? false) === true) {
        $profileStage = 'tournament_prestige';
        $tournamentRewards = (new TournamentRewardProjectionService($database))->prestigeSnapshot($mgwId);
        json_response([
            'ok'=>true,
            'tournament_rewards'=>$tournamentRewards,
            'lightweight'=>true,
        ]);
    }

    $profileService = new MgwProfileService($database);
    $moderation = new ModerationService($database);
    $profileUpdateRequested = isset($payload['profile_update']) && is_array($payload['profile_update']);
    try {
        $profileStage = $profileUpdateRequested ? 'canonical_profile_update' : 'canonical_profile_read';
        if ($profileUpdateRequested) {
            $moderation->assertAllowed($mgwId, 'profile');
            $canonicalProfile = $profileService->updateProfile($mgwId, $payload['profile_update']);
        } else {
            // Read access remains available so a restricted/banned player can
            // inspect the account and reach the appeal flow.
            $canonicalProfile = $profileService->publicProfile($mgwId);
        }
    } catch (ModerationException $error) {
        json_response(['ok'=>false,'error'=>mgw_public_api_error($error->getMessage()),'code'=>$error->reason], 403);
    } catch (InvalidArgumentException $error) {
        [$code, $message] = mgw_profile_v2_validation_error($error);
        json_response(['ok'=>false,'error'=>$message,'code'=>$code], 422);
    } catch (RuntimeException $error) {
        if ($error->getMessage() === MgwIdentityPolicy::NICKNAME_TAKEN_ERROR) {
            json_response(['ok'=>false,'error'=>MgwIdentityPolicy::NICKNAME_TAKEN_ERROR,'code'=>'nickname_taken'], 409);
        }
        throw $error;
    }
    $profileStage = 'inventory';
    $inventory = (new ProductInventoryService($database))->snapshot($mgwId);
    $profileStage = 'rating';
    $rating = (new PerGameRatingRuntimeBridge($configRef, $router, $database))->snapshotForProfile(
        $mgwId,
        static function (string $ratingStage) use (&$profileStage): void {
            $profileStage = 'rating_' . $ratingStage;
        }
    );
    $profileStage = 'yearly_medals';
    $yearlyMedals = (new YearlyMedalService($database))->userSnapshot($mgwId);
    $profileStage = 'rating_archive';
    $ratingArchive = (new RatingArchiveService($database))->profileSnapshot($mgwId);
    $profileStage = 'tournament_rewards';
    $tournamentRewards = (new TournamentRewardProjectionService($database))->profileSnapshot($mgwId);

    $users = new UserService($configRef);
    $historyService = new HistoryService($configRef, $users);
    $storage = StorageFactory::createJson((string)($configRef['data_dir'] ?? (__DIR__ . '/data')));
    $runtimeSections = ['users', 'games', 'transactions'];

    // Full Profile V2 is a read path after profile.php/bootstrap have already
    // ensured the runtime user. Do not hold the global JSON writer lock while
    // calculating statistics/history or while HistoryService reads its DB
    // projection: under two real clients that serialized unrelated gameplay
    // traffic for ~20 seconds and could make one profile request fail.
    //
    // Profile mutations still keep the canonical ensureUser write owner, but
    // the transaction ends immediately after capturing the minimal runtime
    // snapshot. All expensive presentation work happens after the lock is free.
    $profileStage = 'runtime_snapshot';
    if ($profileUpdateRequested) {
        $runtimeSnapshot = $storage->transaction(
            static function (array &$data) use ($authenticatedUser, $users, $runtimeSections): array {
                $user = $users->ensureUser($data, $authenticatedUser);
                $snapshot = [];
                foreach ($runtimeSections as $section) {
                    $snapshot[$section] = is_array($data[$section] ?? null) ? $data[$section] : [];
                }
                return ['user'=>$user, 'data'=>$snapshot];
            }
        );
    } else {
        $runtimeUserId = trim((string)($authenticatedUser['id'] ?? ''));
        $captureReadSnapshot = static function (array $data) use ($runtimeUserId): array {
            $user = $runtimeUserId !== '' ? ($data['users'][$runtimeUserId] ?? null) : null;
            if (!is_array($user)) {
                throw new RuntimeException('Authenticated runtime profile is unavailable.');
            }
            return ['user'=>$user, 'data'=>$data];
        };
        $runtimeSnapshot = $storage instanceof SelectiveReadStorageInterface
            ? $storage->readOnlySections($runtimeSections, $captureReadSnapshot)
            : $storage->readOnly($captureReadSnapshot);
    }

    $runtimeData = is_array($runtimeSnapshot['data'] ?? null) ? $runtimeSnapshot['data'] : [];
    $runtimeUser = is_array($runtimeSnapshot['user'] ?? null) ? $runtimeSnapshot['user'] : null;
    if (!is_array($runtimeUser)) {
        throw new RuntimeException('Runtime profile snapshot is unavailable.');
    }
    $runtimeUserId = (string)($runtimeUser['id'] ?? '');
    $profileStage = 'runtime_stats';
    $runtimeStats = $users->profileStats($runtimeUser, $runtimeData);
    $runtimeStats['by_game'] = mgw_profile_v2_stats_by_game($runtimeData, $runtimeUserId);
    $profileStage = 'history';
    $runtimeHistory = $historyService->userHistory($runtimeData, $runtimeUserId, 6);
    $runtime = [
        'user' => $users->publicUser($runtimeUser),
        'stats' => $runtimeStats,
        'history' => $runtimeHistory,
    ];
    $provider = strtolower(trim((string)($authenticatedUser['mgw_identity_provider'] ?? '')));
    $profileStage = 'response';
    json_response([
        'ok'=>true,
        'profile'=>$canonicalProfile,
        'inventory'=>$inventory,
        'rating'=>$rating,
        'yearly_medals'=>$yearlyMedals,
        'rating_archive'=>$ratingArchive,
        'tournament_rewards'=>$tournamentRewards,
        'user'=>$runtime['user'] ?? null,
        'stats'=>$runtime['stats'] ?? null,
        'history'=>$runtime['history'] ?? ['matches'=>[],'operations'=>[]],
        'auth'=>['provider'=>$provider !== '' ? $provider : null,'provider_neutral'=>true],
    ]);
} catch (Throwable $error) {
    $elapsedMs = max(0, (int)round((microtime(true) - $profileStartedAt) * 1000));
    error_log(sprintf(
        '[MiniGamesWorld Profile v2] stage=%s elapsed_ms=%d error=%s: %s',
        $profileStage,
        $elapsedMs,
        get_class($error),
        $error->getMessage()
    ));
    $response = ['ok'=>false,'error'=>'Не удалось загрузить профиль MGW.'];
    $environment = strtolower(trim((string)($config['environment'] ?? 'production')));
    if ($environment === 'staging') {
        $response['diagnostic_stage'] = $profileStage;
        $response['diagnostic_elapsed_ms'] = $elapsedMs;
    }
    json_response($response, 500);
}
