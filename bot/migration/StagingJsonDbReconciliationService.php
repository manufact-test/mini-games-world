<?php
declare(strict_types=1);

final class StagingJsonDbReconciliationService
{
    public function __construct(
        private DatabaseConnectionInterface $database,
        private LegacyRealtimeShadowSyncService $realtimeShadow,
        private LegacyEconomyShadowSyncService $economyShadow,
        private LegacyOpeningBalanceImportService $openingBalances,
        private LegacyFinancialArchiveImportService $financialArchive
    ) {}

    public function report(): array
    {
        $realtime = $this->realtimeShadow->preview();
        $economy = $this->economyShadow->preview();
        $opening = $this->openingBalances->preview();
        $archive = $this->financialArchive->preview();

        $blocking = [];
        $this->collectShadowBlockingReasons('realtime', $realtime['sections'] ?? [], $blocking);
        $this->collectShadowBlockingReasons('economy', $economy['sections'] ?? [], $blocking);

        if (!empty($opening['blocking_reasons'])) {
            foreach ((array)$opening['blocking_reasons'] as $reason) {
                $blocking[] = 'opening_balances: ' . (string)$reason;
            }
        }
        if (!empty($archive['blocking_reasons'])) {
            foreach ((array)$archive['blocking_reasons'] as $reason) {
                $blocking[] = 'financial_archive: ' . (string)$reason;
            }
        }

        $accountMapping = $this->accountMappingSummary();
        $targets = $this->normalizedTargetSummary($realtime, $opening, $archive, $accountMapping);
        $gaps = [];
        foreach ($targets as $name => $target) {
            if (!empty($target['gap_count'])) {
                $gaps[] = $name . ': source=' . (int)$target['source_count']
                    . ', database=' . (int)$target['database_count'];
            }
        }
        if (($accountMapping['missing_identity_count'] ?? 0) > 0) {
            $gaps[] = 'accounts: ' . (int)$accountMapping['missing_identity_count']
                . ' legacy user(s) still need MGW identity mapping';
        }
        if (($accountMapping['missing_user_row_count'] ?? 0) > 0) {
            $gaps[] = 'accounts: ' . (int)$accountMapping['missing_user_row_count']
                . ' mapped identity record(s) have no MGW user row';
        }

        $fingerprint = $this->reportFingerprint([
            'realtime' => (string)($realtime['source_fingerprint'] ?? ''),
            'economy' => (string)($economy['source_fingerprint'] ?? ''),
            'opening' => (string)($opening['source_fingerprint'] ?? ''),
            'financial_archive' => (string)($archive['source_fingerprint'] ?? ''),
            'targets' => $targets,
            'account_mapping' => $accountMapping,
        ]);

        return [
            'ok' => $blocking === [],
            'read_only' => true,
            'ready_for_next_import_step' => $blocking === [],
            'count_parity_complete' => $blocking === [] && $gaps === [],
            'report_fingerprint' => $fingerprint,
            'source_fingerprints' => [
                'realtime' => (string)($realtime['source_fingerprint'] ?? ''),
                'economy' => (string)($economy['source_fingerprint'] ?? ''),
                'opening_balances' => (string)($opening['source_fingerprint'] ?? ''),
                'financial_archive' => (string)($archive['source_fingerprint'] ?? ''),
            ],
            'shadow_checks' => [
                'realtime' => $this->compactShadowSections($realtime['sections'] ?? []),
                'economy' => $this->compactShadowSections($economy['sections'] ?? []),
            ],
            'opening_balances' => [
                'status' => (string)($opening['status'] ?? ''),
                'ready' => !empty($opening['ready']),
                'source_user_count' => (int)($opening['source_user_count'] ?? 0),
                'source_asset_count' => (int)($opening['source_asset_count'] ?? 0),
                'source_totals' => $opening['source_totals'] ?? [],
                'unchanged_count' => (int)($opening['unchanged_count'] ?? 0),
                'conflict_count' => (int)($opening['conflict_count'] ?? 0),
                'unmanaged_balance_count' => (int)($opening['unmanaged_balance_count'] ?? 0),
                'unmanaged_ledger_count' => (int)($opening['unmanaged_ledger_count'] ?? 0),
            ],
            'financial_archive' => [
                'status' => (string)($archive['status'] ?? ''),
                'ready' => !empty($archive['ready']),
                'source_counts' => $archive['source_counts'] ?? [],
                'archive_counts' => $archive['archive_counts'] ?? [],
                'unchanged_counts' => $archive['unchanged_counts'] ?? [],
                'conflict_counts' => $archive['conflict_counts'] ?? [],
                'unmanaged_counts' => $archive['unmanaged_counts'] ?? [],
                'skipped_transaction_count' => (int)($archive['skipped_transaction_count'] ?? 0),
            ],
            'account_mapping' => $accountMapping,
            'normalized_targets' => $targets,
            'blocking_reasons' => array_values(array_unique($blocking)),
            'migration_gaps' => $gaps,
        ];
    }

