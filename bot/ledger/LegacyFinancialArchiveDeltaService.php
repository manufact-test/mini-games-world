<?php
declare(strict_types=1);

final class LegacyFinancialArchiveDeltaService
{
    private const META_KEY = 'legacy_financial_archive_import_v1';
    private const FINGERPRINT_BLOCKER = 'Archive metadata belongs to a different source fingerprint.';
    private const COMPLETED_GAP_BLOCKER = 'Completed archive import is missing expected rows.';

    public function __construct(
        private DatabaseConnectionInterface $database,
        private LegacyFinancialArchiveImportService $archive
    ) {}

    public function preview(): array
    {
        $plan = $this->archive->preview();
        $blockers = array_values(array_map('strval', (array)($plan['blocking_reasons'] ?? [])));
        $planned = $this->sumCounts($plan['planned_create_counts'] ?? []);
        $conflicts = $this->sumCounts($plan['conflict_counts'] ?? []);
        $unmanaged = $this->sumCounts($plan['unmanaged_counts'] ?? []);
        $status = (string)($plan['status'] ?? 'not_started');
        $metaFingerprint = (string)($plan['meta']['source_fingerprint'] ?? '');
        $sourceFingerprint = (string)($plan['source_fingerprint'] ?? '');

        $unexpected = array_values(array_filter(
            $blockers,
            static fn(string $reason): bool => !in_array(
                $reason,
                [self::FINGERPRINT_BLOCKER, self::COMPLETED_GAP_BLOCKER],
                true
            )
        ));
        $requiresAdvance = $status === 'completed'
            && $planned > 0
            && $conflicts === 0
            && $unmanaged === 0
            && $unexpected === []
            && in_array(self::FINGERPRINT_BLOCKER, $blockers, true)
            && $metaFingerprint !== ''
            && $sourceFingerprint !== ''
            && !hash_equals($metaFingerprint, $sourceFingerprint);
        $ready = !empty($plan['ready']) || $requiresAdvance;

        return [
            'ok' => $ready,
            'ready' => $ready,
            'read_only' => true,
            'status' => $status,
            'source_fingerprint' => $sourceFingerprint,
            'previous_source_fingerprint' => $metaFingerprint,
            'planned_create_total' => $planned,
            'conflict_total' => $conflicts,
            'unmanaged_total' => $unmanaged,
            'requires_metadata_advance' => $requiresAdvance,
            'blocking_reasons' => $ready ? [] : $blockers,
            'archive_plan' => $plan,
        ];
    }

    public function run(): array
    {
        $preview = $this->preview();
        if (!$preview['ready']) {
            throw new RuntimeException(
                'Legacy financial archive delta is not ready: '
                . implode('; ', (array)$preview['blocking_reasons'])
            );
        }

        $advanced = false;
        if ($preview['requires_metadata_advance']) {
            $this->advanceMetadata(
                (string)$preview['previous_source_fingerprint'],
                (string)$preview['source_fingerprint']
            );
            $advanced = true;
        }

        $result = $this->archive->run();
        return [
            'ok' => !empty($result['ok']),
            'action' => 'run',
            'metadata_advanced' => $advanced,
            'source_fingerprint' => (string)($result['source_fingerprint'] ?? ''),
            'created_counts' => $result['created_counts'] ?? [],
            'unchanged_counts' => $result['unchanged_counts'] ?? [],
            'verification' => $result['verification'] ?? [],
            'archive_result' => $result,
        ];
    }

    private function advanceMetadata(string $expectedFingerprint, string $nextFingerprint): void
    {
        $this->database->transaction(function (DatabaseConnectionInterface $database) use (
            $expectedFingerprint,
            $nextFingerprint
        ): void {
            $rows = $database->fetchAll(
                'SELECT meta_value FROM mgw_meta WHERE meta_key = :meta_key',
                ['meta_key' => self::META_KEY]
            );
            if (count($rows) !== 1) {
                throw new RuntimeException('Financial archive metadata row is missing or duplicated.');
            }

            try {
                $meta = json_decode((string)$rows[0]['meta_value'], true, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                throw new RuntimeException('Financial archive metadata is invalid.');
            }
            if (!is_array($meta) || (string)($meta['status'] ?? '') !== 'completed') {
                throw new RuntimeException('Financial archive metadata is not in completed state.');
            }
            $currentFingerprint = (string)($meta['source_fingerprint'] ?? '');
            if ($currentFingerprint === '' || !hash_equals($expectedFingerprint, $currentFingerprint)) {
                throw new RuntimeException('Financial archive metadata changed after delta preview.');
            }

            $details = is_array($meta['details'] ?? null) ? $meta['details'] : [];
            $details['delta_previous_source_fingerprint'] = $currentFingerprint;
            $details['delta_started_at_utc'] = $this->now();
            $value = LedgerIntegrity::canonicalJson([
                'status' => 'started',
                'source_fingerprint' => $nextFingerprint,
                'details' => $details,
            ]);
            $updated = $database->execute(
                'UPDATE mgw_meta SET meta_value = :meta_value, updated_at_utc = :updated_at_utc '
                . 'WHERE meta_key = :meta_key',
                [
                    'meta_value' => $value,
                    'updated_at_utc' => $this->now(),
                    'meta_key' => self::META_KEY,
                ]
            );
            if ($updated !== 1) {
                throw new RuntimeException('Financial archive metadata could not be advanced.');
            }
        });
    }

    private function sumCounts(mixed $counts): int
    {
        $total = 0;
        foreach ((array)$counts as $count) $total += max(0, (int)$count);
        return $total;
    }

    private function now(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');
    }
}
