<?php
declare(strict_types=1);

require __DIR__ . '/core/bootstrap.php';
require_once __DIR__ . '/localization/ServerLocalization.php';
require_once __DIR__ . '/helpers/WebAppLaunchUrl.php';
require_once __DIR__ . '/accounts/AndroidDeviceAuthService.php';

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    http_response_code(405);
    header('Content-Type: text/plain; charset=utf-8');
    echo ServerLocalization::copy('server.account_chain.auth.open_from_android', 'Open MINI GAMES WORLD from the Android app.');
    exit;
}

$service = new AndroidDeviceAuthService($config);
if (!$service->enabled()) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo ServerLocalization::copy('server.account_chain.auth.unavailable', 'Android sign-in is temporarily unavailable.');
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
$inviteToken = strtolower(trim((string)($form['invite'] ?? '')));
if ($inviteToken !== '' && preg_match('/^[a-f0-9]{24}$/', $inviteToken) !== 1) {
    http_response_code(400);
    header('Content-Type: text/plain; charset=utf-8');
    echo ServerLocalization::copy('server.account_chain.auth.invalid_invite_link', 'Invalid invitation link.');
    exit;
}
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

    $launchUrl = $inviteToken !== ''
        ? WebAppLaunchUrl::invitation($config, $inviteToken)
        : WebAppLaunchUrl::base($config);
    if ($launchUrl === '') throw new RuntimeException('Android launch URL is unavailable.');

    header('Location: ' . $launchUrl, true, 303);
    exit;
} catch (AndroidAuthRateLimitException $error) {
    header('Retry-After: ' . $error->retryAfterSec);
    http_response_code(429);
    header('Content-Type: text/plain; charset=utf-8');
    echo ServerLocalization::copy('server.account_chain.auth.rate_limited', 'Too many sign-in attempts. Try again later.');
    exit;
} catch (InvalidArgumentException) {
    http_response_code(400);
    header('Content-Type: text/plain; charset=utf-8');
    echo ServerLocalization::copy('server.account_chain.reauth.device_confirm_failed', 'This device could not be verified.');
    exit;
} catch (Throwable $error) {
    error_log('[Mini Games World Android auth] ' . $error::class . ': ' . $error->getMessage());
    http_response_code(503);
    header('Content-Type: text/plain; charset=utf-8');
    echo ServerLocalization::copy('server.account_chain.auth.open_failed', 'MINI GAMES WORLD could not be opened. Try again.');
    exit;
}
