<?php
declare(strict_types=1);

final class ProductionPrimaryApplicationEntrypoints
{
    private const PATH_TO_ID = [
        'bot/api.php' => 'api',
        'bot/webhook.php' => 'webhook',
        'bot/invites.php' => 'invites',
        'bot/notifications.php' => 'notifications',
        'bot/invite-opponents.php' => 'invite_opponents',
        'bot/presence.php' => 'presence',
        'bot/game-clock.php' => 'game_clock',
        'bot/game-live-v108.php' => 'game_live_v108',
        'bot/search-speed.php' => 'search_speed',
        'bot/shop-history.php' => 'shop_history',
        'bot/cron/weekly-match.php' => 'weekly_match_cron',
    ];

    public static function resolve(string $projectRoot, array $server): string
    {
        $root = realpath($projectRoot);
        $script = realpath(trim((string)($server['SCRIPT_FILENAME'] ?? '')));
        if ($root === false || $script === false) return '';

        $rootPrefix = rtrim(str_replace('\\', '/', $root), '/') . '/';
        $normalizedScript = str_replace('\\', '/', $script);
        if (!str_starts_with($normalizedScript, $rootPrefix)) return '';

        $relative = substr($normalizedScript, strlen($rootPrefix));
        return self::PATH_TO_ID[$relative] ?? '';
    }

    public static function supports(string $entrypoint): bool
    {
        return in_array($entrypoint, self::identifiers(), true);
    }

    /** @return list<string> */
    public static function identifiers(): array
    {
        return array_values(self::PATH_TO_ID);
    }

    /** @return array<string, string> */
    public static function pathMap(): array
    {
        return self::PATH_TO_ID;
    }
}
