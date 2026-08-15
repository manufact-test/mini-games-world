<?php
declare(strict_types=1);

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
  throw new RuntimeException('Старое Gold-приглашение доступно только в архиве. Создайте новое приглашение.');
        }
    }

    public static function legacyArchiveMessage(): string
    {
        return 'Legacy Match/Gold операции доступны только для просмотра. Новые операции отключены.';
    }

    public static function rejectLegacyCommerceWrite(): never
    {
        throw new RuntimeException(self::legacyArchiveMessage());
    }
}
