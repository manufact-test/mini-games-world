<?php
declare(strict_types=1);

final class WebAppLaunchUrl
{
    // Emergency rollback: restore the accepted v110 graph as the active route.
    private const ENTRY_PATH = '/app/v110.php?v=1206&domino_stability=30&runtime_fix=1&four_store=static-v6&paid_default=dedup-v2&four_profile=spacing-v2&four_module=export-v8&four_live=game-v5&four_drop=target-lock-no-base-flash-v2&four_effect2=chain-v3&copy=human-v1';
    private const ADMIN_PATH = '/app/admin.php?v=1';
    // The isolated v120 controller remains in the repository for postmortem only:
    // private const ENTRY_PATH = '/app/v120.php?v=1200';
    private const INVITE_PATTERN = '/^[a-f0-9]{24}$/i';

    public static function base(array $config): string
    {
        $baseUrl = rtrim(trim((string)($config['base_url'] ?? '')), '/');
        return $baseUrl === '' ? '' : $baseUrl . self::ENTRY_PATH;
    }

    public static function admin(array $config): string
    {
        $baseUrl = rtrim(trim((string)($config['base_url'] ?? '')), '/');
        return $baseUrl === '' ? '' : $baseUrl . self::ADMIN_PATH;
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
