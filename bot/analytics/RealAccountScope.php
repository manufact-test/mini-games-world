<?php
declare(strict_types=1);

/**
 * Single SQL owner for product-facing "real account" metrics.
 *
 * Synthetic staging identities remain durable history, but must never inflate
 * product readiness or analytics.
 */
final class RealAccountScope
{
    private function __construct() {}

    /**
     * @return array{sql:string,params:array<string,string>}
     */
    public static function userPredicate(string $alias = 'u', string $prefix = 'real'): array
    {
        self::assertIdentifier($alias);
        self::assertIdentifier($prefix);

        return [
            'sql' => $alias . '.status <> :' . $prefix . '_retired_fixture_status
                AND NOT EXISTS (
                    SELECT 1
                    FROM mgw_identities ' . $prefix . '_dev_identity
                    WHERE ' . $prefix . '_dev_identity.mgw_id = ' . $alias . '.mgw_id
                      AND ' . $prefix . '_dev_identity.provider = :' . $prefix . '_development_provider
                )
                AND NOT EXISTS (
                    SELECT 1
                    FROM mgw_account_ownership ' . $prefix . '_fixture_ownership
                    WHERE ' . $prefix . '_fixture_ownership.mgw_id = ' . $alias . '.mgw_id
                      AND (
                          (
                              ' . $prefix . '_fixture_ownership.source_type = :' . $prefix . '_legacy_fixture_source_type
                              AND substr(' . $prefix . '_fixture_ownership.legacy_user_id, 1, 9) = :' . $prefix . '_fixture_legacy_prefix
                          )
                          OR (
                              ' . $prefix . '_fixture_ownership.source_type = :' . $prefix . '_runtime_identity_source_type
                              AND substr(' . $prefix . '_fixture_ownership.source_ref, 1, 21) = :' . $prefix . '_fixture_source_ref_prefix
                          )
                      )
                )',
            'params' => [
                $prefix . '_retired_fixture_status' => 'staging_fixture_retired',
                $prefix . '_development_provider' => 'development',
                $prefix . '_legacy_fixture_source_type' => 'staging_fixture_repair',
                $prefix . '_runtime_identity_source_type' => 'runtime_identity',
                $prefix . '_fixture_legacy_prefix' => 'stg_tour_',
                $prefix . '_fixture_source_ref_prefix' => 'development:stg_tour_',
            ],
        ];
    }

    /**
     * @return array{sql:string,params:array<string,string>}
     */
    public static function existsForMgwExpression(
        string $mgwExpression,
        string $prefix = 'real'
    ): array {
        self::assertColumnExpression($mgwExpression);
        $userAlias = $prefix . '_user';
        $scope = self::userPredicate($userAlias, $prefix);

        return [
            'sql' => 'EXISTS (
                SELECT 1
                FROM mgw_users ' . $userAlias . '
                WHERE ' . $userAlias . '.mgw_id = ' . $mgwExpression . '
                  AND ' . $scope['sql'] . '
            )',
            'params' => $scope['params'],
        ];
    }

    /**
     * Ledger/balance history can predate direct mgw_id attachment. Resolve
     * account_ref through canonical ownership before deciding whether the row
     * belongs to a real account.
     *
     * @return array{sql:string,params:array<string,string>}
     */
    public static function existsForLedgerIdentity(
        string $mgwExpression,
        string $accountRefExpression,
        string $prefix = 'real'
    ): array {
        self::assertColumnExpression($mgwExpression);
        self::assertColumnExpression($accountRefExpression);
        $userAlias = $prefix . '_user';
        $ownerAlias = $prefix . '_owner';
        $scope = self::userPredicate($userAlias, $prefix);

        return [
            'sql' => 'EXISTS (
                SELECT 1
                FROM mgw_users ' . $userAlias . '
                WHERE (
                    ' . $userAlias . '.mgw_id = ' . $mgwExpression . '
                    OR EXISTS (
                        SELECT 1
                        FROM mgw_account_ownership ' . $ownerAlias . '
                        WHERE ' . $ownerAlias . '.account_ref = ' . $accountRefExpression . '
                          AND ' . $ownerAlias . '.mgw_id = ' . $userAlias . '.mgw_id
                          AND ' . $ownerAlias . '.ownership_status = :' . $prefix . '_ownership_status
                    )
                )
                  AND ' . $scope['sql'] . '
            )',
            'params' => $scope['params'] + [
                $prefix . '_ownership_status' => 'active',
            ],
        ];
    }

    private static function assertIdentifier(string $value): void
    {
        if (preg_match('/^[A-Za-z][A-Za-z0-9_]*$/', $value) !== 1) {
            throw new InvalidArgumentException('Invalid SQL scope identifier.');
        }
    }

    private static function assertColumnExpression(string $value): void
    {
        if (preg_match('/^[A-Za-z][A-Za-z0-9_]*\.[A-Za-z][A-Za-z0-9_]*$/', $value) !== 1) {
            throw new InvalidArgumentException('Invalid SQL scope column expression.');
        }
    }
}
