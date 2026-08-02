<?php
declare(strict_types=1);

final class WebAppLaunchUrl
{
    // Emergency rollback: restore the accepted v110 graph as the active route.
    private const ENTRY_PATH = '/app/v110.php?v=1123';
    // The isolated v120 controller remains in the repository for postmortem only:
    // private const ENTRY_PATH = '/app/v120.php?v=1200';
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
