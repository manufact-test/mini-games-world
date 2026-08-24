<?php
declare(strict_types=1);

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');

require __DIR__ . '/core/bootstrap.php';
require_once __DIR__ . '/helpers/AdminWebAuth.php';
require_once __DIR__ . '/services/StagingAdminTestCoinGrantService.php';

try {
    if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
        json_response(['ok' => false, 'error' => 'Method not allowed.'], 405);
    }
    if (strtolower(trim((string)($config['environment'] ?? ''))) !== 'staging') {
        json_response(['ok' => false, 'error' => 'Тестовые начисления доступны только на staging.'], 403);
    }

    $payload = json_decode(file_get_contents('php://input') ?: '{}', true);
    if (!is_array($payload) || (string)($payload['action'] ?? '') !== 'grant_test_coins') {
        json_response(['ok' => false, 'error' => 'Некорректный запрос.'], 400);
    }

    $telegramUser = AdminWebAuth::authorize($config, (string)($payload['initData'] ?? ''));
    $telegramId = trim((string)($telegramUser['id'] ?? ''));
    if ($telegramId === '') {
        throw new RuntimeException('Authorized Telegram admin identity is unavailable.');
    }
    $amount = filter_var($payload['amount'] ?? null, FILTER_VALIDATE_INT);
    if ($amount === false) {
        throw new InvalidArgumentException('Укажите целое количество коинов.');
    }
    $playerQuery = trim((string)($payload['player'] ?? ''));
    if ($playerQuery === '') $playerQuery = $telegramId;

    $storage = StorageFactory::createJson((string)($config['data_dir'] ?? (__DIR__ . '/data')));
    $service = new StagingAdminTestCoinGrantService($config, $storage);
    $grant = $service->grant(
        $playerQuery,
        (int)$amount,
        'telegram:' . $telegramId,
        (string)($payload['reason'] ?? ''),
        (string)($payload['request_token'] ?? '')
    );

    $syncState = 'completed';
    try {
        $router = new RuntimeStorageRouter($config);
        (new EconomyRuntimeBridge($config, $router, $storage))->synchronizeCurrentJson();
    } catch (Throwable $syncError) {
        $syncState = 'pending';
        error_log('[MiniGamesWorld staging admin test coins] economy sync pending');
    }

    json_response([
        'ok' => true,
        'generated_at' => gmdate(DATE_ATOM),
        'grant' => $grant,
        'economy_sync' => $syncState,
    ]);
} catch (AdminWebAuthException $error) {
    json_response(['ok' => false, 'error' => $error->publicMessage()], $error->httpStatus());
} catch (InvalidArgumentException $error) {
    json_response(['ok' => false, 'error' => $error->getMessage()], 422);
} catch (Throwable $error) {
    error_log('[MiniGamesWorld staging admin test coins] ' . $error->getMessage());
    json_response(['ok' => false, 'error' => 'Не удалось начислить тестовые коины.'], 500);
}
