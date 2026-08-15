<?php
declare(strict_types=1);

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');

require __DIR__ . '/core/bootstrap.php';
require_once __DIR__ . '/helpers/AdminWebAuth.php';

function mgw_admin_read_only_text(string $text, string $commandMarker): string
{
    $position = strpos($text, $commandMarker);
    return $position === false ? trim($text) : rtrim(substr($text, 0, $position));
}

try {
    if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
        json_response(['ok' => false, 'error' => 'Method not allowed.'], 405);
    }

    $payload = json_decode(file_get_contents('php://input') ?: '{}', true);
    if (!is_array($payload) || (string)($payload['action'] ?? '') !== 'snapshot') {
        json_response(['ok' => false, 'error' => 'Некорректный запрос.'], 400);
    }

    $initData = (string)($payload['initData'] ?? '');
    AdminWebAuth::authorize($config, $initData);
    $admin = new AdminService($config);

    // admin-read.php is mapped to the existing API DB-primary entrypoint context.
    // No separate storage selector or admin database owner is introduced here.
    $storage = StorageFactory::createJson((string)($config['data_dir'] ?? (__DIR__ . '/data')));
    $snapshot = $storage->readOnly(static function (array $data) use ($admin): array {
        return [
            'dashboard' => mgw_admin_read_only_text($admin->dashboard($data), "\nКоманды:\n"),
            'system_check' => mgw_admin_read_only_text($admin->systemCheck($data), "\nКоманда:\n"),
        ];
    });

    $flags = new FeatureFlagService($config);
    json_response([
        'ok' => true,
        'generated_at' => gmdate(DATE_ATOM),
        'environment' => (string)($config['environment'] ?? 'production'),
        'build' => FeatureFlagService::BUILD,
        'runtime' => $flags->publicStatus(),
        'dashboard' => (string)($snapshot['dashboard'] ?? ''),
        'system_check' => (string)($snapshot['system_check'] ?? ''),
    ]);
} catch (AdminWebAuthException $error) {
    json_response(['ok' => false, 'error' => $error->publicMessage()], $error->httpStatus());
} catch (Throwable $error) {
    error_log('[MiniGamesWorld web admin] ' . $error->getMessage());
    json_response(['ok' => false, 'error' => 'Не удалось загрузить панель. Откройте её заново из Telegram.'], 500);
}
