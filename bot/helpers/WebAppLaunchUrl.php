<?php
declare(strict_types=1);

final class WebAppLaunchUrl
{
    // This is the single user-facing Telegram /start and invitation graph.
    // Keep it identical to the route exercised by the canonical browser suite.
    private const ENTRY_PATH = '/app/v114.php?v=124';
    private const INVITE_PATTERN = '/^[a-f0-9]{24}$/i';

    public static function base(array $config): string
    {
        $baseUrl = rtrim(trim((string)($config['base_url'] ?? '')), '/');
        return $baseUrl === '' ? '' : $baseUrl . self::ENTRY_PATH;
    }

    public static function invitation(array $config, string $token): string
    {
        $baseUrl = self::base($config);
        if ($baseUrl === '') return '';

        $normalizedToken = strtolower(trim($token));
        if (!preg_match(self::INVITE_PATTERN, $normalizedToken)) return $baseUrl;

        return $baseUrl . '&invite=' . rawurlencode($normalizedToken);
    }
}
