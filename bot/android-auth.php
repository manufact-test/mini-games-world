<?php
declare(strict_types=1);

require __DIR__ . '/core/bootstrap.php';
require_once __DIR__ . '/helpers/WebAppLaunchUrl.php';
require_once __DIR__ . '/accounts/AndroidDeviceAuthService.php';

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    http_response_code(405);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Откройте MINI GAMES WORLD через приложение Android.';
    exit;
}

$service = new AndroidDeviceAuthService($config);
if (!$service->enabled()) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Android-вход сейчас недоступен.';
    exit;
}

$form = $_POST;
if (!is_array($form) || trim((string)($form['credential'] ?? '')) === '') {
    $raw = file_get_contents('php://input') ?: '';
    $parsed = [];
    parse_str($raw, $parsed);
    if (is_array($parsed)) $form = $parsed;
}

$credential = trim((string)($form['credential'] ?? ''));
$existingToken = trim((string)($_COOKIE[AndroidDeviceAuthService::COOKIE_NAME] ?? ''));
$remoteAddress = trim((string)($_SERVER['REMOTE_ADDR'] ?? ''));

try {
    $result = $service->bootstrapCredential($credential, $remoteAddress, $existingToken);
    $sessionToken = (string)($result['session_token'] ?? '');
    if ($sessionToken === '') throw new RuntimeException('Android session token was not issued.');

    setcookie(AndroidDeviceAuthService::COOKIE_NAME, $sessionToken, [
        'expires'=>time() + $service->cookieTtlSec(),
        'path'=>'/',
        'secure'=>true,
        'httponly'=>true,
        'samesite'=>'Strict',
    ]);

    $launchUrl = WebAppLaunchUrl::base($config);
    if ($launchUrl === '') throw new RuntimeException('Android launch URL is unavailable.');

    header('Location: ' . $launchUrl, true, 303);
    exit;
} catch (AndroidAuthRateLimitException $error) {
    header('Retry-After: ' . $error->retryAfterSec);
    http_response_code(429);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Слишком много попыток входа. Попробуйте немного позже.';
    exit;
} catch (InvalidArgumentException) {
    http_response_code(400);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Не удалось подтвердить это устройство.';
    exit;
} catch (Throwable $error) {
    error_log('[Mini Games World Android auth] ' . $error::class . ': ' . $error->getMessage());
    http_response_code(503);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Не удалось открыть MINI GAMES WORLD. Попробуйте ещё раз.';
    exit;
}
