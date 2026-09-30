<?php
declare(strict_types=1);

require __DIR__ . '/core/bootstrap.php';
require_once __DIR__ . '/accounts/AndroidDeviceAuthService.php';
require_once __DIR__ . '/accounts/AccountReauthGuard.php';

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    json_response(['ok'=>false,'code'=>'method_not_allowed','error'=>'Метод запроса не поддерживается.'], 405);
}

$service = new AndroidDeviceAuthService($config);
if (!$service->enabled()) {
    json_response(['ok'=>false,'code'=>'android_reauth_unavailable','error'=>'Подтверждение Android сейчас недоступно.'], 404);
}

$raw = file_get_contents('php://input');
if (!is_string($raw) || strlen($raw) > 1024) {
    json_response(['ok'=>false,'code'=>'invalid_request','error'=>'Некорректный запрос подтверждения.'], 400);
}

$form = $_POST;
if (!is_array($form) || trim((string)($form['credential'] ?? '')) === '') {
    $parsed = [];
    parse_str($raw, $parsed);
    if (is_array($parsed)) $form = $parsed;
}

$credential = trim((string)($form['credential'] ?? ''));
$sessionToken = trim((string)($_COOKIE[AndroidDeviceAuthService::COOKIE_NAME] ?? ''));
$remoteAddress = trim((string)($_SERVER['REMOTE_ADDR'] ?? ''));

if ($credential === '' || $sessionToken === '') {
    json_response([
        'ok'=>false,
        'code'=>'android_reauth_session_required',
        'error'=>'Сессия Android недоступна. Закройте приложение и откройте снова.',
    ], 401);
}

try {
    $result = $service->reauthenticateCredential($credential, $sessionToken, $remoteAddress);
    json_response([
        'ok'=>true,
        'reauthenticated_at_utc'=>(string)($result['reauthenticated_at_utc'] ?? ''),
        'valid_for_seconds'=>AccountReauthGuard::MAX_AGE_SECONDS,
    ]);
} catch (AndroidAuthRateLimitException $error) {
    header('Retry-After: ' . $error->retryAfterSec);
    json_response([
        'ok'=>false,
        'code'=>'rate_limited',
        'error'=>'Слишком много попыток подтверждения. Попробуйте немного позже.',
    ], 429);
} catch (InvalidArgumentException) {
    json_response([
        'ok'=>false,
        'code'=>'android_reauth_rejected',
        'error'=>'Не удалось подтвердить эту Android-сессию.',
    ], 401);
} catch (Throwable $error) {
    error_log('[Mini Games World Android reauth] ' . $error::class);
    json_response([
        'ok'=>false,
        'code'=>'android_reauth_unavailable',
        'error'=>'Не удалось подтвердить действие. Попробуйте ещё раз.',
    ], 503);
}
