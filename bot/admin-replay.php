<?php
declare(strict_types=1);

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');

require __DIR__ . '/core/bootstrap.php';
require_once __DIR__ . '/helpers/AdminWebAuth.php';
require_once __DIR__ . '/replay/MatchReplayReader.php';

try {
    if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
        json_response(['ok' => false, 'error' => 'Method not allowed.'], 405);
    }

    $payload = json_decode(file_get_contents('php://input') ?: '{}', true);
    if (!is_array($payload) || (string)($payload['action'] ?? '') !== 'match_replay') {
        json_response(['ok' => false, 'error' => 'Некорректный запрос.'], 400);
    }

    AdminWebAuth::authorize($config, (string)($payload['initData'] ?? ''));
    $matchId = trim((string)($payload['matchId'] ?? ''));
    if ($matchId === '' || strlen($matchId) > 191) {
        json_response(['ok' => false, 'error' => 'Укажите корректный Match ID.'], 400);
    }

    $databaseConfig = DatabaseConfig::fromApplicationConfig($config);
    if (!$databaseConfig->enabled()) {
        json_response(['ok' => false, 'error' => 'Replay storage недоступен: DB-primary отключён.'], 503);
    }

    $database = PdoConnectionFactory::create($databaseConfig);
    $reader = new MatchReplayReader($database);
    $replay = $reader->load($matchId);
    if ($replay === null) {
        json_response(['ok' => false, 'error' => 'Матч не найден.'], 404);
    }

    json_response([
        'ok' => true,
        'generated_at' => gmdate(DATE_ATOM),
        'replay' => $replay,
    ]);
} catch (AdminWebAuthException $error) {
    json_response(['ok' => false, 'error' => $error->publicMessage()], $error->httpStatus());
} catch (InvalidArgumentException $error) {
    json_response(['ok' => false, 'error' => 'Укажите корректный Match ID.'], 400);
} catch (Throwable $error) {
    error_log('[MiniGamesWorld admin replay] ' . $error->getMessage());
    json_response(['ok' => false, 'error' => 'Не удалось загрузить replay матча.'], 500);
}
