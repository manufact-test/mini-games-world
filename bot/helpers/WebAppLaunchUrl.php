<?php
declare(strict_types=1);

final class WebAppLaunchUrl
{
    // Emergency rollback: restore the accepted v110 graph as the active route.
    private const ENTRY_PATH = '/app/v110.php?v=1233&domino_stability=30&runtime_fix=1&four_store=live-previews-v3&paid_default=dedup-v2&four_profile=live-previews-v3&four_module=export-v11&four_live=game-v12&four_drop=target-lock-no-base-flash-v2&four_effect2=random-chain-v4&four_victory=full-finale-v2&copy=compact-v3&four_geometry=7x6&four_fx=victory-test-exact-v3&battleship_store=preview-parity-v14&battleship_profile=four-parity-v3&battleship_live=maps-fleets-v4&battleship_shot=live-v2&battleship_impacts=live-v2&battleship_destroy=live-v4&battleship_fire=direct-result-v4&battleship_frame=full-v1&battleship_neon_fleet=tube-v4&battleship_preview_geometry=svg-circles-v6&battleship_store_hydration=observer-v1&battleship_preview_inline_owner=svg-v5&battleship_fleet_preview=svg-models-v3&battleship_neon_map_ships=white-v1&battleship_leave_sheet=readonly-guard-v1&battleship_viewport=scroll-v1&battleship_geometry=square&battleship_copy=human-v1&battleship_header=steel-ship-v1&battleship_neon_frame=outer-safe-v1&battleship_effects=live-parity-destroy-v3&battleship_paid_default=distinct-v1&bundles=checkers-preview-fit-sheet-parity-v4';
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
