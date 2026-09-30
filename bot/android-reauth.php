<?php
declare(strict_types=1);

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');

require __DIR__ . '/core/bootstrap.php';
require_once __DIR__ . '/accounts/AndroidDeviceAuthService.php';
require_once __DIR__ . '/accounts/AndroidAccountReauthService.php';

try {
    if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
        json_response(['ok'=>false,'error'=>'Метод запроса не поддерживается.'], 405);
    }

    $payload = [];
    $contentType = strtolower(trim((string)($_SERVER['CONTENT_TYPE'] ?? '')));
    if (str_contains($contentType, 'application/json')) {
        $decoded = json_decode(file_get_contents('php://input') ?: '{}', true);
        if (is_array($decoded)) $payload = $decoded;
    } else {
        $payload = is_array($_POST ?? null) ? $_POST : [];
        if ($payload === []) {
            $raw = file_get_contents('php://input') ?: '';
            parse_str($raw, $parsed);
            if (is_array($parsed)) $payload = $parsed;
        }
    }

    $databaseConfig = DatabaseConfig::fromApplicationConfig($config);
    if (!$databaseConfig->enabled()) {
        json_response(['ok'=>false,'error'=>'Подтверждение Android сейчас недоступно.'], 503);
    }
    $database = PdoConnectionFactory::create($databaseConfig);
    $service = new AndroidAccountReauthService($config, $database);
    if (!$service->enabled()) {
        json_response(['ok'=>false,'error'=>'Подтверждение Android сейчас недоступно.'], 503);
    }

    $action = strtolower(trim((string)($payload['action'] ?? 'create')));

    if ($action === 'create') {
        $authenticated = (new AuthService($config))->getUserFromRequest($payload);
        if ((string)($authenticated['mgw_identity_provider'] ?? '') !== 'android_device') {
            json_response([
                'ok'=>false,
                'code'=>'android_auth_required',
                'error'=>'Подтверждение доступно только в Android-приложении.',
            ], 403);
        }

        $sessionToken = trim((string)($_COOKIE[AndroidDeviceAuthService::COOKIE_NAME] ?? ''));
        json_response([
            'ok'=>true,
            'reauth'=>$service->createChallenge($authenticated, $sessionToken),
        ]);
    }

    if ($action === 'confirm_native') {
        $challengeId = trim((string)($payload['challenge_id'] ?? ''));
        $credential = trim((string)($payload['credential'] ?? ''));
        $remoteAddress = trim((string)($_SERVER['REMOTE_ADDR'] ?? ''));

        json_response([
            'ok'=>true,
            'reauth'=>$service->confirmNative($challengeId, $credential, $remoteAddress),
        ]);
    }

    json_response(['ok'=>false,'error'=>'Некорректное действие подтверждения Android.'], 400);
} catch (AndroidAuthRateLimitException $error) {
    header('Retry-After: ' . $error->retryAfterSec);
    json_response([
        'ok'=>false,
        'code'=>'rate_limited',
        'error'=>'Слишком много попыток подтверждения. Попробуйте позже.',
    ], 429);
} catch (AndroidAccountReauthException $error) {
    if ($error->reason === 'rate_limited') {
        header('Retry-After: 3600');
    }
    json_response([
        'ok'=>false,
        'code'=>$error->reason,
        'error'=>mgw_public_api_error($error->getMessage()),
    ], $error->httpStatus);
} catch (InvalidArgumentException) {
    json_response([
        'ok'=>false,
        'code'=>'credential_invalid',
        'error'=>'Не удалось подтвердить это устройство.',
    ], 400);
} catch (Throwable $error) {
    error_log('[Mini Games World Android reauth] ' . $error::class . ': ' . $error->getMessage());
    json_response([
        'ok'=>false,
        'error'=>'Не удалось подтвердить действие. Попробуйте ещё раз.',
    ], 500);
}
