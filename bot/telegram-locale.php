<?php
declare(strict_types=1);

require __DIR__ . '/core/bootstrap.php';
require_once __DIR__ . '/localization/TelegramLocalePreference.php';

header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        json_response(['ok'=>false, 'error'=>'Method not allowed.'], 405);
    }

    $payload = json_decode(file_get_contents('php://input') ?: '{}', true);
    if (!is_array($payload)) json_response(['ok'=>false, 'error'=>'Invalid request.'], 400);

    $locale = $payload['locale'] ?? null;
    if (!is_string($locale) || !in_array($locale, ['ru', 'en'], true)) {
        json_response(['ok'=>false, 'error'=>'Unsupported locale.'], 400);
    }

    // Use Telegram's HMAC-verified, age-bounded initData directly. Do not
    // accept browser-dev identity, Android cookies or untrusted user IDs.
    $initData = $payload['initData'] ?? null;
    if (!is_string($initData) || $initData === '') {
        json_response(['ok'=>false, 'error'=>'Telegram authorization required.'], 401);
    }
    $actor = (new AuthService($config))->getTelegramUserFromInitData($initData, false);
    $telegramId = (string)($actor['id'] ?? '');
    if (!TelegramLocalePreference::save($config, $telegramId, $locale)) {
        json_response(['ok'=>false, 'error'=>'User is not ready.'], 409);
    }

    json_response(['ok'=>true, 'locale'=>$locale]);
} catch (Throwable $error) {
    error_log('[MiniGamesWorld Telegram locale] preference update failed');
    json_response(['ok'=>false, 'error'=>'Could not save language preference.'], 401);
}
