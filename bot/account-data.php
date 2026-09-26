<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: no-referrer');
}

require __DIR__ . '/core/bootstrap.php';
require_once __DIR__ . '/accounts/AccountReauthGuard.php';
require_once __DIR__ . '/accounts/AccountDataZipWriter.php';
require_once __DIR__ . '/accounts/AccountDataLifecycleService.php';

function mgw_account_data_http_status(string $reason): int
{
    return match ($reason) {
        'reauth_required', 'identity_unavailable' => 401,
        'account_not_found', 'export_missing' => 404,
        'deletion_locked', 'deletion_not_cancellable', 'export_not_ready', 'export_expired', 'rate_limited' => 409,
        'invalid_mgw_id', 'invalid_source' => 422,
        default => 422,
    };
}

try {
    $databaseConfig = DatabaseConfig::fromApplicationConfig($config);
    $router = new RuntimeStorageRouter($config);
    if (!$databaseConfig->enabled()
        || ($router->enabled() && $router->routeFor('accounts') !== RuntimeStorageRouter::DRIVER_DATABASE)) {
        if (PHP_SAPI === 'cli') {
            fwrite(STDERR, "Account data lifecycle is unavailable: canonical accounts DB is disabled.\n");
            exit(2);
        }
        json_response(['ok'=>false,'error'=>'Управление данными аккаунта временно недоступно.'], 503);
    }

    $database = PdoConnectionFactory::create($databaseConfig);
    // Account deletion must also scrub the legacy JSON rollback/runtime identity.
    // This is intentionally the explicit JSON owner rather than the currently
    // selected API primary adapter.
    $runtimeStorage = new JsonStorageAdapter((string)$config['data_dir']);
    $service = new AccountDataLifecycleService($database, $runtimeStorage, $config);

    $argvList = is_array($argv ?? null) ? $argv : [];
    if (PHP_SAPI === 'cli') {
        if (!in_array('--run-retention', $argvList, true)) {
            fwrite(STDERR, "Use --run-retention to process due deletions and expired exports.\n");
            exit(2);
        }
        $summary = $service->runRetention();
        fwrite(STDOUT, json_encode(
            ['ok'=>$summary['deletions_failed'] === 0,'account_data_retention'=>$summary],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        ) . PHP_EOL);
        exit($summary['deletions_failed'] === 0 ? 0 : 1);
    }

    if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
        json_response(['ok'=>false,'error'=>'Метод запроса не поддерживается.'], 405);
    }

    $payload = json_decode(file_get_contents('php://input') ?: '{}', true);
    if (!is_array($payload)) {
        json_response(['ok'=>false,'error'=>'Некорректный запрос.'], 400);
    }
    $action = strtolower(trim((string)($payload['action'] ?? 'snapshot')));

    $requiresFreshReauth = in_array(
        $action,
        ['schedule_delete','cancel_delete','create_export','download_export'],
        true
    );
    $authenticated = $requiresFreshReauth
        ? AccountReauthGuard::authorize($config, $payload)
        : (new AuthService($config))->getUserFromRequest($payload);

    $mgwId = strtoupper(trim((string)($authenticated['mgw_id'] ?? '')));
    if (!MgwIdGenerator::isValid($mgwId)) {
        json_response(['ok'=>false,'error'=>'Профиль MGW недоступен для этой сессии.'], 401);
    }
    $sourceRef = 'self:' . strtolower(trim((string)($authenticated['mgw_identity_provider'] ?? 'telegram')));

    if ($action === 'schedule_delete') {
        $request = $service->scheduleDeletion($mgwId, 'mini_app', $sourceRef);
        json_response([
            'ok'=>true,
            'request'=>$request,
            'account_data'=>$service->snapshot($mgwId),
        ]);
    }
    if ($action === 'cancel_delete') {
        $request = $service->cancelDeletion($mgwId);
        json_response([
            'ok'=>true,
            'request'=>$request,
            'account_data'=>$service->snapshot($mgwId),
        ]);
    }
    if ($action === 'create_export') {
        $request = $service->createExport($mgwId, 'mini_app', $sourceRef);
        json_response([
            'ok'=>true,
            'request'=>$request,
            'account_data'=>$service->snapshot($mgwId),
        ]);
    }
    if ($action === 'download_export') {
        $requestId = trim((string)($payload['request_id'] ?? ''));
        $path = $service->exportPathForUser($requestId, $mgwId);
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="mini-games-world-data-' . rawurlencode($mgwId) . '.zip"');
        header('Content-Length: ' . (string)filesize($path));
        header('Cache-Control: no-store, private, max-age=0');
        readfile($path);
        exit;
    }
    if ($action !== 'snapshot') {
        json_response(['ok'=>false,'error'=>'Некорректное действие управления данными аккаунта.'], 400);
    }

    json_response([
        'ok'=>true,
        'account_data'=>$service->snapshot($mgwId),
    ]);
} catch (AccountReauthException $error) {
    json_response([
        'ok'=>false,
        'code'=>$error->reason,
        'error'=>$error->getMessage(),
    ], mgw_account_data_http_status($error->reason));
} catch (AccountDataLifecycleException $error) {
    json_response([
        'ok'=>false,
        'code'=>$error->reason,
        'error'=>$error->getMessage(),
    ], mgw_account_data_http_status($error->reason));
} catch (Throwable $error) {
    error_log('[MiniGamesWorld account data] ' . $error->getMessage());
    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, "Account data retention failed.\n");
        exit(1);
    }
    json_response(['ok'=>false,'error'=>'Не удалось обработать данные аккаунта.'], 500);
}
