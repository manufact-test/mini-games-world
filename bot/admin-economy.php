<?php
declare(strict_types=1);

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');

require __DIR__ . '/core/bootstrap.php';
require_once __DIR__ . '/helpers/AdminWebAuth.php';
require_once __DIR__ . '/economy/EconomyConfigSimulator.php';
require_once __DIR__ . '/economy/EconomyConfigDefinition.php';
require_once __DIR__ . '/economy/EconomyConfigService.php';

try {
    if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
        json_response(['ok' => false, 'error' => 'Method not allowed.'], 405);
    }

    $payload = json_decode(file_get_contents('php://input') ?: '{}', true);
    if (!is_array($payload)) {
        json_response(['ok' => false, 'error' => 'Некорректный запрос.'], 400);
    }

    $action = (string)($payload['action'] ?? '');
    if (!in_array($action, ['snapshot', 'update', 'rollback'], true)) {
        json_response(['ok' => false, 'error' => 'Некорректное действие экономики.'], 400);
    }

    $telegramUser = AdminWebAuth::authorize($config, (string)($payload['initData'] ?? ''));
    $telegramId = trim((string)($telegramUser['id'] ?? ''));
    if ($telegramId === '') {
        throw new RuntimeException('Authorized Telegram admin identity is unavailable.');
    }

    // ProductionPrimaryApplicationEntrypoints maps this narrow endpoint to the
    // already accepted API DB-primary context. It is not a second DB owner.
    $database = PdoConnectionFactory::create(DatabaseConfig::fromApplicationConfig($config));
    $service = new EconomyConfigService($database);
    $actorRef = 'telegram:' . $telegramId;

    if ($action === 'update') {
        $candidate = $payload['config'] ?? null;
        if (!is_array($candidate)) {
            throw new InvalidArgumentException('Economy config must be a JSON object.');
        }
        $service->update($candidate, $actorRef, (string)($payload['reason'] ?? ''));
    } elseif ($action === 'rollback') {
        $target = filter_var($payload['version'] ?? null, FILTER_VALIDATE_INT);
        if ($target === false || $target < 1) {
            throw new InvalidArgumentException('Rollback version is invalid.');
        }
        $service->rollback((int)$target, $actorRef, (string)($payload['reason'] ?? ''));
    }

    json_response([
        'ok' => true,
        'generated_at' => gmdate(DATE_ATOM),
        'current' => $service->current(),
        'history' => $service->history(20),
    ]);
} catch (AdminWebAuthException $error) {
    json_response(['ok' => false, 'error' => $error->publicMessage()], $error->httpStatus());
} catch (InvalidArgumentException $error) {
    json_response(['ok' => false, 'error' => $error->getMessage()], 422);
} catch (Throwable $error) {
    error_log('[MiniGamesWorld economy admin] ' . $error->getMessage());
    json_response(['ok' => false, 'error' => 'Не удалось загрузить или изменить конфигурацию экономики.'], 500);
}
