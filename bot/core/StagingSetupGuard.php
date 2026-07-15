<?php
declare(strict_types=1);

final class StagingSetupGuard
{
    public static function assertStaging(array $config): void
    {
        if ((string)($config['environment'] ?? '') !== 'staging') {
            throw new RuntimeException('Staging setup tools are unavailable in this environment.');
        }
    }

    public static function authorize(array $config, string $providedKey): void
    {
        self::assertStaging($config);

        $expectedKey = trim((string)($config['staging_setup_key'] ?? ''));
        if (strlen($expectedKey) < 20) {
            throw new RuntimeException('Staging setup key is not configured.');
        }

        if ($providedKey === '' || !hash_equals($expectedKey, $providedKey)) {
            throw new RuntimeException('Staging setup access denied.');
        }
    }

    public static function webhookUrl(array $config): string
    {
        self::assertStaging($config);

        $baseUrl = rtrim(trim((string)($config['base_url'] ?? '')), '/');
        if ($baseUrl === '') {
            throw new RuntimeException('Staging base URL is not configured.');
        }

        return $baseUrl . '/bot/webhook.php';
    }

    public static function expectedBotUsername(array $config): string
    {
        return ltrim(strtolower(trim((string)($config['staging_bot_username'] ?? ''))), '@');
    }

    public static function assertExpectedBot(array $config, array $bot): void
    {
        $expected = self::expectedBotUsername($config);
        if ($expected === '') {
            return;
        }

        $actual = ltrim(strtolower(trim((string)($bot['username'] ?? ''))), '@');
        if ($actual === '' || !hash_equals($expected, $actual)) {
            throw new RuntimeException('Configured Telegram token belongs to an unexpected bot.');
        }
    }
}
