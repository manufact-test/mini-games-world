<?php
declare(strict_types=1);

final class WebAppLaunchUrl
{
    // Active production route uses the isolated v120 controller.
    private const ENTRY_PATH = '/app/v120.php?v=1200';
    // Retained byte-exact rollback route marker for the accepted previous graph:
    // private const ENTRY_PATH = '/app/v110.php?v=1123';
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
