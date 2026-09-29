<?php
declare(strict_types=1);

final class TelegramWebhookSecurity
{
    private const TOKEN_CONTEXT = 'mgw-telegram-webhook-secret-v1';

    public static function secretToken(array $config): string
    {
        $configured = trim((string)($config['telegram_webhook_secret'] ?? ''));
        if ($configured !== '') {
            self::assertTelegramSecretShape($configured);
            return $configured;
        }

        $setupSecret = self::setupSecret($config);
        $derived = rtrim(strtr(
            base64_encode(hash_hmac('sha256', self::TOKEN_CONTEXT, $setupSecret, true)),
            '+/',
            '-_'
        ), '=');
        $token = 'mgw_' . $derived;
        self::assertTelegramSecretShape($token);
        return $token;
    }

    public static function incomingAuthorized(array $config, array $server): bool
    {
        $provided = trim((string)($server['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] ?? ''));
        if ($provided === '') return false;

        try {
            return hash_equals(self::secretToken($config), $provided);
        } catch (Throwable) {
            return false;
        }
    }

    public static function setupBearerAuthorized(array $config, array $server): bool
    {
        $authorization = trim((string)(
            $server['HTTP_AUTHORIZATION']
            ?? $server['REDIRECT_HTTP_AUTHORIZATION']
            ?? ''
        ));
        if (preg_match('/^Bearer\s+([^\s]+)$/i', $authorization, $match) !== 1) {
            return false;
        }

        try {
            return hash_equals(self::setupSecret($config), trim((string)$match[1]));
        } catch (Throwable) {
            return false;
        }
    }

    private static function setupSecret(array $config): string
    {
        $secret = trim((string)($config['setup_secret'] ?? ''));
        if (strlen($secret) < 32
            || $secret === 'CHANGE_THIS_SETUP_SECRET'
            || $secret === 'CHANGE_ME_TO_LONG_RANDOM_SECRET'
            || str_contains(strtoupper($secret), 'CHANGE_ME')) {
            throw new RuntimeException('Setup authorization secret is not configured safely.');
        }
        return $secret;
    }

    private static function assertTelegramSecretShape(string $secret): void
    {
        if (strlen($secret) < 32
            || strlen($secret) > 256
            || preg_match('/^[A-Za-z0-9_-]+$/', $secret) !== 1) {
            throw new RuntimeException('Telegram webhook secret is not configured safely.');
        }
    }
}
