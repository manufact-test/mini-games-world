<?php
declare(strict_types=1);

header('Cache-Control: no-store, private, max-age=0');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');

require __DIR__ . '/core/bootstrap.php';
require_once __DIR__ . '/accounts/AccountReauthGuard.php';
require_once __DIR__ . '/accounts/AccountDataZipWriter.php';
require_once __DIR__ . '/accounts/AccountDataLifecycleService.php';

try {
    if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'GET') {
        json_response(['ok'=>false,'error'=>'Метод запроса не поддерживается.'], 405);
    }

    $requestId = trim((string)($_GET['request_id'] ?? ''));
    if (preg_match('/^adr_[a-f0-9]{32}$/', $requestId) !== 1) {
        json_response(['ok'=>false,'error'=>'Некорректный архив.'], 400);
    }

    $databaseConfig = DatabaseConfig::fromApplicationConfig($config);
    $router = new RuntimeStorageRouter($config);
    if (!$databaseConfig->enabled()
        || ($router->enabled() && $router->routeFor('accounts') !== RuntimeStorageRouter::DRIVER_DATABASE)) {
        json_response(['ok'=>false,'error'=>'Скачивание данных временно недоступно.'], 503);
    }

    $authenticated = AccountReauthGuard::authorize($config, []);
    $mgwId = strtoupper(trim((string)($authenticated['mgw_id'] ?? '')));
    if (!MgwIdGenerator::isValid($mgwId)) {
        json_response(['ok'=>false,'error'=>'Профиль MGW недоступен для этой сессии.'], 401);
    }

    $database = PdoConnectionFactory::create($databaseConfig);
    $runtimeStorage = new JsonStorageAdapter((string)$config['data_dir']);
    $service = new AccountDataLifecycleService($database, $runtimeStorage, $config);
    $path = $service->exportPathForUser($requestId, $mgwId);

    $filename = 'mini-games-world-data-' . strtolower($mgwId) . '.zip';
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . (string)filesize($path));
    header('Cache-Control: no-store, private, max-age=0');
    readfile($path);
    exit;
} catch (AccountReauthException $error) {
    $status = in_array($error->reason, ['reauth_required','android_reauth_required','identity_unavailable'], true)
        ? 401
        : 403;
    json_response([
        'ok'=>false,
        'code'=>$error->reason,
        'error'=>mgw_public_api_error($error->getMessage()),
    ], $status);
} catch (AccountDataLifecycleException $error) {
    $status = match ($error->reason) {
        'export_missing' => 404,
        'export_not_ready', 'export_expired' => 409,
        default => 422,
    };
    json_response([
        'ok'=>false,
        'code'=>$error->reason,
        'error'=>mgw_public_api_error($error->getMessage()),
    ], $status);
} catch (Throwable $error) {
    error_log('[MiniGamesWorld account data download] ' . $error->getMessage());
    json_response(['ok'=>false,'error'=>'Не удалось скачать архив данных.'], 500);
}
