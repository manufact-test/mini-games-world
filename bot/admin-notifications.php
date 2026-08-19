<?php
declare(strict_types=1);

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');

require __DIR__ . '/core/bootstrap.php';
require_once __DIR__ . '/helpers/AdminWebAuth.php';
require_once __DIR__ . '/notifications/AdminNotificationEventService.php';

try {
    if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
        json_response(['ok' => false, 'error' => 'Method not allowed.'], 405);
    }

    $payload = json_decode(file_get_contents('php://input') ?: '{}', true);
    if (!is_array($payload)) {
        json_response(['ok' => false, 'error' => 'Некорректный запрос.'], 400);
    }

    $admin = AdminWebAuth::authorize($config, (string)($payload['initData'] ?? ''));
    $storage = StorageFactory::createJson((string)($config['data_dir'] ?? (__DIR__ . '/data')));
    $service = new AdminNotificationEventService();
    $action = strtolower(trim((string)($payload['action'] ?? 'snapshot')));
    $created = null;

    if ($action === 'create') {
        $event = is_array($payload['event'] ?? null) ? $payload['event'] : [];
        $createdBy = 'telegram:' . trim((string)($admin['id'] ?? 'unknown'));
        $created = $storage->transaction(
            static function (array &$data) use ($service, $event, $createdBy): array {
                return $service->createEvent($data, $event, $createdBy);
            }
        );
    } elseif ($action !== 'snapshot') {
        json_response(['ok' => false, 'error' => 'Некорректное действие notification pipeline.'], 400);
    }

    $history = $storage->readOnly(
        static fn(array $data): array => $service->history($data, 50)
    );

    json_response([
        'ok' => true,
        'generated_at' => gmdate(DATE_ATOM),
        'created' => $created,
        'events' => $history,
        'audience_types' => AdminNotificationEventService::AUDIENCE_TYPES,
        'source_types' => AdminNotificationEventService::SOURCE_TYPES,
        'deep_links' => ['', 'home', 'profile', 'store', 'store:orders'],
    ]);
} catch (AdminWebAuthException $error) {
    json_response(['ok' => false, 'error' => $error->publicMessage()], $error->httpStatus());
} catch (AdminNotificationEventException $error) {
    json_response([
        'ok' => false,
        'error' => $error->getMessage(),
        'reason' => $error->reason,
    ], 422);
} catch (Throwable $error) {
    error_log('[MiniGamesWorld admin notifications] ' . $error->getMessage());
    json_response(['ok' => false, 'error' => 'Не удалось обработать bell event.'], 500);
}