    private function collectShadowBlockingReasons(string $group, array $sections, array &$blocking): void
    {
        foreach ($sections as $name => $summary) {
            if (!is_array($summary)) {
                $blocking[] = $group . '/' . $name . ': invalid summary';
                continue;
            }
            $inserted = (int)($summary['inserted_count'] ?? 0);
            $updated = (int)($summary['updated_count'] ?? 0);
            $repaired = (int)($summary['repair_count'] ?? 0);
            $deleted = (int)($summary['deleted_count'] ?? 0);
            if ($inserted + $updated + $repaired + $deleted > 0) {
                $blocking[] = $group . '/' . $name . ': shadow differs from current JSON';
            }
        }
    }

    private function compactShadowSections(array $sections): array
    {
        $result = [];
        foreach ($sections as $name => $summary) {
            if (!is_array($summary)) continue;
            $result[(string)$name] = [
                'source_count' => (int)($summary['source_count'] ?? 0),
                'unchanged_count' => (int)($summary['unchanged_count'] ?? 0),
                'inserted_count' => (int)($summary['inserted_count'] ?? 0),
                'updated_count' => (int)($summary['updated_count'] ?? 0),
                'repair_count' => (int)($summary['repair_count'] ?? 0),
                'deleted_count' => (int)($summary['deleted_count'] ?? 0),
            ];
        }
        return $result;
    }

    private function accountMappingSummary(): array
    {
        $sourceRows = $this->database->fetchAll(
            "SELECT entity_key FROM mgw_legacy_realtime_shadow
             WHERE entity_type = 'economy_user_balance' ORDER BY entity_key"
        );
        $sourceIds = [];
        foreach ($sourceRows as $row) {
            $id = trim((string)($row['entity_key'] ?? ''));
            if ($id !== '') $sourceIds[$id] = true;
        }

        $identityRows = $this->database->fetchAll(
            "SELECT mgw_id, provider_subject FROM mgw_identities
             WHERE provider IN ('telegram', 'development')"
        );
        $identities = [];
        foreach ($identityRows as $row) {
            $subject = trim((string)($row['provider_subject'] ?? ''));
            $mgwId = trim((string)($row['mgw_id'] ?? ''));
            if ($subject === '' || $mgwId === '') continue;
            $identities[$subject] = $mgwId;
        }

        $userIds = [];
        foreach ($this->database->fetchAll('SELECT mgw_id FROM mgw_users') as $row) {
            $mgwId = trim((string)($row['mgw_id'] ?? ''));
            if ($mgwId !== '') $userIds[$mgwId] = true;
        }

        $mapped = 0;
        $missingUsers = 0;
        foreach (array_keys($sourceIds) as $legacyId) {
            $mgwId = $identities[$legacyId] ?? null;
            if ($mgwId === null) continue;
            $mapped++;
            if (!isset($userIds[$mgwId])) $missingUsers++;
        }

        $orphanIdentities = 0;
        foreach (array_keys($identities) as $subject) {
            if (!isset($sourceIds[$subject])) $orphanIdentities++;
        }

        return [
            'source_user_count' => count($sourceIds),
            'database_user_count' => count($userIds),
            'provider_identity_count' => count($identities),
            'mapped_identity_count' => $mapped,
            'missing_identity_count' => max(0, count($sourceIds) - $mapped),
            'missing_user_row_count' => $missingUsers,
            'orphan_identity_count' => $orphanIdentities,
        ];
    }

    private function normalizedTargetSummary(
        array $realtime,
        array $opening,
        array $archive,
        array $accounts
    ): array {
        $source = [
            'users' => (int)($accounts['source_user_count'] ?? 0),
            'matches' => (int)($realtime['sections']['games']['source_count'] ?? 0),
            'queue' => (int)($realtime['sections']['queue']['source_count'] ?? 0),
            'invites' => (int)($realtime['sections']['invites']['source_count'] ?? 0),
            'notifications' => (int)($realtime['sections']['notifications']['source_count'] ?? 0),
            'balances' => (int)($opening['source_asset_count'] ?? 0),
            'legacy_payments' => (int)($archive['archive_counts']['payments'] ?? 0),
            'legacy_shop_orders' => (int)($archive['archive_counts']['shop_orders'] ?? 0),
            'legacy_financial_transactions' => (int)($archive['archive_counts']['transactions'] ?? 0),
        ];
        $tables = [
            'users' => 'mgw_users',
            'matches' => 'mgw_matches',
            'queue' => 'mgw_match_queue',
            'invites' => 'mgw_invites',
            'notifications' => 'mgw_notifications',
            'balances' => 'mgw_balances',
            'legacy_payments' => 'mgw_legacy_payments',
            'legacy_shop_orders' => 'mgw_legacy_shop_orders',
            'legacy_financial_transactions' => 'mgw_legacy_financial_transactions',
        ];

        $result = [];
        foreach ($tables as $name => $table) {
            $databaseCount = (int)$this->database->fetchValue('SELECT COUNT(*) FROM ' . $table);
            $sourceCount = $source[$name];
            $result[$name] = [
                'source_count' => $sourceCount,
                'database_count' => $databaseCount,
                'gap_count' => abs($sourceCount - $databaseCount),
                'count_matches' => $sourceCount === $databaseCount,
            ];
        }
        return $result;
    }

    private function reportFingerprint(array $value): string
    {
        return hash('sha256', LedgerIntegrity::canonicalJson($value));
    }
}
