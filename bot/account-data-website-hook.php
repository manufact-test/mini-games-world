<?php
declare(strict_types=1);

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');

require __DIR__ . '/core/bootstrap.php';
require_once __DIR__ . '/accounts/AccountDataZipWriter.php';
require_once __DIR__ . '/accounts/AccountDataLifecycleService.php';

function mgw_account_data_hook_authorize(array $config, string $body): void
{
    $secret = trim((string)($config['account_data_website_hook_secret'] ?? ''));
    if ($secret === '') {
        json_response(['ok'=>false,'error'=>'Website account-data hook is not configured.'], 503);
    }

    $timestamp = filter_var($_SERVER['HTTP_X_MGW_TIMESTAMP'] ?? null, FILTER_VALIDATE_INT);
    $signature = strtolower(trim((string)($_SERVER['HTTP_X_MGW_SIGNATURE'] ?? '')));
    if ($timestamp === false || $timestamp <= 0 || preg_match('/^[a-f0-9]{64}$/', $signature) !== 1) {
        json_response(['ok'=>false,'error'=>'Website hook authorization is invalid.'], 401);
    }
    $now = time();
    if ($timestamp > $now + 60 || $now - $timestamp > 300) {
        json_response(['ok'=>false,'error'=>'Website hook request is stale.'], 401);
    }

    $expected = hash_hmac('sha256', $timestamp . "\n" . $body, $secret);
    if (!hash_equals($expected, $signature)) {
        json_response(['ok'=>false,'error'=>'Website hook authorization is invalid.'], 401);
    }
}

try {
    if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
        json_response(['ok'=>false,'error'=>'Method not allowed.'], 405);
    }

    $body = file_get_contents('php://input') ?: '{}';
    mgw_account_data_hook_authorize($config, $body);
    $payload = json_decode($body, true);
    if (!is_array($payload)) {
        json_response(['ok'=>false,'error'=>'Некорректный запрос.'], 400);
    }

    $mgwId = strtoupper(trim((string)($payload['mgw_id'] ?? '')));
    if (!MgwIdGenerator::isValid($mgwId)) {
        json_response(['ok'=>false,'error'=>'Некорректный MGW-ID.'], 422);
    }
    $verificationRef = trim((string)($payload['verification_ref'] ?? ''));
    if ($verificationRef === '') {
        json_response(['ok'=>false,'error'=>'Не передана подтверждённая website verification reference.'], 422);
    }

    $databaseConfig = DatabaseConfig::fromApplicationConfig($config);
    $router = new RuntimeStorageRouter($config);
    if (!$databaseConfig->enabled()
        || ($router->enabled() && $router->routeFor('accounts') !== RuntimeStorageRouter::DRIVER_DATABASE)) {
        json_response(['ok'=>false,'error'=>'Account data lifecycle is unavailable.'], 503);
    }

    $database = PdoConnectionFactory::create($databaseConfig);
    $service = new AccountDataLifecycleService(
        $database,
        new JsonStorageAdapter((string)$config['data_dir']),
        $config
    );
    $action = strtolower(trim((string)($payload['action'] ?? '')));

    if ($action === 'schedule_delete') {
        $request = $service->scheduleDeletion($mgwId, 'website', $verificationRef);
    } elseif ($action === 'create_export') {
        $request = $service->createExport($mgwId, 'website', $verificationRef);
    } else {
        json_response(['ok'=>false,'error'=>'Unsupported website account-data action.'], 400);
    }

    json_response([
        'ok'=>true,
        'request'=>$request,
        'account_data'=>$service->snapshot($mgwId),
    ]);
} catch (AccountDataLifecycleException $error) {
    $status = in_array($error->reason, ['deletion_locked','rate_limited'], true) ? 409 : 422;
    json_response(['ok'=>false,'code'=>$error->reason,'error'=>$error->getMessage()], $status);
} catch (Throwable $error) {
    error_log('[MiniGamesWorld account data website hook] ' . $error->getMessage());
    json_response(['ok'=>false,'error'=>'Website account-data hook failed.'], 500);
}
