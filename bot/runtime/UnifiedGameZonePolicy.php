<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/localization/ServerLocalization.php';

final class UnifiedGameZonePolicy
{
    private const STORAGE_ROOM = 'match';

    public static function storageRoom(): string
    {
        return self::STORAGE_ROOM;
    }

    public static function entryCost(array $config): int
    {
        $runtime = $config['canonical_match_economy'] ?? null;
        $entry = is_array($runtime) ? (int)($runtime['entry_cost'] ?? 0) : 0;
        if ($entry <= 0) $entry = (int)($config['match_bet'] ?? 0);
        if ($entry <= 0) throw new RuntimeException('Canonical unified-zone entry cost is unavailable.');
        return $entry;
    }

    public static function assertInviteWritable(array $invite): void
    {
        if (strtolower(trim((string)($invite['room'] ?? self::STORAGE_ROOM))) === 'gold') {
  throw new RuntimeException(ServerLocalization::copy('server.game_runtime.legacy_zone.archived_gold_invite', 'The legacy Gold invitation is archive-only. Create a new invitation.'));
        }
    }

    public static function legacyArchiveMessage(): string
    {
        return ServerLocalization::copy('server.game_runtime.legacy_zone.read_only', 'Legacy Match/Gold operations are read-only. New operations are disabled.');
    }

    public static function rejectLegacyCommerceWrite(): never
    {
        throw new RuntimeException(self::legacyArchiveMessage());
    }
}
