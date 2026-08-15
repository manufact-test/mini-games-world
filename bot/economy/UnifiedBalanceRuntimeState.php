<?php
declare(strict_types=1);

/**
 * Canonical runtime owner for the MVP-15.3 single-balance transition.
 *
 * Legacy balance_match / balance_gold values are captured once for audit and
 * never used as writable runtime balances after migration. The authoritative
 * runtime field is users[*].balance.
 */
final class UnifiedBalanceRuntimeState
{
    public const VERSION = 'mvp15.3-staging-1to1-v1';
    public const FIELD = 'balance';

    public static function migrateAll(array &$data): array
    {
        if (!isset($data['users']) || !is_array($data['users'])) {
            $data['users'] = [];
        }

        $migrated = 0;
        $already = 0;
        foreach ($data['users'] as &$user) {
            if (!is_array($user)) continue;
            $result = self::ensureUser($user);
            if ($result['migrated']) $migrated++;
            else $already++;
        }
        unset($user);

        return [
            'version' => self::VERSION,
            'migrated_user_count' => $migrated,
            'already_migrated_user_count' => $already,
        ];
    }

    public static function ensureUser(array &$user): array
    {
        if (array_key_exists(self::FIELD, $user)) {
            $balance = self::nonNegativeInteger($user[self::FIELD], self::FIELD);
            $user[self::FIELD] = $balance;
            self::assertMigrationMetadata($user, $balance);
            return [
                'migrated' => false,
                'balance' => $balance,
            ];
        }

        $match = self::nonNegativeInteger($user['balance_match'] ?? 0, 'balance_match');
        $gold = self::nonNegativeInteger($user['balance_gold'] ?? 0, 'balance_gold');
        if ($match > PHP_INT_MAX - $gold) {
            throw new RuntimeException('Unified balance migration would overflow integer range.');
        }
        $balance = $match + $gold;
        $migratedAt = gmdate('Y-m-d\TH:i:s\Z');

        $user[self::FIELD] = $balance;
        $user['unified_balance_migration'] = [
            'version' => self::VERSION,
            'source_balance_match' => $match,
            'source_balance_gold' => $gold,
            'target_balance' => $balance,
            'migrated_at_utc' => $migratedAt,
            'mapping' => '1_match=1_mgw_coin;1_gold=1_mgw_coin',
        ];

        return [
            'migrated' => true,
            'balance' => $balance,
            'legacy_match' => $match,
            'legacy_gold' => $gold,
        ];
    }

    public static function legacyBreakdown(array $user): array
    {
        $migration = is_array($user['unified_balance_migration'] ?? null)
            ? $user['unified_balance_migration']
            : [];

        return [
            'version' => (string)($migration['version'] ?? ''),
            'source_balance_match' => self::nonNegativeInteger(
                $migration['source_balance_match'] ?? ($user['balance_match'] ?? 0),
                'legacy source_balance_match'
            ),
            'source_balance_gold' => self::nonNegativeInteger(
                $migration['source_balance_gold'] ?? ($user['balance_gold'] ?? 0),
                'legacy source_balance_gold'
            ),
            'target_balance' => self::nonNegativeInteger(
                $migration['target_balance'] ?? ($user[self::FIELD] ?? 0),
                'legacy target_balance'
            ),
            'migrated_at_utc' => (string)($migration['migrated_at_utc'] ?? ''),
        ];
    }

    private static function assertMigrationMetadata(array &$user, int $balance): void
    {
        $migration = $user['unified_balance_migration'] ?? null;
        if (!is_array($migration)) {
            // A canonical balance created natively after MVP-15.3 does not need a
            // legacy conversion record. Existing legacy fields, however, must
            // never silently coexist with an unexplained canonical balance.
            if (array_key_exists('balance_match', $user) || array_key_exists('balance_gold', $user)) {
                throw new RuntimeException('Canonical balance has legacy fields but no unification audit metadata.');
            }
            return;
        }

        $version = trim((string)($migration['version'] ?? ''));
        if ($version !== self::VERSION) {
            throw new RuntimeException('Unified balance migration version mismatch.');
        }
        $match = self::nonNegativeInteger($migration['source_balance_match'] ?? null, 'source_balance_match');
        $gold = self::nonNegativeInteger($migration['source_balance_gold'] ?? null, 'source_balance_gold');
        if ($match > PHP_INT_MAX - $gold || $match + $gold !== (int)($migration['target_balance'] ?? -1)) {
            throw new RuntimeException('Unified balance legacy breakdown is inconsistent.');
        }
        if ((int)$migration['target_balance'] < 0) {
            throw new RuntimeException('Unified balance migration target is invalid.');
        }

        // target_balance is the one-time conversion snapshot, not the current
        // mutable balance. It is intentionally allowed to differ after gameplay.
        $user[self::FIELD] = $balance;
    }

    private static function nonNegativeInteger(mixed $value, string $label): int
    {
        if (is_int($value)) {
            $number = $value;
        } elseif (is_string($value) && preg_match('/^\d+$/', trim($value)) === 1) {
            if (strlen(trim($value)) > strlen((string)PHP_INT_MAX)
                || (strlen(trim($value)) === strlen((string)PHP_INT_MAX)
                    && strcmp(trim($value), (string)PHP_INT_MAX) > 0)) {
                throw new RuntimeException('Unified balance field exceeds integer range: ' . $label . '.');
            }
            $number = (int)trim($value);
        } elseif (is_float($value) && $value >= 0 && floor($value) === $value && $value <= PHP_INT_MAX) {
            $number = (int)$value;
        } else {
            throw new RuntimeException('Invalid unified balance field: ' . $label . '.');
        }

        if ($number < 0) {
            throw new RuntimeException('Negative unified balance field: ' . $label . '.');
        }
        return $number;
    }
}
